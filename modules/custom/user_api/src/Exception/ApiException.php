<?php

namespace Drupal\user_api\Exception;

use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * A client-facing error that renders as a JSON error envelope.
 */
class ApiException extends \Exception {

  /**
   * Constructs an ApiException.
   *
   * @param string $message
   *   Human readable message, safe to show to the API consumer.
   * @param string $errorCode
   *   Machine readable code the client can branch on.
   * @param int $status
   *   HTTP status code.
   * @param array $details
   *   Optional per-field details, keyed by field name.
   */
  public function __construct(
    string $message,
    protected string $errorCode = 'error',
    protected int $status = 400,
    protected array $details = [],
  ) {
    parent::__construct($message);
  }

  /**
   * Builds an ApiException from an OAuth2 server exception.
   *
   * Prefers the "error_code" payload key added by
   * \Drupal\user_api\Exception\AuthException so that account state survives
   * the trip through the authorization server.
   *
   * @param \League\OAuth2\Server\Exception\OAuthServerException $exception
   *   The OAuth2 exception.
   *
   * @return static
   *   The API exception.
   */
  public static function fromOauthException(OAuthServerException $exception): static {
    $payload = $exception->getPayload();

    return new static(
      $payload['error_description'] ?? $exception->getMessage(),
      (string) ($payload['error_code'] ?? $payload['error'] ?? 'invalid_grant'),
      $exception->getHttpStatusCode()
    );
  }

  /**
   * Gets the machine readable error code.
   *
   * @return string
   *   The error code.
   */
  public function getErrorCode(): string {
    return $this->errorCode;
  }

  /**
   * Gets the HTTP status code.
   *
   * @return int
   *   The status code.
   */
  public function getStatus(): int {
    return $this->status;
  }

  /**
   * Gets the per-field error details.
   *
   * @return array
   *   The details.
   */
  public function getDetails(): array {
    return $this->details;
  }

  /**
   * Renders the exception as a JSON response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function toResponse(): JsonResponse {
    $error = [
      'code' => $this->errorCode,
      'message' => $this->getMessage(),
    ];
    if ($this->details) {
      $error['details'] = $this->details;
    }

    return new JsonResponse(['error' => $error], $this->status);
  }

}
