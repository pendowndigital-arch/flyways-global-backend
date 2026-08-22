<?php

namespace Drupal\user_api\Controller;

use Drupal\user\UserInterface;
use Drupal\user_api\Exception\ApiException;
use Drupal\user_api\Service\TokenRevoker;
use Drupal\user_api\Service\UserNormalizer;
use Drupal\user_api\TokenExpiryTriggerHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read and update endpoints for the authenticated user's own account.
 */
class ProfileController extends ApiControllerBase {

  public function __construct(
    protected UserNormalizer $userNormalizer,
    protected TokenRevoker $tokenRevoker,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_api.user_normalizer'),
      $container->get('user_api.token_revoker')
    );
  }

  /**
   * Returns the authenticated user's profile.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function get(): JsonResponse {
    return $this->handle(function () {
      $account = $this->loadCurrentAccount();

      return $this->success('Profile loaded.', [
        'user' => $this->userNormalizer->normalize($account),
      ]);
    });
  }

  /**
   * Updates the authenticated user's profile.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function update(Request $request): JsonResponse {
    return $this->handle(fn() => $this->doUpdate($request));
  }

  /**
   * Applies the submitted changes to the current account.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If nothing was submitted or the values do not validate.
   */
  protected function doUpdate(Request $request): JsonResponse {
    $account = $this->loadCurrentAccount();
    $data = $this->decodeBody($request);

    // Handing the plain current password to the pass field lets core's
    // ProtectedUserField constraint enforce it for email and password changes,
    // rather than reimplementing that check here.
    $current_password = isset($data['current_password']) && is_scalar($data['current_password'])
      ? (string) $data['current_password']
      : '';
    if ($current_password !== '') {
      $account->get('pass')->existing = $current_password;
    }

    $changed = FALSE;
    // Tracks whether the caller's credentials changed, which must invalidate
    // every token on the account.
    $credentials_changed = FALSE;

    $name = $this->readString($data, 'name', 'username');
    if ($name !== '' && $name !== $account->getAccountName()) {
      $account->setUsername($name);
      $changed = TRUE;
    }

    $mail = $this->readString($data, 'mail', 'email');
    if ($mail !== '' && $mail !== $account->getEmail()) {
      $account->setEmail($mail);
      $changed = TRUE;
      $credentials_changed = TRUE;
    }

    if (isset($data['password']) && is_scalar($data['password']) && (string) $data['password'] !== '') {
      $account->setPassword((string) $data['password']);
      $changed = TRUE;
      $credentials_changed = TRUE;
    }

    foreach ($this->editableFields() as $field_name) {
      if (!array_key_exists($field_name, $data) || !$account->hasField($field_name)) {
        continue;
      }
      if (!is_scalar($data[$field_name]) && $data[$field_name] !== NULL) {
        continue;
      }
      $account->set($field_name, $data[$field_name]);
      $changed = TRUE;
    }

    if (!$changed) {
      throw new ApiException('No supported fields were submitted.', 'nothing_to_update', 400);
    }

    $this->assertNoViolations($account->validate());

    // Saving an account makes simple_oauth drop all of its tokens. That is the
    // behaviour we want after an email or password change, but not when only a
    // profile field moved, so the save is flagged in the latter case.
    $account->{TokenExpiryTriggerHandler::PRESERVE_TOKENS_FLAG} = !$credentials_changed;
    $account->save();

    if ($credentials_changed) {
      // simple_oauth leaves refresh tokens behind on a user update, which would
      // let an old refresh token outlive the credentials it was obtained with.
      $this->tokenRevoker->revokeAllForAccount($account);
    }

    return $this->success(
      $credentials_changed
        ? 'Profile updated. Your credentials changed, so every existing token has been cleared - please log in again.'
        : 'Profile updated.',
      [
        'user' => $this->userNormalizer->normalize($account),
        // Lets a client know up front that it has to re-authenticate.
        'reauthentication_required' => $credentials_changed,
      ]
    );
  }

  /**
   * Loads the full user entity behind the authenticated request.
   *
   * @return \Drupal\user\UserInterface
   *   The account.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the account can no longer be loaded.
   */
  protected function loadCurrentAccount(): UserInterface {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());

    if (!$account instanceof UserInterface) {
      throw new ApiException('The authenticated account could not be loaded.', 'account_not_found', 404);
    }

    return $account;
  }

  /**
   * Gets the configured list of extra editable fields.
   *
   * @return string[]
   *   The field names.
   */
  protected function editableFields(): array {
    $fields = $this->config('user_api.settings')->get('editable_fields');

    return is_array($fields) ? $fields : [];
  }

}
