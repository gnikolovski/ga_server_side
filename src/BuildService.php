<?php

namespace Drupal\ga_server_side;

use Drupal\commerce\Context;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\PriceCalculatorInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the BuildService class.
 */
class BuildService {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private AccountInterface $currentUser;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The current store.
   *
   * @var \Drupal\commerce_store\CurrentStoreInterface
   */
  protected CurrentStoreInterface $currentStore;

  /**
   * The price calculator.
   *
   * @var \Drupal\commerce_order\PriceCalculatorInterface
   */
  protected PriceCalculatorInterface $priceCalculator;

  /**
   * Constructs a new BuildService object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   * @param \Drupal\commerce_order\PriceCalculatorInterface $price_calculator
   *   The price calculator.
   */
  public function __construct(
    AccountInterface $current_user,
    ModuleHandlerInterface $module_handler,
    CurrentStoreInterface $current_store,
    PriceCalculatorInterface $price_calculator
  ) {
    $this->currentUser = $current_user;
    $this->moduleHandler = $module_handler;
    $this->currentStore = $current_store;
    $this->priceCalculator = $price_calculator;
  }

  /**
   * Builds the product variation event params.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation
   *   The purchased entity.
   * @param string $quantity
   *   The quantity.
   *
   * @return array
   *   The event params.
   */
  public function buildProductVariationEventParams(ProductVariationInterface $product_variation, string $quantity): array {
    if ($product_variation->getPrice() === NULL) {
      return [];
    }

    $price = $product_variation->getPrice()->getNumber();
    $calculated_price = $this->getCalculatedPrice($product_variation);
    $discount = Calculator::subtract($price, $calculated_price);

    $discounted_price = Calculator::subtract($price, $discount);
    $value = Calculator::multiply($discounted_price, $quantity);

    return [
      'currency' => $product_variation->getPrice()->getCurrencyCode(),
      'value' => (float) $value,
      'items' => [$this->buildItems($product_variation, $quantity)],
    ];
  }

  /**
   * Builds the order event params.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param bool $include_transaction_id
   *   Flag that indicates whether we should include order ID in params.
   * @param bool $include_shipping_value
   *   Flag that indicates whether we should include shipping value in params.
   *
   * @return array
   *   The event params.
   */
  public function buildOrderEventParams(OrderInterface $order, bool $include_transaction_id = FALSE, bool $include_shipping_value = FALSE): array {
    $event_params = [
      'currency' => $order->getTotalPrice()->getCurrencyCode(),
      'value' => (float) $order->getSubtotalPrice()->getNumber(),
      'items' => $this->getOrderItems($order),
    ];

    if ($include_transaction_id === TRUE) {
      $event_params['transaction_id'] = $order->getOrderNumber();
    }

    if (
      $include_shipping_value === TRUE &&
      $this->moduleHandler->moduleExists('commerce_shipping') &&
      $order->hasField('shipments') &&
      $order->get('shipments')->isEmpty() === FALSE
    ) {
      $shipping_cost = '0';

      foreach ($order->get('shipments')->referencedEntities() as $shipment) {
        /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
        $shipment_amount = $shipment->getAmount()->getNumber();
        $shipping_cost = Calculator::add($shipping_cost, $shipment_amount);
      }

      $event_params['shipping'] = (float) $shipping_cost;
    }

    return $event_params;
  }

  /**
   * Gets the order items.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The order items.
   */
  public function getOrderItems(OrderInterface $order): array {
    $order_items = [];

    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();

      if ($purchased_entity instanceof ProductVariationInterface) {
        $order_items[] = $this->buildItems($purchased_entity, $order_item->getQuantity());
      }
    }

    return $order_items;
  }

  /**
   * Builds the items.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation
   *   The product variation.
   * @param string $quantity
   *   The quantity.
   *
   * @return array
   *   The items.
   */
  protected function buildItems(ProductVariationInterface $product_variation, string $quantity): array {
    $price = $product_variation->getPrice()->getNumber();
    $calculated_price = $this->getCalculatedPrice($product_variation);
    $discount = Calculator::subtract($price, $calculated_price);

    return [
      'item_id' => $product_variation->getSku(),
      'item_name' => $product_variation->getProduct()->getTitle(),
      'discount' => (float) $discount,
      'item_variant' => $product_variation->getTitle(),
      'price' => (float) $price,
      'quantity' => (float) $quantity,
    ];
  }

  /**
   * Gets the calculated price.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation
   *   The product variation.
   *
   * @return string
   *   The calculated price including promotion adjustment.
   */
  public function getCalculatedPrice(ProductVariationInterface $product_variation): string {
    $context = new Context($this->currentUser, $this->currentStore->getStore());
    return $this->priceCalculator
      ->calculate($product_variation, '1', $context, ['promotion'])
      ->getCalculatedPrice()
      ->getNumber();
  }

}
