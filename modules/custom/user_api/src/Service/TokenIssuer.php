<?php

namespace Drupal\user_api\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Server\AuthorizationServerFactoryInterface;
use Drupal\user_api\Exception\ApiException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a grant against the simple_oauth authorization server.
 *
 * This lets /api/user/login return a JSON envelope with the user profile
 * attached, while the tokens themselves are still minted by simple_oauth so
 * they are indistinguishable from tokens issued at /oauth/token.
 */
class TokenIssuer {

  public function __construct(
    protected AuthorizationServerFactoryInterface $authorizationServerFactory,
    protected ClientRepositoryInterface $clientRepository,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Exchanges grant parameters for a token response.
   *
   * @param array $parameters
   *   The grant parameters, including grant_type and client_id.
   *
   * @return array
   *   The decoded token response, e.g. access_token, expires_in, token_type.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the client is unknown, the grant is not enabled for it, or the
   *   authorization server refuses the request.
   */
  public function issue(array $parameters): array {
    $client = $this->loadClient((string) ($parameters['client_id'] ?? ''));
    $grant_type = (string) ($parameters['grant_type'] ?? '');

    if (!$this->clientAllowsGrant($client, $grant_type)) {
      throw new ApiException(
        sprintf('The "%s" grant type is not enabled for this client.', $grant_type),
        'unsupported_grant_type',
        400
      );
    }

    // The authorization server only reads the parsed body, so a synthetic
    // PSR-7 request carrying the parameters is enough.
    $psr_request = (new ServerRequest('POST', '/oauth/token'))->withParsedBody($parameters);

    try {
      $psr_response = $this->authorizationServerFactory->get($client)
        ->respondToAccessTokenRequest($psr_request, new Response());
    }
    catch (OAuthServerException $exception) {
      throw ApiException::fromOauthException($exception);
    }

    $decoded = json_decode((string) $psr_response->getBody(), TRUE);
    if (!is_array($decoded)) {
      $this->logger->error('The authorization server returned an unreadable token response.');
      throw new ApiException('Could not issue a token. Please try again.', 'token_issue_failed', 500);
    }

    return $decoded;
  }

  /**
   * Loads the consumer behind a client ID.
   *
   * @param string $client_id
   *   The OAuth2 client ID.
   *
   * @return \Drupal\consumers\Entity\Consumer
   *   The consumer entity.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If no enabled consumer matches.
   */
  protected function loadClient(string $client_id): Consumer {
    $client_entity = $client_id === '' ? NULL : $this->clientRepository->getClientEntity($client_id);
    if (!$client_entity) {
      throw new ApiException('Unknown or disabled OAuth2 client.', 'invalid_client', 401);
    }

    /** @var \Drupal\simple_oauth\Entities\ClientEntityInterface $client_entity */
    return $client_entity->getDrupalEntity();
  }

  /**
   * Checks whether a grant type is enabled on a consumer.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   * @param string $grant_type
   *   The grant type plugin ID.
   *
   * @return bool
   *   TRUE if the consumer may use the grant.
   */
  protected function clientAllowsGrant(Consumer $client, string $grant_type): bool {
    foreach ($client->get('grant_types')->getValue() as $item) {
      if ($item['value'] === $grant_type) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
