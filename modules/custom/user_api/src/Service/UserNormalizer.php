<?php

namespace Drupal\user_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\UserInterface;

/**
 * Builds the user payload returned by the API.
 */
class UserNormalizer {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Normalises an account for API output.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   *
   * @return array
   *   The user payload.
   */
  public function normalize(UserInterface $account): array {
    $data = [
      'uid' => (int) $account->id(),
      'uuid' => $account->uuid(),
      'name' => $account->getAccountName(),
      'mail' => $account->getEmail(),
      'status' => $account->isActive() ? 'active' : 'blocked',
      'roles' => array_values($account->getRoles()),
      'created' => (int) $account->getCreatedTime(),
      'last_access' => (int) $account->getLastAccessedTime(),
    ];

    foreach ($this->exposedFields() as $field_name) {
      if (!$account->hasField($field_name)) {
        continue;
      }
      $field = $account->get($field_name);
      $data[$field_name] = $field->isEmpty() ? NULL : $field->first()->getString();
    }

    return $data;
  }

  /**
   * Gets the configured list of extra fields to expose.
   *
   * @return string[]
   *   The field names.
   */
  protected function exposedFields(): array {
    $fields = $this->configFactory->get('user_api.settings')->get('exposed_fields');

    return is_array($fields) ? $fields : [];
  }

}
