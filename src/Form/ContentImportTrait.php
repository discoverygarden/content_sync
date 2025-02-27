<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Queue\QueueInterface;

/**
 * Defines the content import form.
 */
trait ContentImportTrait {

  /**
   * The queue of items to delete.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $queueDelete;

  /**
   * The queue of items to update.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $queueSync;

  /**
   * Define the delete queue prefix.
   *
   * XXX: Would be a constant; however, traits are not allowed to define them.
   *
   * @return string
   *   The queue prefix to use.
   */
  public static function getDeleteQueuePrefix() {
    return 'content_sync_delete';
  }

  /**
   * Define the sync queue prefix.
   *
   * XXX: Would be a constant; however, traits are not allowed to define them.
   *
   * @return string
   *   The queue prefix to use.
   */
  public static function getSyncQueuePrefix() : string {
    return 'content_sync_sync';
  }

  /**
   * Generate import batch.
   *
   * @param array $content_to_sync
   *   Array of content to sync.
   * @param array $content_to_delete
   *   Array of content to delete.
   * @param array $serializer_context
   *   An associative array containing:
   *   - content_sync_directory: The content sync directory from which to
   *     import.
   *
   * @return array
   *   Batch definition.
   */
  public function generateImportBatch(array $content_to_sync, array $content_to_delete, array $serializer_context = []) : array {
    if (!isset($serializer_context['content_sync_directory'])) {
      $serializer_context['content_sync_directory'] = content_sync_get_content_directory(ContentSyncManagerInterface::DEFAULT_DIRECTORY);
    }
    $serializer_context['content_sync_directory_entities'] = $serializer_context['content_sync_directory'] . "/entities";
    $serializer_context['content_sync_directory_files'] = $serializer_context['content_sync_directory'] . "/files";

    $uuid = \Drupal::service('uuid')->generate();

    $this->queueDelete = \Drupal::queue(static::getDeleteQueuePrefix() . ":{$uuid}", TRUE);
    array_map([$this->queueDelete, 'createItem'], array_reverse($content_to_delete));
    $this->queueSync = \Drupal::queue(static::getSyncQueuePrefix() . ":{$uuid}", TRUE);
    array_map(
      [$this->queueSync, 'createItem'],
      array_reverse($this->contentSyncManager->generateImportQueue(
        $content_to_sync,
        $serializer_context['content_sync_directory_entities']
      ))
    );

    $operations[] = [[$this, 'deleteContent'], [$serializer_context]];
    $operations[] = [[$this, 'syncContent'], [$serializer_context]];

    $batch = [
      'title' => $this->t('Synchronizing Content...'),
      'message' => $this->t('Synchronizing Content...'),
      'operations' => $operations,
    ];
    return $batch;
  }

  /**
   * Batch operation callback; process items from sync queue accordingly.
   *
   * Processes the content import to be updated or created batch and persists
   * the importer.
   *
   * @param array $serializer_context
   *   Serializer context.
   * @param array $context
   *   Reference to batch context.
   */
  public function syncContent(array $serializer_context = [], array &$context = []) : void {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['directory'] = $serializer_context['content_sync_directory_entities'];
      $context['sandbox']['max'] = $this->queueSync->numberOfItems();
    }

    $queue_item = $this->queueSync->claimItem();

    if ($queue_item) {
      $error = FALSE;
      $item = $queue_item->data;
      $decoded_entity = $item['decoded_entity'];
      $entity_type_id = $item['entity_type_id'];
      $entity = $this->contentSyncManager->getContentImporter()
        ->importEntity($decoded_entity, $serializer_context);
      if ($entity) {
        $context['results'][] = TRUE;
        $context['message'] = $this->t('Imported content @label (@entity_type: @id).', [
          '@label' => $entity->label(),
          '@id' => $entity->id(),
          '@entity_type' => $entity->getEntityTypeId(),
        ]);
        // Invalidate the CS Cache of the entity.
        $bundle = $entity->bundle();
        $entity_id = $entity->getEntityTypeId();
        $name = $entity_id . '.' . $bundle . '.' . $entity->uuid();
        \Drupal::cache('content')->invalidate($entity_id . '.' . $bundle . ':' . $name);
        unset($entity);
      }
      else {
        $error = TRUE;
      }
      if ($error) {
        $context['message'] = $this->t('Error importing content of type @entity_type.', [
          '@entity_type' => $entity_type_id,
        ]);
        if (!isset($context['results']['errors'])) {
          $context['results']['errors'] = [];
        }
        $context['results']['errors'][] = $context['message'];
      }
      if ($error) {
        \Drupal::messenger()->addError($context['message']);
      }
      // We need to count the progress anyway even if an error has occured.
      $context['sandbox']['progress']++;
      $this->queueSync->deleteItem($queue_item);
    }
    $context['finished'] = $context['sandbox']['max'] > 0
                        && $context['sandbox']['progress'] < $context['sandbox']['max'] ?
                           $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
  }

  /**
   * Batch operation callback; process items from delete queue accordingly.
   *
   * Processes the content import to be deleted or created batch and persists
   * the importer.
   *
   * @param array $serializer_context
   *   Serializer context.
   * @param array $context
   *   Reference to batch context.
   */
  public function deleteContent(array $serializer_context = [], array &$context = []) : void {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['directory'] = $serializer_context['content_sync_directory_entities'];
      $context['sandbox']['max'] = $this->queueDelete->numberOfItems();
    }
    $queue_item = $this->queueDelete->claimItem();
    $error = FALSE;
    if ($queue_item) {
      $error = TRUE;
      $item = $queue_item->data;
      $ids = explode('.', $item);
      [$entity_type_id, $bundle, $uuid] = $ids;

      $entity = $this->contentSyncManager->getEntityTypeManager()->getStorage($entity_type_id)
        ->loadByProperties(['uuid' => $uuid]);
      $entity = array_shift($entity);
      if (!empty($entity)) {

        // Prevent Anonymous User and Super Admin from being deleted.
        if ($entity_type_id === 'user' && (
           (int) $entity->id() === 0 ||
           (int) $entity->id() === 1)) {

          $message = $this->t('@uuid - Anonymous user or super admin can not be removed.', [
            '@entity_type' => $entity_type_id,
            '@uuid' => $uuid,
          ]);

        }
        else {
          try {
            $message = $this->t('Deleted content @label (@entity_type: @id).', [
              '@label' => $entity->label(),
              '@id' => $entity->id(),
              '@entity_type' => $entity->getEntityTypeId(),
            ]);
            $entity->delete();
            $error = FALSE;
            // Invalidate the CS Cache of the entity.
            $bundle = $entity->bundle();
            $name = $entity_type_id . '.' . $bundle . '.' . $entity->uuid();
            \Drupal::cache('content')->invalidate($entity_type_id . '.' . $bundle . ':' . $name);
          }
          catch (EntityStorageException $e) {
            $message = $e->getMessage();
            \Drupal::messenger()->addError($message);
          }
        }
      }
      else {
        $message = $this->t('@uuid of type @entity_type was not found.', [
          '@entity_type' => $entity_type_id,
          '@uuid' => $uuid,
        ]);
      }
      $this->queueDelete->deleteItem($queue_item);
    }
    $context['results'][] = TRUE;
    $context['sandbox']['progress']++;
    $context['message'] = $message ?? "No message";

    if ($error) {
      if (!isset($context['results']['errors'])) {
        $context['results']['errors'] = [];
      }
      $context['results']['errors'][] = $context['message'];
    }

    $context['finished'] = $context['sandbox']['max'] > 0
                        && $context['sandbox']['progress'] < $context['sandbox']['max'] ?
                           $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
  }

  /**
   * Finish batch.
   *
   * This function is a static function to avoid serializing the ContentSync
   * object unnecessarily.
   */
  public static function finishImportBatch($success, $results, $operations) {
    if ($success) {
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          \Drupal::messenger()->addError($error);
          \Drupal::logger('config_sync')->error($error);
        }
        \Drupal::messenger()->addWarning(\Drupal::translation()
          ->translate('The content was imported with errors.'));
      }
      else {
        \Drupal::messenger()->addStatus(\Drupal::translation()
          ->translate('The content was imported successfully.'));
      }
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      $message = \Drupal::translation()
        ->translate('An error occurred while processing %error_operation with arguments: @arguments', [
          '%error_operation' => $error_operation[0],
          '@arguments' => print_r($error_operation[1], TRUE),
        ]);
      \Drupal::messenger()->addError($message, 'error');
    }
  }

}
