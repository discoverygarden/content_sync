<?php

namespace Drupal\content_sync\Content;

use Drupal\Core\Config\DatabaseStorage as UpstreamDatabaseStorage;
use Drupal\Core\Config\StorageException;

/**
 * Defines the Database storage.
 *
 * Collection-independent storage manipulations.
 *
 * phpcs:disable Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
 */
class DatabaseStorage extends UpstreamDatabaseStorage implements DatabaseStorageInterface {

  /**
   * {@inheritDoc}
   */
  public function contentSyncWrite(string $name, array $data, string $collection) : bool {
    $encoded_data = $this->encode($data);
    try {
      return $this->contentSyncDoWrite($name, $encoded_data, $collection);
    }
    catch (\Exception $e) {
      // If there was an exception, try to create the table.
      if ($this->ensureTableExists()) {
        return $this->contentSyncDoWrite($name, $encoded_data, $collection);
      }
      // Some other failure that we can not recover from.
      throw new StorageException($e->getMessage(), 0, $e);
    }
  }

  /**
   * Helper method so we can re-try a write.
   *
   * @param string $name
   *   The content name.
   * @param string $data
   *   The content data, already dumped to a string.
   * @param string $collection
   *   The content collection name, entity type + bundle.
   *
   * @return bool
   *   TRUE on success; otherwise, FALSE.
   */
  protected function contentSyncDoWrite(string $name, string $data, string $collection) : bool {
    $this->contentSyncDelete($name);

    return (bool) $this->connection->merge($this->table, $this->options)
      ->keys(['collection', 'name'], [$collection, $name])
      ->fields(['data' => $data])
      ->execute();
  }

  /**
   * {@inheritDoc}
   */
  public function contentSyncRead($name) : array|false {
    try {
      $raw = $this->connection->query('SELECT data FROM {' . $this->connection->escapeTable($this->table) . '} WHERE name = :name', [':name' => $name], $this->options)->fetchField();
      if ($raw !== FALSE) {
        return $this->decode($raw);
      }
    }
    catch (\Exception $e) {
      // If we attempt a read without actually having the database or the table
      // available, just return FALSE so the caller can handle it.
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function contentSyncDelete($name) : bool {
    return (bool) $this->connection->delete($this->table, $this->options)
      ->condition('name', $name)
      ->execute();
  }

}
