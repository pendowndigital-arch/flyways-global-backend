<?php

namespace Drupal\user_api\Exception;

use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * An OAuth2 error that carries an extra machine readable code.
 *
 * The token endpoint must answer with a spec-compliant "error" value, which
 * leaves no room to say *why* a grant was refused. This subclass keeps the
 * standard error value intact and adds an "error_code" payload key, so both
 * /oauth/token and /api/user/login can tell an unactivated account apart from
 * a wrong password.
 */
class AuthException extends OAuthServerException {

  /**
   * Builds an invalid_grant exception with an extra machine readable code.
   *
   * @param string $message
   *   The error description.
   * @param string $code
   *   The machine readable code, e.g. "account_not_activated".
   * @param int $status
   *   The HTTP status code.
   *
   * @return static
   *   The exception.
   */
  public static function create(string $message, string $code, int $status = 400): static {
    // OAuthServerException::__construct() is final, so the payload is enriched
    // after construction rather than through an overridden constructor.
    $exception = new static($message, 6, 'invalid_grant', $status);
    $exception->setPayload($exception->getPayload() + ['error_code' => $code]);

    return $exception;
  }

}
