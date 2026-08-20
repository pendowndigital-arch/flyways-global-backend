<?php

namespace Drupal\user_management_api\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Drupal\user\UserInterface;

/**
 * Event that is fired when a user changes their password.
 */
class PasswordChangedEvent extends Event {

  const EVENT_NAME = 'user_management_api.password_changed';

  /**
   * The user entity.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * Constructs a new PasswordChangedEvent.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   */
  public function __construct(UserInterface $user) {
    $this->user = $user;
  }

  /**
   * Gets the user entity.
   *
   * @return \Drupal\user\UserInterface
   */
  public function getUser(): UserInterface {
    return $this->user;
  }

}
