<?php

namespace Drupal\content_sync\Importer;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\serialization\Normalizer\SerializedColumnNormalizerTrait;
use Drupal\user\UserInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Content importer service.
 */
class ContentImporter implements ContentImporterInterface {

  use SerializedColumnNormalizerTrait;

  /**
   * Valid format.
   *
   * @var string
   */
  protected string $format = 'yaml';

  /**
   * Flag to apply updates.
   *
   * @var bool
   */
  protected bool $updateEntities = TRUE;

  /**
   * Importer context.
   *
   * @var array
   */
  protected array $context = [];

  /**
   * Constructor.
   */
  public function __construct(
    protected Serializer $serializer,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function importEntity(array $decoded_entity, array $context = []) : ?ContentEntityInterface {
    $context = $this->context + $context;

    if (!empty($context['entity_type'])) {
      $entity_type_id = $context['entity_type'];
    }
    elseif (!empty($decoded_entity['_content_sync']['entity_type'])) {
      $entity_type_id = $decoded_entity['_content_sync']['entity_type'];
    }
    else {
      return NULL;
    }

    // Replace a menu link to a node with an actual one.
    if ($entity_type_id === 'menu_link_content' && !empty($decoded_entity["_content_sync"]["menu_entity_link"])) {
      $decoded_entity = $this->alterMenuLink($decoded_entity);
    }

    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    if (!$entity_type instanceof ContentEntityTypeInterface) {
      return NULL;
    }

    // Exception for parent null, allowing the term to be displayed on the
    // taxonomy list.
    if (($entity_type_id === 'taxonomy_term') && empty($decoded_entity['parent'])) {
      $decoded_entity['parent']['target_id'] = 0;
    }

    // Get Translations before denormalize.
    if (!empty($decoded_entity['_translations'])) {
      $entity_translations = $decoded_entity['_translations'];
    }

    $entity = $this->serializer->denormalize($decoded_entity, $entity_type->getClass(), $this->format, $context);

    if (!empty($entity)) {
      // Prevent Anonymous User from being saved.
      if ($entity_type_id === 'user' && !$entity->isNew() && (int) $entity->id() === 0) {
        return $entity;
      }
      $entity = $this->syncEntity($entity);
    }

    // Include Translations.
    if ($entity) {
      if (isset($entity_translations) && is_array($entity_translations)) {
        $this->updateTranslation($entity, $entity_type, $entity_translations, $context);
      }
    }
    return $entity;
  }

  /**
   * Updates translations.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   An entity object.
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   A ContentEntityType object.
   * @param array $entity_translations
   *   An array of translations.
   * @param array $context
   *   The importer context.
   */
  protected function updateTranslation(
    ContentEntityInterface $entity,
    ContentEntityTypeInterface $entity_type,
    array $entity_translations,
    array $context,
  ) : void {
    foreach ($entity_translations as $langcode => $translation) {
      // Denormalize.
      $translation = $this->serializer->denormalize($translation, $entity_type->getClass(), $this->format, $context);

      // If an entity has a translation - update one, otherwise - add a new one.
      $entity_translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity->addTranslation($langcode);

      // Get fields definitions.
      $fields = $translation->getFieldDefinitions();

      foreach ($translation as $itemID => $item) {
        if ($entity_translation->hasField($itemID) && $fields[$itemID]->isTranslatable() == TRUE) {
          $entity_translation->{$itemID}->setValue($item->getValue());
        }
      }

      // Avoid issues updating revisions.
      if ($entity_translation->getEntityType()->hasKey('revision')) {
        $entity_translation->updateLoadedRevisionId();
        $entity_translation->setNewRevision(FALSE);
      }

      // Save the entity translation.
      $entity_translation->save();
    }

  }

  /**
   * Replaces a link to a node with an actual one.
   *
   * @param array $decoded_entity
   *   Array of entity values.
   *
   * @return array
   *   Array of entity values with the link values changed.
   */
  protected function alterMenuLink(array $decoded_entity) : array {
    $referenced_entity_uuid = reset($decoded_entity["_content_sync"]["menu_entity_link"]);
    $referenced_entity_type = key($decoded_entity["_content_sync"]["menu_entity_link"]);
    if ($referenced_entity = \Drupal::service('entity.repository')->loadEntityByUuid($referenced_entity_type, $referenced_entity_uuid)) {
      $url = $referenced_entity->toUrl();
      $decoded_entity["link"][0]["uri"] = $url->toUriString();
    }
    return $decoded_entity;
  }

  /**
   * Get the format.
   *
   * @return string
   *   The format.
   */
  public function getFormat() : string {
    return $this->format;
  }

  /**
   * Synchronize a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to update.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The updated entity
   */
  protected function syncEntity(ContentEntityInterface $entity) : ?ContentEntityInterface {
    $preparedEntity = $this->prepareEntity($entity);
    if ($this->validateEntity($preparedEntity)) {
      $preparedEntity->save();
      return $preparedEntity;
    }
    if (!$preparedEntity->isNew()) {
      return $preparedEntity;
    }
    return NULL;
  }

  /**
   * Serializes fields which have to be stored serialized.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to update.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity with the fields being serialized.
   */
  protected function processSerializedFields(ContentEntityInterface $entity) : ContentEntityInterface {
    foreach ($entity->getTypedData() as $name => $field_items) {
      foreach ($field_items as $field_item) {
        // The field to be stored in a serialized way.
        if (!empty($this->getCustomSerializedPropertyNames($field_item))) {
          $unserialized_value = $field_item->get('value')->getValue();
          $entity->set($name, is_array($unserialized_value) ? serialize($unserialized_value) : $unserialized_value);
        }
      }
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareEntity(ContentEntityInterface $entity) : ContentEntityInterface {
    $uuid = $entity->uuid();
    $original_entity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
      ->loadByProperties(['uuid' => $uuid]);

    if (!empty($original_entity)) {
      $original_entity = reset($original_entity);
      if (!$this->updateEntities) {
        return $original_entity;
      }

      // Overwrite the received properties.
      if (!empty($entity->_restSubmittedFields)) {
        foreach ($entity->_restSubmittedFields as $field_name) {
          if ($this->isValidEntityField($original_entity, $entity, $field_name)) {
            $original_entity->set($field_name, $entity->get($field_name)
              ->getValue());
          }
        }
      }
      return $this->processSerializedFields($original_entity);
    }
    $duplicate = $entity->createDuplicate();
    $entity_type = $entity->getEntityType();
    $duplicate->{$entity_type->getKey('uuid')}->value = $uuid;

    return $this->processSerializedFields($duplicate);
  }

  /**
   * Checks if the entity field needs to be synchronized.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $original_entity
   *   The original entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   True if the field needs to be synced.
   */
  protected function isValidEntityField(ContentEntityInterface $original_entity, ContentEntityInterface $entity, string $field_name) : bool {
    $valid = TRUE;
    $entity_keys = $entity->getEntityType()->getKeys();
    // Check if the target entity has the field.
    if (!$entity->hasField($field_name)) {
      $valid = FALSE;
    }
    // Entity key fields need special treatment: together they uniquely
    // identify the entity. Therefore it does not make sense to modify any of
    // them. However, rather than throwing an error, we just ignore them as
    // long as their specified values match their current values.
    elseif (in_array($field_name, $entity_keys, TRUE)) {
      // Unchanged values for entity keys don't need access checking.
      if ($original_entity->get($field_name)
        ->getValue() === $entity->get($field_name)->getValue()
          // It is not possible to set the language to NULL as it is
          // automatically re-initialized.
          // As it must not be empty, skip it if it is.
          || isset($entity_keys['langcode'])
          && $field_name === $entity_keys['langcode']
          && $entity->get($field_name)->isEmpty()
          || $field_name === $entity->getEntityType()->getKey('id')
          || $entity->getEntityType()->isRevisionable()
          && $field_name === $entity->getEntityType()->getKey('revision')
      ) {
        $valid = FALSE;
      }
    }
    return $valid;
  }

  /**
   * Perform entity validation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to validate.
   *
   * @return bool
   *   TRUE if valid; otherwise, FALSE. NOTE: We presently only validate user
   *   entities.
   */
  public function validateEntity(ContentEntityInterface $entity) : bool {
    $valid = TRUE;
    if ($entity instanceof UserInterface) {
      $validations = $entity->validate();
      if (count($validations)) {
        /** @var \Symfony\Component\Validator\ConstraintViolation $validation */
        foreach ($validations as $validation) {
          if (!empty($this->getContext()['skipped_constraints']) && in_array(get_class($validation->getConstraint()), $this->getContext()['skipped_constraints'])) {
            continue;
          }
          $valid = FALSE;
          \Drupal::logger('content_sync')
            ->error($validation->getMessage());
        }
      }
    }
    return $valid;
  }

  /**
   * Get context.
   *
   * @return array
   *   The context.
   */
  public function getContext() : array {
    return $this->context;
  }

  /**
   * Set context.
   *
   * @param array $context
   *   The context.
   */
  public function setContext(array $context) : void {
    $this->context = $context;
  }

}
