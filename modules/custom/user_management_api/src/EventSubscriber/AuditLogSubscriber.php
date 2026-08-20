<?php

namespace Drupal\user_management_api\EventSubscriber;

use Drupal\Core\Logger\LoggerFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\user_management_api\Event\UserRegisteredEvent;
use Drupal\user_management_api\Event\UserVerifiedEvent;
use Drupal\user_management_api\Event\PasswordChangedEvent;
use Drupal\user_management_api\Event\UserUpdatedEvent;

/**
 * Event subscriber to log audit logs for user management activities.
 */
class AuditLogSubscriber implements EventSubscriberInterface {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerFactoryInterface
   */
  protected $loggerFactory;

  /**
   * AuditLogSubscriber constructor.
   *
   * @param \Drupal\Core\Logger\LoggerFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      UserRegisteredEvent::EVENT_NAME => 'onUserRegistered',
      UserVerifiedEvent::EVENT_NAME => 'onUserVerified',
      PasswordChangedEvent::EVENT_NAME => 'onPasswordChanged',
      UserUpdatedEvent::EVENT_NAME => 'onUserUpdated',
    ];
  }

  /**
   * Log user registration events.
   *
   * @param \Drupal\user_management_api\Event\UserRegisteredEvent $event
   *   The registered event.
   */
  public function onUserRegistered(UserRegisteredEvent $event) {
    $user = $event->getUser();
    $this->loggerFactory->get('user_management_api')->info(
      'Audit log: New user registered. Username: @name, Email: @email, UID: @uid',
      [
        '@name' => $user->getAccountName(),
        '@email' => $user->getEmail(),
        '@uid' => $user->id(),
      ]
    );
  }

  /**
   * Log user verification events.
   *
   * @param \Drupal\user_management_api\Event\UserVerifiedEvent $event
   *   The verified event.
   */
  public function onUserVerified(UserVerifiedEvent $event) {
    $user = $event->getUser();
    $this->loggerFactory->get('user_management_api')->info(
      'Audit log: User email verified. Username: @name, Email: @email, UID: @uid',
      [
        '@name' => $user->getAccountName(),
        '@email' => $user->getEmail(),
        '@uid' => $user->id(),
      ]
    );
  }

  /**
   * Log user password changes.
   *
   * @param \Drupal\user_management_api\Event\PasswordChangedEvent $event
   *   The password changed event.
   */
  public function onPasswordChanged(PasswordChangedEvent $event) {
    $user = $event->getUser();
    $this->loggerFactory->get('user_management_api')->info(
      'Audit log: User updated password. Username: @name, UID: @uid',
      [
        '@name' => $user->getAccountName(),
        '@uid' => $user->id(),
      ]
    );
  }

  /**
   * Log user updates.
   *
   * @param \Drupal\user_management_api\Event\UserUpdatedEvent $event
   *   The user updated event.
   */
  public function onUserUpdated(UserUpdatedEvent $event) {
    $user = $event->getUser();
    $this->loggerFactory->get('user_management_api')->info(
      'Audit log: User profile details updated. Username: @name, UID: @uid',
      [
        '@name' => $user->getAccountName(),
        '@uid' => $user->id(),
      ]
    );
  }

}
