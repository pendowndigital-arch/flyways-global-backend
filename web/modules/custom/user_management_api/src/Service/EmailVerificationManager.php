<?php

namespace Drupal\user_management_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\UserInterface;
use Drupal\user_management_api\Exception\ApiException;
use Drupal\user_management_api\Event\UserVerifiedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service to handle email verification token generation, mailing, and validation.
 */
class EmailVerificationManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * EmailVerificationManager constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    MailManagerInterface $mail_manager,
    EntityTypeManagerInterface $entity_type_manager,
    EventDispatcherInterface $event_dispatcher,
    StateInterface $state
  ) {
    $this->configFactory = $config_factory;
    $this->mailManager = $mail_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->state = $state;
  }

  /**
   * Generate verification token and send verification email.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   */
  public function sendVerificationEmail(UserInterface $user) {
    // Generate secure verification token.
    $token = bin2hex(random_bytes(32));

    // Save token mapping to user ID with timestamp.
    $this->state->set('user_management_api.verification_token.' . $token, [
      'uid' => $user->id(),
      'created' => time(),
    ]);

    // Send the email.
    $module = 'user_management_api';
    $key = 'verify_email';
    $to = $user->getEmail();
    $langcode = $user->getPreferredLangcode();
    
    // Generate verification link.
    global $base_url;
    $verification_url = $base_url . '/api/auth/verify-email?token=' . $token;

    $params = [
      'subject' => 'Verify your email address',
      'message' => "Hello " . $user->getDisplayName() . ",\n\nPlease verify your email address by clicking the link below:\n" . $verification_url . "\n\nThank you!",
      'user' => $user,
    ];

    $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, TRUE);
  }

  /**
   * Verify verification token.
   *
   * @param string $token
   *   The verification token.
   *
   * @return \Drupal\user\UserInterface
   *   The verified user.
   *
   * @throws \Drupal\user_management_api\Exception\ApiException
   */
  public function verifyToken(string $token): UserInterface {
    $data = $this->state->get('user_management_api.verification_token.' . $token);

    if (!$data || empty($data['uid'])) {
      throw new ApiException('Invalid or expired verification token.', 400);
    }

    $config = $this->configFactory->get('user_management_api.settings');
    $expiry = $config->get('token_expiry') ?: 86400;

    if (time() - $data['created'] > $expiry) {
      $this->state->delete('user_management_api.verification_token.' . $token);
      throw new ApiException('Verification token has expired.', 400);
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($data['uid']);

    if (!$user) {
      throw new ApiException('User not found.', 404);
    }

    // Activate the user if they were blocked/unverified.
    $user->activate();
    $user->save();

    // Remove the verification token.
    $this->state->delete('user_management_api.verification_token.' . $token);

    // Dispatch verified event.
    $event = new UserVerifiedEvent($user);
    $this->eventDispatcher->dispatch($event, UserVerifiedEvent::EVENT_NAME);

    return $user;
  }

}
