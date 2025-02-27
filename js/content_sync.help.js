/**
 * @file
 * JavaScript behaviors for help.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Handles help accordion.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior for help accordion.
   */
  Drupal.behaviors.content_syncHelpAccordion = {
    attach: function (context) {
      once('content_sync-help-accordion', '.content_sync-help-accordion', context).forEach((element) => {
        var $widget = $(element);

        $widget.accordion({
          header: 'h2',
          collapsible: TRUE,
          heightStyle: 'content'
        });

        if (location.hash) {
          var $container = $('h2' + location.hash, $widget);
          if ($container.length) {
            var active = $widget.find($widget.accordion('option', 'header')).index($container);
            $widget.accordion('option', 'active', active);
          }
        }
      });
    }
  };

  /**
   * Handles disabling help dialog for mobile devices.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior for disabling help dialog for mobile devices.
   */
  Drupal.behaviors.content_syncHelpDialog = {
    attach: function (context) {
      once('content_sync-help-dialog', '.button-content_sync-play', context).forEach((element) => {
        $(element).on('click', function (event) {
          if ($(window).width() < 768) {
            event.stopImmediatePropagation();
          }
        }).each(function () {
          // Must make sure that this click event handler is execute first and
          // before the Ajax dialog handler.
          // @see http://stackoverflow.com/questions/2360655/jquery-event-handlers-always-execute-in-order-they-were-bound-any-way-around-t
          var handlers = $._data(this, 'events')['click'];
          var handler = handlers.pop();
          // Move it at the beginning.
          handlers.splice(0, 0, handler);
        });
      });
    }
  };

})(jQuery, Drupal, once);
