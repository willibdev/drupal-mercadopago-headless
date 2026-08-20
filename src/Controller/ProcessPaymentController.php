<?php

namespace Drupal\mercadopago\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderTotalSummary;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mercadopago\Service\MercadoPagoApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ProcessPaymentController extends ControllerBase implements ContainerInjectionInterface {

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
   * @var \Drupal\commerce_order\OrderTotalSummary
   *
   * Servicio para recalcular la orden.
   */
  protected $orderTotalSummary;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MercadoPagoApiService $mp_api_service,
    AccountInterface $current_user,
    OrderTotalSummary $order_total_summary,
  ) {
    $this->mpApiService = $mp_api_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->orderTotalSummary = $order_total_summary;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('mercadopago.api'),
      $container->get('current_user'),
      $container->get('commerce_order.order_total_summary'),
    );
  }

  public function activateSubscription(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    $card_token_id = $data['token'] ?? NULL;
    $payer_email = $data['payer']['email'] ?? NULL;
    $variation_id = $data['variation_id'] ?? NULL;

    if (!$variation_id || !$card_token_id || !$payer_email) {
      return new JsonResponse(['error' => 'Datos insuficientes para la suscripción.'], 400);
    }

    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variation_id);
    $userId = $this->currentUser->id();
    $price = $variation->getPrice();

    // Obtener el ID del plan de Mercado Pago.
    // (guardado previamente en el campo de la variación)
    $mp_plan_id = $variation->get('field_preapproval_plan_id')->value;

    // Asumimos que la pasarela de MP ya está cargada.
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load('mercadopago_headless');

    // Crear orden con variación inicial para la suscripción.
    $order = $this->createOrder($data, $request);

    // createOrder() puede devolver un JsonResponse en su path de error
    // (ej. variación no encontrada). En ese caso se devuelve la respuesta
    // tal cual, sin intentar operar sobre un objeto que no es OrderInterface.
    if (!($order instanceof OrderInterface)) {
      return $order;
    }

    // Llamar al servicio de mercado pago para crear la suscripción preaprobada.
    try {
      $mp_response = $this->mpApiService->createPreapprovalSubscription(
        $mp_plan_id,
        $variation->getTitle(),
        $card_token_id,
        $payer_email,
        $order->uuid()
      );

      if ($mp_response->id && ($mp_response->status !== 'cancelled' && $mp_response->status !== 'expired')) {
        // Crear el Payment Method (Simula la tokenización)
        $payment_method = $this->entityTypeManager->getStorage('commerce_payment_method')->create([
          'type' => 'credit_card',
          'payment_gateway' => $payment_gateway->id(),
          'payment_gateway_mode' => $payment_gateway->getPlugin()->getMode(),
          'uid' => $userId,
          'billing_profile' => $order->getBillingProfile(),
          'remote_id' => $mp_response->id,
        ]);
        $payment_method->save();

        // Crear la Entidad Payment (Simula el cobro inicial)
        $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
          'payment_gateway' => $payment_gateway->id(),
          'payment_method' => $payment_method->id(),
          'order_id' => $order->id(),
          'amount' => [
            'number' => $price->getNumber(),
            'currency_code' => $price->getCurrencyCode(),
          ],
          'state' => 'authorization',
          'remote_id' => $mp_response->id,
          'remote_state' => $mp_response->status,
        ]);
        $payment->save();

        // Vinculación el metodo de pago a la orden.
        $order->set('payment_method', $payment_method->id());

        // Finalizar la orden para que commerce_recurring la procese.
        $order->set('state', 'draft');
        $order->save();

        return new JsonResponse($mp_response);
      }
    }
    catch (\Throwable $th) {
      return new JsonResponse([
        'error' => TRUE,
        'message' => $th->getMessage(),
        'data' => [
          'variation' => $variation->getTitle(),
        ],
      ], Response::HTTP_BAD_REQUEST);
    }

    return new JsonResponse(['error' => 'Error de Mercado Pago.'], 500);
  }

  /**
   * Crea la orden en Drupal.
   *
   * @param mixed $data
   *   Información de la orden, incluyendo variación y perfil de facturación.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La solicitud HTTP que contiene la información de la orden.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|OrderInterface
   *   Devuelve la orden creada o un JsonResponse con un error si no se puede
   *   crear la orden.
   */
  private function createOrder($data, Request $request): JsonResponse|OrderInterface {
    // Crear variaciones e items de orden.
    if ($data['variation_id']) {
      $variation_id = $data['variation_id'];
      $userId = $this->currentUser->id();
      /** @var \Drupal\commerce_order\OrderStorageInterface $orderStorage */
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      // Validar si existe una Orden ya creada por el usuario con la variación
      // enviada en data, para retornarla, de lo contrario se creará una nueva.
      /** @var \Drupal\commerce_order\Entity\OrderInterface[] $orders */
      $orders = $orderStorage->loadByProperties([
        'uid' => $userId,
        'state' => 'draft',
      ]);

      $newOrder = NULL;

      // Validar si el usuario ya ha creado una orden con la misma variación
      // Sin haber terminado el proceso.
      if ($orders) {
        foreach ($orders as $order) {
          foreach ($order->getItems() as $order_item) {
            if ($order_item->getPurchasedEntity()->id() == $variation_id) {
              $newOrder = $order;
              break;
            }
          }
        }
      }

      // Creación de orden base.
      if (!$newOrder) {
        /** @var \Drupal\commerce_store\StoreStorage $storeStorage */
        $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
        /** @var \Drupal\commerce_store\Entity\StoreInterface $defaultStore */
        $defaultStore = $storeStorage->loadDefault();
        /** @var \Drupal\commerce_order\OrderItemStorage $orderItemStorage */
        $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
        /** @var \Drupal\commerce_order\Entity\OrderInterface $newOrder */
        $newOrder = $orderStorage->create([
          'type' => 'default',
          'store_id' => $defaultStore->id(),
          'mail' => $data['payer']['email'] ?? '',
          'uid' => $userId,
          'ip_address' => $request->getClientIp(),
          'state' => 'draft',
        ]);

        // Cargar la entidad comprable, la variación.
        /** @var \Drupal\commerce_product\ProductVariationStorage $variationStorage */
        $variationStorage = $this->entityTypeManager()->getStorage('commerce_product_variation');
        /** @var \Drupal\commerce_product\Entity\ProductVariationInterface */
        $variation = $variationStorage->load($variation_id);

        if (!$variation) {
          return new JsonResponse(['error' => 'Variación (purchased_entity) no encontrada'], 404);
        }

        // Crear el Order Item a partir de la entidad comprable.
        /** @var \Drupal\commerce_order\OrderItemStorage $orderItemStorage */
        $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
        /** @var \Drupal\commerce_order\Entity\OrderItemInterface $item */
        $item = $orderItemStorage->createFromPurchasableEntity($variation);
        // Añadir el Order Item a la Orden.
        $newOrder->addItem($item);
      }
    }

    // Agergar perfiles de dirección.
    if ($data['billing_profile']) {
      $this->addBillingProfile($newOrder, $data['billing_profile']);
    }

    // Reconstruir los totales de la orden.
    $newOrder->recalculateTotalPrice();

    // Crear orden.
    $newOrder->save();

    return $newOrder;
  }

  /**
   * Crear perfil de dirección.
   */
  private function addBillingProfile(OrderInterface $order, $billing_data) {
    $uid = (int) $order->getCustomer()->id();

    // INTENTAR BUSCAR UN PERFIL EXISTENTE.
    /** @var \Drupal\profile\ProfileStorageInterface $profileStorage */
    $profileStorage = $this->entityTypeManager->getStorage('profile');
    /** @var \Drupal\profile\Entity\ProfileInterface[] $profiles */
    $profiles = $profileStorage->loadByProperties([
      'uid' => $uid,
      'type' => 'customer',
    ]);

    /** @var \Drupal\profile\Entity\ProfileInterface $existing_profile */
    $existing_profile = NULL;

    // Recorrer los perfiles del usuario para encontrar una coincidencia.
    // de dirección exacta.
    foreach ($profiles as $profile) {
      $existing_address = $profile->get('field_direccion')->value;

      if ($billing_data['address'] === $existing_address) {
        $existing_profile = $profile;
      }
    }

    // CREAR O REUTILIZAR.
    if ($existing_profile) {
      // PERFIL ENCONTRADO: Reutilizar.
      $profile_to_use = $existing_profile;
    }
    else {
      $taxonomyTermStorage = $this->entityTypeManager->getStorage('taxonomy_term');
      $query = $taxonomyTermStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('vid', 'countries')
        ->condition('name', $billing_data['country'] ?? 'Colombia');
      $countries = $query->execute();
      $firstCountry = \count($countries) === 1 ? reset($countries) : NULL;

      // PERFIL NO ENCONTRADO: Crear uno nuevo.
      /** @var \Drupal\profile\Entity\ProfileInterface $profile_to_use */
      $profile_to_use = $profileStorage->create([
        'uid' => 0,
        'type' => 'customer',
        'is_default' => TRUE,
        'field_nombre_razon_social' => $billing_data['fullName'],
        'field_tipo_de_documento' => \strtolower($billing_data['documentType']),
        'field_numero_de_documento' => $billing_data['documentNumber'],
        'field_correo_electronico' => $billing_data['email'],
        'field_telefono' => $billing_data['phone'],
        'field_pais' => $firstCountry,
        'field_ciudad' => $billing_data['city'],
        'field_departamento' => $billing_data['departamento'],
        'field_direccion' => $billing_data['address'],
      ]);

      // Guardar el nuevo perfil.
      $profile_to_use->save();
    }

    // Asignar el perfil a la orden.
    $order->setBillingProfile($profile_to_use);
  }

}
