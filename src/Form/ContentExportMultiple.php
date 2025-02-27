<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Multi-export confirm form.
 */
class ContentExportMultiple extends ConfirmFormBase {

  use ContentExportTrait;

  /**
   * List on entities pulled from temp store in building and used in submit.
   *
   * @var array
   */
  protected array $entityList = [];

  /**
   * Constructor.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ContentSyncManagerInterface $contentSyncManager,
    protected array $formats,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('content_sync.manager'),
      $container->getParameter('serializer.formats'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_sync_export_multiple_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() : TranslatableMarkup {
    return $this->formatPlural(count($this->entityList), 'Are you sure you want to export this item?', 'Are you sure you want to export these items?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('system.admin_content');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Export');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) : array|RedirectResponse {
    $this->entityList = $this->tempStoreFactory->get('content_sync_ui_multiple_confirm')
      ->get($this->currentUser()->id());

    if (empty($this->entityList)) {
      return new RedirectResponse($this->getCancelUrl()
        ->setAbsolute()
        ->toString());
    }

    // List of items to export.
    $items = [];
    foreach ($this->entityList as $uuid => $entity_info) {
      $storage = $this->entityTypeManager->getStorage($entity_info['entity_type']);
      $entity = $storage->load($entity_info['entity_id']);
      if (!empty($entity)) {
        $items[$uuid] = $entity->label();
      }
    }
    $form['content_list'] = [
      '#theme' => 'item_list',
      '#title' => 'Content List.',
      '#items' => $items,
    ];

    $form = parent::buildForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) : void {

    if ($form_state->getValue('confirm') && !empty($this->entityList)) {
      // Delete the content tar file in case an older version exist.
      $this->fileSystem->delete($this->getTempFile());

      $entities_list = [];
      foreach ($this->entityList as $entity_info) {
        $entities_list[] = [
          'entity_type' => $entity_info['entity_type'],
          'entity_id' => $entity_info['entity_id'],
        ];
      }
      if (!empty($entities_list)) {
        $batch = $this->generateExportBatch($entities_list);
        batch_set($batch);
      }
    }
    else {
      $form_state->setRedirect('system.admin_content');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityTypeManager() : EntityTypeManagerInterface {
    return $this->entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getContentExporter() : ContentExporterInterface {
    return $this->contentSyncManager->getContentExporter();
  }

  /**
   * {@inheritdoc}
   */
  protected function getExportLogger() : LoggerInterface {
    return $this->logger('content_sync');
  }

}
