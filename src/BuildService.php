<?php

namespace Drupal\ga_server_side;

use Drupal\commerce\Context;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\PriceCalculatorInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\CurrentStoreInterface;
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
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   * @param \Drupal\commerce_order\PriceCalculatorInterface $price_calculator
   *   The price calculator.
   */
  public function __construct(
    AccountInterface $current_user,
    CurrentStoreInterface $current_store,
    PriceCalculatorInterface $price_calculator
  ) {
    $this->currentUser = $current_user;
    $this->currentStore = $current_store;
    $this->priceCalculator = $price_calculator;
  }

  /**
   * Builds the event params.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation
   *   The purchased entity.
   * @param string $quantity
   *   The quantity.
   *
   * @return array
   *   The event params.
   */
  public function buildEventParams(ProductVariationInterface $product_variation, string $quantity): array {
    if ($product_variation->getPrice() === NULL) {
      return [];
    }

    $price = $product_variation->getPrice()->getNumber();
    $calculated_price = $this->getCalculatedPrice($product_variation);
    $discount = Calculator::subtract($price, $calculated_price);

    $items = [
      [
        'item_id' => $product_variation->getSku(),
        'item_name' => $product_variation->getProduct()->getTitle(),
        'item_variant' => $product_variation->getTitle(),
        'quantity' => floatval($quantity),
        'price' => floatval($price),
        'discount' => floatval($discount),
      ],
    ];

    $discounted_price = Calculator::subtract($price, $discount);
    $value = Calculator::multiply($discounted_price, $quantity);

    return [
      'currency' => $product_variation->getPrice()->getCurrencyCode(),
      'value' => floatval($value),
      'items' => $items,
    ];
  }

  /**
   * Gets the calculated price.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation
   *   The product variation.
   *
   * @return string
   *   The calculated price.
   */
  public function getCalculatedPrice(ProductVariationInterface $product_variation): string {
    $context = new Context($this->currentUser, $this->currentStore->getStore());
    return $this->priceCalculator
      ->calculate($product_variation, '1', $context, ['promotion'])
      ->getCalculatedPrice()
      ->getNumber();
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
        $price = $purchased_entity->getPrice()->getNumber();
        $calculated_price = $this->getCalculatedPrice($purchased_entity);
        $discount = Calculator::subtract($price, $calculated_price);

        $order_items[] = [
          'item_id' => $purchased_entity->getSku(),
          'item_name' => $purchased_entity->getProduct()->getTitle(),
          'item_variant' => $purchased_entity->getTitle(),
          'quantity' => floatval($order_item->getQuantity()),
          'price' => floatval($order_item->getUnitPrice()->getNumber()),
          'discount' => floatval($discount),
        ];
      }
    }

    return $order_items;
  }

}
