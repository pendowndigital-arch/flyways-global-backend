<?php

namespace Drupal\article_views\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\node\NodeInterface;
use Drupal\user_api\Controller\ApiControllerBase;
use Drupal\user_api\Exception\ApiException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Records and reports per-user article views.
 *
 * A view is recorded at most once per (user, article) pair. That is enforced
 * by the {article_views} table's composite primary key, not a
 * check-then-insert in PHP, so two concurrent requests from the same user
 * cannot both succeed in inserting a row.
 */
class ArticleViewController extends ApiControllerBase {

  /**
   * The database table holding one row per user that has viewed an article.
   */
  const TABLE = 'article_views';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('datetime.time')
    );
  }

  /**
   * Records that the authenticated user has viewed an article.
   *
   * @param int $nid
   *   The article's node ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function record(int $nid): JsonResponse {
    return $this->handle(function () use ($nid) {
      $node = $this->loadArticle($nid);
      $uid = (int) $this->currentUser()->id();

      $first_view = TRUE;
      try {
        $this->database->insert(self::TABLE)
          ->fields([
            'uid' => $uid,
            'nid' => (int) $node->id(),
            'viewed' => $this->time->getRequestTime(),
          ])
          ->execute();
      }
      catch (IntegrityConstraintViolationException) {
        // The primary key on (uid, nid) rejected a row that already exists,
        // which is exactly the "don't add again" behaviour this endpoint
        // wants, not an error.
        $first_view = FALSE;
      }

      return $this->success(
        $first_view ? 'View recorded.' : 'View already recorded.',
        ['article_id' => (int) $node->id(), 'first_view' => $first_view]
      );
    });
  }

  /**
   * Returns the number of distinct users who have viewed one article.
   *
   * @param int $nid
   *   The article's node ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function articleCount(int $nid): JsonResponse {
    return $this->handle(function () use ($nid) {
      $node = $this->loadArticle($nid);

      $count = (int) $this->database->select(self::TABLE, 'v')
        ->condition('nid', (int) $node->id())
        ->countQuery()
        ->execute()
        ->fetchField();

      return $this->success('Article view count.', [
        'article_id' => (int) $node->id(),
        'viewers' => $count,
      ]);
    });
  }

  /**
   * Returns the number of distinct users who have viewed any article.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function totalCount(): JsonResponse {
    return $this->handle(function () {
      // distinct() survives countQuery()'s rewrite, so this counts distinct
      // uids rather than total view rows.
      $count = (int) $this->database->select(self::TABLE, 'v')
        ->fields('v', ['uid'])
        ->distinct()
        ->countQuery()
        ->execute()
        ->fetchField();

      return $this->success('Total article viewers.', ['viewers' => $count]);
    });
  }

  /**
   * Loads a node and confirms it is a published, viewable article.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return \Drupal\node\NodeInterface
   *   The article.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If no such article exists or the current user cannot view it.
   */
  protected function loadArticle(int $nid): NodeInterface {
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager()->getStorage('node')->load($nid);

    if (!$node instanceof NodeInterface || $node->bundle() !== 'article' || !$node->access('view')) {
      throw new ApiException('Article not found.', 'article_not_found', 404);
    }

    return $node;
  }

}
