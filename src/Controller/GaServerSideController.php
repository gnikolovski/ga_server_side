<?php

namespace Drupal\ga_server_side\Controller;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->buildService = $container->get('ga_server_side.build_service');
    $instance->pushService = $container->get('ga_server_side.push_service');
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * Logs a validation error to the custom database table.
   *
   * @param string $event_name
   *   The event name.
   * @param string $error_message
   *   The error message.
   */
  protected function logValidationError(string $event_name, string $error_message): void {
    try {
      $this->database->insert('ga_server_side_event_log')
        ->fields([
          'event_name' => $event_name,
          'event_data' => NULL,
          'client_id' => NULL,
          'session_id' => NULL,
          'user_id' => $this->currentUser()->isAuthenticated() ? $this->currentUser()->id() : 0,
          'http_status_code' => NULL,
          'response_body' => NULL,
          'error_message' => $error_message,
          'log_type' => 'validation_error',
          'timestamp' => time(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      // Silently fail to avoid breaking the main functionality.
    }
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
      $this->logValidationError('view_item', (string) $message);
      return new JsonResponse(['message' => $message], 400);
    }

    $product_variation = $this->entityTypeManager()
      ->getStorage('commerce_product_variation')
      ->load($product_variation_id);
    if (!$product_variation instanceof ProductVariationInterface) {
      $message = $this->t('The request was not sent to Google Analytics because the product variation cannot be found.');
      $this->logValidationError('view_item', (string) $message);
      return new JsonResponse(['message' => $message], 400);
    }

    $event_params = $this->buildService->buildProductVariationEventParams($product_variation, '1');
    if (!empty($event_params)) {
      $this->pushService->push('view_item', $event_params);
    }

    return new JsonResponse(['message' => 'The request has been sent to Google Analytics.'], 200);
  }

}
