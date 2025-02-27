<?php

namespace Drupal\content_sync\Plugin\SyncNormalizerDecorator;

use Drupal\content_sync\Plugin\SyncNormalizerDecoratorBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\path_alias\AliasManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a decorator for setting the alias to entity.
 *
 * @SyncNormalizerDecorator(
 *   id = "alias",
 *   name = @Translation("Alias"),
 * )
 */
class Alias extends SyncNormalizerDecoratorBase implements ContainerFactoryPluginInterface {

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AliasManager $aliasManager,
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
      $container->get('path_alias.manager'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function decorateNormalization(array &$normalized_entity, ContentEntityInterface $entity, $format, array $context = []) : void {
    if ($entity->hasLinkTemplate('canonical')) {
      $url = $entity->toUrl();
      if (!empty($url)) {
        $system_path = '/' . $url->getInternalPath();
        $langcode = $entity->language()->getId();
        $path_alias = $this->aliasManager->getAliasByPath($system_path, $langcode);
        if (!empty($path_alias) && $path_alias !== $system_path) {
          $normalized_entity['path'] = [
            [
              'alias' => $path_alias,
            ],
          ];
        }
      }
    }
  }

}
