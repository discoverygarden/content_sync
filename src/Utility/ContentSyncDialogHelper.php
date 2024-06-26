<?php

namespace Drupal\content_sync\Utility;

use Drupal\Component\Serialization\Json;
use Drupal\imce\Imce;

/**
 * Helper class for dialog methods.
 */
class ContentSyncDialogHelper {

  /**
   * Off canvas trigger name.
   *
   * @var string
   */
  protected static $offCanvasTriggerName;

  /**
   * Get Off canvas trigger name.
   *
   * @return string
   *   The off canvas trigger name.
   *
   * @see Issue #2862625: Rename offcanvas to two words in code and comments.
   * @see https://www.drupal.org/node/2862625
   */
  public static function getOffCanvasTriggerName() {
    if (isset(self::$offCanvasTriggerName)) {
      return self::$offCanvasTriggerName;
    }

    $main_content_renderers = \Drupal::getContainer()->getParameter('main_content_renderers');

    if (isset($main_content_renderers['drupal_dialog_offcanvas'])) {
      self::$offCanvasTriggerName = 'offcanvas';
    }
    else {
      self::$offCanvasTriggerName = 'off_canvas';
    }

    return self::$offCanvasTriggerName;
  }

  /**
   * Use outside-in off-canvas system tray instead of dialogs.
   *
   * @return bool
   *   TRUE if outside_in.module is enabled and system trays are not disabled.
   */
  public static function useOffCanvas() {
    return ((floatval(\Drupal::VERSION) >= 8.3) && \Drupal::moduleHandler()->moduleExists('outside_in') && !\Drupal::config('content_sync.settings')->get('ui.offcanvas_disabled')) ? TRUE : FALSE;
  }

  /**
   * Attach libraries required by (modal) dialogs.
   *
   * @param array $build
   *   A render array.
   */
  public static function attachLibraries(array &$build) {
    $build['#attached']['library'][] = 'content_sync/content_sync.admin.dialog';
  }

  /**
   * Get modal dialog attributes.
   *
   * @param int $width
   *   Width of the modal dialog.
   * @param array $class
   *   Additional class names to be included in the dialog's attributes.
   *
   * @return array
   *   Modal dialog attributes.
   */
  public static function getModalDialogAttributes($width = 800, array $class = []) {
    if (\Drupal::config('content_sync.settings')->get('ui.dialog_disabled')) {
      return $class ? ['class' => $class] : [];
    }
    else {
      $class[] = 'use-ajax';
      if (self::useOffCanvas()) {
        return [
          'class' => $class,
          'data-dialog-type' => 'dialog',
          'data-dialog-renderer' => self::getOffCanvasTriggerName(),
          'data-dialog-options' => Json::encode([
            'width' => ($width > 480) ? 480 : $width,
            // @todo Decide if we want to use 'Outside In' custom system tray styling.
            // 'dialogClass' => 'ui-dialog-outside-in',
          ]),
        ];
      }
      else {
        return [
          'class' => $class,
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => $width,
          ]),
        ];
      }
    }
  }

}
