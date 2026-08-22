<?php

namespace Drupal\user_api\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Drupal\user_api\Exception\ApiException;
use Psr\Log\LoggerInterface;

/**
 * Issues, mails and redeems account activation tokens.
 *
 * Only a SHA-256 digest of the token is stored, so a database read cannot be
 * replayed as an activation link. The digest lives in an expirable key/value
 * collection which gives the token its lifetime for free.
 */
class AccountActivation {

  /**
   * Key/value collection holding pending activation digests.
   */
  const COLLECTION = 'user_api.activation';

  /**
   * user.data module name for the pending digest pointer.
   */
  const USER_DATA_MODULE = 'user_api';

  /**
   * user.data key for the pending digest pointer.
   */
  const USER_DATA_KEY = 'activation_hash';

  /**
   * user.data key recording that the address was confirmed.
   */
  const USER_DATA_VERIFIED_KEY = 'email_verified';

  /**
   * Fallback token lifetime in seconds.
   */
  const DEFAULT_TTL = 86400;

  /**
   * Set while this service activates an account.
   *
   * Drupal core emails a "status activated" notice, complete with a one-time
   * login URL, whenever an account goes from blocked to active. That is right
   * for an administrator approving a registration, but redundant and needlessly
   * link-bearing when the user just confirmed their own email address, so
   * user_api_mail_alter() cancels that one message while this is TRUE.
   *
   * @var bool
   *
   * @see user_api_mail_alter()
   */
  public static bool $activating = FALSE;

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
   * Issues a fresh activation token and emails it to the account holder.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account awaiting activation.
   *
   * @return bool
   *   TRUE if the activation email was accepted for delivery.
   */
  public function start(UserInterface $account): bool {
    $token = $this->issueToken($account);

    return $this->sendActivationMail($account, $token);
  }

  /**
   * Redeems an activation token and activates the account.
   *
   * @param string $token
   *   The plain activation token from the email link.
   *
   * @return \Drupal\user\UserInterface
   *   The activated account.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the token is missing, unknown, expired or its account is gone.
   */
  public function activate(string $token): UserInterface {
    if ($token === '') {
      throw new ApiException('An activation token is required.', 'missing_token', 400);
    }

    $digest = Crypt::hashBase64($token);
    $store = $this->keyValueExpirable->get(self::COLLECTION);
    $record = $store->get($digest);

    if (!is_array($record) || empty($record['uid'])) {
      throw new ApiException(
        'This activation link is invalid or has expired. Please request a new one.',
        'invalid_activation_token',
        400
      );
    }

    /** @var \Drupal\user\UserInterface|null $account */
    $account = $this->entityTypeManager->getStorage('user')->load($record['uid']);
    if (!$account) {
      $store->delete($digest);
      throw new ApiException('The account for this activation link no longer exists.', 'account_not_found', 404);
    }

    // Burn the token whatever happens next, so a link works exactly once.
    $store->delete($digest);
    $this->userData->delete(self::USER_DATA_MODULE, (int) $account->id(), self::USER_DATA_KEY);
    // Record the confirmation itself, separately from the account status: on a
    // site that also requires administrator approval the address is verified
    // while the account stays blocked.
    $this->userData->set(self::USER_DATA_MODULE, (int) $account->id(), self::USER_DATA_VERIFIED_KEY, 1);

    // Confirming the address must not stand in for a human approving the
    // account, so on an approval-gated site the account stays blocked and the
    // administrator is notified instead.
    if (!$account->isActive() && $this->requiresAdminApproval()) {
      _user_mail_notify('register_pending_approval_admin', $account);
      $this->logger->notice('Address confirmed for %name (uid @uid); the account is awaiting administrator approval.', [
        '%name' => $account->getAccountName(),
        '@uid' => $account->id(),
      ]);

      return $account;
    }

    // Activating twice is not an error: the caller gets the same success shape
    // if they follow the emailed link a second time within the token lifetime.
    if (!$account->isActive()) {
      $account->activate();
      self::$activating = TRUE;
      try {
        $account->save();
      }
      finally {
        self::$activating = FALSE;
      }
      $this->logger->notice('Activated account %name (uid @uid) through the user API.', [
        '%name' => $account->getAccountName(),
        '@uid' => $account->id(),
      ]);
    }

    return $account;
  }

  /**
   * Re-sends an activation email if the address maps to a pending account.
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
  public function resend(string $mail): bool {
    if ($mail === '') {
      return FALSE;
    }

    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail]);
    /** @var \Drupal\user\UserInterface|false $account */
    $account = $accounts ? reset($accounts) : FALSE;

    if (!$account || (int) $account->id() < 1 || $account->isActive()) {
      return FALSE;
    }

    return $this->start($account);
  }

  /**
   * Creates a token, storing only its digest, and invalidating any previous.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   *
   * @return string
   *   The plain token to put in the activation link.
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
   * Sends the activation email in the account's preferred language.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $token
   *   The plain activation token.
   *
   * @return bool
   *   TRUE if the mail system accepted the message.
   */
  protected function sendActivationMail(UserInterface $account, string $token): bool {
    $langcode = $account->getPreferredLangcode()
      ?: $this->languageManager->getDefaultLanguage()->getId();

    $result = $this->mailManager->mail(
      'user_api',
      'activation',
      $account->getEmail(),
      $langcode,
      [
        'account' => $account,
        'activation_url' => $this->activationUrl($token),
        'expiration_hours' => (int) round($this->tokenTtl() / 3600),
      ]
    );

    if (empty($result['result'])) {
      $this->logger->error('Could not send the activation email to %mail (uid @uid).', [
        '%mail' => $account->getEmail(),
        '@uid' => $account->id(),
      ]);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Builds the activation URL for a token.
   *
   * @param string $token
   *   The plain activation token.
   *
   * @return string
   *   An absolute activation URL.
   */
  protected function activationUrl(string $token): string {
    $template = trim((string) $this->configFactory->get('user_api.settings')->get('activation_url'));
    if ($template !== '') {
      return str_replace(['[token]', '{token}'], $token, $template);
    }

    // Rendering the URL through toString(TRUE) keeps the generated
    // cacheability metadata from leaking into the surrounding request.
    return Url::fromRoute('user_api.activate_link', ['token' => $token], ['absolute' => TRUE])
      ->toString(TRUE)
      ->getGeneratedUrl();
  }

  /**
   * Checks whether the site also requires an administrator to approve signups.
   *
   * @return bool
   *   TRUE if registrations need approval.
   */
  protected function requiresAdminApproval(): bool {
    return $this->configFactory->get('user.settings')->get('register')
      === UserInterface::REGISTER_VISITORS_ADMINISTRATIVE_APPROVAL;
  }

  /**
   * Gets the configured activation token lifetime.
   *
   * @return int
   *   The lifetime in seconds.
   */
  protected function tokenTtl(): int {
    $ttl = (int) $this->configFactory->get('user_api.settings')->get('activation_token_ttl');

    return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
  }

}
