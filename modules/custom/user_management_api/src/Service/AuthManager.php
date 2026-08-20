<?php

namespace Drupal\user_management_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserAuthInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\user_management_api\Exception\ApiException;
use Drupal\user_management_api\Event\UserRegisteredEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service to handle registration, authentication, login, and logout.
 */
class AuthManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The user authentication service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * The session interface.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * The email verification manager.
   *
   * @var \Drupal\user_management_api\Service\EmailVerificationManager
   */
  protected $emailVerificationManager;

  /**
   * AuthManager constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    EventDispatcherInterface $event_dispatcher,
    UserAuthInterface $user_auth,
    SessionInterface $session,
    EmailVerificationManager $email_verification_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->eventDispatcher = $event_dispatcher;
    $this->userAuth = $user_auth;
    $this->session = $session;
    $this->emailVerificationManager = $email_verification_manager;
  }

  /**
   * Registers a new user.
   *
   * @param array $data
   *   User registration details.
   *
   * @return array
   *   The profile details of the registered user.
   *
   * @throws \Drupal\user_management_api\Exception\ApiException
   */
  public function register(array $data): array {
    // Validate inputs.
    if (empty($data['email']) || empty($data['password']) || empty($data['first_name']) || empty($data['last_name'])) {
      throw new ApiException('Missing required registration fields (email, password, first_name, last_name).', 400);
    }

    $email = trim($data['email']);
    $password = $data['password'];
    $first_name = trim($data['first_name']);
    $last_name = trim($data['last_name']);
    $bio = isset($data['bio']) ? trim($data['bio']) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new ApiException('Invalid email address format.', 400);
    }

    if (strlen($password) < 6) {
      throw new ApiException('Password must be at least 6 characters long.', 400);
    }

    // Standardize user name creation.
    $username = strtolower($first_name . '.' . $last_name);
    
    // Check for duplicates.
    /** @var \Drupal\user\UserStorageInterface $user_storage */
    $user_storage = $this->entityTypeManager->getStorage('user');
    
    $email_check = $user_storage->getQuery()->accessCheck(TRUE)->condition('mail', $email)->execute();
    if (!empty($email_check)) {
      throw new ApiException('Email is already registered.', 400);
    }

    // Resolve name clash by appending suffix if needed.
    $original_username = $username;
    $suffix = 1;
    while (!empty($user_storage->getQuery()->accessCheck(TRUE)->condition('name', $username)->execute())) {
      $username = $original_username . $suffix;
      $suffix++;
    }

    // Check if configuration requires email verification.
    $config = \Drupal::config('user_management_api.settings');
    $require_verification = $config->get('require_email_verification') ?? TRUE;
    
    // Create new user blocked (0) if verification is required, active (1) otherwise.
    $user_data = [
      'name' => $username,
      'mail' => $email,
      'pass' => $password,
      'status' => $require_verification ? 0 : 1,
      'roles' => ['authenticated'],
    ];

    /** @var \Drupal\user\UserInterface $user */
    $user = $user_storage->create($user_data);

    if ($user->hasField('field_first_name')) {
      $user->set('field_first_name', $first_name);
    }
    if ($user->hasField('field_last_name')) {
      $user->set('field_last_name', $last_name);
    }
    if ($user->hasField('field_bio')) {
      $user->set('field_bio', $bio);
    }

    $user->save();

    // Dispatch event.
    $event = new UserRegisteredEvent($user);
    $this->eventDispatcher->dispatch($event, UserRegisteredEvent::EVENT_NAME);

    // If email verification required, send verification mail.
    if ($require_verification) {
      $this->emailVerificationManager->sendVerificationEmail($user);
    }

    return [
      'uid' => (int) $user->id(),
      'username' => $user->getAccountName(),
      'email' => $user->getEmail(),
      'verification_required' => $require_verification,
    ];
  }

  /**
   * Authenticates and logs in a user.
   *
   * @param string $username_or_email
   *   Username or registered email.
   * @param string $password
   *   Clear password.
   *
   * @return array
   *   Session details.
   *
   * @throws \Drupal\user_management_api\Exception\ApiException
   */
  public function login(string $username_or_email, string $password): array {
    $username_or_email = trim($username_or_email);
    
    /** @var \Drupal\user\UserStorageInterface $user_storage */
    $user_storage = $this->entityTypeManager->getStorage('user');
    
    // Resolve user by username or email.
    $uid_arr = [];
    if (filter_var($username_or_email, FILTER_VALIDATE_EMAIL)) {
      $uid_arr = $user_storage->getQuery()->accessCheck(TRUE)->condition('mail', $username_or_email)->execute();
    }
    if (empty($uid_arr)) {
      $uid_arr = $user_storage->getQuery()->accessCheck(TRUE)->condition('name', $username_or_email)->execute();
    }

    if (empty($uid_arr)) {
      throw new ApiException('Invalid credentials.', 401);
    }

    $uid = reset($uid_arr);
    /** @var \Drupal\user\UserInterface $user */
    $user = $user_storage->load($uid);

    if (!$user) {
      throw new ApiException('Invalid credentials.', 401);
    }

    // Authenticate password.
    if (!$this->userAuth->authenticate($user->getAccountName(), $password)) {
      throw new ApiException('Invalid credentials.', 401);
    }

    // Check account status.
    if (!$user->isActive()) {
      $config = \Drupal::config('user_management_api.settings');
      $require_verification = $config->get('require_email_verification') ?? TRUE;
      if ($require_verification) {
        throw new ApiException('Account is not verified. Please check your email for the verification link.', 403);
      }
      throw new ApiException('Account is blocked.', 403);
    }

    // Finalize session login.
    user_login_finalize($user);

    return [
      'uid' => (int) $user->id(),
      'username' => $user->getAccountName(),
      'email' => $user->getEmail(),
      'session_name' => session_name(),
      'session_id' => session_id(),
    ];
  }

  /**
   * Logs out the current user session.
   */
  public function logout() {
    if ($this->currentUser->isAuthenticated()) {
      user_logout();
    }
  }

}
