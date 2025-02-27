<?php

namespace Drupal\content_sync\DependencyResolver;

use Drupal\content_sync\Content\DatabaseStorageInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Class ImportQueueResolver.
 *
 * @package Drupal\content_sync\DependencyResolver
 */
class ExportQueueResolver extends AbstractResolver {

  use AutowireTrait;

  /**
   * Constructor.
   */
  public function __construct(
    #[Autowire(service: 'content.storage.active')]
    protected DatabaseStorageInterface $storage,
  ) {}

  /**
   * {@inheritDoc}
   */
  protected function getEntity(string $identifier, array $normalized_entities) : array|false {
    if (!empty($normalized_entities[$identifier])) {
      $entity = $normalized_entities[$identifier];
    }
    else {
      $entity = $this->storage->contentSyncRead($identifier);
    }
    return $entity;
  }

}
