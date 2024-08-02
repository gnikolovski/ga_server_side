<?php

namespace Drupal\ga_server_side\Controller;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ga_server_side\BuildService;
use Drupal\ga_server_side\PushService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines the GaServerSideController class.
 */
class GaServerSideController extends ControllerBase {

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->buildService = $container->get('ga_server_side.build_service');
    $instance->pushService = $container->get('ga_server_side.push_service');
    return $instance;
  }

  /**
   * Responds to the "view_item" event sent from JavaScript.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function viewItem(Request $request): JsonResponse {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    $product_variation_id = $data['product_variation_id'] ?? NULL;
    if (is_numeric($product_variation_id) === FALSE) {
      $message = $this->t('The request was not sent to Google Analytics because the product_variation_id parameter is not numeric.');
      $this->getLogger('ga_server_side:event_response')->error($message);
      return new JsonResponse(['message' => $message], 400);
    }

    $product_variation = $this->entityTypeManager()
      ->getStorage('commerce_product_variation')
      ->load($product_variation_id);
    if (!$product_variation instanceof ProductVariationInterface) {
      $message = $this->t('The request was not sent to Google Analytics because the product variation cannot be found.');
      $this->getLogger('ga_server_side:event_response')->error($message);
      return new JsonResponse(['message' => $message], 400);
    }

    $event_params = $this->buildService->buildProductVariationEventParams($product_variation, '1');
    if (!empty($event_params)) {
      $this->pushService->push('view_item', $event_params);
    }

    return new JsonResponse(['message' => 'The request has been sent to Google Analytics.'], 200);
  }

}
