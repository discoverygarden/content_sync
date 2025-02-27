<?php

namespace Drupal\content_sync\Plugin\SyncNormalizerDecorator;

use Drupal\content_sync\Plugin\SyncNormalizerDecoratorBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a decorator for setting the alias to entity.
 *
 * @SyncNormalizerDecorator(
 *   id = "id_cleaner",
 *   name = @Translation("IDs Cleaner"),
 * )
 */
class IdsCleaner extends SyncNormalizerDecoratorBase implements ContainerFactoryPluginInterface {

  /**
   * Constructor.
   */
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function decorateNormalization(array &$normalized_entity, ContentEntityInterface $entity, $format, array $context = []) : void {
    $this->cleanReferenceIds($normalized_entity, $entity);
    $this->cleanIds($normalized_entity, $entity);
  }

  /**
   * Clear out referenced IDs.
   *
   * @param array $normalized_entity
   *   The entity data from which to clear reference IDs.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   */
  protected function cleanReferenceIds(array &$normalized_entity, ContentEntityInterface $entity) : void {
    $field_definitions = $entity->getFieldDefinitions();
    /**
     * @var string $field_name
     * @var \Drupal\Core\Field\FieldDefinitionInterface $field_definition
     */
    foreach ($field_definitions as $field_name => $field_definition) {
      // We are only interested in importing content entities.
      if (!$field_definition instanceof EntityReferenceFieldItemListInterface) {
        continue;
      }
      if (!empty($normalized_entity[$field_name]) && is_array($normalized_entity[$field_name])) {
        $entity_type_id = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
        $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

        if (!$entity_type instanceof ContentEntityInterface) {
          continue;
        }
        $key = $field_definition->getFieldStorageDefinition()
          ->getMainPropertyName();
        foreach ($normalized_entity[$field_name] as &$item) {
          if (!empty($item[$key])) {
            unset($item[$key]);
          }
          if (!empty($item['url'])) {
            unset($item['url']);
          }
        }
      }
    }
  }

  /**
   * Clear out IDs.
   *
   * @param array $normalized_entity
   *   Normalized entity in which to clean IDs.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity of which to clear out IDs.
   */
  protected function cleanIds(array &$normalized_entity, ContentEntityInterface $entity) : void {
    $keys = $entity->getEntityType()->getKeys();
    if (isset($normalized_entity[$keys['id']])) {
      unset($normalized_entity[$keys['id']]);
    }
    if (isset($keys['revision'], $normalized_entity[$keys['revision']])) {
      unset($normalized_entity[$keys['revision']]);
    }
  }

}
