<?php

namespace Drupal\user_management_api\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Drupal\user\UserInterface;

/**
 * Event that is fired when a user updates their profile.
 */
class UserUpdatedEvent extends Event {

  const EVENT_NAME = 'user_management_api.user_updated';

  /**
   * The user entity.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * Constructs a new UserUpdatedEvent.
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
