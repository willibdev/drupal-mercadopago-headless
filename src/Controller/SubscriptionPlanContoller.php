<?php

namespace Drupal\mercadopago\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\mercadopago\Service\MercadoPagoApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creación de planes.
 *
 * Controlador para la creación de planes de Mercado Pago desde la interfaz
 * de administración.
 */
class SubscriptionPlanContoller extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Define el servicio de mercado pago.
   *
   * @var \Drupal\mercadopago\Service\MercadoPagoApiService
   */
  protected $mpApiService;

  /**
   * {@inheritdoc}
   */
  public function __construct(MercadoPagoApiService $mp_api_service,) {
    $this->mpApiService = $mp_api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mercadopago.api')
    );
  }

  /**
   * Ejecuta la lógica para crear el plan de suscripción en Mercado Pago.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $commerce_product_variation
   *   La variación del producto cargada automáticamente por el router.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Una redirección de vuelta a la lista de variaciones.
   */
  public function createPlan(ProductVariationInterface $commerce_product_variation) {

    $variation = $commerce_product_variation;

    // Validaciones básicas.
    if ($variation->get('billing_schedule')->isEmpty() || $variation->getPrice() === NULL) {
      $this->messenger()->addError($this->t('La variación no tiene un horario de facturación o precio definido.'));
      return $this->redirect('entity.commerce_product.edit_form', ['commerce_product' => $variation->getProductId()]);
    }

    try {
      // Obtener datos para MP.
      $billing_schedule = $variation->get(field_name: 'billing_schedule')->entity;
      $billing_configuration = $billing_schedule->getPlugin()->getConfiguration();
      $interval_type = ($billing_configuration["interval"]['unit'] === 'month') ? "months" : "years";

      // Llamada al servicio API para crear el plan.
      $mp_response = $this->mpApiService->createSuscriptionPlan($variation, [
        'frequency' => $billing_configuration["interval"]['number'],
        'frequency_type' => $interval_type,
      ]);

      $mp_plan_id = $mp_response->id ?? NULL;

      if ($mp_plan_id) {
        // Guardar el ID de MP en la Variación.
        $variation->set('field_preapproval_plan_id', $mp_plan_id);
        $variation->save();

        $this->messenger()->addStatus($this->t('Plan de Mercado Pago creado con éxito (ID: @id).', ['@id' => $mp_plan_id]));
      }
      else {
        $error_message = $mp_response['message'] ?? $this->t('Error de API desconocido.');
        $this->messenger()->addError($this->t('Error al crear el plan en MP: @msg', ['@msg' => $error_message]));
      }

    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Excepción: @message', ['@message' => $e->getMessage()]));
    }

    // Redirigir de vuelta al formulario de edición del producto.
    return $this->redirect('entity.commerce_product_variation.collection', ['commerce_product' => $variation->getProductId()]);
  }

}
