<?php

namespace Drupal\content_sync;

use Drupal\content_sync\DependencyResolver\ContentSyncResolverInterface;
use Drupal\content_sync\DependencyResolver\ImportQueueResolver;
use Drupal\content_sync\DependencyResolver\ExportQueueResolver;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\content_sync\Importer\ContentImporterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Serializer;

/**
 * Manager service for content_sync.
 */
class ContentSyncManager implements ContentSyncManagerInterface {

  use AutowireTrait;

  const DELIMITER = '.';

  /**
   * Constructor.
   */
  public function __construct(
    protected Serializer $serializer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ContentExporterInterface $contentExporter,
    protected ContentImporterInterface $contentImporter,
    #[Autowire(service: 'content_sync.resolver.export')]
    protected ContentSyncResolverInterface|ExportQueueResolver $exportResolver,
    #[Autowire(service: 'content_sync.resolver.import')]
    protected ContentSyncResolverInterface|ImportQueueResolver $importResolver,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function getContentExporter() : ContentExporterInterface {
    return $this->contentExporter;
  }

  /**
   * {@inheritDoc}
   */
  public function getContentImporter() : ContentImporterInterface {
    return $this->contentImporter;
  }

  /**
   * Generate import queue.
   *
   * @param iterable $file_names
   *   Iterable of filenames to enqueue.
   * @param string $directory
   *   Directory in which the files exist.
   *
   * @return array
   *   An array of items to import.
   */
  public function generateImportQueue(iterable $file_names, string $directory) : array {
    $queue = [];
    foreach ($file_names as $file) {
      $ids = explode('.', $file);
      [$entity_type_id, $bundle] = $ids + ['', ''];
      $file_path = $directory . "/" . $entity_type_id . "/" . $bundle . "/" . $file . ".yml";
      if (!file_exists($file_path) || !static::isValidFilename($file)) {
        continue;
      }
      $content = file_get_contents($file_path);
      $format = $this->contentImporter->getFormat();
      $decoded_entity = $this->serializer->decode($content, $format);
      $decoded_entities[$file] = $decoded_entity;
    }
    if (!empty($decoded_entities)) {
      $queue = $this->importResolver->resolve($decoded_entities);
    }
    return $queue;
  }

  /**
   * Generate export queue.
   *
   * @param array $decoded_entities
   *   File names to enqueue.
   * @param array $visited
   *   Already touched entities, when resolving dependencies.
   *
   * @return array
   *   Array of entity names to export.
   */
  public function generateExportQueue(array $decoded_entities, array $visited) : array {
    $queue = [];
    if (!empty($decoded_entities)) {
      $queue = $this->exportResolver->resolve($decoded_entities, $visited);
    }
    return $queue;
  }

  /**
   * {@inheritDoc}
   */
  public function getSerializer() : Serializer {
    return $this->serializer;
  }

  /**
   * {@inheritDoc}
   */
  public function getEntityTypeManager() : EntityTypeManagerInterface {
    return $this->entityTypeManager;
  }

  /**
   * Checks filename structure.
   *
   * @param string $filename
   *   The filename to check.
   *
   * @return bool
   *   TRUE if valid; otherwise, FALSE.
   */
  protected static function isValidFilename(string $filename) : bool {
    $parts = explode(static::DELIMITER, $filename);
    return count($parts) === 3;
  }

}
