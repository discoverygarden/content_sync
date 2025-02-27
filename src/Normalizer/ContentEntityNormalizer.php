<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\content_sync\ContentSyncManager;
use Drupal\content_sync\Plugin\SyncNormalizerDecoratorManager;
use Drupal\content_sync\Plugin\SyncNormalizerDecoratorTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Url;
use Drupal\serialization\Normalizer\ContentEntityNormalizer as BaseContentEntityNormalizer;

/**
 * Adds the file URI to embedded file entities.
 */
class ContentEntityNormalizer extends BaseContentEntityNormalizer {

  use SyncNormalizerDecoratorTrait;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeRepositoryInterface $entity_type_repository,
    EntityFieldManagerInterface $entity_field_manager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected EntityRepositoryInterface $entityRepository,
    protected SyncNormalizerDecoratorManager $decoratorManager,
  ) {
    parent::__construct($entity_type_manager, $entity_type_repository, $entity_field_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) : mixed {
    if (is_null($data)) {
      return NULL;
    }
    $original_data = $data;

    // Get the entity type ID while letting context override the $class param.
    $entity_type_id = !empty($context['entity_type']) ? $context['entity_type'] : $this->entityTypeRepository->getEntityTypeFromClass($class);

    $bundle = FALSE;
    /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type_definition */
    // Get the entity type definition.
    $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($entity_type_definition->hasKey('bundle')) {
      $bundle_key = $entity_type_definition->getKey('bundle');
      // Get the base field definitions for this entity type.
      $base_field_definitions = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);

      // Get the ID key from the base field definition for the bundle key or
      // default to 'value'.
      $key_id = isset($base_field_definitions[$bundle_key]) ?
        $base_field_definitions[$bundle_key]->getFieldStorageDefinition()->getMainPropertyName() :
        'value';

      // Normalize the bundle if it is not explicitly set.
      $bundle = $data[$bundle_key][0][$key_id] ?? ($data[$bundle_key] ?? NULL);
    }

    // Decorate data before denormalizing it.
    $this->decorateDenormalization($data, $entity_type_id, $format, $context);

    // Resolve references.
    $this->fixReferences($data, $entity_type_id, $bundle);

    // Remove invalid fields.
    $this->cleanupData($data, $entity_type_id, $bundle);

    // Data to Entity.
    $entity = parent::denormalize($data, $class, $format, $context);

    // Decorate denormalized entity before retuning it.
    $this->decorateDenormalizedEntity($entity, $original_data, $format, $context);

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []) : float|array|\ArrayObject|bool|int|string|null {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $object */
    $normalized_data = parent::normalize($object, $format, $context);

    $normalized_data['_content_sync'] = $this->getContentSyncMetadata($object, $context);

    /**
     * @var \Drupal\Core\Entity\ContentEntityBase $object
     */
    $referenced_entities = $object->referencedEntities();

    // Add node uuid for menu link if any.
    if ($object->getEntityTypeId() === 'menu_link_content') {
      if ($entity = $this->getMenuLinkNodeAttached($object)) {
        $normalized_data['_content_sync']['menu_entity_link'][$entity->getEntityTypeId()] = $entity->uuid();
        $referenced_entities[] = $entity;
      }
    }

    if (!empty($referenced_entities)) {
      $dependencies = [];
      foreach ($referenced_entities as $entity) {
        $reflection = new \ReflectionClass($entity);
        if ($reflection->implementsInterface(ContentEntityInterface::class)) {
          $ids = [
            $entity->getEntityTypeId(),
            $entity->bundle(),
            $entity->uuid(),
          ];
          $dependency = implode(ContentSyncManager::DELIMITER, $ids);
          if (!$this->inDependencies($dependency, $dependencies)) {
            $dependencies[$entity->getEntityTypeId()][] = $dependency;
          }
        }
      }
      $normalized_data['_content_sync']['entity_dependencies'] = $dependencies;
    }
    // Decorate normalized entity before retuning it.
    if (is_a($object, ContentEntityInterface::class, TRUE)) {
      $this->decorateNormalization($normalized_data, $object, $format, $context);
    }
    return $normalized_data;
  }

  /**
   * Checks if a dependency is in a dependencies nested array.
   *
   * @param string $dependency
   *   An entity identifier.
   * @param array $dependencies
   *   A nested array of dependencies.
   *
   * @return bool
   *   TRUE if the value is present; otherwise, FALSE.
   */
  protected function inDependencies(string $dependency, array $dependencies) : bool {
    [$entity_type_id] = explode('.', $dependency);
    return isset($dependencies[$entity_type_id]) && in_array($dependency, $dependencies[$entity_type_id], FALSE);
  }

  /**
   * Gets a node attached to a menu link. The node has already been imported.
   *
   * @param \Drupal\Core\Entity\EntityInterface $object
   *   Menu Link Entity.
   *
   * @return false|\Drupal\Core\Entity\EntityInterface|null
   *   The linked entity if discovered. NULL if we discovered an entity but
   *   failed to load it. FALSE if if we failed to find an entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  protected function getMenuLinkNodeAttached(ContentEntityInterface $object) : ContentEntityInterface|false|null {
    $entity = FALSE;

    $uri = $object->get('link')->getString();
    $url = Url::fromUri($uri);
    $route_parameters = NULL;
    try {
      $route_parameters = $url->getRouteParameters();
    }
    catch (\Exception $e) {
      // If menu link is linked to a non-node page - just do nothing.
    }
    if (is_array($route_parameters) && count($route_parameters) === 1) {
      $entity_id = reset($route_parameters);
      $entity_type = key($route_parameters);
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    }
    return $entity;
  }

  /**
   * Get content sync metadata for the given entity and context.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $object
   *   The content entity to import.
   * @param array $context
   *   Import context.
   *
   * @return array
   *   The relevant metadata.
   */
  protected function getContentSyncMetadata(ContentEntityInterface $object, array $context = []) : array {
    return [
      'entity_type' => $object->getEntityTypeId(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDecoratorManager() : SyncNormalizerDecoratorManager {
    return $this->decoratorManager;
  }

  /**
   * Fix references in passed data.
   *
   * @param array $data
   *   A reference to the data in which to fix references.
   * @param string $entity_type_id
   *   The type of entity that $data represents.
   * @param string|false $bundle
   *   The bundle represented by the data.
   *
   * @return array
   *   The fixed data. Given $data comes in as a reference, it might not be
   *   necessary to use this return.
   */
  protected function fixReferences(array &$data, $entity_type_id, string|false $bundle = FALSE) : array {
    /**
     * @var string $field_name
     * @var \Drupal\Core\Field\FieldDefinitionInterface $field_definition
     */
    foreach ($this->getFieldDefinitions($entity_type_id, $bundle) as $field_name => $field_definition) {
      // We are only interested in importing content entities.
      if (!$field_definition instanceof EntityReferenceFieldItemListInterface) {
        continue;
      }
      if (!empty($data[$field_name]) && is_array($data[$field_name])) {
        $key = $field_definition->getFieldStorageDefinition()
          ->getMainPropertyName();
        foreach ($data[$field_name] as $i => &$item) {
          if (!empty($item['target_uuid'])) {
            $reference = $this->entityRepository->loadEntityByUuid($item['target_type'], $item['target_uuid']);
            if ($reference) {
              $item[$key] = $reference->id();
              if ($reference instanceof RevisionableInterface) {
                $item['target_revision_id'] = $reference->getRevisionId();
              }
            }
            else {
              $entity_type = $this->entityTypeManager->getStorage($item['target_type'])->getEntityType();

              if ($entity_type instanceof ContentEntityInterface) {
                unset($data[$field_name][$i]);
              }
            }
          }
        }
      }
    }
    return $data;
  }

  /**
   * Cleanup given data.
   *
   * @param array $data
   *   Reference to data to cleanup.
   * @param string $entity_type_id
   *   The type of entity that the data represents.
   * @param string|false $bundle
   *   The bundle of the entity represented.
   */
  protected function cleanupData(array &$data, string $entity_type_id, string|false $bundle = FALSE) : void {
    $field_names = array_keys($this->getFieldDefinitions($entity_type_id, $bundle));
    foreach ($data as $field_name => $field_data) {
      if (!in_array($field_name, $field_names, TRUE)) {
        unset($data[$field_name]);
      }
    }
  }

  /**
   * Get field definitions for the given type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type for which to get field definitions.
   * @param string|false $bundle
   *   A bundle to which to constrain fields, if desired.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   An associative array mapping the field names to the related field
   *   definitions.
   */
  protected function getFieldDefinitions(string $entity_type_id, string|false $bundle) : array {
    if ($bundle) {
      // Given a particular bundle, targetedly get the fields.
      return $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    }

    // No bundle passed, get _ALL_ the fields!
    $bundles = array_keys($this->entityTypeBundleInfo->getBundleInfo($entity_type_id));
    $field_definitions = [];
    foreach ($bundles as $_bundle) {
      $field_definitions_bundle = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $_bundle);
      if (is_array($field_definitions_bundle)) {
        $field_definitions += $field_definitions_bundle;
      }
    }
    return $field_definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsNormalization($data, $format = NULL, array $context = []): bool {
    return parent::supportsNormalization($data, $format, $context) && ($context['content_sync'] ?? FALSE);
  }

}
