<?php

namespace Drupal\content_sync\DependencyResolver;

/**
 * Dependency resolver interface for content_sync.
 */
interface ContentSyncResolverInterface {

  /**
   * Resolve dependencies.
   *
   * @param array $entities
   *   The entities to resolve.
   * @param array $visited
   *   Reference to array of visited entities.
   *
   * @return array
   *   The fully built-out array of entities to manage.
   */
  public function resolve(array $entities, array &$visited = []) : array;

}
