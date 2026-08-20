<?php

namespace Drupal\user_management_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user_management_api\Service\AuthManager;
use Drupal\user_management_api\Service\ResponseManager;
use Drupal\user_management_api\Service\EmailVerificationManager;
use Drupal\user_management_api\Exception\ApiException;

/**
 * Controller for authentication and email verification endpoints.
 */
class AuthController extends ControllerBase {

  /**
   * The authentication manager.
   *
   * @var \Drupal\user_management_api\Service\AuthManager
   */
  protected $authManager;

  /**
   * The response manager.
   *
   * @var \Drupal\user_management_api\Service\ResponseManager
   */
  protected $responseManager;

  /**
   * The email verification manager.
   *
   * @var \Drupal\user_management_api\Service\EmailVerificationManager
   */
  protected $emailVerificationManager;

  /**
   * AuthController constructor.
   *
   * @param \Drupal\user_management_api\Service\AuthManager $auth_manager
   *   The auth manager.
   * @param \Drupal\user_management_api\Service\ResponseManager $response_manager
   *   The response manager.
   * @param \Drupal\user_management_api\Service\EmailVerificationManager $email_verification_manager
   *   The email verification manager.
   */
  public function __construct(
    AuthManager $auth_manager,
    ResponseManager $response_manager,
    EmailVerificationManager $email_verification_manager
  ) {
    $this->authManager = $auth_manager;
    $this->responseManager = $response_manager;
    $this->emailVerificationManager = $email_verification_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_management_api.auth_manager'),
      $container->get('user_management_api.response_manager'),
      $container->get('user_management_api.email_verification_manager')
    );
  }

  /**
   * Handle user registration POST request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function register(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE) ?: [];
      $result = $this->authManager->register($data);
      
      $message = 'Registration successful.';
      if ($result['verification_required']) {
        $message .= ' Please check your email to verify your account before logging in.';
      }

      return $this->responseManager->successResponse($result, $message, 201);
    }
    catch (ApiException $e) {
      return $this->responseManager->exceptionResponse($e);
    }
    catch (\Exception $e) {
      return $this->responseManager->errorResponse('Registration failed: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Handle user login POST request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function login(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE) ?: [];
      
      if (empty($data['username']) || empty($data['password'])) {
        throw new ApiException('Username/Email and Password are required.', 400);
      }

      $result = $this->authManager->login($data['username'], $data['password']);
      return $this->responseManager->successResponse($result, 'Login successful.');
    }
    catch (ApiException $e) {
      return $this->responseManager->exceptionResponse($e);
    }
    catch (\Exception $e) {
      return $this->responseManager->errorResponse('Authentication failed: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Handle user logout POST request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function logout(Request $request): JsonResponse {
    try {
      $this->authManager->logout();
      return $this->responseManager->successResponse([], 'Logout successful.');
    }
    catch (ApiException $e) {
      return $this->responseManager->exceptionResponse($e);
    }
    catch (\Exception $e) {
      return $this->responseManager->errorResponse('Logout failed: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Handle email verification GET request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function verifyEmail(Request $request): JsonResponse {
    try {
      $token = $request->query->get('token');
      if (empty($token)) {
        throw new ApiException('Verification token is required.', 400);
      }

      $user = $this->emailVerificationManager->verifyToken($token);
      return $this->responseManager->successResponse([
        'uid' => (int) $user->id(),
        'email' => $user->getEmail(),
        'verified' => TRUE,
      ], 'Email verified successfully. You can now log in.');
    }
    catch (ApiException $e) {
      return $this->responseManager->exceptionResponse($e);
    }
    catch (\Exception $e) {
      return $this->responseManager->errorResponse('Email verification failed: ' . $e->getMessage(), 500);
    }
  }

}
