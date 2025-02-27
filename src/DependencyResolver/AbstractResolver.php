<?php

namespace Drupal\content_sync\DependencyResolver;

/**
 * Abstract base dependency resolver.
 */
abstract class AbstractResolver implements ContentSyncResolverInterface {

  /**
   * Builds a graph placing the deepest vertexes at the first place.
   *
   * @param array $visited
   *   Array of vertexes to return.
   * @param array $identifiers
   *   Array of entity identifiers to process.
   * @param array $normalized_entities
   *   Parsed entities to import.
   */
  protected function depthFirstSearch(array &$visited, array $identifiers, array $normalized_entities) : void {
    foreach ($identifiers as $identifier) {
      if (isset($visited[$identifier])) {
        // Already accounted for; skip.
        continue;
      }

      $visited[$identifier] = TRUE;

      // Get a decoded entity.
      $entity = $this->getEntity($identifier, $normalized_entities);

      if (!$entity) {
        $visited['Missing'][$identifier] = TRUE;
        continue;
      }

      // Process dependencies first.
      if (!empty($entity['_content_sync']['entity_dependencies'])) {
        foreach ($entity['_content_sync']['entity_dependencies'] as $references) {
          $this->depthFirstSearch($visited, $references, $normalized_entities);
        }
      }

      // Process translations' dependencies if any.
      if (!empty($entity["_translations"])) {
        foreach ($entity["_translations"] as $translation) {
          if (!empty($translation['_content_sync']['entity_dependencies'])) {
            foreach ($translation['_content_sync']['entity_dependencies'] as $references) {
              $this->depthFirstSearch($visited, $references, $normalized_entities);
            }
          }
        }
      }

    }
  }

  /**
   * Gets an entity.
   *
   * @param string $identifier
   *   An entity identifier to process.
   * @param array $normalized_entities
   *   An array of entity identifiers to process.
   *
   * @return bool|array
   *   Array of entity data to manage or FALSE if no entity found (db error).
   */
  abstract protected function getEntity(string $identifier, array $normalized_entities) : array|false;

  /**
   * {@inheritDoc}
   */
  public function resolve(array $normalized_entities, array &$visited = []) : array {
    $this->depthFirstSearch($visited, array_keys($normalized_entities), $normalized_entities);

    return $visited;
  }

}
