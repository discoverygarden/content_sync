<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\Content\ContentStorageComparer;
use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Construct the storage changes in a content synchronization form.
 */
class ContentSync extends FormBase {

  use ContentImportTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected StorageInterface $syncStorage,
    protected StorageInterface $activeStorage,
    protected ConfigManagerInterface $configManager,
    protected ContentSyncManagerInterface $contentSyncManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content.storage.sync'),
      $container->get('content.storage'),
      $container->get('config.manager'),
      $container->get('content_sync.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'content_admin_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) : array {
    // Validate site uuid unless bypass the validation is selected.
    $config = $this->config('content_sync.settings');
    if (!$config->get('content_sync.site_uuid_override')) {
      // Get site uuid from site settings configuration.
      $site_config = $this->config('system.site');
      $target = $site_config->get('uuid');
      // Get site uuid from content sync folder.
      $source = $this->syncStorage->read('site.uuid');
      if ($source && $source['site_uuid'] !== $target) {
        $this->messenger()->addError($this->t('The staged content cannot be imported, because it originates from a different site than this site. You can only synchronize content between cloned instances of this site.'));
        $form['actions']['#access'] = FALSE;
        return $form;
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import all'),
    ];

    // Check that there is something on the content sync folder.
    $storage_comparer = new ContentStorageComparer($this->syncStorage, $this->activeStorage);
    $storage_comparer->createChangelist();

    // Store the comparer for use in the submit.
    $form_state->set('storage_comparer', $storage_comparer);

    // Add the AJAX library to the form for dialog support.
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    foreach ($storage_comparer->getAllCollectionNames() as $collection) {

      foreach ($storage_comparer->getChangelist(NULL, $collection) as $config_change_type => $config_names) {
        if (empty($config_names)) {
          continue;
        }
        // @todo A table caption would be more appropriate, but does not have the
        //   visual importance of a heading.
        $form[$collection][$config_change_type]['heading'] = [
          '#type' => 'html_tag',
          '#tag' => 'h3',
        ];
        switch ($config_change_type) {
          case 'create':
            $form[$collection][$config_change_type]['heading']['#value'] = $collection . ' ' . $this->formatPlural(count($config_names), '@count new', '@count new');
            break;

          case 'update':
            $form[$collection][$config_change_type]['heading']['#value'] = $collection . ' ' . $this->formatPlural(count($config_names), '@count changed', '@count changed');
            break;

          case 'delete':
            $form[$collection][$config_change_type]['heading']['#value'] = $collection . ' ' . $this->formatPlural(count($config_names), '@count removed', '@count removed');
            break;

          case 'rename':
            $form[$collection][$config_change_type]['heading']['#value'] = $collection . ' ' . $this->formatPlural(count($config_names), '@count renamed', '@count renamed');
            break;
        }
        $form[$collection][$config_change_type]['list'] = [
          '#type' => 'table',
          '#header' => [$this->t('Name'), $this->t('Operations')],
        ];
        foreach ($config_names as $config_name) {
          if ($config_change_type == 'rename') {
            $names = $storage_comparer->extractRenameNames($config_name);
            $route_options = [
              'source_name' => $names['old_name'],
              'target_name' => $names['new_name'],
            ];
            $config_name = $this->t('@source_name to @target_name', [
              '@source_name' => $names['old_name'],
              '@target_name' => $names['new_name'],
            ]);
          }
          else {
            $route_options = ['source_name' => $config_name];
          }
          if ($collection !== StorageInterface::DEFAULT_COLLECTION) {
            $route_name = 'content.diff_collection';
            $route_options['collection'] = $collection;
          }
          else {
            $route_name = 'content.diff';
          }
          $links['view_diff'] = [
            'title' => $this->t('View differences'),
            'url' => Url::fromRoute($route_name, $route_options),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => json_encode([
                'width' => 700,
              ]),
            ],
          ];
          $form[$collection][$config_change_type]['list']['#rows'][] = [
            'name' => $config_name,
            'operations' => [
              'data' => [
                '#type' => 'operations',
                '#links' => $links,
              ],
            ],
          ];
        }
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) : void {
    $comparer = $form_state->get('storage_comparer');
    $collections = $comparer->getAllCollectionNames();
    // Set Batch to process the files from the content directory.
    // Get the files to be processed.
    $contents_to_sync = [];
    $contents_to_delete = [];
    foreach ($collections as $collection_name) {
      $actions = $comparer->getChangeList("", $collection_name);
      if (!empty($actions['create'])) {
        $contents_to_sync[] = $actions['create'];
      }
      if (!empty($actions['update'])) {
        $contents_to_sync[] = $actions['update'];
      }
      if (!empty($actions['delete'])) {
        $contents_to_delete[] = $actions['delete'];
      }
    }

    $content_to_sync = array_unique(array_merge(...$contents_to_sync));
    $content_to_delete = array_unique(array_merge(...$contents_to_delete));

    $serializer_context = [];
    $batch = $this->generateImportBatch($content_to_sync, $content_to_delete, $serializer_context);
    batch_set($batch);
  }

}
