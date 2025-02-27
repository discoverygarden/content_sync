<?php

namespace Drupal\content_sync\Content;

use Drupal\Core\Config\StorageInterface;

/**
 * Collection-independent database storage methods.
 *
 * phpcs:disable Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
 */
interface DatabaseStorageInterface extends StorageInterface {

  /**
   * Write to the given collection, ensuring non-existence in any others.
   *
   * @param string $name
   *   The name of the item to add to the storage.
   * @param array $data
   *   The data to store.
   * @param string $collection
   *   The name of the collection in which to store the data.
   *
   * @return bool
   *   TRUE on success; otherwise, FALSE.
   *
   * @throws \Drupal\Core\Config\StorageException
   *   If we are unable to handle a deeper exception.
   *
   * @see \Drupal\Core\Config\StorageInterface::write()
   */
  public function contentSyncWrite(string $name, array $data, string $collection) : bool;

  /**
   * Read the given key.
   *
   * @param string $name
   *   The name of the item to read from storage.
   *
   * @return array|false
   *   The data storged for the given name if present; otherwise, FALSE.
   *
   * @see \Drupal\Core\Config\StorageInterface::read()
   */
  public function contentSyncRead(string $name) : array|false;

  /**
   * Delete data with the given key.
   *
   * @param string $name
   *   The name of the item to delete from storage.
   *
   * @return bool
   *   TRUE on success; otherwise, FALSE.
   *
   * @see \Drupal\Core\Config\StorageInterface::delete()
   */
  public function contentSyncDelete(string $name) : bool;

}
