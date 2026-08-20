<?php

namespace Drupal\user_management_api\Exception;

/**
 * Custom exception class for the User Management API.
 */
class ApiException extends \Exception {

  /**
   * The HTTP status code.
   *
   * @var int
   */
  protected $statusCode;

  /**
   * Detailed error list (e.g., validation errors).
   *
   * @var array
   */
  protected $errors;

  /**
   * ApiException constructor.
   *
   * @param string $message
   *   The exception message.
   * @param int $statusCode
   *   The HTTP status code (defaults to 400).
   * @param array $errors
   *   An array of detailed validation or contextual errors.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(string $message = "", int $statusCode = 400, array $errors = [], ?\Throwable $previous = NULL) {
    parent::__construct($message, $statusCode, $previous);
    $this->statusCode = $statusCode;
    $this->errors = $errors;
  }

  /**
   * Gets the HTTP status code.
   *
   * @return int
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

  /**
   * Gets the detailed errors.
   *
   * @return array
   */
  public function getErrors(): array {
    return $this->errors;
  }

}
