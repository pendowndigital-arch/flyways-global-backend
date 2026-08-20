<?php

namespace Drupal\user_management_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user_management_api\Service\UserManager;
use Drupal\user_management_api\Service\ResponseManager;
use Drupal\user_management_api\Exception\ApiException;

/**
 * Controller for user profile operations.
 */
class UserController extends ControllerBase {

  /**
   * The user manager.
   *
   * @var \Drupal\user_management_api\Service\UserManager
   */
  protected $userManager;

  /**
   * The response manager.
   *
   * @var \Drupal\user_management_api\Service\ResponseManager
   */
  protected $responseManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * UserController constructor.
   *
   * @param \Drupal\user_management_api\Service\UserManager $user_manager
   *   The user manager.
   * @param \Drupal\user_management_api\Service\ResponseManager $response_manager
   *   The response manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    UserManager $user_manager,
    ResponseManager $response_manager,
    AccountProxyInterface $current_user
  ) {
    $this->userManager = $user_manager;
    $this->responseManager = $response_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_management_api.user_manager'),
      $container->get('user_management_api.response_manager'),
      $container->get('current_user')
    );
  }

  /**
   * Fetch profile details of the logged in user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getProfile(Request $request): JsonResponse {
    try {
      $uid = (int) $this->currentUser->id();
      if ($uid === 0) {
        throw new ApiException('Access denied. You must be logged in.', 401);
      }

      $profile = $this->userManager->getUserProfile($uid);
      return $this->responseManager->successResponse($profile, 'Profile details retrieved successfully.');
    }
    catch (ApiException $e) {
      return $this->responseManager->exceptionResponse($e);
    }
    catch (\Exception $e) {
      return $this->responseManager->errorResponse('Failed to retrieve profile: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Update profile details of the logged in user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function updateProfile(Request $request): JsonResponse {
    try {
      $uid = (int) $this->currentUser->id();
      if ($uid === 0) {
        throw new ApiException('Access denied. You must be logged in.', 401);
      }

      $data = json_decode($request->getContent(), TRUE) ?: [];
      $this->userManager->updateUserProfile($uid, $data);
      $profile = $this->userManager->getUserProfile($uid);

      return $this->responseManager->successResponse($profile, 'Profile updated successfully.');
    }
    catch (ApiException $e) {
      return $this->responseManager->exceptionResponse($e);
    }
    catch (\Exception $e) {
      return $this->responseManager->errorResponse('Profile update failed: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Change password of the logged in user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function changePassword(Request $request): JsonResponse {
    try {
      $uid = (int) $this->currentUser->id();
      if ($uid === 0) {
        throw new ApiException('Access denied. You must be logged in.', 401);
      }

      $data = json_decode($request->getContent(), TRUE) ?: [];

      if (empty($data['old_password']) || empty($data['new_password'])) {
        throw new ApiException('Both old_password and new_password fields are required.', 400);
      }

      $this->userManager->changePassword($uid, $data['old_password'], $data['new_password']);
      return $this->responseManager->successResponse([], 'Password updated successfully.');
    }
    catch (ApiException $e) {
      return $this->responseManager->exceptionResponse($e);
    }
    catch (\Exception $e) {
      return $this->responseManager->errorResponse('Password change failed: ' . $e->getMessage(), 500);
    }
  }

}
