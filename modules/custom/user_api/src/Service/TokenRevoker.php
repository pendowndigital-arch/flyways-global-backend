<?php

namespace Drupal\user_api\Service;

use Defuse\Crypto\Core;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\simple_oauth\Server\ResourceServerFactoryInterface;
use Drupal\user\UserInterface;
use League\OAuth2\Server\CryptTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Clears the tokens behind an authenticated request.
 *
 * The oauth2_token entity stores a JWT's "jti" claim in its value field, not
 * the JWT itself, so the presented bearer token has to be resolved through the
 * resource server before it can be found. Refresh tokens reach the client
 * encrypted, and carry no reference back to the access token they were paired
 * with, so a client that wants its refresh token cleared must present it.
 */
class TokenRevoker {

  use CryptTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ResourceServerFactoryInterface $resourceServerFactory,
    protected HttpMessageFactoryInterface $httpMessageFactory,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Deletes the access token used by a request, plus a given refresh token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The authenticated request.
   * @param string|null $refresh_token
   *   The encrypted refresh token to clear as well, if the client has one.
   *
   * @return array
   *   Keys "access_token" and "refresh_token", each TRUE if a stored token was
   *   found and deleted.
   */
  public function revokeCurrentSession(Request $request, ?string $refresh_token = NULL): array {
    $result = ['access_token' => FALSE, 'refresh_token' => FALSE];

    $access_token_id = $this->currentAccessTokenId($request);
    if ($access_token_id !== NULL) {
      $result['access_token'] = $this->deleteToken($access_token_id, 'access_token');
    }

    if (is_string($refresh_token) && $refresh_token !== '') {
      $refresh_token_id = $this->refreshTokenId($refresh_token);
      if ($refresh_token_id !== NULL) {
        $result['refresh_token'] = $this->deleteToken($refresh_token_id, 'refresh_token');
      }
    }

    return $result;
  }

  /**
   * Deletes every token belonging to an account, refresh tokens included.
   *
   * simple_oauth's own account-update cleanup filters refresh tokens out
   * (ExpiredCollector::collectForAccount() excludes that bundle), so after an
   * email or password change an old refresh token could still be exchanged for
   * a fresh access token. This closes that window.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account whose tokens should be cleared.
   *
   * @return int
   *   The number of tokens deleted.
   */
  public function revokeAllForAccount(UserInterface $account): int {
    $storage = $this->entityTypeManager->getStorage('oauth2_token');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('auth_user_id', $account->id())
      ->execute();

    if (!$ids) {
      return 0;
    }

    $tokens = $storage->loadMultiple($ids);
    $storage->delete($tokens);

    $this->logger->notice('Cleared @count OAuth2 token(s) for uid @uid after a credential change.', [
      '@count' => count($tokens),
      '@uid' => $account->id(),
    ]);

    return count($tokens);
  }

  /**
   * Resolves the "jti" of the bearer token on a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The authenticated request.
   *
   * @return string|null
   *   The token identifier, or NULL if it could not be resolved.
   */
  protected function currentAccessTokenId(Request $request): ?string {
    try {
      $psr_request = $this->httpMessageFactory->createRequest($request);
      $validated = $this->resourceServerFactory->get()->validateAuthenticatedRequest($psr_request);
      $identifier = $validated->getAttribute('oauth_access_token_id');

      return (is_string($identifier) && $identifier !== '') ? $identifier : NULL;
    }
    catch (\Throwable $exception) {
      // Authentication already succeeded for this route, so failing here means
      // something unexpected. Log it and let logout report a partial result
      // rather than erroring out on the client.
      $this->logger->warning('Could not resolve the access token during logout: @message', [
        '@message' => $exception->getMessage(),
      ]);

      return NULL;
    }
  }

  /**
   * Decrypts a refresh token and extracts its stored identifier.
   *
   * @param string $refresh_token
   *   The encrypted refresh token as issued to the client.
   *
   * @return string|null
   *   The refresh token identifier, or NULL if it cannot be read.
   */
  protected function refreshTokenId(string $refresh_token): ?string {
    try {
      // simple_oauth seeds the authorization server's encryption key from the
      // site hash salt, so the same derivation reverses it here.
      $this->setEncryptionKey(Core::ourSubstr(Settings::getHashSalt(), 0, 32));
      $payload = json_decode($this->decrypt($refresh_token), TRUE);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not decrypt the refresh token presented at logout.');

      return NULL;
    }

    $identifier = $payload['refresh_token_id'] ?? NULL;

    return (is_string($identifier) && $identifier !== '') ? $identifier : NULL;
  }

  /**
   * Deletes a stored token by identifier and bundle.
   *
   * @param string $identifier
   *   The token identifier held in the entity's value field.
   * @param string $bundle
   *   The oauth2_token bundle, "access_token" or "refresh_token".
   *
   * @return bool
   *   TRUE if at least one token was deleted.
   */
  protected function deleteToken(string $identifier, string $bundle): bool {
    $storage = $this->entityTypeManager->getStorage('oauth2_token');
    $tokens = $storage->loadByProperties(['value' => $identifier, 'bundle' => $bundle]);

    if (!$tokens) {
      return FALSE;
    }

    // Deleting rather than flagging revoked keeps the token table from growing
    // with every logout; simple_oauth treats a missing token as revoked.
    $storage->delete($tokens);

    return TRUE;
  }

}
