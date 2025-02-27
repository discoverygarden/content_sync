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
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Returns responses for content module routes.
 */
class ContentController implements ContainerInjectionInterface {

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('content.storage'),
      $container->get('content.storage.sync'),
      $container->get('config.manager'),
      $container->get('diff.formatter'),
      $container->get('file_system'),
      $container->get('file.mime_type.guesser'),
    );
  }

  /**
   * Constructor.
   */
  public function __construct(
    protected StorageInterface $targetStorage,
    protected StorageInterface $sourceStorage,
    protected ConfigManagerInterface $contentManager,
    protected DiffFormatter $diffFormatter,
    protected FileSystemInterface $fileSystem,
    protected MimeTypeGuesserInterface $mimeTypeGuesser,
  ) {}

  /**
   * Downloads a tarball of the site content.
   */
  public function downloadExport() : BinaryFileResponse|int {
    $filename = 'content.tar.gz';
    $file_path = $this->fileSystem->getTempDirectory() . '/' . $filename;
    if (file_exists($file_path)) {
      unset($_SESSION['content_tar_download_file']);
      $mime = $this->mimeTypeGuesser->guessMimeType($file_path);
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
   * @return array
   *   Renderable array with table showing a two-way diff between the active and
   *   staged content.
   */
  public function diff($source_name, $target_name = NULL, $collection = NULL) : array {
    if (!isset($collection)) {
      $collection = StorageInterface::DEFAULT_COLLECTION;
    }
    $diff = $this->contentManager->diff($this->targetStorage, $this->sourceStorage, $source_name, $target_name, $collection);
    $this->diffFormatter->show_header = FALSE;

    $build = [];

    $build['#title'] = $this->t('View changes of @content_file', ['@content_file' => $source_name]);
    // Add the CSS for the inline diff.
    $build['#attached']['library'][] = 'system/diff';

    $build['diff'] = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['diff'],
      ],
      '#header' => [
        ['data' => $this->t('Active'), 'colspan' => '2'],
        ['data' => $this->t('Staged'), 'colspan' => '2'],
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
