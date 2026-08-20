<?php

namespace Drupal\mercadopago\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsCreatingPaymentMethodsInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provee la pasarela de pago Headless para Mercado Pago.
 */
#[CommercePaymentGateway(
  id: "mercadopago_headless",
  label: new TranslatableMarkup("Mercado Pago Headless (Suscripciones)"),
  display_label: new TranslatableMarkup("Mercado Pago Headless (Suscripciones)"),
  payment_method_types: ["credit_card"]
)]
class MercadoPagoHeadless extends PaymentGatewayBase implements SupportsCreatingPaymentMethodsInterface {

  /**
   * Logger channel factory interface.
   *
   * @var \Drupal\Core\Logger\LoggerChannel
   */
  protected LoggerChannel $mercadopagoLoggerChannel;

  /**
   * {@inheritdoc}
   */
  protected MercadoPagoApiService $mercadopagoApiService;
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->mercadopagoLoggerChannel = $container->get('logger.channel.mercadopago');
    $instance->mercadopagoApiService = $container->get('mercadopago.api');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // Fail-fast: sin remote_id no hay contrato remoto. Mejor fallar acá
    // (el checkout muestra error) que después del "éxito" en createPayment.
    if (empty($payment_details['remote_id'])) {
      throw new PaymentGatewayException('No se recibió el identificador del contrato recurrente (remote_id) de Mercado Pago.');
    }
    $payment_method->setRemoteId($payment_details['remote_id']);
    $payment_method->setReusable(TRUE);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment->getPaymentMethod();

    // OBTENER EL ID DEL CONTRATO DE MERCADO PAGO.
    // Este es el preapproval_id que se guardó en el pago inicial.
    if (!$preapproval_id = $payment_method->getRemoteId()) {
      throw new PaymentGatewayException('No se encontró el Contrato Recurrente (preapproval_id) en el método de pago.');
    }

    // REGISTRAR QUE EL PAGO HA SIDO DELEGADO.
    // Para las renovaciones, Mercado Pago es el que inicia el cobro.
    // El parámetro $capture decide cómo reflejar el cobro en Drupal:
    // - TRUE: el cobro ya fue efectuado por MP (preapproval) -> completed.
    // - FALSE: autorización pendiente de confirmación externa.
    try {
      // Fijar el identificador de reconciliación local (UUID de la orden).
      // Esto NO es un id de transacción de Mercado Pago; la firma real llega por webhook.
      $payment->setRemoteId($order->uuid());

      if ($capture) {
        $payment->setState('completed');
        $payment->setRemoteState('completed');
      }
      else {
        $payment->setState('authorization');
        $payment->setRemoteState('authorized');
      }

      // Registrar en el log de Drupal para seguimiento.
      $this->mercadopagoLoggerChannel->info(
        'Orden recurrente @order_id (Contrato @preapproval_id) delegada a Mercado Pago. @estado',
        [
          '@order_id' => $order->id(),
          '@preapproval_id' => $preapproval_id,
          '@estado' => $capture ? 'Cobro capturado' : 'Esperando confirmación por webhook',
        ]
      );
    }
    catch (\Exception $e) {
      // Si hay un error (ej. método de pago sin remote_id), lanzamos.
      // una excepción para que el cron sepa que la orden falló.
      throw new PaymentGatewayException("Error al preparar el pago recurrente: {$e->getMessage()}");
    }

    // GUARDAR Y RETORNAR.
    // La entidad de pago ya ha sido guardada por el servicio de pago de Commerce,
    // pero la confirmación del estado es clave.
    $payment->save();
    return $payment;
  }

  /**
   * Fija el identificador de reconciliación local del pago.
   *
   * NOTA: esto NO es un id de transacción de Mercado Pago. El remote_id del
   * payment se usa como clave local de conciliación (UUID de la orden) porque
   * MP inicia el cobro y el id real de la transacción llega por webhook.
   * No confundir con remote_id del payment method (preapproval_id).
   */
  private function setReconciliationReference(PaymentInterface $payment, OrderInterface $order): void {
    $payment->setRemoteId($order->uuid());
  }

  /**
   * {@inheritdoc}
   *
   * Cancela el contrato recurrente en Mercado Pago antes de permitir el
   * borrado local. Si la cancelación remota falla, se lanza una excepción
   * para que el formulario de borrado no elimine el método local (sino el cliente seguiría cobrado para siempre).
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $preapproval_id = $payment_method->getRemoteId();

    // Sin contrato remoto asociado no hay nada que cancelar en MP.
    if (!$preapproval_id) {
      return;
    }

    try {
      $this->mercadopagoApiService->cancelSubscription($preapproval_id);
    }
    catch (\Exception $e) {
      throw new PaymentGatewayException('No se pudo cancelar el contrato recurrente en Mercado Pago: ' . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   *
   * Define el formulario de configuración para la pasarela (Access Tokens).
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key'),
      '#description' => $this->t('Public key provided by Mercado Pago for your environment (production or sandbox).'),
      '#default_value' => $this->configuration['public_key'] ?? '',
      '#maxlength' => 255,
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['access_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access token'),
      '#description' => $this->t('Access token (private key) used to authenticate server-to-server requests.'),
      '#default_value' => $this->configuration['access_token'] ?? '',
      '#maxlength' => 255,
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['back_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL de retorno'),
      '#description' => $this->t('Url de retorno después de obtener el estado del pago.'),
      '#default_value' => $this->configuration['back_url'] ?? '',
      '#maxlength' => 255,
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['notification_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL de notificación (Webhook)'),
      '#description' => $this->t('URL para recibir las notificaciones de la pasarela de pago.'),
      '#default_value' => $this->configuration['notification_url'] ?? '',
      '#maxlength' => 255,
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook secret (X-Signature)'),
      '#description' => $this->t('Secreto de firma configurado en Mercado Pago (Webhooks > Signature secret). Si está definido, el webhook valida la firma X-Signature de forma obligatoria (fail-closed). Si se deja vacío, el webhook acepta notificaciones sin verificar (no recomendado en producción).'),
      '#default_value' => $this->configuration['webhook_secret'] ?? '',
      '#maxlength' => 255,
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['access_token'] = $values['access_token'];
    $this->configuration['public_key'] = $values['public_key'];
    $this->configuration['back_url'] = $values['back_url'];
    $this->configuration['notification_url'] = $values['notification_url'];
    $this->configuration['webhook_secret'] = $values['webhook_secret'] ?? '';
  }

}
