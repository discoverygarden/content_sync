<?php

namespace Drupal\content_sync\Content;

use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Cache\NullBackend;

/**
 * Extends config storage comparer.
 */
class ContentStorageComparer extends StorageComparer {

  /**
   * Constructor.
   *
   * Largely copypasta of the parent, with the exception of rolling
   * "NullBackend" caches instead of "MemoryBackend".
   */
  public function __construct(StorageInterface $source_storage, StorageInterface $target_storage) {

    $this->sourceCacheStorage = new NullBackend(__CLASS__ . '::source');
    $this->sourceStorage = new CachedStorage($source_storage, $this->sourceCacheStorage);
    $this->targetCacheStorage = new NullBackend(__CLASS__ . '::target');
    $this->targetStorage = new CachedStorage($target_storage, $this->targetCacheStorage);
    $this->changelist[StorageInterface::DEFAULT_COLLECTION] = $this
      ->getEmptyChangelist();
  }

  /**
   * {@inheritdoc}
   */
  public function createChangelistbyCollection($collection) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
    $this->getAndSortConfigData($collection);
    $this->addChangelistCreate($collection);
    $this->addChangelistUpdate($collection);
    $this->addChangelistDelete($collection);
    // Only collections that support configuration entities can have renames.
    if ($collection == StorageInterface::DEFAULT_COLLECTION) {
      $this->addChangelistRename($collection);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function createChangelistbyCollectionAndNames($collection, $names) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
    if ($this->getAndSortContentDataByCollectionAndNames($collection, $names)){
      $this->addChangelistCreate($collection);
      $this->addChangelistUpdate($collection);
      $this->addChangelistDelete($collection);
      // Only collections that support configuration entities can have renames.
      if ($collection == StorageInterface::DEFAULT_COLLECTION) {
        $this->addChangelistRename($collection);
      }
    }
    return $this;
  }

  /**
   * Gets and sorts configuration data from the source and target storages.
   */
  protected function getAndSortContentDataByCollectionAndNames($collection, $names) {
    $names = explode(',', $names);
    $target_names = [];
    $source_names = [];
    foreach($names as $key => $name){
      $name = $collection.'.'.$name;
      $source_storage = $this->getSourceStorage($collection);
      $target_storage = $this->getTargetStorage($collection);
      if($source_storage->exists($name) ||
        $target_storage->exists($name) ){
        $target_names = array_merge($target_names, $target_storage->listAll($name));
        $source_names = array_merge($source_names, $source_storage->listAll($name));
      }
    }
    $target_names = array_filter($target_names);
    $source_names = array_filter($source_names);
    if(!empty($target_names) || !empty($source_names)){
      // Prime the static caches by reading all the configuration in the source
      // and target storages.
      $target_data = $target_storage->readMultiple($target_names);
      $source_data = $source_storage->readMultiple($source_names);
      $this->targetNames[$collection] = $target_names;
      $this->sourceNames[$collection] = $source_names;
      return true;
    }
    return false;
  }

  /**
   * {@inheritdoc}
   *
   * Essentially, copypasta of StorageComparer::getAndSortConfigData() with
   * the two StorageInterface::readMultiple() calls moved, so they can be
   * avoided.
   */
  protected function getAndSortConfigData($collection) {
    $source_storage = $this
      ->getSourceStorage($collection);
    $target_storage = $this
      ->getTargetStorage($collection);
    $target_names = $target_storage
      ->listAll();
    $source_names = $source_storage
      ->listAll();

    // If the collection only supports simple configuration do not use
    // configuration dependencies.
    if ($collection == StorageInterface::DEFAULT_COLLECTION) {
      // XXX: Upstream, this exists outside of this if branch; however, that
      // unnecessarily leads to massive memory usage.
      // Prime the static caches by reading all the configuration in the source
      // and target storages.
      $target_data = $target_storage
        ->readMultiple($target_names);
      $source_data = $source_storage
        ->readMultiple($source_names);

      $dependency_manager = new ConfigDependencyManager();
      $this->targetNames[$collection] = $dependency_manager
        ->setData($target_data)
        ->sortAll();
      $this->sourceNames[$collection] = $dependency_manager
        ->setData($source_data)
        ->sortAll();
    }
    else {
      $this->targetNames[$collection] = $target_names;
      $this->sourceNames[$collection] = $source_names;
    }
  }

  /**
   * Clear our changelist for the given collection.
   *
   * @param string $collection
   *   The name of the collection to clear.
   */
  public function resetCollectionChangelist($collection) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
  }

}
