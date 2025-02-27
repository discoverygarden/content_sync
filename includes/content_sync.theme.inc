<?php

/**
 * @file
 * Preprocessors and helper functions to make theming easier.
 */

/**
 * Prepares variables for content_sync_help templates.
 *
 * Default template: content_sync_help.html.twig.
 *
 * @param array $variables
 *   An associative array containing:
 *   - title: Help title.
 *   - content: Help content.
 */
function template_preprocess_content_sync_help(array &$variables) : void {
  /** @var \Drupal\content_sync\ContentSyncHelpManagerInterface $help_manager */
  $help_manager = \Drupal::service('content_sync.help_manager');

  $help_info = $variables['info'];

  $variables += $help_info;

  $help = [];
  if (!empty($help_info['menu']) && !\Drupal::config('content_sync.settings')->get('content_sync.help_menu_disabled')) {
    $help['menu'] = $help_manager->buildHelpMenu();
  }
  if (is_array($help_info['content'])) {
    $help['content'] = $help_info['content'];
  }
  else {
    $help['content'] = [
      '#markup' => $help_info['content'],
    ];
  }

  $variables['help'] = $help;
}

/**
 * Prepares variables for content_sync_message templates.
 *
 * Default template: content-sync-message.html.twig.
 *
 * @param array $variables
 *   An associative array containing:
 *   - element: An associative array containing the properties of the element.
 *     Properties used: #id, #attributes, #children.
 *
 * @see template_preprocess_container()
 */
function template_preprocess_content_sync_message(array &$variables) : void {
  $variables['has_parent'] = FALSE;
  $element = $variables['element'];
  // Ensure #attributes is set.
  $element += ['#attributes' => []];

  // Special handling for content_sync elements.
  if (isset($element['#array_parents'])) {
    // Assign an html ID.
    if (!isset($element['#attributes']['id'])) {
      $element['#attributes']['id'] = $element['#id'];
    }
    $variables['has_parent'] = TRUE;
  }

  $variables['message'] = $element['#message'];
  $variables['attributes'] = $element['#attributes'];
  if (isset($element['#closed'])) {
    $variables['closed'] = $element['#closed'];
  }

}
