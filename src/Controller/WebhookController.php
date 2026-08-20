<?php

namespace Drupal\mercadopago\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\mercadopago\Service\MercadoPagoApiService;

class WebhookController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   *
   * Defines the interface for a configuration object factory.
   */
  protected $configFactory;

  /**
   * @var \Drupal\mercadopago\Service\MercadoPagoApiService
   *
   * Define el servicio de mercado pago.
   */
  protected $mpApiService;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, MercadoPagoApiService $mp_api_service) {
    $this->configFactory = $config_factory;
    $this->mpApiService = $mp_api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('mercadopago.api'),
    );
  }

  /**
   * Maneja las notificaciones (webhook/IPN) de Mercado Pago.
   *
   * Acceso anónimo (ver mercadopago.routing.yml): Mercado Pago no se autentica
   * con sesión Drupal. La seguridad se delega a la verificación de firma
   * X-Signature.
   *
   * Política de verificación de firma:
   * - Si la pasarela tiene configurado `webhook_secret`, la verificación es
   *   OBLIGATORIA (fail-closed): si la firma no coincide se responde 401 y la
   *   notificación se descarta.
   * - Si `webhook_secret` NO está configurado (entornos de desarrollo), la
   *   notificación se acepta pero se registra un WARNING en el canal
   *   `mercadopago` alertando que el endpoint corre SIN verificación de firma
   *   y, por lo tanto, es spoofeable.
   *
   * Algoritmo de verificación según la documentación oficial de Mercado Pago:
   * 1. Del header `X-Signature` (formato `ts=...,v1=...`; también se acepta
   *    `v4`) se extraen el timestamp y el hash.
   * 2. Se construye el manifest: `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`
   *    omitiendo los pares cuyo valor no está presente.
   * 3. Se calcula HMAC-SHA256(manifest, webhook_secret) en hexadecimal.
   * 4. Se compara en tiempo constante contra el hash del header.
   */
  public function handle(Request $request) {
    // Leer los datos de la notificación de MP (Type, Data.id/resource_id).
    $data = json_decode($request->getContent(), TRUE);

    // Configuración de la pasarela para leer el webhook_secret.
    $configuration = $this->configFactory->get('commerce_payment.commerce_payment_gateway.mercadopago_headless')->get('configuration') ?? [];
    $webhook_secret = $configuration['webhook_secret'] ?? '';

    if ($webhook_secret !== '') {
      // Verificación OBLIGATORIA (fail-closed). El data.id se extrae aquí solo
      // para construir el manifest; si la firma falla, se loguea el rechazo
      // con el id y la notificación se descarta.
      $resource_id = $data['data']['id'] ?? NULL;
      $x_signature = $request->headers->get('x-signature');
      $x_request_id = $request->headers->get('x-request-id');

      if (!$this->verifySignature($x_signature, $x_request_id, $resource_id, $webhook_secret)) {
        $this->getLogger('mercadopago')->warning('Webhook de Mercado Pago rechazado: firma X-Signature inválida (data.id: @id).', [
          '@id' => $resource_id ?? 'unknown',
        ]);
        return new JsonResponse(['error' => 'Invalid signature'], 401);
      }
    }
    else {
      // Sin webhook_secret configurado (dev): se acepta la notificación pero
      // se alerta claramente que el endpoint no valida la procedencia.
      $this->getLogger('mercadopago')->warning('El webhook de Mercado Pago corre SIN verificación de firma X-Signature. Configure el campo webhook_secret en la pasarela para habilitar la validación (no recomendado en producción).');
    }

    $resource_id = $data['data']['id'] ?? NULL;

    if (!$resource_id) {
      return new JsonResponse(['error' => 'No resource ID provided'], 400);
    }

    // Consultar el estado real de la Suscripción en MP.
    // processNotification() devuelve FALSE cuando el resource id no es
    // numérico (payload malformado/spoofeado). En ese caso se responde 4xx
    // para que Mercado Pago NO reintente y se evita el 500 por TypeError.
    $processed = $this->mpApiService->processNotification($resource_id, $data['type']);

    if ($processed === FALSE) {
      return new JsonResponse(['error' => 'Invalid resource ID'], 400);
    }

    // DEVOLVER 200 OK SIEMPRE a Mercado Pago para evitar reintentos.
    return new JsonResponse(['status' => 'acknowledged'], 200);
  }

  /**
   * Verifica la firma X-Signature de una notificación de Mercado Pago.
   *
   * @param string|null $x_signature
   *   Valor crudo del header X-Signature (ej. "ts=1704908010,v1=<hash>").
   * @param string|null $x_request_id
   *   Valor del header X-Request-Id.
   * @param string|null $resource_id
   *   Valor de data.id del payload de la notificación.
   * @param string $webhook_secret
   *   Secreto de firma de la aplicación en Mercado Pago.
   *
   * @return bool
   *   TRUE si la firma es válida, FALSE en caso contrario.
   */
  private function verifySignature($x_signature, $x_request_id, $resource_id, $webhook_secret): bool {
    if (!$x_signature || $resource_id === NULL || $resource_id === '') {
      return FALSE;
    }

    // Parsear los componentes "clave=valor" del header (separados por ',' o ';').
    $parts = [];
    foreach (preg_split('/[;,]/', $x_signature) as $part) {
      if (str_contains($part, '=')) {
        [$key, $value] = explode('=', $part, 2);
        $parts[trim($key)] = trim($value);
      }
    }

    $ts = $parts['ts'] ?? NULL;
    $hash = $parts['v1'] ?? $parts['v4'] ?? NULL;

    if (!$ts || !$hash) {
      return FALSE;
    }

    // Construir el manifest según la documentación oficial, omitiendo los
    // pares sin valor presente en la petición.
    $manifest = "id:{$resource_id};";
    if ($x_request_id) {
      $manifest .= "request-id:{$x_request_id};";
    }
    $manifest .= "ts:{$ts};";

    // HMAC-SHA256 del manifest con el webhook_secret, codificado en hexadecimal
    // (formato en el que Mercado Pago envía el hash en el header X-Signature).
    $expected = hash_hmac('sha256', $manifest, $webhook_secret);

    // Comparación en tiempo constante para mitigar ataques de timing.
    return hash_equals($expected, $hash);
  }

}