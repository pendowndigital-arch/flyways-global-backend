<?php

namespace Drupal\user_api\Controller;

use Drupal\Core\Flood\FloodInterface;
use Drupal\user_api\Exception\ApiException;
use Drupal\user_api\Service\PasswordReset;
use Drupal\user_api\Service\TokenRevoker;
use Drupal\user_api\Service\UserNormalizer;
use Drupal\user_api\TokenExpiryTriggerHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Forgot-password endpoints: request and redeem a reset token.
 */
class PasswordController extends ApiControllerBase {

  /**
   * Flood event name for reset requests.
   */
  const FLOOD_RESET = 'user_api.password_reset';

  public function __construct(
    protected PasswordReset $passwordReset,
    protected TokenRevoker $tokenRevoker,
    protected UserNormalizer $userNormalizer,
    protected FloodInterface $flood,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_api.password_reset'),
      $container->get('user_api.token_revoker'),
      $container->get('user_api.user_normalizer'),
      $container->get('flood')
    );
  }

  /**
   * Requests a password reset email.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function forgot(Request $request): JsonResponse {
    return $this->handle(function () use ($request) {
      $data = $this->decodeBody($request);
      $mail = $this->readString($data, 'mail', 'email');

      if ($mail === '') {
        throw new ApiException('The submitted values are not valid.', 'validation_failed', 422, [
          'mail' => ['An email address is required.'],
        ]);
      }

      $this->guardFlood($request);
      $this->flood->register(self::FLOOD_RESET, $this->floodWindow(), $request->getClientIp());
      $this->passwordReset->request($mail);

      // Answer identically whether or not the address is registered, so this
      // endpoint cannot be used to discover which addresses have accounts.
      return $this->success('If that address belongs to an active account, a password reset email is on its way.');
    });
  }

  /**
   * Redeems a reset token and sets a new password.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function reset(Request $request): JsonResponse {
    return $this->handle(fn() => $this->doReset($request));
  }

  /**
   * Applies the new password and clears every existing token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the token or password is missing or invalid.
   */
  protected function doReset(Request $request): JsonResponse {
    $data = $this->decodeBody($request);
    $token = $this->readString($data, 'token');
    $password = isset($data['password']) && is_scalar($data['password'])
      ? (string) $data['password']
      : '';

    $missing = [];
    if ($token === '') {
      $missing['token'] = ['A reset token is required.'];
    }
    if ($password === '') {
      $missing['password'] = ['A new password is required.'];
    }
    if ($missing) {
      throw new ApiException('The submitted values are not valid.', 'validation_failed', 422, $missing);
    }

    $account = $this->passwordReset->redeem($token);

    // The account's status may have changed between issuing and redeeming the
    // token; re-check it rather than trust the state at request() time.
    if (!$account->isActive()) {
      throw new ApiException('This account is blocked.', 'account_blocked', 403);
    }

    $account->setPassword($password);
    $this->assertNoViolations($account->validate());

    // A password reset is a credential change like any other, so every
    // existing token -- on this device and every other -- must stop working.
    $account->{TokenExpiryTriggerHandler::PRESERVE_TOKENS_FLAG} = FALSE;
    $account->save();
    // simple_oauth leaves refresh tokens behind on a user update, which would
    // let an old refresh token outlive the password it was obtained with.
    $this->tokenRevoker->revokeAllForAccount($account);

    return $this->success('Your password has been reset. You can now log in with your new password.', [
      'user' => $this->userNormalizer->normalize($account),
    ]);
  }

  /**
   * Rejects the request if the caller's IP has tripped the flood limit.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the limit has been reached.
   */
  protected function guardFlood(Request $request): void {
    $limit = (int) $this->config('user_api.settings')->get('password_reset_flood_limit');
    if ($limit < 1) {
      return;
    }

    if (!$this->flood->isAllowed(self::FLOOD_RESET, $limit, $this->floodWindow(), $request->getClientIp())) {
      throw new ApiException(
        'Too many password reset requests from your IP address. Please try again later.',
        'flood_password_reset',
        429
      );
    }
  }

  /**
   * Gets the configured password reset flood window.
   *
   * @return int
   *   The window in seconds.
   */
  protected function floodWindow(): int {
    $window = (int) $this->config('user_api.settings')->get('password_reset_flood_window');

    return $window > 0 ? $window : 3600;
  }

}
