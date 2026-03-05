CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Installation
 * Configuration
 * Support
 * Maintainers

INTRODUCTION
------------

The content synchronization module provides a mechanism to export single content
items, or all content items, from an environment, and move them to another,
effortlessly.


INSTALLATION
------------

Extract module at drupal/modules/contrib directory and enable it from browser
by going in this path /admin/modules.

The `CONTENT_SYNC__SUPPRESS_SNAPSHOT_ON_INSTALL` environment variable might be set dto soemthing truthy in order to skip the build of the snapshot during module installation, with the expectation that the snapshot will be built by other means, such as the `drush content-sync:snapshot` command ( https://github.com/discoverygarden/content_sync/blob/7816728adc70b3c85642bd254b9096a44a1d0308/src/Drush/Commands/ContentSyncCommands.php#L519-L528 ).


CONFIGURATION
-------------

Configure at admin/config/development/content.


SUPPORT
-------

This open source project is supported by the Drupal.org community. To report a
bug, request a feature, or upgrade to the latest version, please visit the
project page: http://drupal.org/project/content_sync


MAINTAINERS
-----------

Blanca Esqueda (Blanca.Esqueda)
https://www.drupal.org/u/blancaesqueda

David Gil Hidalgo 
https://www.drupal.org/u/dabito

David Nova (david4lim)
https://www.drupal.org/u/david4lim


