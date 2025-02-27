<?php

namespace Drupal\content_sync\DependencyResolver;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Serialization\Yaml;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Import queue resolver.
 */
class ImportQueueResolver extends AbstractResolver {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'database')]
    protected Connection $database,
  ) {}

  /**
   * Gets an entity.
   *
   * @param string $identifier
   *   An entity identifier to process.
   * @param array $normalized_entities
   *   An array of entity identifiers to process.
   *
   * @return bool|mixed
   *   Decoded entity or FALSE if an entity already exists and doesn't require
   *   to be imported.
   *
   * @throws \Exception
   */
  protected function getEntity(string $identifier, array $normalized_entities) : array|false {
    if (!empty($normalized_entities[$identifier])) {
      $entity = $normalized_entities[$identifier];
    }
    else {
      [$entity_type_id, $bundle] = explode('.', $identifier);
      $file_path = content_sync_get_content_directory(ContentSyncManagerInterface::DEFAULT_DIRECTORY) . "/entities/" . $entity_type_id . "/" . $bundle . "/" . $identifier . ".yml";
      $raw_entity = file_get_contents($file_path);

      // Problems to open the .yml file.
      if (!$raw_entity) {
        return FALSE;
      }

      $entity = Yaml::decode($raw_entity);
    }
    return $entity;
  }

  /**
   * {@inheritDoc}
   */
  public function resolve(array $normalized_entities, array &$visited = []) : array {
    // Reverse the array to adjust it to an array_pop-driven iterator.
    return array_reverse(parent::resolve($normalized_entities, $visited));
  }

}
