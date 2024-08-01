<?php

namespace Drupal\ga_server_side;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Http\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\Client;

/**
 * Defines the PushService class.
 */
class PushService {

  const GA_BASE_URL = 'https://www.google-analytics.com';

  const GA_DEBUG_ENDPOINT = '/debug/mp/collect';

  const GA_PRODUCTION_ENDPOINT = '/mp/collect';

  const CLIENT_ID_KEY = 'ga4_client_id';

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private AccountInterface $currentUser;

  /**
   * The config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The uuid service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuid;

  /**
   * The request stack.
   *
   * @var \Drupal\Core\Http\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a PushService object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The uuid service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Http\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
    UuidInterface $uuid,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack
  ) {
    $this->currentUser = $current_user;
    $this->config = $config_factory->get('ga_server_side.settings');
    $this->uuid = $uuid;
    $this->loggerFactory = $logger_factory;
    $this->requestStack = $request_stack;
  }

  /**
   * Gets or generates the client ID.
   *
   * @return string
   *   The client ID.
   */
  protected function generateClientId(): string {
    $request = $this->requestStack->getCurrentRequest();

    if ($request->cookies->has(self::CLIENT_ID_KEY)) {
      $client_id = $request->cookies->get(self::CLIENT_ID_KEY);
    }
    elseif (isset($_SESSION[self::CLIENT_ID_KEY])) {
      $client_id = $_SESSION[self::CLIENT_ID_KEY];
    }
    else {
      // No client_id found in cookie or session so we need to create it.
      $client_id = $this->uuid->generate();
      // Store client_id in both cookie and a session that expires in 1 year.
      setcookie(self::CLIENT_ID_KEY, $client_id, time() + 60 * 60 * 24 * 365, '/');
      $_SESSION[self::CLIENT_ID_KEY] = $client_id;
    }

    return $client_id;
  }

  /**
   * Gets the client ID.
   *
   * @return string
   *   The client ID.
   */
  protected function getClientId(): string {
    $request = $this->requestStack->getCurrentRequest();

    if ($request->cookies->has('_ga') === FALSE) {
      $client_id = $this->generateClientId();
      if ($this->config->get('log_client_and_session_id') === TRUE) {
        $this->loggerFactory->get('ga_server_side:ga_cookie')->debug('Cookie _ga not found. Falling back to a randomly generated client ID: @client_id', [
          '@client_id' => $client_id,
        ]);
      }
      return $client_id;
    }

    $cookie_value = $request->cookies->get('_ga');
    $parts = explode('.', $cookie_value);
    if (count($parts) < 4) {
      $client_id = $this->generateClientId();
      if ($this->config->get('log_client_and_session_id') === TRUE) {
        $this->loggerFactory->get('ga_server_side:ga_cookie')->debug('Cookie _ga found, but it is malformed. Falling back to a randomly generated client ID: @client_id', [
          '@client_id' => $client_id,
        ]);
      }
      return $client_id;
    }

    $client_id = $parts[2] . '.' . $parts[3];
    if ($this->config->get('log_client_and_session_id') === TRUE) {
      $this->loggerFactory->get('ga_server_side:ga_cookie')->debug('Cookie _ga found, client ID: @client_id', [
        '@client_id' => $client_id,
      ]);
    }

    return $client_id;
  }

  /**
   * Builds the endpoint URL.
   *
   * @return string
   *   The endpoint URL.
   */
  protected function buildEndpointUrl(): string {
    if ($this->config->get('debug_mode') === TRUE) {
      $url = self::GA_DEBUG_ENDPOINT;
    }
    else {
      $url = self::GA_PRODUCTION_ENDPOINT;
    }

    $measurement_id = $this->config->get('measurement_id');
    $api_secret = $this->config->get('api_secret');
    $url .= '?api_secret=' . $api_secret . '&measurement_id=' . $measurement_id;
    return $url;
  }

  /**
   * Pushes event data to Google Analytics.
   *
   * @param string $event_name
   *   The event name.
   * @param array $event_params
   *   The event data.
   */
  public function push(string $event_name, array $event_params): void {
    $client = new Client([
      'base_uri' => self::GA_BASE_URL,
      'timeout' => 5,
    ]);

    $data = [
      'client_id' => $this->getClientId(),
      'events' => [
        [
          'name' => $event_name,
          'params' => $event_params,
        ],
      ],
    ];

    if (
      $this->config->get('send_user_id') === TRUE &&
      $this->currentUser->isAuthenticated()
    ) {
      $data['user_id'] = $this->currentUser->id();
    }

    try {
      $url = $this->buildEndpointUrl();
      $response = $client->post($url, [
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'body' => json_encode($data),
      ]);

      if ($this->config->get('log_events') === TRUE) {
        $response_message = $response->getBody()->getContents();

        $this->loggerFactory->get('ga_server_side:event_response')->debug('[@event_name] Response message: @response_message. Status code: @status_code. Event params: @event_params', [
          '@event_name' => $event_name,
          '@response_message' => !empty($response_message) ? $response_message : '-',
          '@status_code' => $response->getStatusCode(),
          '@event_params' => json_encode($event_params),
        ]);
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ga_server_side:event_response')->error('Error while sending data to Google Analytics: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
