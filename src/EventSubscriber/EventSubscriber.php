<?php

namespace Drupal\ga_server_side\EventSubscriber;

use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\CartOrderItemAddEvent;
use Drupal\commerce_cart\Event\CartOrderItemRemoveEvent;
use Drupal\commerce_cart\Event\CartOrderItemUpdateEvent;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\ga_server_side\BuildService;
use Drupal\ga_server_side\PushService;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines the EventSubscriber class.
 */
class EventSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The build service.
   *
   * @var \Drupal\ga_server_side\BuildService
   */
  protected BuildService $buildService;

  /**
   * The push service.
   *
   * @var \Drupal\ga_server_side\PushService
   */
  protected PushService $pushService;

  /**
   * Constructs a new EventSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\ga_server_side\BuildService $build_service
   *   The build service.
   * @param \Drupal\ga_server_side\PushService $push_service
   *   The push service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    RequestStack $request_stack,
    BuildService $build_service,
    PushService $push_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack;
    $this->buildService = $build_service;
    $this->pushService = $push_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ProductEvents::PRODUCT_VARIATION_AJAX_CHANGE => ['handleProductVariationChange'],
      CartEvents::CART_ORDER_ITEM_ADD => ['handleOrderItemAdd'],
      CartEvents::CART_ORDER_ITEM_REMOVE => ['handleOrderItemRemove'],
      CartEvents::CART_ORDER_ITEM_UPDATE => ['handleOrderItemUpdate'],
      'commerce_checkout.init' => ['handleBeginCheckout'],
      'commerce_order.place.post_transition' => ['handleOrderPlace'],
    ];
  }

  /**
   * Handles the view item event on variation change.
   *
   * @param \Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent $ajax_change_event
   *   The product variation ajax change event.
   */
  public function handleProductVariationChange(ProductVariationAjaxChangeEvent $ajax_change_event): void {
    $product_variation = $ajax_change_event->getProductVariation();

    if ($product_variation instanceof ProductVariationInterface) {
      $event_params = $this->buildService->buildProductVariationEventParams($product_variation, '1');
      if (!empty($event_params)) {
        $this->pushService->push('view_item', $event_params);
      }
    }
  }

  /**
   * Handles the order item add event.
   *
   * @param \Drupal\commerce_cart\Event\CartOrderItemAddEvent $event
   *   The order item add event.
   */
  public function handleOrderItemAdd(CartOrderItemAddEvent $event): void {
    $order_item = $event->getOrderItem();
    $purchased_entity = $order_item->getPurchasedEntity();

    if ($purchased_entity instanceof ProductVariationInterface) {
      $event_params = $this->buildService->buildProductVariationEventParams($purchased_entity, strval($event->getQuantity()));
      if (!empty($event_params)) {
        $this->pushService->push('add_to_cart', $event_params);
      }
    }
  }

  /**
   * Handles the order item remove event.
   *
   * @param \Drupal\commerce_cart\Event\CartOrderItemRemoveEvent $event
   *   The order item remove event.
   */
  public function handleOrderItemRemove(CartOrderItemRemoveEvent $event): void {
    $order_item = $event->getOrderItem();
    $purchased_entity = $order_item->getPurchasedEntity();

    if ($purchased_entity instanceof ProductVariationInterface) {
      $event_params = $this->buildService->buildProductVariationEventParams($purchased_entity, $order_item->getQuantity());
      if (!empty($event_params)) {
        $this->pushService->push('remove_from_cart', $event_params);
      }
    }
  }

  /**
   * Handles the order item update event.
   *
   * @param \Drupal\commerce_cart\Event\CartOrderItemUpdateEvent $event
   *   The order item update event.
   */
  public function handleOrderItemUpdate(CartOrderItemUpdateEvent $event): void {
    $order_item = $event->getOrderItem();
    $order_item_quantity = $order_item->getQuantity();

    $original_order_item = $event->getOriginalOrderItem();
    $original_order_item_quantity = $original_order_item->getQuantity();

    $quantity = Calculator::subtract($order_item_quantity, $original_order_item_quantity);
    $event_name = 'add_to_cart';

    if (Calculator::compare($quantity, '0') === -1) {
      $quantity = Calculator::multiply($quantity, '-1');
      $event_name = 'remove_from_cart';
    }

    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity instanceof ProductVariationInterface) {
      $event_params = $this->buildService->buildProductVariationEventParams($purchased_entity, $quantity);
      if (!empty($event_params)) {
        $this->pushService->push($event_name, $event_params);
      }
    }
  }

  /**
   * Handles the begin checkout event.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function handleBeginCheckout(OrderEvent $event): void {
    $order = $event->getOrder();
    $event_params = $this->buildService->buildOrderEventParams($order);
    $this->pushService->push('begin_checkout', $event_params);
  }

  /**
   * Handles the order place post transition event.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The order place post transition event.
   */
  public function handleOrderPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $event_params = $this->buildService->buildOrderEventParams($order, TRUE, TRUE);
    $this->pushService->push('purchase', $event_params);
  }

}
