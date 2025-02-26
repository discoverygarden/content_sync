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
   * @return \Drupal\content_sync\Exporter\ContentExporterInterface
   */
  public function getContentExporter() : ContentExporterInterface {
    return $this->contentExporter;
  }

  /**
   * @return \Drupal\content_sync\Importer\ContentImporterInterface
   */
  public function getContentImporter() : ContentImporterInterface {
    return $this->contentImporter;
  }


  /**
   * @param $file_names
   * @param $directory
   *
   * @return array
   */
  public function generateImportQueue($file_names, $directory) {
    $queue = [];
    foreach ($file_names as $file) {
      $ids = explode('.', $file);
      [$entity_type_id, $bundle, $uuid] = $ids + ['', '', ''];
      $file_path = $directory . "/" . $entity_type_id . "/" . $bundle . "/" . $file . ".yml";
      if (!file_exists($file_path) || !$this->isValidFilename($file)) {
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
   * @param $file_names
   * @param $directory
   *
   * @return array
   */
  public function generateExportQueue($decoded_entities, $visited) {
    $queue = [];
    if (!empty($decoded_entities)) {
      $queue = $this->exportResolver->resolve($decoded_entities, $visited);
    }
    return $queue;
  }

  /**
   * @return \Symfony\Component\Serializer\Serializer
   */
  public function getSerializer() {
    return $this->serializer;
  }

  /**
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * Checks filename structure
   *
   * @param $filename
   *
   * @return bool
   */
  protected function isValidFilename($filename) {
    $parts = explode(static::DELIMITER, $filename);
    return count($parts) === 3;
  }

}
