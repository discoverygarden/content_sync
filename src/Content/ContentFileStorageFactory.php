<?php

namespace Drupal\content_sync\Content;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Config\FileStorage;

/**
 * Provides a factory for creating content file storage objects.
 */
class ContentFileStorageFactory {

  /**
   * Returns a FileStorage object working with the active content directory.
   *
   * @return \Drupal\Core\Config\FileStorage FileStorage
   *
   * @deprecated in Drupal 8.0.x and will be removed before 9.0.0. Drupal core
   * no longer creates an active directory.
   */
  public static function getActive() {
    return new FileStorage(content_sync_get_content_directory(CONFIG_ACTIVE_DIRECTORY)."/entities");
  }

  /**
   * Returns a FileStorage object working with the sync content directory.
   *
   * @return \Drupal\Core\Config\FileStorage FileStorage
   */
  public static function getSync() {
    return new FileStorage(content_sync_get_content_directory(ContentSyncManagerInterface::DEFAULT_DIRECTORY)."/entities");
  }
}
