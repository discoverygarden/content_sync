<?php

namespace Drupal\content_sync\Plugin\SyncNormalizerDecorator;

use Drupal\content_sync\Plugin\SyncNormalizerDecoratorBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parent sync normalizer decorator.
 *
 * @SyncNormalizerDecorator(
 *   id = "parents",
 *   name = @Translation("Parents"),
 * )
 */
class Parents extends SyncNormalizerDecoratorBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) : self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function decorateNormalization(array &$normalized_entity, ContentEntityInterface $entity, $format, array $context = []) : void {
    if ($entity->hasField('parent')) {
      $entity_type = $entity->getEntityTypeId();
      $storage = $this->entityTypeManager->getStorage($entity_type);
      if (method_exists($storage, 'loadParents')) {
        $parents = $storage->loadParents($entity->id());
        foreach ($parents as $parent) {
          if (!$this->parentExistence($parent->uuid(), $normalized_entity)) {
            $normalized_entity['parent'][] = [
              'target_type' => $entity_type,
              'target_uuid' => $parent->uuid(),
            ];
            $normalized_entity['_content_sync']['entity_dependencies'][$entity_type][] = $entity_type . "." . $parent->bundle() . "." . $parent->uuid();
          }
        }
      }
      elseif (method_exists($entity, 'getParentId')) {
        $parent_id = $entity->getParentId();
        if (($tmp = strstr($parent_id, ':')) !== FALSE) {
          $parent_uuid = substr($tmp, 1);
          $parents = $storage->loadByProperties(['uuid' => $parent_uuid]);
          $parent = !empty($parents) ? reset($parents) : NULL;
          if (!empty($parent) && !$this->parentExistence($parent_uuid, $normalized_entity)) {
            $normalized_entity['parent'][] = [
              'target_type' => $entity_type,
              'target_uuid' => $parent_uuid,
            ];
            $normalized_entity['_content_sync']['entity_dependencies'][$entity_type][] = $entity_type . "." . $parent->bundle() . "." . $parent_uuid;
          }
        }
      }
    }
  }

  /**
   * Sees if the parent has not already been added prior to this point.
   *
   * @param string $parent_uuid
   *   The UUID of the parent to check against.
   * @param array $normalized_entity
   *   The entity being exported.
   *
   * @return bool
   *   TRUE if it already exists, FALSE if not.
   */
  protected function parentExistence(string $parent_uuid, array $normalized_entity) : bool {
    return in_array($parent_uuid, array_column($normalized_entity['parent'], 'target_uuid'), TRUE);
  }

}
