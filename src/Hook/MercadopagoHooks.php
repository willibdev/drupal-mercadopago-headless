<?php

namespace Drupal\mercadopago\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Implements plugin hooks for Mercadopago module.
 */
class MercadopagoHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_entity_operation_alter().
   *
   * Agrega la acción 'Crear Plan de MP' al listado de operaciones
   *   de la entidad.
   */
  #[Hook('entity_operation_alter')]
  public function entityOperationAlter(array &$operations, EntityInterface $entity): void {

    // 1. Verificar que estamos en la entidad correcta (Product Variation).
    if ($entity->getEntityTypeId() !== 'commerce_product_variation') {
      return;
    }
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $entity */
    // Verificar que la variación tenga un monto mínimo de cobro.
    $price = (float) $entity->getPrice()->getNumber();
    if ($price == 0) {
      return;
    }

    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = $entity;

    $operations['mercadopago_create_plan_single'] = [
      'title' => $this->t('Crear Plan de Suscripción en Mercado Pago'),
      'url' => Url::fromRoute(
        'mercadopago.create_subscription_plan',
        [
          'commerce_product_variation' => $variation->id(),
        ]
      ),
      'weight' => 100,
    ];
  }

}
