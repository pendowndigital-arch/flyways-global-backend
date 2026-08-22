<?php

namespace Drupal\user_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Derives a Drupal username from an email address.
 *
 * Clients sign up with an email address only, so the username has to be
 * produced here. The address is used verbatim whenever Drupal will accept it,
 * which keeps the admin UI readable, and a generated name is substituted when
 * it will not. Substitution is needed more often than it looks:
 *
 * - usernames are capped at 60 characters while addresses run to 254, so long
 *   corporate addresses do not fit;
 * - usernames allow a narrower character set than email, so an address
 *   containing "!" or "#" is deliverable but not a legal username;
 * - the address may already be in use as a *username* while being free as an
 *   address, which happens once anybody changes their email: their old address
 *   stays behind as their username and would otherwise block the next person
 *   who signs up with it.
 *
 * Uniqueness of the address itself is deliberately not handled here. The mail
 * field carries its own unique constraint, so a genuine repeat signup is still
 * rejected -- and now reported against "mail", which is what the user typed.
 */
class UsernameGenerator {

  /**
   * Prefix for generated usernames.
   */
  const FALLBACK_PREFIX = 'user_';

  /**
   * Attempts allowed when generating a unique fallback.
   */
  const MAX_ATTEMPTS = 10;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Derives a usable username from an email address.
   *
   * @param string $mail
   *   The email address.
   *
   * @return string
   *   The address itself, or a generated username.
   */
  public function fromEmail(string $mail): string {
    return $this->isUsable($mail) ? $mail : $this->generate();
  }

  /**
   * Checks whether a string can be stored as this site's username.
   *
   * Core's own constraints are run rather than reimplemented, so length, the
   * permitted character set and uniqueness all stay in step with Drupal.
   *
   * @param string $candidate
   *   The candidate username.
   *
   * @return bool
   *   TRUE if Drupal would accept it.
   */
  protected function isUsable(string $candidate): bool {
    if ($candidate === '') {
      return FALSE;
    }

    $probe = $this->entityTypeManager->getStorage('user')->create(['name' => $candidate]);

    return $probe->get('name')->validate()->count() === 0;
  }

  /**
   * Generates a random username that is free and always legal.
   *
   * @return string
   *   The generated username.
   *
   * @throws \RuntimeException
   *   If no free name could be found, which needs 64-bit collisions to happen.
   */
  protected function generate(): string {
    $storage = $this->entityTypeManager->getStorage('user');

    for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
      // Hex keeps the result inside the username character set, and 21
      // characters total stays well under the 60 character limit.
      $candidate = self::FALLBACK_PREFIX . bin2hex(random_bytes(8));
      if (!$storage->loadByProperties(['name' => $candidate])) {
        return $candidate;
      }
    }

    throw new \RuntimeException('Could not generate a unique username.');
  }

}
