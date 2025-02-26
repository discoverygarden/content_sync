<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the content export form.
 */
class ContentExportForm extends FormBase {

  use ContentExportTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ContentExporterInterface $contentExporter,
    protected ContentSyncManagerInterface $contentSyncManager,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) : static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('content_sync.exporter'),
      $container->get('content_sync.manager'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'content_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) : array {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) : void {
    // Delete the content tar file in case an older version exist.
    $this->fileSystem->delete($this->getTempFile());

    // Set batch operations by entity type/bundle.
    $serializer_context['export_type'] = 'tar';
    $serializer_context['include_files'] = 'folder';
    $batch = $this->generateExportBatch($this->generateEntities(), $serializer_context);

    // Avoid kicking off batch if there were no items enqueued.
    if ($this->getExportQueue()->numberOfItems() > 0) {
      batch_set($batch);
    }
  }

  /**
   * Trigger snapshot batch.
   */
  public function snapshot() : void {
    // Set batch operations by entity type/bundle.
    $serializer_context['export_type'] = 'snapshot';
    $batch = $this->generateExportBatch($this->generateEntities(FALSE), $serializer_context);

    // Avoid kicking off batch if there were no items enqueued.
    if ($this->getExportQueue()->numberOfItems() > 0) {
      batch_set($batch);
    }
  }

  /**
   * Helper; generate ALL content entities.
   *
   * @param bool $access_check
   *   TRUE (default) to perform access checking; FALSE to disable access
   *   checking.
   *
   * @return \Traversable
   *   ALL content entities.
   */
  protected function generateEntities(bool $access_check = TRUE) : \Traversable {
    $entity_type_definitions = $this->entityTypeManager->getDefinitions();
    foreach ($entity_type_definitions as $entity_type => $definition) {
      if (!$definition instanceof ContentEntityInterface) {
        continue;
      }
      $entities = $this->entityTypeManager->getStorage($entity_type)
        ->getQuery()
        ->accessCheck($access_check)
        ->execute();
      foreach ($entities as $entity_id) {
        yield [
          'entity_type' => $entity_type,
          'entity_id' => $entity_id,
        ];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityTypeManager(): EntityTypeManagerInterface {
    return $this->entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getContentExporter(): ContentExporterInterface {
    return $this->contentExporter;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExportLogger(): LoggerInterface {
    return $this->logger('content_sync');
  }

}
