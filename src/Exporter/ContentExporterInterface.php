<?php

namespace Drupal\content_sync\Exporter;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Content sync exporter interface.
 */
interface ContentExporterInterface {

  /**
   * Exports the given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to be exported.
   * @param array $context
   *   Content regarding serialization.
   *
   * @return string
   *   YAML-encoded entity.
   */
  public function exportEntity(ContentEntityInterface $entity, array $context = []) : string;

}
