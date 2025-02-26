<?php

namespace Drupal\content_sync\Content;

use Drupal\Core\Config\StorageInterface;

interface DatabaseStorageInterface extends StorageInterface {

  public function cs_write($name, array $data, $collection);

  public function cs_read($name);

  public function cs_delete($name);

}
