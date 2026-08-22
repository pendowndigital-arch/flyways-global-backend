<?php

namespace Drupal\user_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Drupal\user_api\Exception\AuthException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Validates user credentials for the password grant.
 *
 * Drupal's own authentication service only compares the password hash: it does
 * not look at whether the account is active. Blocking unactivated accounts
 * therefore has to happen here, otherwise the email confirmation step would be
 * decorative.
 */
class CredentialsValidator {

  /**
   * Flood event name for failed attempts grouped by IP.
   */
  const FLOOD_IP = 'user_api.failed_login_ip';

  /**
   * Flood event name for failed attempts grouped by account and IP.
   */
  const FLOOD_USER = 'user_api.failed_login_user';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UserAuthenticationInterface $userAuth,
    protected FloodInterface $flood,
    protected ConfigFactoryInterface $configFactory,
    protected RequestStack $requestStack,
    protected UserDataInterface $userData,
  ) {
  }

  /**
   * Validates credentials and returns the matching active account.
   *
   * @param string $identifier
   *   A username or email address.
   * @param string $password
   *   The plain text password.
   *
   * @return \Drupal\user\UserInterface
   *   The authenticated, active account.
   *
   * @throws \League\OAuth2\Server\Exception\OAuthServerException
   *   If the credentials are wrong, the account cannot be used, or the caller
   *   has tripped a flood limit.
   */
  public function validate(string $identifier, #[\SensitiveParameter] string $password): UserInterface {
    $flood_config = $this->configFactory->get('user.flood');
    $ip_window = (int) $flood_config->get('ip_window');
    $user_window = (int) $flood_config->get('user_window');
    $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '';

    if (!$this->flood->isAllowed(self::FLOOD_IP, (int) $flood_config->get('ip_limit'), $ip_window, $ip)) {
      throw AuthException::create(
        'Too many failed login attempts from your IP address. Please try again later.',
        'flood_ip',
        429
      );
    }

    $account = $this->lookupAccount($identifier);
    if (!$account) {
      // Register against the IP only: there is no account to attribute this to,
      // and doing otherwise would let a caller lock out arbitrary usernames.
      $this->flood->register(self::FLOOD_IP, $ip_window, $ip);
      throw OAuthServerException::invalidCredentials();
    }

    $user_identifier = $account->id() . '-' . $ip;
    if (!$this->flood->isAllowed(self::FLOOD_USER, (int) $flood_config->get('user_limit'), $user_window, $user_identifier)) {
      throw AuthException::create(
        'Too many failed login attempts for this account. Please try again later.',
        'flood_user',
        429
      );
    }

    if (!$this->userAuth->authenticateAccount($account, $password)) {
      $this->flood->register(self::FLOOD_IP, $ip_window, $ip);
      $this->flood->register(self::FLOOD_USER, $user_window, $user_identifier);
      throw OAuthServerException::invalidCredentials();
    }

    // Only disclose why an account is unusable once the caller has proven they
    // own it, so this endpoint cannot be used to enumerate account states.
    if (!$account->isActive()) {
      throw $this->blockedAccountException($account);
    }

    $this->flood->clear(self::FLOOD_USER, $user_identifier);

    return $account;
  }

  /**
   * Loads an account by email address, falling back to username.
   *
   * The email address is tried first because it is the authoritative
   * identifier: accounts are created with the address as their username, but
   * only the address is kept in step afterwards. Once somebody changes their
   * email their old address remains as their username, so the same string can
   * match two accounts -- one by stale username, one by current address. The
   * owner of the address wins.
   *
   * The username fallback exists for accounts created outside this API, such
   * as staff logging in with a plain username.
   *
   * @param string $identifier
   *   An email address or username.
   *
   * @return \Drupal\user\UserInterface|null
   *   The account, or NULL if no real account matches.
   */
  public function lookupAccount(string $identifier): ?UserInterface {
    if ($identifier === '') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $accounts = str_contains($identifier, '@')
      ? $storage->loadByProperties(['mail' => $identifier])
      : [];
    if (!$accounts) {
      $accounts = $storage->loadByProperties(['name' => $identifier]);
    }

    /** @var \Drupal\user\UserInterface|false $account */
    $account = $accounts ? reset($accounts) : FALSE;

    // Guard against the anonymous user (uid 0), which has an empty password
    // hash and must never be authenticated.
    return ($account && (int) $account->id() > 0) ? $account : NULL;
  }

  /**
   * Explains why a blocked account cannot be used.
   *
   * The three cases are worth distinguishing, because each needs a different
   * next step from the user: follow the emailed link, wait for an
   * administrator, or contact support.
   *
   * @param \Drupal\user\UserInterface $account
   *   The blocked account.
   *
   * @return \Drupal\user_api\Exception\AuthException
   *   The exception to throw.
   */
  protected function blockedAccountException(UserInterface $account): AuthException {
    $uid = (int) $account->id();

    if ($this->userData->get(AccountActivation::USER_DATA_MODULE, $uid, AccountActivation::USER_DATA_KEY)) {
      return AuthException::create(
        'This account has not been activated yet. Please use the activation link sent to your email address.',
        'account_not_activated',
        403
      );
    }

    if ($this->userData->get(AccountActivation::USER_DATA_MODULE, $uid, AccountActivation::USER_DATA_VERIFIED_KEY)) {
      return AuthException::create(
        'Your email address is confirmed, but an administrator still has to approve this account.',
        'account_pending_approval',
        403
      );
    }

    return AuthException::create('This account has been blocked.', 'account_blocked', 403);
  }

}
