<?php

namespace Drupal\user_management_api\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\user_management_api\Exception\ApiException;

/**
 * Service to format and return standard REST API responses.
 */
class ResponseManager {

  /**
   * Return a standard successful JSON response.
   *
   * @param array $data
   *   The output payload.
   * @param string $message
   *   Optional success message.
   * @param int $status
   *   HTTP status code.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function successResponse(array $data = [], string $message = 'Success', int $status = 200): JsonResponse {
    return new JsonResponse([
      'status' => 'success',
      'message' => $message,
      'data' => $data,
    ], $status);
  }

  /**
   * Return a standard error JSON response.
   *
   * @param string $message
   *   Error message.
   * @param int $status
   *   HTTP status code.
   * @param array $errors
   *   A list of nested, validation, or validation-specific errors.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function errorResponse(string $message = 'Error', int $status = 400, array $errors = []): JsonResponse {
    $response = [
      'status' => 'error',
      'message' => $message,
    ];

    if (!empty($errors)) {
      $response['errors'] = $errors;
    }

    return new JsonResponse($response, $status);
  }

  /**
   * Helper to format an ApiException.
   *
   * @param \Drupal\user_management_api\Exception\ApiException $exception
   *   The caught API exception.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function exceptionResponse(ApiException $exception): JsonResponse {
    return $this->errorResponse(
      $exception->getMessage(),
      $exception->getStatusCode(),
      $exception->getErrors()
    );
  }

}
