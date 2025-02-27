<?php

namespace Drupal\content_sync\Exporter;

use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\Serializer\Serializer;
use Drupal\Component\Serialization\Yaml;

/**
 * Content exporter service.
 */
class ContentExporter implements ContentExporterInterface {

  /**
   * Serializer format.
   *
   * @var string
   */
  protected string $format = 'yaml';

  /**
   * Serializer context.
   *
   * @var array
   */
  protected array $context = [];

  /**
   * ContentExporter constructor.
   */
  public function __construct(
    protected Serializer $serializer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function exportEntity(ContentEntityInterface $entity, array $context = []): string {
    $context = $this->context + $context;
    // Allows normalizers to know that this is a content sync generated entity.
    $context += [
      'content_sync' => TRUE,
    ];

    $normalized_entity = $this->serializer->serialize($entity, $this->format, $context);

    // Include translations to the normalized entity.
    $yaml_parsed = Yaml::decode($normalized_entity);
    $lang_default = $entity->language()->getId();
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      // Verify that it is not the default langcode.
      if ($langcode != $lang_default) {
        if ($entity->hasTranslation($langcode)) {
          $entity_translated = $entity->getTranslation($langcode);
          $normalized_entity_translations = $this->serializer->serialize($entity_translated, $this->format, $context);
          $yaml_parsed['_translations'][$langcode] = Yaml::decode($normalized_entity_translations);
        }
      }
    }
    return Yaml::encode($yaml_parsed);
  }

  /**
   * Format accessor.
   */
  public function getFormat(): string {
    return $this->format;
  }

  /**
   * Serializer accessor.
   */
  public function getSerializer(): Serializer {
    return $this->serializer;
  }

}
