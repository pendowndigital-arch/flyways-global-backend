<?php

namespace Drupal\user_api;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\simple_oauth\TokenExpiryTriggerHandlerInterface;
use Drupal\user\UserInterface;

/**
 * Keeps a session alive across harmless profile edits.
 *
 * simple_oauth deletes every token belonging to an account whenever that
 * account is saved, which is the right reflex after a credential change but
 * makes a profile endpoint unusable: editing a phone number would sign the
 * user out of every device.
 *
 * This decorator honours a flag that
 * \Drupal\user_api\Controller\ProfileController sets when the update it just
 * performed touched nothing security-sensitive. Every other code path -- an
 * administrator editing the account, drush, other modules, and any update that
 * did change the email address or password -- still clears the tokens.
 */
class TokenExpiryTriggerHandler implements TokenExpiryTriggerHandlerInterface {

  /**
   * Entity property that marks an update as safe for existing tokens.
   */
  const PRESERVE_TOKENS_FLAG = 'userApiPreserveTokens';

  public function __construct(
    protected TokenExpiryTriggerHandlerInterface $inner,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function handleUserUpdate(UserInterface $user): void {
    if (!empty($user->{self::PRESERVE_TOKENS_FLAG})) {
      return;
    }

    $this->inner->handleUserUpdate($user);
  }

  /**
   * {@inheritdoc}
   */
  public function handleConsumerUpdate(ConsumerInterface $consumer): void {
    $this->inner->handleConsumerUpdate($consumer);
  }

  /**
   * {@inheritdoc}
   */
  public function handleCron(): void {
    $this->inner->handleCron();
  }

}
