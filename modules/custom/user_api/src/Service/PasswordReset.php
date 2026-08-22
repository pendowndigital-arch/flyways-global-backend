<?php

namespace Drupal\user_api\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Drupal\user_api\Exception\ApiException;
use Psr\Log\LoggerInterface;

/**
 * Issues, mails and redeems password reset tokens.
 *
 * Mirrors \Drupal\user_api\Service\AccountActivation: only a SHA-256 digest of
 * the token is stored, in the same kind of expirable key/value collection, so
 * a database read cannot be replayed as a reset link, and expiry is handled
 * by the store.
 */
class PasswordReset {

  /**
   * Key/value collection holding pending reset digests.
   */
  const COLLECTION = 'user_api.password_reset';

  /**
   * user.data module name for the pending digest pointer.
   */
  const USER_DATA_MODULE = 'user_api';

  /**
   * user.data key for the pending digest pointer.
   */
  const USER_DATA_KEY = 'password_reset_hash';

  /**
   * Fallback token lifetime in seconds.
   */
  const DEFAULT_TTL = 3600;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected UserDataInterface $userData,
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Issues a reset token and emails it, if the address maps to a usable account.
   *
   * The return value is intentionally not surfaced to clients: responding the
   * same way whether or not the address exists keeps this endpoint from
   * confirming which addresses are registered.
   *
   * @param string $mail
   *   The email address.
   *
   * @return bool
   *   TRUE if an email was sent.
   */
  public function request(string $mail): bool {
    if ($mail === '') {
      return FALSE;
    }

    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail]);
    /** @var \Drupal\user\UserInterface|false $account */
    $account = $accounts ? reset($accounts) : FALSE;

    // A blocked account cannot log in regardless of its password, so there is
    // nothing a reset link would unlock, and issuing one would only fail at
    // the login step.
    if (!$account || (int) $account->id() < 1 || !$account->isActive()) {
      return FALSE;
    }

    $token = $this->issueToken($account);

    return $this->sendResetMail($account, $token);
  }

  /**
   * Redeems a reset token, returning the account it was issued for.
   *
   * The caller is responsible for setting and saving the new password; this
   * only proves the token is valid and burns it so it cannot be reused.
   *
   * @param string $token
   *   The plain reset token from the emailed link.
   *
   * @return \Drupal\user\UserInterface
   *   The account.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the token is missing, unknown, expired or its account is gone.
   */
  public function redeem(string $token): UserInterface {
    if ($token === '') {
      throw new ApiException('A password reset token is required.', 'missing_token', 400);
    }

    $digest = Crypt::hashBase64($token);
    $store = $this->keyValueExpirable->get(self::COLLECTION);
    $record = $store->get($digest);

    if (!is_array($record) || empty($record['uid'])) {
      throw new ApiException(
        'This password reset link is invalid or has expired. Please request a new one.',
        'invalid_reset_token',
        400
      );
    }

    /** @var \Drupal\user\UserInterface|null $account */
    $account = $this->entityTypeManager->getStorage('user')->load($record['uid']);
    if (!$account) {
      $store->delete($digest);
      throw new ApiException('The account for this reset link no longer exists.', 'account_not_found', 404);
    }

    // Burn the token whatever happens next, so a link works exactly once.
    $store->delete($digest);
    $this->userData->delete(self::USER_DATA_MODULE, (int) $account->id(), self::USER_DATA_KEY);

    return $account;
  }

  /**
   * Creates a token, storing only its digest, and invalidating any previous.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   *
   * @return string
   *   The plain token to put in the reset link or code.
   */
  protected function issueToken(UserInterface $account): string {
    $store = $this->keyValueExpirable->get(self::COLLECTION);
    $uid = (int) $account->id();

    // Supersede an earlier pending token so only the newest link works.
    $previous = $this->userData->get(self::USER_DATA_MODULE, $uid, self::USER_DATA_KEY);
    if (is_string($previous) && $previous !== '') {
      $store->delete($previous);
    }

    // Crypt::randomBytesBase64() is URL-safe, so the token needs no encoding.
    $token = Crypt::randomBytesBase64(32);
    $digest = Crypt::hashBase64($token);

    $store->setWithExpire($digest, ['uid' => $uid], $this->tokenTtl());
    $this->userData->set(self::USER_DATA_MODULE, $uid, self::USER_DATA_KEY, $digest);

    return $token;
  }

  /**
   * Sends the reset email in the account's preferred language.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $token
   *   The plain reset token.
   *
   * @return bool
   *   TRUE if the mail system accepted the message.
   */
  protected function sendResetMail(UserInterface $account, string $token): bool {
    $langcode = $account->getPreferredLangcode()
      ?: $this->languageManager->getDefaultLanguage()->getId();

    $result = $this->mailManager->mail(
      'user_api',
      'password_reset',
      $account->getEmail(),
      $langcode,
      [
        'account' => $account,
        'token' => $token,
        'reset_url' => $this->resetUrl($token),
        'expiration_hours' => (int) round($this->tokenTtl() / 3600),
      ]
    );

    if (empty($result['result'])) {
      $this->logger->error('Could not send the password reset email to %mail (uid @uid).', [
        '%mail' => $account->getEmail(),
        '@uid' => $account->id(),
      ]);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Builds the reset URL for a token, if a front end is configured.
   *
   * Unlike the activation link, redeeming a reset token also requires the new
   * password, so there is no site route this can fall back to the way
   * activation falls back to GET /api/user/activate/{token}. Without a
   * configured front end, the mail carries the raw token instead of a link.
   *
   * @param string $token
   *   The plain reset token.
   *
   * @return string|null
   *   An absolute reset URL, or NULL if none is configured.
   */
  protected function resetUrl(string $token): ?string {
    $template = trim((string) $this->configFactory->get('user_api.settings')->get('password_reset_url'));
    if ($template === '') {
      return NULL;
    }

    return str_replace(['[token]', '{token}'], $token, $template);
  }

  /**
   * Gets the configured reset token lifetime.
   *
   * @return int
   *   The lifetime in seconds.
   */
  protected function tokenTtl(): int {
    $ttl = (int) $this->configFactory->get('user_api.settings')->get('password_reset_token_ttl');

    return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
  }

}
