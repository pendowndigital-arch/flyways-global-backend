<?php

namespace Drupal\Tests\user_management_api\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\user_management_api\Service\UserManager;
use Drupal\user_management_api\Exception\ApiException;

/**
 * @coversDefaultClass \Drupal\user_management_api\Service\UserManager
 * @group user_management_api
 */
class UserManagerTest extends UnitTestCase {

  /**
   * The entity type manager mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The event dispatcher mock.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
  }

  /**
   * Test loading user entity that does not exist.
   */
  public function testLoadUserNotFound() {
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->expects($this->once())
      ->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($userStorage);

    $userManager = new UserManager($this->entityTypeManager, $this->eventDispatcher);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('User not found.');
    $userManager->loadUser(999);
  }

}
