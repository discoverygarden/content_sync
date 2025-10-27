<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\serialization\Normalizer\EntityReferenceFieldItemNormalizer;

class ImageItemNormalizer extends EntityReferenceFieldItemNormalizer {

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {
    $denormalized_data =  parent::constructValue($data, $context);
    foreach (['alt', 'title'] as $field) {
      if(!empty($data[$field])) {
        $denormalized_data[$field] = $data[$field];
      }
    }
    return $denormalized_data;
  }

  /**
   * {@inheritDoc}
   */
  public function getSupportedTypes(?string $format) : array {
    return [
      ImageItem::class => TRUE,
    ];
  }

}
