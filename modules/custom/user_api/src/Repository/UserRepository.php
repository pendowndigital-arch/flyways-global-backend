<?php

namespace Drupal\user_api\Repository;

use Drupal\simple_oauth\Entities\UserEntity;
use Drupal\user_api\Service\CredentialsValidator;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

/**
 * Resolves resource owner credentials for the password grant.
 */
class UserRepository implements UserRepositoryInterface {

  public function __construct(
    protected CredentialsValidator $credentialsValidator,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getUserEntityByUserCredentials(
    string $username,
    #[\SensitiveParameter] string $password,
    string $grantType,
    ClientEntityInterface $clientEntity,
  ): ?UserEntityInterface {
    // Failures are raised as OAuthServerException by the validator so that the
    // reason (wrong password, unactivated, flooded) reaches the client instead
    // of collapsing into a bare "invalid credentials".
    $account = $this->credentialsValidator->validate($username, $password);

    $user_entity = new UserEntity();
    $user_entity->setIdentifier($account->id());

    return $user_entity;
  }

}
