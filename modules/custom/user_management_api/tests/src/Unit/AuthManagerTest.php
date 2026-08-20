<?php

namespace Drupal\Tests\user_management_api\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\user_management_api\Service\AuthManager;
use Drupal\user_management_api\Service\EmailVerificationManager;
use Drupal\user_management_api\Exception\ApiException;

/**
 * @coversDefaultClass \Drupal\user_management_api\Service\AuthManager
 * @group user_management_api
 */
class AuthManagerTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The current user mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * The event dispatcher mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $eventDispatcher;

  /**
   * The user auth service mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $userAuth;

  /**
   * The session mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $session;

  /**
   * The email verification manager mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $emailVerificationManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $this->userAuth = $this->createMock(UserAuthInterface::class);
    $this->session = $this->createMock(SessionInterface::class);
    $this->emailVerificationManager = $this->createMock(EmailVerificationManager::class);
  }

  /**
   * Test registration with missing fields.
   */
  public function testRegisterMissingFields() {
    $authManager = new AuthManager(
      $this->entityTypeManager,
      $this->currentUser,
      $this->eventDispatcher,
      $this->userAuth,
      $this->session,
      $this->emailVerificationManager
    );

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Missing required registration fields');
    $authManager->register([]);
  }

  /**
   * Test registration with invalid email.
   */
  public function testRegisterInvalidEmail() {
    $authManager = new AuthManager(
      $this->entityTypeManager,
      $this->currentUser,
      $this->eventDispatcher,
      $this->userAuth,
      $this->session,
      $this->emailVerificationManager
    );

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Invalid email address format.');
    $authManager->register([
      'email' => 'invalid-email',
      'password' => '123456',
      'first_name' => 'John',
      'last_name' => 'Doe',
    ]);
  }

}
