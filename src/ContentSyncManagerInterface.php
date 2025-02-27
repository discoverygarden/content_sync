<?php

namespace Drupal\content_sync;

use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\content_sync\Importer\ContentImporterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Manager interface for content_sync.
 */
interface ContentSyncManagerInterface {

  const DEFAULT_DIRECTORY = 'sync';

  /**
   * Get the content importer.
   */
  public function getContentImporter() : ContentImporterInterface;

  /**
   * Get the content exporter.
   */
  public function getContentExporter() : ContentExporterInterface;

  /**
   * Get the serializer.
   */
  public function getSerializer() : Serializer;

  /**
   * Get the entity type manager.
   */
  public function getEntityTypeManager() : EntityTypeManagerInterface;

}
