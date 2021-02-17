<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\content_sync\Content\ContentDatabaseStorage;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;

/**
 * Defines the content export form.
 */
trait ContentExportTrait {

  /**
   * Define the export queue prefix.
   *
   * XXX: Would be a constant; however, traits are not allowed to define them.
   *
   * @return string
   *   The queue prefix to use.
   */
  public static function getExportQueuePrefix() {
    return 'content_sync_export';
  }

  /**
   * @var ArchiveTar
   */
  protected $archiver;

  /**
   * The queue in which to keep the items to export prior to processing.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $exportQueue;

  /**
   * Lazy accessor for the export queue.
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   The export queue.
   */
  public function getExportQueue() {
    if (!isset($this->exportQueue)) {
      $uuid = \Drupal::service('uuid')->generate();
      $this->exportQueue = \Drupal::queue(static::getExportQueuePrefix() . ":{$uuid}", TRUE);
    }
    return $this->exportQueue;
  }

  /**
   * @param $entities
   *
   * @param $serializer_context
   * export_type:
   * Tar -> YML to Tar file
   * Snapshot -> YML to content_sync table.
   * Directory -> YML to content_sync_directory_entities
   *
   * content_sync_directory:
   * path for the content sync directory.
   *
   * content_sync_directory_entities:
   * path for the content sync entities directory.
   *
   * content_sync_directory_files:
   * path to store media/files.
   *
   * content_sync_file_base_64:
   * Include file as a data in the YAML.
   *
   * @return array
   */
  public function generateExportBatch($entities = [], $serializer_context = []) {
    if (!isset($serializer_context['content_sync_directory'])) {
      $serializer_context['content_sync_directory'] = content_sync_get_content_directory(ContentSyncManagerInterface::DEFAULT_DIRECTORY);
    }
    $serializer_context['content_sync_directory_entities'] = $serializer_context['content_sync_directory'] . "/entities";
    if (isset($serializer_context['include_files'])){
      if ($serializer_context['include_files'] == 'folder'){
        $serializer_context['content_sync_directory_files'] = $serializer_context['content_sync_directory'] . "/files";
      }
      if ($serializer_context['include_files'] == 'base64') {
        $serializer_context['content_sync_file_base_64'] = TRUE;
      }
      unset($serializer_context['include_files']);
    }

    //Set batch operations by entity type/bundle
    $operations = [];
    $operations[] = [[$this, 'generateSiteUUIDFile'], [$serializer_context]];
    foreach ($entities as $entity) {
      $this->getExportQueue()->createItem($entity);
    }
    $operations[] = [
      [$this, 'processContentExportFiles'],
      [$serializer_context],
    ];

    //Set Batch
    $batch = [
      'operations' => $operations,
      'title' => $this->t('Exporting content'),
      'init_message' => $this->t('Starting content export.'),
      'progress_message' => $this->t('Completed @current step of @total.'),
      'error_message' => $this->t('Content export has encountered an error.'),
    ];
    if (isset($serializer_context['export_type'])
      && $serializer_context['export_type'] == 'tar') {
      $batch['finished'] = [$this,'finishContentExportBatch'];
    }
    return $batch;
  }

  /**
   * Helper; split an identifier into its parts.
   *
   * @param string $name
   *   The identifier to split; something like: "entity_type.bundle.uuid".
   *
   * @return string[]
   *   An associative array containing:
   *   - entity_type: The type of entity.
   *   - entity_uuid: The UUID of the identified entity.
   */
  protected static function exportSplitName($name) {
    list($entity_type, , $entity_uuid) = explode('.', $name);
    return compact('entity_type', 'entity_uuid');
  }

  /**
   * Processes the content archive export batch
   *
   * @param array $serializer_context
   *   The serializer context.
   * @param array|DrushBatchContext $context
   *   The batch context.
   */
  public function processContentExportFiles($serializer_context = [], &$context) {
    //Initialize Batch
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $this->exportQueue->numberOfItems();
      $context['sandbox']['dependencies'] = [];
      $context['sandbox']['exported'] = [];
    }

    $queue_item = $this->exportQueue->claimItem();
    if (!$queue_item) {
      $context['message'] = 'Nothing in queue...';
      return;
    }

    $item = $queue_item->data;

    if (!is_array($item)) {
      $item = static::exportSplitName($item);
    }

    // Get submitted values
    $entity_type = $item['entity_type'];

    //Validate that it is a Content Entity
    $instances = $this->getEntityTypeManager()->getDefinitions();
    if (!(isset($instances[$entity_type]) && $instances[$entity_type] instanceof ContentEntityType)) {
      $context['results']['errors'][] = $this->t('Entity type does not exist or it is not a content instance.') . $entity_type;
    }
    else {
      if (isset($item['entity_uuid'])){
        $entity_id = $item['entity_uuid'];
        $entity = $this->getEntityTypeManager()->getStorage($entity_type)
                       ->loadByProperties(['uuid' => $entity_id]);
        $entity = array_shift($entity);
      }else{
        $entity_id = $item['entity_id'];
        $entity = $this->getEntityTypeManager()->getStorage($entity_type)
                       ->load($entity_id);
      }

      //Make sure the entity exist for import
      if(empty($entity)){
        $context['results']['errors'][] = $this->t('Entity does not exist:') . $entity_type . "(".$entity_id.")";
      }else{

        // Create the name
        $bundle = $entity->bundle();
        $uuid = $entity->uuid();
        $name = $entity_type . "." .  $bundle . "." . $uuid;

        if (!isset($context['sandbox']['exported'][$name])) {

          // Generate the YAML file.
          $exported_entity = $this->getContentExporter()
                                  ->exportEntity($entity, $serializer_context);

          if (isset($serializer_context['export_type'])){
            if ($serializer_context['export_type'] == 'snapshot') {
              //Save to cs_db_snapshot table.
              $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
              $activeStorage->cs_write($name, Yaml::decode($exported_entity), $entity_type.'.'.$bundle);
            }else{
              // Compate the YAML from the snapshot.
              // If for some reason is not on our snapshoot then add it.
              // Or if the new YAML is different the update it.
              $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
              $exported_entity_snapshoot = $activeStorage->cs_read($name);

              if (!$exported_entity_snapshoot || Yaml::encode($exported_entity_snapshoot) !== $exported_entity ){
                //Save to cs_db_snapshot table.
                $activeStorage->cs_write($name, Yaml::decode($exported_entity), $entity_type.'.'.$bundle);
              }

              if ($serializer_context['export_type'] == 'tar') {
                // YAML in Archive .
                $this->getArchiver()->addString("entities/$entity_type/$bundle/$name.yml", $exported_entity);

                // Include Files to the archiver.
                if (method_exists($entity, 'getFileUri')
                    && !empty($serializer_context['content_sync_directory_files']) ) {
                  $uri = $entity->getFileUri();
                  $scheme = \Drupal::service('stream_wrapper_manager')->getScheme($uri);
                  $destination = "{$serializer_context['content_sync_directory_files']}/{$scheme}/";
                  $destination = str_replace($scheme . '://', $destination, $uri);
                  $strip_path = str_replace('/files' , '', $serializer_context['content_sync_directory_files'] );
                  $this->getArchiver()->addModify([$destination], '', $strip_path);
                }
              }
              if( $serializer_context['export_type'] == 'folder') {
                // YAML in a directory.
                $path = $serializer_context['content_sync_directory_entities']."/$entity_type/$bundle";
                $destination = $path . "/$name.yml";
                \Drupal::service('file_system')->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY);
                $file =  \Drupal::service('file_system')->saveData($exported_entity, $destination, FileSystemInterface::EXISTS_REPLACE);
              }

              // Invalidate the CS Cache of the entity.
              $cache = \Drupal::cache('content')->invalidate($entity_type.".".$bundle.":".$name);

              if ($serializer_context['include_dependencies']) {
                //Include Dependencies
                if (!isset($context['sandbox']['dependencies'][$name])) {
                  $exported_entity = Yaml::decode($exported_entity);

                  $queue = $this->contentSyncManager->generateExportQueue([$name => $exported_entity], $context['sandbox']['exported']);
                  $new_deps = array_diff_key($queue, $context['sandbox']['dependencies']);
                  $context['sandbox']['dependencies'] += $new_deps;
                  unset($new_deps[$name]);
                  if (!empty($new_deps)) {
                    // Update the batch queue.
                    array_map([$this->exportQueue, 'createItem'], $new_deps);
                    $context['sandbox']['max'] = $context['sandbox']['max'] + count($new_deps);
                  }
                }
              }

              $context['sandbox']['exported'][$name] = $name;
            }
          }

        }
      }
    }

    $this->exportQueue->deleteItem($queue_item);

    $context['sandbox']['progress']++;
    $context['results'][] = $name;
    $context['message'] = "$name {$context['sandbox']['progress']}/{$context['sandbox']['max']}";

    $context['finished'] = $context['sandbox']['max'] > 0
                           && $context['sandbox']['progress'] < $context['sandbox']['max'] ?
                           $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
  }

  /**
   * Generate UUID YAML file
   * To use for site UUID validation.
   *
   * @param $data
   *   The batch content to persist.
   * @param array $context
   *   The batch context.
   */
  public function generateSiteUUIDFile($serializer_context, &$context) {
    //Include Site UUID to YML file
    $site_config = \Drupal::config('system.site');
    $site_uuid_source = $site_config->get('uuid');
    $entity['site_uuid'] = $site_uuid_source;

    // Set the name
    $name = "site.uuid";
    if (isset($serializer_context['export_type'])){
      if ($serializer_context['export_type'] == 'snapshot') {
        //Save to cs_db_snapshot table.
        $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
        $activeStorage->write($name, $entity);
      }elseif( $serializer_context['export_type'] == 'tar') {
        // Add YAML to the archiver
        $this->getArchiver()->addString("entities/$name.yml", Yaml::encode($entity));
      }elseif( $serializer_context['export_type'] == 'folder') {
        $path = $serializer_context['content_sync_directory_entities'];
        $destination = $path . "/$name.yml";
        \Drupal::service('file_system')->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY);
        $file = \Drupal::service('file_system')->saveData(Yaml::encode($entity), $destination, FileSystemInterface::EXISTS_REPLACE);
      }
    }
    $context['message'] = $name;
    $context['results'][] = $name;
    $context['finished'] = 1;
  }

  /**
   * Finish batch.
   *
   * Provide information about the Content Batch results.
   */
   public function finishContentExportBatch($success, $results, $operations) {
    if ($success) {
      if (isset($results['errors'])){
        $errors = $results['errors'];
        unset($results['errors']);
      }
      $results = array_unique($results);
      // Log all the items processed
      foreach ($results as $key => $result) {
        if ($key != 'errors') {
          //drupal_set_message(t('Processed UUID @title.', array('@title' => $result)));
          $this->getExportLogger()
               ->info('Processed UUID @title.', [
                 '@title' => $result,
                 'link' => 'Export',
               ]);
        }
      }
      if (isset($errors) && !empty($errors)) {
        // Log the errors
        $errors = array_unique($errors);
        foreach ($errors as $error) {
          //drupal_set_message($error, 'error');
          $this->getExportLogger()->error($error);
        }
        // Log the note that the content was exported with errors.
        \Drupal::messenger()->addWarning($this->t('The content was exported with errors. <a href=":content-overview">Logs</a>', [':content-overview' => Url::fromRoute('content.overview')->toString()]));
        $this->getExportLogger()
             ->warning('The content was exported with errors.', ['link' => 'Export']);
      }
      else {
        // Log the new created export link if applicable.
        \Drupal::messenger()->addStatus($this->t('The content was exported successfully. <a href=":export-download">Download tar file</a>', [':export-download' => Url::fromRoute('content.export_download')->toString()]));
        $this->getExportLogger()
             ->info('The content was exported successfully. <a href=":export-download">Download tar file</a>', [
               ':export-download' =>  Url::fromRoute('content.export_download')->toString(),
               'link' => 'Export',
             ]);
      }
    }
    else {
      // Log that there was an error.
      $message = $this->t('Finished with an error.<a href=":content-overview">Logs</a>', [':content-overview' => Url::fromRoute('content.overview')->toString()]);
      \Drupal::messenger()->addStatus($message);
      $this->getExportLogger()
           ->error('Finished with an error.', ['link' => 'Export']);
    }
  }

  protected function getArchiver() {
    if (!isset($this->archiver)) {
      $this->archiver = new ArchiveTar($this->getTempFile(), 'gz');
    }
    return $this->archiver;
  }

  protected function getTempFile() {
    return \Drupal::service('file_system')->getTempDirectory() . '/content.tar.gz';
  }

  /**
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  abstract protected function getEntityTypeManager();

  /**
   * @return \Drupal\content_sync\Exporter\ContentExporterInterface
   */
  abstract protected function getContentExporter();

  /**
   * @return \Psr\Log\LoggerInterface
   */
  abstract protected function getExportLogger();

}
