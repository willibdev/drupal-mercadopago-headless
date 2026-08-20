<?php

namespace Drupal\mercadopago\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mercadopago\Service\MercadoPagoApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ProcessSubscriptionController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   *
   * Provides an interface for entity type managers.
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\mercadopago\Service\MercadoPagoApiService
   *
   * Define el servicio de mercado pago.
   */
  protected $mpApiService;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MercadoPagoApiService $mp_api_service,
    AccountInterface $current_user,
  ) {
    $this->mpApiService = $mp_api_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('mercadopago.api'),
      $container->get('current_user')
    );
  }

  public function manageSubscription(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    $subscription_id = $data['subscription_id'] ?? NULL;
    $action = $data['action'] ?? NULL;

    if (!$subscription_id) {
      return new JsonResponse(['error' => 'No se proporciono un ID de suscripción.'], 400);
    }

    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = $this->entityTypeManager->getStorage('commerce_subscription')->load($subscription_id);
    $payment_method = $subscription->getPaymentMethod();
    $preapproval_id = $payment_method->getRemoteId();

    if ($action === 'cancel') {

      if ($subscription->getState()->value === 'active') {
        $mp_response = $this->mpApiService->cancelSubscription($preapproval_id);

        if ($mp_response->status === 'cancelled') {
          $subscription->cancel();
          $subscription->save();
          return new JsonResponse(['error' => FALSE, 'message' => 'Suscripción cancelada correctamente.']);
        }
      }
    }

    return new JsonResponse(['error' => 'No se pudo cancelar la suscripción.'], 500);
  }

}
