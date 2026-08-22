<?php

namespace Drupal\user_api\Controller;

use Drupal\user_api\Exception\ApiException;
use Drupal\user_api\Service\CredentialsValidator;
use Drupal\user_api\Service\TokenIssuer;
use Drupal\user_api\Service\TokenRevoker;
use Drupal\user_api\Service\UserNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Login and logout endpoints.
 */
class AuthController extends ApiControllerBase {

  public function __construct(
    protected TokenIssuer $tokenIssuer,
    protected TokenRevoker $tokenRevoker,
    protected CredentialsValidator $credentialsValidator,
    protected UserNormalizer $userNormalizer,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_api.token_issuer'),
      $container->get('user_api.token_revoker'),
      $container->get('user_api.credentials_validator'),
      $container->get('user_api.user_normalizer')
    );
  }

  /**
   * Exchanges credentials for an OAuth2 token and returns the user profile.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function login(Request $request): JsonResponse {
    return $this->handle(fn() => $this->doLogin($request));
  }

  /**
   * Clears the token used for the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function logout(Request $request): JsonResponse {
    return $this->handle(fn() => $this->doLogout($request));
  }

  /**
   * Runs the password grant and attaches the account to the response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If required values are missing or the grant is refused.
   */
  protected function doLogin(Request $request): JsonResponse {
    $data = $this->decodeBody($request);
    $username = $this->readString($data, 'username', 'name', 'mail', 'email');
    $client_id = $this->readString($data, 'client_id');
    $password = isset($data['password']) && is_scalar($data['password'])
      ? (string) $data['password']
      : '';

    $missing = [];
    if ($username === '') {
      $missing['username'] = ['A username or email address is required.'];
    }
    if ($password === '') {
      $missing['password'] = ['A password is required.'];
    }
    if ($client_id === '') {
      $missing['client_id'] = ['A client_id is required.'];
    }
    if ($missing) {
      throw new ApiException('The submitted values are not valid.', 'validation_failed', 422, $missing);
    }

    $parameters = [
      'grant_type' => 'password',
      'username' => $username,
      'password' => $password,
      'client_id' => $client_id,
    ];
    $client_secret = $this->readString($data, 'client_secret');
    if ($client_secret !== '') {
      $parameters['client_secret'] = $client_secret;
    }
    $scope = $this->readString($data, 'scope');
    if ($scope !== '') {
      $parameters['scope'] = $scope;
    }

    $token = $this->tokenIssuer->issue($parameters);

    // The grant has already authenticated the account, so this lookup only
    // fetches the profile to return alongside the token.
    $account = $this->credentialsValidator->lookupAccount($username);
    if ($account) {
      $token['user'] = $this->userNormalizer->normalize($account);
    }

    return $this->success('Login successful.', $token);
  }

  /**
   * Deletes the presented access token and any refresh token given with it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  protected function doLogout(Request $request): JsonResponse {
    $data = $this->decodeBody($request);
    $refresh_token = $this->readString($data, 'refresh_token');

    $revoked = $this->tokenRevoker->revokeCurrentSession(
      $request,
      $refresh_token !== '' ? $refresh_token : NULL
    );

    return $this->success('Logged out. The token is no longer valid.', [
      'access_token_cleared' => $revoked['access_token'],
      'refresh_token_cleared' => $revoked['refresh_token'],
    ]);
  }

}
