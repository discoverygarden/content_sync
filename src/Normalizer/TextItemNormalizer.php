<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\serialization\Normalizer\NormalizerBase;
use Drupal\text\Plugin\Field\FieldType\TextItemBase;

/**
 * Converts TextItem fields to an array including computed values.
 */
class TextItemNormalizer extends NormalizerBase {

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []) : float|int|bool|\ArrayObject|array|string|null {
    $attributes = [];
    foreach ($object->getProperties(TRUE) as $name => $field) {
      $value = $this->serializer->normalize($field, $format, $context);
      if (is_object($value)) {
        $value = $this->serializer->normalize($value, $format, $context);
      }
      $attributes[$name] = $value;
    }
    return $attributes;
  }


  /**
   * {@inheritDoc}
   */
  public function getSupportedTypes(?string $format) : array {
    return [
      TextItemBase::class => TRUE,
    ];
  }

}
