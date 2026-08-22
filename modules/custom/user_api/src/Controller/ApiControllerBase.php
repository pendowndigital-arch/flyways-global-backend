<?php

namespace Drupal\user_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\user_api\Exception\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared JSON request and response handling for the user API.
 */
abstract class ApiControllerBase extends ControllerBase {

  /**
   * Runs a handler, turning ApiException into a JSON error response.
   *
   * @param callable $handler
   *   The handler returning a JsonResponse.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  protected function handle(callable $handler): JsonResponse {
    try {
      return $handler();
    }
    catch (ApiException $exception) {
      return $exception->toResponse();
    }
  }

  /**
   * Decodes a JSON request body.
   *
   * Form-encoded bodies are accepted too, which keeps the endpoints usable
   * from a plain HTML form or a curl -d call.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The decoded payload.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the body is present but not valid JSON.
   */
  protected function decodeBody(Request $request): array {
    if ($request->request->count()) {
      return $request->request->all();
    }

    $content = trim((string) $request->getContent());
    if ($content === '') {
      return [];
    }

    try {
      $data = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new ApiException('The request body is not valid JSON.', 'invalid_json', 400);
    }

    if (!is_array($data)) {
      throw new ApiException('The request body must be a JSON object.', 'invalid_json', 400);
    }

    return $data;
  }

  /**
   * Reads a trimmed string value, accepting a list of alternative keys.
   *
   * @param array $data
   *   The decoded payload.
   * @param string ...$keys
   *   Candidate keys, in order of preference.
   *
   * @return string
   *   The first non-empty value found, or an empty string.
   */
  protected function readString(array $data, string ...$keys): string {
    foreach ($keys as $key) {
      if (isset($data[$key]) && is_scalar($data[$key])) {
        $value = trim((string) $data[$key]);
        if ($value !== '') {
          return $value;
        }
      }
    }

    return '';
  }

  /**
   * Builds a success response.
   *
   * @param string $message
   *   A human readable message.
   * @param array $data
   *   The response payload.
   * @param int $status
   *   The HTTP status code.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  protected function success(string $message, array $data = [], int $status = 200): JsonResponse {
    return new JsonResponse([
      'message' => $message,
      // Cast so that an empty payload encodes as {} rather than [], keeping
      // "data" an object for every response on every endpoint.
      'data' => $data === [] ? new \stdClass() : $data,
    ], $status);
  }

  /**
   * Converts entity validation violations into an ApiException.
   *
   * @param \Drupal\Core\Entity\EntityConstraintViolationListInterface $violations
   *   The violation list.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the list is not empty.
   */
  protected function assertNoViolations(EntityConstraintViolationListInterface $violations): void {
    if (!$violations->count()) {
      return;
    }

    $details = [];
    foreach ($violations as $violation) {
      // Property paths look like "mail.0.value"; the field name is enough for
      // a client to attach the message to the right input.
      $field = explode('.', $violation->getPropertyPath())[0] ?: 'entity';
      $details[$field][] = (string) $violation->getMessage();
    }

    throw new ApiException('The submitted values are not valid.', 'validation_failed', 422, $details);
  }

}
