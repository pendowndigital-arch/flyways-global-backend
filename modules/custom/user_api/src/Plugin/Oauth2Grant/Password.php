<?php

namespace Drupal\user_api\Plugin\Oauth2Grant;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The resource owner password credentials grant plugin.
 *
 * simple_oauth 6.x ships only the authorization_code, client_credentials and
 * refresh_token grants, so this plugin adds the password grant that a
 * first-party mobile app or SPA needs to exchange a username and password for
 * a token in a single request.
 *
 * @Oauth2Grant(
 *   id = "password",
 *   label = @Translation("Password")
 * )
 */
class Password extends Oauth2GrantBase implements ContainerFactoryPluginInterface {

  /**
   * Default refresh token lifetime in seconds, matching simple_oauth.
   */
  const DEFAULT_REFRESH_TOKEN_TTL = 1209600;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected UserRepositoryInterface $userRepository,
    protected RefreshTokenRepositoryInterface $refreshTokenRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('user_api.repository.user'),
      $container->get('simple_oauth.repositories.refresh_token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getGrantType(Consumer $client): GrantTypeInterface {
    $refresh_token_enabled = $this->isRefreshTokenEnabled($client);

    /** @var \Drupal\simple_oauth\Repositories\OptionalRefreshTokenRepositoryInterface $refresh_token_repository */
    $refresh_token_repository = $this->refreshTokenRepository;
    if (!$refresh_token_enabled) {
      $refresh_token_repository->disableRefreshToken();
    }

    $grant_type = new PasswordGrant($this->userRepository, $refresh_token_repository);

    if ($refresh_token_enabled) {
      $ttl = !$client->get('refresh_token_expiration')->isEmpty
        ? (int) $client->get('refresh_token_expiration')->value
        : self::DEFAULT_REFRESH_TOKEN_TTL;
      $grant_type->setRefreshTokenTTL(new \DateInterval(sprintf('PT%dS', $ttl)));
    }

    return $grant_type;
  }

  /**
   * Checks if the refresh_token grant is also enabled on the client.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return bool
   *   TRUE when the client may receive refresh tokens.
   */
  protected function isRefreshTokenEnabled(Consumer $client): bool {
    foreach ($client->get('grant_types')->getValue() as $grant_type) {
      if ($grant_type['value'] === 'refresh_token') {
        return TRUE;
      }
    }

    return FALSE;
  }

}
