<?php

namespace Drupal\user_management_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Drupal\user_management_api\Exception\ApiException;
use Drupal\user_management_api\Event\UserUpdatedEvent;
use Drupal\user_management_api\Event\PasswordChangedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service to manage user retrieval, updates, validation, and password changes.
 */
class UserManager {

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
   * UserManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EventDispatcherInterface $event_dispatcher) {
    $this->entityTypeManager = $entity_type_manager;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * Load user entity by ID.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\user\UserInterface
   *
   * @throws \Drupal\user_management_api\Exception\ApiException
   */
  public function loadUser(int $uid): UserInterface {
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      throw new ApiException('User not found.', 404);
    }
    return $user;
  }

  /**
   * Get user profile details formatted for API response.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   */
  public function getUserProfile(int $uid): array {
    $user = $this->loadUser($uid);

    return [
      'uid' => (int) $user->id(),
      'username' => $user->getAccountName(),
      'email' => $user->getEmail(),
      'first_name' => $user->hasField('field_first_name') ? $user->get('field_first_name')->value : '',
      'last_name' => $user->hasField('field_last_name') ? $user->get('field_last_name')->value : '',
      'bio' => $user->hasField('field_bio') ? $user->get('field_bio')->value : '',
      'created' => (int) $user->getCreatedTime(),
      'status' => (int) $user->isActive(),
    ];
  }

  /**
   * Check if an email is already registered.
   *
   * @param string $email
   *   The email address.
   * @param int|null $exclude_uid
   *   Exclude a user ID from the search (e.g. during update).
   *
   * @return bool
   */
  public function emailExists(string $email, ?int $exclude_uid = NULL): bool {
    $query = $this->entityTypeManager->getStorage('user')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('mail', $email);

    if ($exclude_uid) {
      $query->condition('uid', $exclude_uid, '<>');
    }

    $result = $query->execute();
    return !empty($result);
  }

  /**
   * Check if a username is already taken.
   *
   * @param string $username
   *   The username.
   * @param int|null $exclude_uid
   *   Exclude a user ID from the search.
   *
   * @return bool
   */
  public function usernameExists(string $username, ?int $exclude_uid = NULL): bool {
    $query = $this->entityTypeManager->getStorage('user')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('name', $username);

    if ($exclude_uid) {
      $query->condition('uid', $exclude_uid, '<>');
    }

    $result = $query->execute();
    return !empty($result);
  }

  /**
   * Update a user's profile details.
   *
   * @param int $uid
   *   The user ID.
   * @param array $data
   *   Fields to update.
   *
   * @return \Drupal\user\UserInterface
   *
   * @throws \Drupal\user_management_api\Exception\ApiException
   */
  public function updateUserProfile(int $uid, array $data): UserInterface {
    $user = $this->loadUser($uid);

    // Validate email if it is being changed.
    if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
      if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new ApiException('Invalid email format.', 400);
      }
      if ($this->emailExists($data['email'], $uid)) {
        throw new ApiException('Email is already registered by another user.', 400);
      }
      $user->setEmail($data['email']);
    }

    // Update custom profile fields if present on user entity.
    if (isset($data['first_name']) && $user->hasField('field_first_name')) {
      $user->set('field_first_name', $data['first_name']);
    }
    if (isset($data['last_name']) && $user->hasField('field_last_name')) {
      $user->set('field_last_name', $data['last_name']);
    }
    if (isset($data['bio']) && $user->hasField('field_bio')) {
      $user->set('field_bio', $data['bio']);
    }

    $user->save();

    // Dispatch update event.
    $event = new UserUpdatedEvent($user);
    $this->eventDispatcher->dispatch($event, UserUpdatedEvent::EVENT_NAME);

    return $user;
  }

  /**
   * Update user password after validating the old password.
   *
   * @param int $uid
   *   The user ID.
   * @param string $old_password
   *   The current password.
   * @param string $new_password
   *   The new password.
   *
   * @throws \Drupal\user_management_api\Exception\ApiException
   */
  public function changePassword(int $uid, string $old_password, string $new_password) {
    $user = $this->loadUser($uid);

    // Validate old password.
    /** @var \Drupal\Core\Password\PasswordInterface $password_hasher */
    $password_hasher = \Drupal::service('password');
    if (!$password_hasher->check($old_password, $user->getPassword())) {
      throw new ApiException('Incorrect current password.', 400);
    }

    if (strlen($new_password) < 6) {
      throw new ApiException('New password must be at least 6 characters long.', 400);
    }

    // Set new password.
    $user->setPassword($new_password);
    $user->save();

    // Dispatch password changed event.
    $event = new PasswordChangedEvent($user);
    $this->eventDispatcher->dispatch($event, PasswordChangedEvent::EVENT_NAME);
  }

}
