<?php

namespace Drupal\content_sync\Drush\Commands;

use Consolidation\AnnotatedCommand\Attributes\HookSelector;
use Drupal\content_sync\Form\ContentExportForm;
use Drush\Commands\AutowireTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\content_sync\Form\ContentExportTrait;
use Drupal\content_sync\Form\ContentImportTrait;
use Drupal\Core\Config\FileStorage;
use Drupal\content_sync\Content\ContentStorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Path;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class ContentSyncCommands extends DrushCommands {

  use AutowireTrait;
  use ContentExportTrait;
  use ContentImportTrait;
  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Gets the contentStorageSync.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The contentStorageSync.
   */
  public function getContentStorageSync(): StorageInterface {
    return $this->contentStorageSync;
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
    return $this->contentSyncManager->getContentExporter();
  }

  /**
   * {@inheritdoc}
   */
  protected function getExportLogger(): LoggerInterface {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    return $this->logger() ?? \Drupal::logger('content_sync');
  }

  /**
   * Constructor.
   */
  public function __construct(
    #[Autowire(service: 'content.storage')]
    protected StorageInterface $contentStorage,
    #[Autowire(service: 'content.storage.sync')]
    protected StorageInterface $contentStorageSync,
    #[Autowire(service: 'content_sync.manager')]
    protected ContentSyncManagerInterface $contentSyncManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'event_dispatcher')]
    protected EventDispatcherInterface $eventDispatcher,
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    protected TypedConfigManagerInterface $configTyped,
    protected ModuleInstallerInterface $moduleInstaller,
    protected ThemeHandlerInterface $themeHandler,
    #[Autowire(service: 'content_sync.snaphoshot')]
    protected ContentExportForm $snapshot,
    #[Autowire(service: 'logger.channel.content_sync')]
    LoggerInterface $logger,
  ) {
    parent::__construct();
    $this->setLogger($logger);
  }

  /**
   * Select a content directory to work with.
   *
   * If there are multiple content directories, we need to get the the 'label'
   * that matches the key in $content_directories from settings.php. That
   * determines which directory we are either importing from, or exporting to.
   *
   * @hook interact @interact-content-label
   */
  public function interactContentLabel(InputInterface $input, ConsoleOutputInterface $output) : void {
    // phpcs:ignore Drupal.NamingConventions.ValidGlobal.GlobalUnderScore
    global $content_directories;

    if (empty($input->getArgument('label'))) {
      $keys = array_keys($content_directories);
      $choices = array_combine($keys, $keys);
      if (count($choices) >= 2) {
        $label = $this->io()->choice('Choose a content_sync directory:', $choices);
      }
      else {
        $label = ContentSyncManagerInterface::DEFAULT_DIRECTORY;
      }
      $input->setArgument('label', $label);
    }
  }

  /**
   * Import content from a content directory.
   *
   * @param string|null $label
   *   A content directory label (i.e. a key in \$content_directories array in
   *   settings.php).
   * @param array $options
   *   The command options.
   *
   * @command content-sync:import
   * @interact-content-label
   * @option entity-types A list of entity type names separated by commas.
   * @option uuids A list of UUIDs separated by commas.
   * @option actions A list of Actions separated by commas.
   * @option skiplist skip the change list before proceed with the import.
   * @usage drush content-sync-import.
   * @aliases csi,content-sync-import
   */
  public function import(
    ?string $label = NULL,
    array $options = [
      'entity-types' => '',
      'uuids' => '',
      'actions' => '',
      'skiplist' => FALSE,
    ],
  ) : void {

    // Determine source directory.
    $source_storage_dir = content_sync_get_content_directory($label);
    // Prepare content storage for the import.
    if ($label == ContentSyncManagerInterface::DEFAULT_DIRECTORY) {
      $source_storage = $this->getContentStorageSync();
    }
    else {
      $source_storage = new FileStorage($source_storage_dir);
    }

    // Generate comparer with filters.
    $storage_comparer = new ContentStorageComparer($source_storage, $this->contentStorage);
    $change_list = [];
    $collections = $storage_comparer->getAllCollectionNames();
    if (!empty($options['entity-types'])) {
      $entity_types = explode(',', $options['entity-types']);
      $match_collections = [];
      foreach ($entity_types as $entity_type) {
        $match_collections = $match_collections + preg_grep('/^' . $entity_type . '/', $collections);
      }
      $collections = $match_collections;
    }
    foreach ($collections as $collection) {
      if (!empty($options['uuids'])) {
        $storage_comparer->createChangelistbyCollectionAndNames($collection, $options['uuids']);
      }
      else {
        $storage_comparer->createChangelistbyCollection($collection);
      }
      if (!empty($options['actions'])) {
        $actions = explode(',', $options['actions']);
        foreach ($actions as $op) {
          if (in_array($op, ['create', 'update', 'delete'])) {
            $change_list[$collection][$op] = $storage_comparer->getChangelist($op, $collection);
          }
        }
      }
      else {
        $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
      }
      $change_list = array_map('array_filter', $change_list);
      $change_list = array_filter($change_list);
    }
    unset($change_list['']);

    // Display the change list.
    if (empty($options['skiplist'])) {
      // Show differences.
      $this->output()->writeln("Differences of the export directory to the active content:\n");
      // Print a table with changes in color.
      $table = self::contentChangesTable($change_list, $this->output());
      $table->render();
      // Ask to continue.
      if (!$this->io()->confirm(dt('Do you want to import?'))) {
        throw new UserAbortException();
      }
    }
    // Process the Import Data.
    $contents_to_sync = [];
    $contents_to_delete = [];
    foreach ($change_list as $collection => $actions) {
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

    $content_to_sync = array_merge(...$contents_to_sync);
    $content_to_delete = array_merge(...$contents_to_delete);

    // Set the Import Batch.
    if (!empty($content_to_sync) || !empty($content_to_delete)) {
      $batch = $this->generateImportBatch(
        $content_to_sync,
        $content_to_delete,
        [
          'content_sync_directory' => $source_storage_dir,
        ]
      );
      batch_set($batch);
      drush_backend_batch_process();
    }
  }

  /**
   * Export Drupal content to a directory.
   *
   * @param string|null $label
   *   A content directory label (i.e. a key in $content_directories array in
   *   settings.php).
   * @param array $options
   *   The command options.
   *
   * @command content-sync:export
   * @interact-content-label
   * @option entity-types A list of entity type names separated by commas.
   * @option uuids A list of UUIDs separated by commas.
   * @option actions A list of Actions separated by commas.
   * @option files A value none/base64/folder  -  default folder.
   * @option include-dependencies export content dependencies.
   * @option skiplist skip the change list before proceed with the export.
   * @usage drush content-sync-export
   * @aliases cse,content-sync-export
   */
  public function export(
    ?string $label = NULL,
    array $options = [
      'entity-types' => '',
      'uuids' => '',
      'actions' => '',
      'files' => 'folder',
      'include-dependencies' => FALSE,
      'skiplist' => FALSE,
    ],
  ) : void {

    // Determine destination directory.
    $destination_dir = Path::canonicalize(\content_sync_get_content_directory($label));
    // Prepare content storage for the export.
    if ($label == ContentSyncManagerInterface::DEFAULT_DIRECTORY) {
      $target_storage = $this->getContentStorageSync();
    }
    else {
      $target_storage = new FileStorage($destination_dir);
    }

    // Generate comparer with filters.
    $storage_comparer = new ContentStorageComparer($this->contentStorage, $target_storage);
    $change_list = [];
    $collections = $storage_comparer->getAllCollectionNames();
    if (!empty($options['entity-types'])) {
      $entity_types = explode(',', $options['entity-types']);
      $match_collections = [];
      foreach ($entity_types as $entity_type) {
        $match_collections = $match_collections + preg_grep('/^' . $entity_type . '/', $collections);
      }
      $collections = $match_collections;
    }
    foreach ($collections as $collection) {
      if (!empty($options['uuids'])) {
        $storage_comparer->createChangelistbyCollectionAndNames($collection, $options['uuids']);
      }
      else {
        $storage_comparer->createChangelistbyCollection($collection);
      }
      if (!empty($options['actions'])) {
        $actions = explode(',', $options['actions']);
        foreach ($actions as $op) {
          if (in_array($op, ['create', 'update', 'delete'])) {
            $change_list[$collection][$op] = $storage_comparer->getChangelist($op, $collection);
          }
        }
      }
      else {
        $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
      }
      $storage_comparer->resetCollectionChangelist($collection);
      $change_list = array_map('array_filter', $change_list);
      $change_list = array_filter($change_list);
    }
    unset($change_list['']);

    // Display the change list.
    if (empty($options['skiplist'])) {
      // Show differences.
      $this->output()->writeln("Differences of the active content to the export directory:\n");
      // Print a table with changes in color.
      $table = self::contentChangesTable($change_list, $this->output());
      $table->render();
      // Ask to continue.
      if (!$this->io()->confirm(dt('Do you want to export?'))) {
        throw new UserAbortException();
      }
    }

    // Process the Export.
    foreach ($change_list as $collection => $changes) {
      // $storage_comparer->getTargetStorage($collection)->deleteAll();
      foreach ($changes as $change => $contents) {
        switch ($change) {
          case 'delete':
            foreach ($contents as $content) {
              $storage_comparer->getTargetStorage($collection)->delete($content);
            }
            break;

          case 'update':
          case 'create':
            foreach ($contents as $content) {
              $this->getExportQueue()->createItem($content);
            }
            break;
        }
      }
    }
    unset($change_list);
    unset($storage_comparer);

    // Files options.
    $include_files = self::processFilesOption($options);
    // Set the Export Batch.
    if ($this->getExportQueue()->numberOfItems() > 0) {
      $batch = $this->generateExportBatch([], [
        'export_type' => 'folder',
        'include_files' => $include_files,
        'include_dependencies' => $options['include-dependencies'],
        'content_sync_directory' => $destination_dir,
      ]);
      batch_set($batch);
      drush_backend_batch_process();
    }
  }

  /**
   * Builds a table of content changes.
   *
   * @param array $content_changes
   *   An array of changes keyed by collection.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param bool $use_color
   *   If it should use color.
   *
   * @return \Symfony\Component\Console\Helper\Table
   *   A Symfony table object.
   */
  public static function contentChangesTable(array $content_changes, OutputInterface $output, bool $use_color = TRUE): Table {
    $rows = [];
    foreach ($content_changes as $collection => $changes) {
      if (is_array($changes)) {
        foreach ($changes as $change => $contents) {
          switch ($change) {
            case 'delete':
              $colour = '<fg=white;bg=red>';
              break;

            case 'update':
              $colour = '<fg=black;bg=yellow>';
              break;

            case 'create':
              $colour = '<fg=white;bg=green>';
              break;

            default:
              $colour = "<fg=black;bg=cyan>";
              break;
          }
          if ($use_color) {
            $prefix = $colour;
            $suffix = '</>';
          }
          else {
            $prefix = $suffix = '';
          }
          foreach ($contents as $content) {
            $rows[] = [
              $collection,
              $content,
              $prefix . ucfirst($change) . $suffix,
            ];
          }
        }
      }
    }
    $table = new Table($output);
    $table->setHeaders(['Collection', 'Content Name', 'Operation']);
    $table->addRows($rows);
    return $table;
  }

  /**
   * Processes 'files' option.
   *
   * @param array $options
   *   The command options.
   *
   * @return string
   *   Processed 'files' option value.
   */
  public static function processFilesOption(array $options) : string {
    $include_files = !empty($options['files']) ? $options['files'] : 'folder';
    if (!in_array($include_files, ['folder', 'base64'])) {
      $include_files = 'none';
    }
    return $include_files;
  }

  /**
   * Helper that rebuilds the snapshot table.
   */
  #[CLI\Command(name: 'content-sync:snapshot', aliases: ['cs:s'])]
  #[HookSelector(name: 'islandora-drush-utils-user-wrap')]
  public function buildSnapshot() : void {
    $this->logger->notice('Building snapshot...');
    $this->snapshot->snapshot();
    $items = $this->snapshot->getExportQueue()->numberOfItems();
    $this->io()->info($this->formatPlural($items, 'Found 1 item to snapshot.', 'Found @count items to snapshot.'));
    if ($items > 0) {
      $this->io()->info('Starting batch...');
      drush_backend_batch_process();
      return;
    }
    $this->io()->info('Skipping batch.');
  }

}
