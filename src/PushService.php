<?php

namespace Drupal\ga_server_side;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
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
   * The config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The uuid service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuid;

  /**
   * Constructs a PushService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The uuid service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    UuidInterface $uuid,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->config = $config_factory->get('ga_server_side.settings');
    $this->uuid = $uuid;
    $this->logger = $logger_factory->get('ga_server_side');
  }

  /**
   * Gets the client ID.
   *
   * @return string
   *   The client ID.
   */
  protected function generateClientId(): string {
    // @codingStandardsIgnoreStart
    if (isset($_COOKIE[self::CLIENT_ID_KEY])) {
      $client_id = $_COOKIE[self::CLIENT_ID_KEY];
    }
    // @codingStandardsIgnoreEnd
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
    // @codingStandardsIgnoreStart
    if (!isset($_COOKIE['_ga'])) {
      return $this->generateClientId();
    }

    $cookie_value = $_COOKIE['_ga'];
    // @codingStandardsIgnoreEnd
    $parts = explode('.', $cookie_value);
    if (count($parts) < 4) {
      return $this->generateClientId();
    }

    return $parts[2] . '.' . $parts[3];
  }

  /**
   * Gets the microtime timestamp.
   *
   * @return string
   *   The microtime timestamp.
   */
  protected function getMicrotimeTimestamp(): string {
    $microtime = microtime(TRUE);
    return sprintf('%0.0f', $microtime * 1000000);
  }

  /**
   * Gets the session ID.
   *
   * @return string|null
   *   The session ID.
   */
  protected function getSessionId(): ?string {
    $measurement_id = $this->config->get('measurement_id');
    $stream_id = str_replace('G-', '', $measurement_id);
    $cookie_name = '_ga_' . $stream_id;

    // @codingStandardsIgnoreStart
    if (!isset($_COOKIE[$cookie_name])) {
      return NULL;
    }

    $cookie_value = $_COOKIE[$cookie_name];
    // @codingStandardsIgnoreEnd
    $pattern = "/GS1\.\d\.(\d+)\.\d+/";
    preg_match($pattern, $cookie_value, $matches);

    if (count($matches) > 1) {
      return $matches[1];
    }

    return NULL;
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
    $url .= '?measurement_id=' . $measurement_id . '&api_secret=' . $api_secret;

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
      'timestamp_micros' => $this->getMicrotimeTimestamp(),
      'events' => [
        'name' => $event_name,
        'params' => $event_params,
      ],
    ];

    $session_id = $this->getSessionId();
    if (!empty($session_id)) {
      $data['events']['params']['session_id'] = $session_id;
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
        $this->logger->debug('[@event_name] Response message: @response_message. Status code: @status_code.', [
          '@event_name' => $event_name,
          '@response_message' => $response->getBody()->getContents(),
          '@status_code' => $response->getStatusCode(),
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error while sending data to Google Analytics: @error.', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
