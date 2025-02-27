<?php

namespace Drupal\content_sync\Importer;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Content importer service interface.
 */
interface ContentImporterInterface {

  /**
   * Import entity.
   *
   * @param array $decoded_entity
   *   The data describing the entity to create/import.
   * @param array $context
   *   Context around the import operation.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The loaded, imported entity on success; otherwise, NULL.
   */
  public function importEntity(array $decoded_entity, array $context = []) : ?ContentEntityInterface;

}
