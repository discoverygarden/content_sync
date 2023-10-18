<?php

namespace Drupal\content_sync\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Diff\DiffFormatter;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mime\Header\Headers;

/**
 * Returns responses for content module routes.
 */
class ContentController implements ContainerInjectionInterface {
  /**
   * The target storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $targetStorage;

  /**
   * The source storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $sourceStorage;

  /**
   * The content manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $contentManager;

  /**
   * The file download controller.
   *
   * @var \Drupal\system\FileDownloadController
   */
  protected $fileDownloadController;

  /**
   * The diff formatter.
   *
   * @var \Drupal\Core\Diff\DiffFormatter
   */
  protected $diffFormatter;

  /**
   * The filesystem service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

    /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content.storage'),
      $container->get('content.storage.sync'),
      $container->get('config.manager'),
      $container->get('diff.formatter'),
      $container->get('file_system')
    );
  }

  /**
   * Constructs a ContentController object.
   *
   * @param \Drupal\Core\Config\StorageInterface $target_storage
   *   The target storage.
   * @param \Drupal\Core\Config\StorageInterface $source_storage
   *   The source storage
   * @param \Drupal\system\FileDownloadController $file_download_controller
   *   The file download controller.
   */
  public function __construct(StorageInterface $target_storage, StorageInterface $source_storage, ConfigManagerInterface $content_manager, DiffFormatter $diff_formatter, FileSystemInterface $file_system) {
    $this->targetStorage = $target_storage;
    $this->sourceStorage = $source_storage;
    $this->contentManager = $content_manager;
    $this->diffFormatter = $diff_formatter;
    $this->fileSystem = $file_system;
  }

  /**
   * Downloads a tarball of the site content.
   */
  public function downloadExport() {
    // NOTE:  Getting - You are not authorized to access this page.
    //$request = new Request(['file' => 'content.tar.gz']);
    //return $this->fileDownloadController->download($request, 'temporary');
    $filename = 'content.tar.gz';
    $file_path = $this->fileSystem->getTempDirectory() . '/' . $filename;
    if (file_exists($file_path) ) {
      unset($_SESSION['content_tar_download_file']);
      $mime = \Drupal::service('file.mime_type.guesser')->guess($file_path);
      $headers = (new Headers())
        ->addParameterizedHeader('Content-Type', $mime, ['name' => basename($file_path)])
        ->addTextHeader('Content-Length', filesize($file_path))
        ->addParameterizedHeader('Content-Disposition', 'attachment', ['filename' => $filename])
        ->addTextHeader('Cache-Control', 'private');
      $header_bag = new ResponseHeaderBag();
      foreach ($headers->all() as $name => $value) {
        $header_bag->set($name, $value);
      }

      return new BinaryFileResponse($file_path, 200, $header_bag->all());
    }
    return -1;
  }

  /**
   * Shows diff of specified content file.
   *
   * @param string $source_name
   *   The name of the content file.
   * @param string $target_name
   *   (optional) The name of the target content file if different from
   *   the $source_name.
   * @param string $collection
   *   (optional) The content collection name. Defaults to the default
   *   collection.
   *
   * @return string
   *   Table showing a two-way diff between the active and staged content.
   */
  public function diff($source_name, $target_name = NULL, $collection = NULL) {
    if (!isset($collection)) {
      $collection = StorageInterface::DEFAULT_COLLECTION;
    }
    $diff = $this->contentManager->diff($this->targetStorage, $this->sourceStorage, $source_name, $target_name, $collection);
    $this->diffFormatter->show_header = FALSE;

    $build = [];

    $build['#title'] = t('View changes of @content_file', ['@content_file' => $source_name]);
    // Add the CSS for the inline diff.
    $build['#attached']['library'][] = 'system/diff';

    $build['diff'] = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['diff'],
      ],
      '#header' => [
        ['data' => t('Active'), 'colspan' => '2'],
        ['data' => t('Staged'), 'colspan' => '2'],
      ],
      '#rows' => $this->diffFormatter->format($diff),
    ];

    $build['back'] = [
      '#type' => 'link',
      '#attributes' => [
        'class' => [
          'dialog-cancel',
        ],
      ],
      '#title' => "Back to 'Synchronize content' page.",
      '#url' => Url::fromRoute('content.sync'),
    ];

    return $build;
  }

}
