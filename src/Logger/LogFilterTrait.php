<?php

namespace Drupal\content_sync\src\Logger;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Helper trait; define filters for where they are used.
 */
trait LogFilterTrait {

  use StringTranslationTrait;

  /**
   * Creates a list of database log administration filters that can be applied.
   *
   * @return array
   *   Associative array of filters. The top-level keys are used as the form
   *   element names for the filters, and the values are arrays containing:
   *   - title: Title of the filter.
   *   - where: The filter condition.
   *   - options: Array of options for the select list for the filter.
   */
  protected function getFilters() : array {
    $filters = [];

    $types = [];

    // Optional(?) dblog integration.
    if (function_exists('_dblog_get_message_types')) {
      foreach (_dblog_get_message_types() as $type) {
        $types[$type] = $type;
      }
    }

    if (!empty($types)) {
      $filters['type'] = [
        'title' => t('Type'),
        'where' => "w.type = ?",
        'options' => $types,
      ];
    }

    $filters['severity'] = [
      'title' => t('Severity'),
      'where' => 'w.severity = ?',
      'options' => RfcLogLevel::getLevels(),
    ];

    return $filters;
  }

}
