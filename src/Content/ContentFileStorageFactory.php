<?php

namespace Drupal\content_sync\Content;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Config\FileStorage;

/**
 * Provides a factory for creating content file storage objects.
 */
class ContentFileStorageFactory {

  /**
   * Returns a FileStorage object working with the sync content directory.
   *
   * @return \Drupal\Core\Config\FileStorage
   *   File storage for sync content.
   */
  public static function getSync() {
    return new FileStorage(content_sync_get_content_directory(ContentSyncManagerInterface::DEFAULT_DIRECTORY) . "/entities");
  }

}
