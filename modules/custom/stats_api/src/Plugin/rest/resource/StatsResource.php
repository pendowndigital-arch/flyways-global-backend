<?php

namespace Drupal\stats_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides Stats REST Resource.
 *
 * @RestResource(
 *   id = "stats_api",
 *   label = @Translation("Site Stats"),
 *   uri_paths = {
 *     "canonical" = "/api/stats"
 *   }
 * )
 */
class StatsResource extends ResourceBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
	protected $entityTypeManager;

	/**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a StatsResource object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('stats_api'),
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Responds to GET requests.
   */
  public function get() {
    // Count articles (nodes of type 'article').
    $article_query = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'article')
      ->count();
    $article_count = $article_query->execute();

    // Count users.
    $user_query = \Drupal::entityQuery('user')
      ->accessCheck(TRUE)
      ->count();
    $user_count = $user_query->execute();

    // Count taxonomy terms (all tags).
    $term_query = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(TRUE)
      ->count();
    $term_count = $term_query->execute();

    $data = [
      'articles' => $article_count,
      'users' => $user_count,
      'tags' => $term_count,
    ];

    $response = new ResourceResponse($data);
    $response->addCacheableDependency(\Drupal::service('config.factory')->getEditable('stats_api.settings'));
    return $response;
  }

}
