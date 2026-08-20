<?php

namespace Drupal\mercadopago\Service;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Client\PreApprovalPlan\PreApprovalPlanClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Resources\Payment;
use MercadoPago\Resources\PreApprovalPlan;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Define el servicio de mercado pago.
 */
class MercadoPagoApiService {

  /**
   * Defines the immutable configuration object.
   *
   * @var array
   */
  protected $config;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   *
   * Provides an interface for entity type managers.
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   *
   * Defines the interface for a configuration object factory.
   */
  protected $configFactory;

  /**
   * Logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $mercadopagoLogger;

  /**
   * Constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $mercadopago_logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->mercadopagoLogger = $mercadopago_logger;

    $config = $config_factory->get('commerce_payment.commerce_payment_gateway.mercadopago_headless');
    $this->config = $config->get('configuration');

    $access_token = $this->config['access_token'];

    // Configuración global del SDK de Mercado Pago.
    MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);
    if (!empty($access_token)) {
      MercadoPagoConfig::setAccessToken($access_token);
    }
  }

  /**
   * Crea un plan de suscripción en Mercado Pago.
   *
   * Este método toma una variación de producto y una configuración
   * de frecuencia, y crea un plan de suscripción en la API de Mercado Pago.
   * Si ocurre algún error en la comunicación con Mercado Pago, se lanzará
   * una excepción que debe ser manejada por el llamador.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   La variación de producto a utilizar como base del plan de suscripción.
   * @param array $frequency
   *   Configuración de frecuencia/recurreción para la suscripción, normalmente
   *   obtenida del plugin de billing_schedule del producto.
   *
   * @return \MercadoPago\Resources\PreApprovalPlan
   *   El plan de suscripción creado en Mercado Pago (PreApprovalPlan).
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Lanza excepción si Mercado Pago no responde correctamente o si ocurre un
   *   error inesperado.
   */
  public function createSuscriptionPlan(ProductVariationInterface $variation, array $frequency): PreApprovalPlan {
    $planClient = new PreApprovalPlanClient();

    try {
      $planRequest = [
        'reason' => $variation->getTitle(),
        "auto_recurring" => [
          "frequency" => $frequency["frequency"],
          "frequency_type" => $frequency["frequency_type"],
          "transaction_amount" => (float) $variation->getPrice()->getNumber(),
          "currency_id" => $variation->getPrice()->getCurrencyCode(),
        ],
        "back_url" => $this->config['back_url'],
        "payment_methods_allowed" => [
          "payment_types" => [
            ["id" => "credit_card"],
          ],
        ],
      ];

      $preApprovalPlan = $planClient->create($planRequest);
      return $preApprovalPlan;
    }
    catch (MPApiException $e) {
      // Loguear el error de la API de Mercado Pago para monitoreo.
      $this->mercadopagoLogger->error(
        'Error al crear plan de suscripción Mercado Pago: @status @content',
        [
          '@status' => $e->getApiResponse()->getStatusCode(),
          '@content' => print_r($e->getApiResponse()->getContent(), TRUE),
        ]
          );

      throw new BadRequestHttpException(
            $e->getApiResponse()->getContent()['code'] . ': ' . $e->getApiResponse()->getContent()['message'],
            $e,
            $e->getStatusCode()
          );
    }
    catch (\Exception $e) {
      // Captura errores inesperados y registra.
      $this->mercadopagoLogger->error(
        'Error inesperado al crear plan de suscripción Mercado Pago: @message',
        ['@message' => $e->getMessage()]
          );

      throw new BadRequestHttpException(
            'mp_suscription_unexpected_exception: ' . $e->getMessage(),
            $e,
            $e->getCode()
          );
    }
  }

  /**
   * Crea una suscripción preaprobada en Mercado Pago.
   *
   * Este método utiliza el ID de un plan preaprobado, el token de la tarjeta,
   * el email del pagador y el ID de la suscripción de Drupal
   * (como referencia externa) para registrar una nueva suscripción recurrente
   * en Mercado Pago.
   *
   * @param string $plan_id
   *   El ID del plan preaprobado de Mercado Pago.
   * @param string $reason
   *   La razón o descripción de la suscripción.
   * @param string $card_token_id
   *   El token de la tarjeta, generado en el frontend.
   * @param string $email
   *   El correo electrónico del pagador (payer).
   * @param string $external_reference
   *   El ID de referencia externa para la suscripción en Mercado Pago
   *   (normalmente el ID de la suscripción de Drupal).
   *
   * @return mixed
   *   La respuesta de Mercado Pago, normalmente un objeto con los datos de la
   *   suscripción creada.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Si la API de Mercado Pago retorna un error o ocurre una excepción
   *   inesperada.
   */
  public function createPreapprovalSubscription(string $plan_id, string $reason, string $card_token_id, string $email, string $external_reference) {
    $client = new PreApprovalClient();

    try {
      $request_data = [
        // CRÍTICO: ID del plan pre-configurado.
        'preapproval_plan_id' => $plan_id,
        'reason' => $reason,
        'payer_email' => $email,
        // ID del token de la tarjeta generado en el frontend.
        'card_token_id' => $card_token_id,
        // ID de la suscripción de Drupal para el webhook.
        'external_reference' => $external_reference,
        // URL a la que MP notificará cambios de estado/cobros.
        'notification_url' => $this->config['notification_url'],
        // Otros campos opcionales: auto_recurring, etc.
      ];

      $preapproval = $client->create($request_data);
      return $preapproval;
    }
    catch (MPApiException $e) {
      // Loguear el error de la API de Mercado Pago para monitoreo.
      $this->mercadopagoLogger->error(
        'Error al crear suscripción a Mercado Pago: @status @content',
        [
          '@status' => $e->getApiResponse()->getStatusCode(),
          '@content' => print_r($e->getApiResponse()->getContent(), TRUE),
        ]
          );

      throw new BadRequestHttpException(
            $e->getApiResponse()->getContent()['code'] . ': ' . $e->getApiResponse()->getContent()['message'],
            $e,
            $e->getStatusCode()
          );
    }
    catch (\Exception $e) {
      // Captura errores inesperados y registra.
      $this->mercadopagoLogger->error(
        'Error inesperado al crear suscripción a Mercado Pago: @message',
        ['@message' => $e->getMessage()]
          );

      throw new BadRequestHttpException(
            'mp_suscription_unexpected_exception: ' . $e->getMessage(),
            $e,
            $e->getCode()
          );
    }
  }

  /**
   * Cancela una suscripción (pre-aprobación) existente en Mercado Pago.
   *
   * Este método utiliza el ID de pre-aprobación proporcionado para actualizar
   * el estado de la suscripción a 'cancelled' a través de la API de Mercado
   * Pago.
   *
   * @param string $preapproval_id
   *   El ID de la pre-aprobación (suscripción) de Mercado Pago a cancelar.
   *
   * @return \MercadoPago\Resources\PreApproval
   *   El objeto PreApproval actualizado con el estado de cancelación.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Si ocurre un error al comunicarse con la API de Mercado Pago
   *   o si hay un error inesperado durante el proceso de cancelación.
   */
  public function cancelSubscription(string $preapproval_id) {
    $client = new PreApprovalClient();

    try {
      $result = $client->update($preapproval_id, ['status' => 'cancelled']);
      return $result;
    }
    catch (MPApiException $e) {
      $this->mercadopagoLogger->error(
        'Error al cancelar suscripción a Mercado Pago: @status @content',
        [
          '@status' => $e->getApiResponse()->getStatusCode(),
          '@content' => print_r($e->getApiResponse()->getContent(), TRUE),
        ]
          );

      throw new BadRequestHttpException(
            $e->getApiResponse()->getContent()['code'] . ': ' . $e->getApiResponse()->getContent()['message'],
            $e,
            $e->getStatusCode()
          );
    }
    catch (\Exception $e) {
      $this->mercadopagoLogger->error(
        'Error inesperado al cancelar suscripción a Mercado Pago: @message',
        ['@message' => $e->getMessage()]
          );
      throw new BadRequestHttpException(
            'mp_suscription_unexpected_exception: ' . $e->getMessage(),
            $e,
            $e->getCode()
          );
    }
  }

  /**
   * Procesa una notificación de Mercado Pago.
   *
   * Este método recibe el ID de la suscripción/preapproval o payment y el tipo
   * de evento recibido desde Mercado Pago (por ejemplo, "preapproval",
   * "payment"), para consultar el estado actualizado en la API de Mercado Pago
   * y ajustarlos registros correspondientes en Drupal.
   *
   * - Si el tipo es "preapproval", consulta el estado de la suscripción
   *   en Mercado Pago usando el PreApprovalClient y puede sincronizar con la
   *   entidad de suscripción Drupal si es requerido.
   * - Si el tipo es "payment", consulta el detalle del pago usando
   *   PaymentClient y puede actualizar el estado del pago y la orden asociada
   *   en Drupal.
   *
   * Se recomienda loggear cualquier acción o error para auditoría.
   *
   * @param string $resource_id
   *   El ID del recurso recibido por notificación de Mercado Pago (puede ser de
   *   suscripción o pago).
   * @param string $topic
   *   El tipo de evento recibido en la notificación
   *   (por ejemplo: 'preapproval', 'payment').
   *
   * @return array|null
   *   Los detalles del recurso consultado o NULL en caso de error.
   */
  public function processNotification(string $resource_id, string $topic): bool {
    // Guard contra TypeError: el data.id que envía Mercado Pago llega como
    // STRING y los ids legítimos (pagos, preapprovals y planes) son siempre
    // numéricos. PaymentClient::get() tiene type-hint `int` y un id no numérico
    // lanzaría un TypeError -> 500 y MP reintentaría sin sentido. Decisión:
    // devolver FALSE para que el WebhookController responda 4xx. Un id no
    // numérico indica un payload malformado o spoofeado que jamás podrá
    // procesarse; el 4xx le indica a MP que no reintente y el warning queda en
    // el log para auditoría. Los flujos válidos no se ven afectados porque sus
    // ids son siempre numéricos.
    if (!is_numeric($resource_id)) {
      $this->mercadopagoLogger->warning(
        'Webhook de Mercado Pago descartado: el resource id "@id" (topic "@topic") no es numérico.',
        ['@id' => $resource_id, '@topic' => $topic]
      );
      return FALSE;
    }

    switch ($topic) {
      case "payment":
        $paymentClient = new PaymentClient();
        $payment = $paymentClient->get((int) $resource_id);
        $this->processPaymentNotification($payment);
        break;

      case "subscription_preapproval_plan":
        $preApprovalPlanClient = new PreApprovalPlanClient();
        $plan = $preApprovalPlanClient->get($resource_id);
        break;

      case "subscription_preapproval":
        $preApprovalClient = new PreApprovalClient();
        $preapproval = $preApprovalClient->get($resource_id);

        if ($preapproval->status === 'cancelled') {
          // El external_reference puede ser el UUID de la orden inicial hasta
          // que el primer webhook de pago lo reemplace por el ID numérico de
          // la suscripción de Drupal. Si no es numérico, no se puede cargar la
          // suscripción local por ID (además de evitar un TypeError por el
          // cast a int), así que se loguea un warning y se omite la cancelación.
          if (is_numeric($preapproval->external_reference)) {
            $this->processCancelSubscription((int) $preapproval->external_reference);
          }
          else {
            $this->mercadopagoLogger->warning(
              'Suscripción de Mercado Pago @id cancelada, pero su external_reference (@reference) no es un ID numérico de suscripción Drupal. No se pudo cancelar la suscripción local.',
              [
                '@id' => $preapproval->id,
                '@reference' => $preapproval->external_reference,
              ]
            );
          }
        }
        break;

      case "subscription_authorized_payment":
        $preApprovalClient = new PreApprovalClient();
        $suscription = $preApprovalClient->get($resource_id);
        break;

      case "point_integration_wh":
        // $_POST contiene la informaciòn relacionada a la notificaciòn.
        break;
    }
    return TRUE;
  }

  /**
   * Cancela una suscripción de Drupal basada en su ID.
   *
   * Este método carga una suscripción de Commerce Recurring por su ID,
   * llama al método `cancel()` sobre ella y luego la guarda para
   * persistir los cambios en la base de datos.
   *
   * @param int $subscription_id
   *   El ID de la suscripción de Drupal a cancelar.
   */
  private function processCancelSubscription(int $subscription_id) {
    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface|null $subscription */
    $subscription = $this->entityTypeManager->getStorage('commerce_subscription')->load($subscription_id);

    // La suscripción pudo haber sido eliminada o el ID no corresponde a una
    // suscripción local (ej. external_reference apuntando a una orden).
    if (!$subscription) {
      $this->mercadopagoLogger->warning(
        'No se pudo cargar la suscripción Drupal @id para cancelarla desde el webhook de Mercado Pago.',
        ['@id' => $subscription_id]
      );
      return;
    }

    $subscription->cancel();
    $subscription->save();
  }

  /**
   * Procesa y sincroniza una notificación de pago de Mercado Pago con Drupal.
   *
   * Este método es llamado cuando se recibe una notificación de pago de
   * Mercado Pago (objeto Payment). Su propósito es actualizar el estado del
   * pago y de la orden asociada en Drupal según el estado reportado por
   * Mercado Pago.
   *
   * Flujos principales:
   * - Busca la orden de Drupal asociada usando el external_reference del pago.
   * - Busca y carga el pago de Drupal relacionado a la orden.
   * - Sincroniza el monto reembolsado y el estado del pago si corresponde.
   * - Según el estado $mp_status recibido desde Mercado Pago:
   *   - Si el pago es 'approved' y ni la orden ni el pago están completados,
   *     marca ambos como 'completed', actualiza remoteId y remoteState.
   *   - Si el pago es 'authorized' o 'pending', marca el pago como
   *     'authorization'.
   *   - Si el pago es 'cancelled' o 'expired', marca la orden como 'canceled'.
   * - Si el pago es 'approved' y la orden no es de tipo 'recurring',
   *   actualiza la referencia externa de la suscripción en Mercado Pago con el
   *   id de la suscripción Drupal correspondiente a la orden (solo si aún no
   *   se ha actualizado).
   *
   * @param \MercadoPago\Resources\Payment $mp_payment
   *   El objeto Payment recibido desde la API de Mercado Pago.
   *
   * @return void
   *   No retorna valor; las actualizaciones son persistidas directamente.
   */
  private function processPaymentNotification(Payment $mp_payment) {
    $mp_status = $mp_payment->status;
    $external_reference = $mp_payment->external_reference;
    $drupal_order = FALSE;
    $isInitialOrder = FALSE;
    $drupal_subscription = NULL;

    /** @var \Drupal\commerce_recurring\SubscriptionStorageInterface $subscription_storage */
    $subscription_storage = $this->entityTypeManager->getStorage('commerce_subscription');

    // En la primera notificación de pago, el external_reference es el uuid de
    // la orden inicial.
    if (!is_numeric($external_reference)) {
      /** @var \Drupal\commerce_order\Entity\OrderInterface[] $drupal_orders */
      $drupal_orders = $this->entityTypeManager->getStorage("commerce_order")
        ->loadByProperties(["uuid" => $external_reference]);

      if (!$drupal_orders) {
        return;
      }

      $drupal_order = reset($drupal_orders);
      $isInitialOrder = TRUE;
    }
    else {
      // En una segunda notificación de pago, el external_reference será el id.
      // de la suscripción.
      $drupal_subscription = $subscription_storage->load($external_reference);
      if ($drupal_subscription) {
        $subscription_orders = $drupal_subscription->getOrders();
        $order_to_update = \count($subscription_orders) === 0 ? 0 : \count($subscription_orders) - 1;
        $drupal_order = $subscription_orders[$order_to_update];
      }
    }

    // La orden no fue encontrada.
    if (!$drupal_order) {
      return;
    }

    // Obtener pagos para actualizar estados.
    /** @var \Drupal\commerce_payment\PaymentStorage $paymentStorage */
    $paymentStorage = $this->entityTypeManager->getStorage("commerce_payment");
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface[] $payments */
    $payments = $paymentStorage->loadByProperties(["order_id" => $drupal_order->id()]);

    // No hay pagos para actualizar.
    if (!$payments) {
      return;
    }

    // Entidad de pago asociada a la orden.
    $drupal_payment = reset($payments);
    $current_drupal_payment_state = $drupal_payment->getState()->value;
    $current_drupal_order_state = $drupal_order->getState()->value;

    // Obtener el remoteId del preapproval para consultar el preapproval
    // y actualizar el external ID.
    try {
      $payment_method = $drupal_order->get('payment_method')->entity;
      if (!$payment_method) {
        throw new PaymentGatewayException('Método de pago no encontrado en la orden.');
      }
      $preapproval_id = $payment_method->getRemoteId();
    }
    catch (\Exception $e) {
      // Loguear error y salir si no se puede obtener el preapproval_id.
      $this->mercadopagoLogger->error('No se pudo obtener el preapproval_id para la orden @id.', ['@id' => $drupal_order->id()]);
      return;
    }

    // Se guarda el estado del pago y la orden para generar la suscripción
    // solo cuando sea completado.
    if ($mp_status === 'approved' && ($current_drupal_order_state !== 'completed' || $current_drupal_payment_state !== 'completed')) {
      $drupal_order->set('state', 'completed');
      $drupal_payment->setState('completed');
      $drupal_payment->setRemoteId($mp_payment->id);
      $drupal_payment->setRemoteState($mp_status);
      $drupal_payment->save();
      $drupal_order->save();

      // Consultar la suscripción después de guardar la orden inicial, de lo
      // contrario no se generaria la suscripción.
      if ($isInitialOrder) {
        // Consultamos la suscripción creada con la primer orden.
        $query = $subscription_storage->getQuery();
        $query->condition('initial_order', $drupal_order->id())
          ->condition('state', ['trial', 'active'], 'IN');
        $subscription_ids = $query->accessCheck(FALSE)->execute();
        $subscription_id = reset($subscription_ids);
        /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface|null $drupal_subscription */
        $drupal_subscription = $subscription_id ? $subscription_storage->load($subscription_id) : NULL;

        // Si la suscripción todavía no fue generada, no se puede actualizar la
        // referencia externa; se omite para no provocar un fatal sobre NULL.
        if ($drupal_subscription) {
          // Se actualiza la referencia externa de la suscripción en mercado
          // pago con el id de la suscripción en Drupal obtenido del pago de la
          // primer orden.
          $preApprovalClient = new PreApprovalClient();
          $preApprovalClient->update($preapproval_id, [
            'external_reference' => $drupal_subscription->id(),
          ]);
        }
      }
    }
    elseif (($mp_status === 'authorized' || $mp_status === 'pending') && ($current_drupal_payment_state !== 'authorization')) {
      $drupal_payment->setState('authorization');
      $drupal_payment->setRemoteState($mp_status);
      $drupal_payment->save();
    }
    elseif (($mp_status === 'cancelled' || $mp_status === 'expired') && $current_drupal_order_state !== 'canceled') {
      $drupal_order->set('state', 'canceled');
      $drupal_order->save();
    }

    // Calcular reembolso y actualizar.
    $this->verifyAndApplyRefund($mp_payment, $drupal_payment, $drupal_subscription);

    // Cancelar suscripcion cuando haya un problema con el pago, el medio de
    // pago o un reembolso total.
    if ($drupal_subscription && ($mp_status === 'refunded' || $mp_status === 'cancelled' || $mp_status === 'expired')) {
      $drupal_subscription->cancel(FALSE);
      $drupal_subscription->save();
    }
  }

  /**
   * Verifica y aplica el reembolso en el pago de Drupal según el estado en MP.
   *
   * Esta función compara el monto total reembolsado reportado en MercadoPago
   * respecto al pago relacionado en Drupal. Si el reembolso en MercadoPago es
   * mayor al registrado actualmente en Drupal, sincroniza el valor de
   * reembolso, actualiza el estado del pago en Drupal (a 'partially_refunded'
   * o 'refunded') y almacena el estado remoto correspondiente.
   *
   * Además, registra un mensaje en el log de la integración cuando se
   * sincroniza un reembolso.
   *
   * @param \MercadoPago\Resources\Payment $mp_payment
   *   El objeto de pago remoto de MercadoPago (API SDK).
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $drupal_payment
   *   Referencia al pago en Drupal a actualizar.
   * @param \Drupal\commerce_recurring\Entity\SubscriptionInterface $drupal_subscription
   *   Referencia a la suscripción de Drupal asociada al pago, si aplica.
   */
  private function verifyAndApplyRefund(Payment $mp_payment, PaymentInterface $drupal_payment, ?SubscriptionInterface $drupal_subscription = NULL) {
    // Total reembolsado en mercado pago.
    $mp_refunded_amount = (float) $mp_payment->transaction_amount_refunded;

    // Monto del pago actual en Drupal.
    $payment_amount = (float) $drupal_payment->getAmount()->getNumber();
    $refunded_amount = $drupal_payment->getRefundedAmount();
    $payment_refounded_amount = $refunded_amount ? (float) $refunded_amount->getNumber() : 0.0;

    // Verificar si hay algún cambio en el monto reembolsado.
    if ($mp_refunded_amount > $payment_refounded_amount) {
      $refunded_state = 'partially_refunded';
      // Calcular el nuevo monto a reembolsar.
      $new_refunded_amount = new Price((string) $mp_refunded_amount, $drupal_payment->getAmount()->getCurrencyCode());
      // Actualizar el pago en Drupal.
      $drupal_payment->setRefundedAmount($new_refunded_amount);

      // Verificar reembolso total.
      if ($mp_refunded_amount >= $payment_amount) {
        // Establecer estado si el reembolso es completo.
        $refunded_state = 'refunded';
      }

      // Establecer nuevo estado de reembolso.
      $drupal_payment->setState($refunded_state);
      $drupal_payment->setRemoteState($mp_payment->status);
      $drupal_payment->save();

      // Registrar en logs.
      $this->mercadopagoLogger->info('Reembolso de @amount sincronizado para el pago @id.', [
        '@amount' => $mp_refunded_amount,
        '@id' => $drupal_payment->id(),
      ]);

      if ($drupal_subscription && $refunded_state === 'refunded') {
        $this->mercadopagoLogger->warning('La suscripción @subscription_id ha sido cancelada debido a un reembolso completo de @amount para el pago @payment_id.', [
          '@subscription_id' => $drupal_subscription->id(),
          '@amount' => $mp_refunded_amount,
          '@payment_id' => $drupal_payment->id(),
        ]);
      }
    }
  }

}
