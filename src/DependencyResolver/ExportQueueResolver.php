<?php

namespace Drupal\content_sync\DependencyResolver;

use Drupal\content_sync\Content\ContentDatabaseStorage;

/**
 * Class ImportQueueResolver.
 *
 * @package Drupal\content_sync\DependencyResolver
 */
class ExportQueueResolver implements ContentSyncResolverInterface {

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
  protected function depthFirstSearch(array &$visited, array $identifiers, array $normalized_entities, array $serializer_context, int $depth = 0) {
    foreach ($identifiers as $identifier) {
      if (isset($visited[$identifier])) {
        // Already accounted for; skip.
        continue;
      }
      if (
        $depth > 0 &&
        // Export not targeting specific entities...
        empty($serializer_context['batch_info']['uuids']) &&
        // Export targeting some set of entity types, so let's avoid visiting
        // these types here, as they should be visited on their own.
        (isset($serializer_context['batch_info']['entity_types']) && !empty($serializer_context['batch_info']['entity_types']))
      ) {
        [$entity_type, ] = explode('.', $identifier, 2);
        if (in_array($entity_type, $serializer_context['batch_info']['entity_types'])) {
          continue;
        }
      }

      $visited[$identifier] = $identifier;

      // Get a decoded entity.
      $entity = $this->getEntity($identifier, $normalized_entities);

      // Process dependencies first.
      if (!empty($entity['_content_sync']['entity_dependencies'])) {
        foreach ($entity['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
          $this->depthFirstSearch($visited, $references, $normalized_entities, $serializer_context, $depth + 1);
        }
      }

      // Process translations' dependencies if any.
      if (!empty($entity["_translations"])) {
        foreach ($entity["_translations"] as $translation) {
          if (!empty($translation['_content_sync']['entity_dependencies'])) {
            foreach ($translation['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
              $this->depthFirstSearch($visited, $references, $normalized_entities, $serializer_context, $depth + 1);
            }
          }
        }
      }


    }
  }

  /**
   * Gets an entity.
   *
   * @param $identifier
   *   An entity identifier to process.
   * @param $normalized_entities
   *   An array of entity identifiers to process.
   *
   * @return bool|array
   *   Array of entity data to export or FALSE if no entity found (db error).
   */
  protected function getEntity($identifier, $normalized_entities) {
    if (!empty($normalized_entities[$identifier])) {
      $entity = $normalized_entities[$identifier];
    }
    else {
      $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
      $entity = $activeStorage->cs_read($identifier);
    }
    return $entity;
  }

  /**
   * Creates a queue.
   *
   * @param array $normalized_entities
   *   Parsed entities to import.
   * @param array $visited
   *   Associative array mapping visited identifiers to a value... either the
   *   identifier proper, or an array containing:
   *   - entity_type: The type of entity; and,
   *   - entity_uuid: The UUID of the entity.
   * @param array $serializer_context
   *   Array of serializer context.
   *
   * @return array
   *   Queue to be processed within a batch process.
   */
  public function resolve(array $normalized_entities, $visited = [], array $serializer_context = []) {
    foreach ($normalized_entities as $identifier => $entity) {
      $this->depthFirstSearch($visited, [$identifier], $normalized_entities, $serializer_context);
    }

    return $visited;
  }

}
