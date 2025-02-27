<?php

namespace Drupal\content_sync\Plugin;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Helper trait; facilitate invocations of decorated (de)normalization plugins.
 */
trait SyncNormalizerDecoratorTrait {

  /**
   * Invoke all managed normalization plugins.
   */
  protected function decorateNormalization(array &$normalized_entity, ContentEntityInterface $entity, $format, array $context = []) : void {
    $plugins = $this->getDecoratorManager()->getDefinitions();
    foreach ($plugins as $decorator) {
      /** @var SyncNormalizerDecoratorInterface $instance */
      $instance = $this->getDecoratorManager()->createInstance($decorator['id']);
      $instance->decorateNormalization($normalized_entity, $entity, $format, $context);
    }
  }

  /**
   * Invoke all managed denormalization plugins.
   */
  protected function decorateDenormalization(array &$normalized_entity, $type, $format, array $context = []) : void {
    $plugins = $this->getDecoratorManager()->getDefinitions();
    foreach ($plugins as $decorator) {
      /** @var SyncNormalizerDecoratorInterface $instance */
      $instance = $this->getDecoratorManager()->createInstance($decorator['id']);
      $instance->decorateDenormalization($normalized_entity, $type, $format, $context);
    }
  }

  /**
   * Invoke all managed entity denormalization plugins.
   */
  protected function decorateDenormalizedEntity(ContentEntityInterface $entity, array $normalized_entity, $format, array $context = []) {
    $plugins = $this->getDecoratorManager()->getDefinitions();
    foreach ($plugins as $decorator) {
      /** @var SyncNormalizerDecoratorInterface $instance */
      $instance = $this->getDecoratorManager()->createInstance($decorator['id']);
      $instance->decorateDenormalizedEntity($entity, $normalized_entity, $format, $context);
    }
  }

  /**
   * Get the sync normalizer decorator manager service.
   */
  abstract protected function getDecoratorManager() : SyncNormalizerDecoratorManager;

}
