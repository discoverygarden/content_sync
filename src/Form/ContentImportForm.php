<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the content import form.
 */
class ContentImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'content_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) : array {
    $directory = content_sync_get_content_directory(ContentSyncManagerInterface::DEFAULT_DIRECTORY);
    $directory_is_writable = is_writable($directory);
    if (!$directory_is_writable) {
      $this->logger('content_sync')->error('The directory %directory is not writable.', [
        '%directory' => $directory,
        'link' => 'Import Archive',
      ]);
      $this->messenger()->addError($this->t('The directory %directory is not writable.', ['%directory' => $directory]));
    }

    $form['import_tarball'] = [
      '#type' => 'file',
      '#title' => $this->t('Configuration archive'),
      '#description' => $this->t('Allowed types: @extensions.', ['@extensions' => 'tar.gz tgz tar.bz2']),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload'),
      '#disabled' => !$directory_is_writable,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $all_files = $this->getRequest()->files->get('files', []);
    if (!empty($all_files['import_tarball'])) {
      $file_upload = $all_files['import_tarball'];
      if ($file_upload->isValid()) {
        $form_state->setValue('import_tarball', $file_upload->getRealPath());
        return;
      }
    }
    $form_state->setErrorByName('import_tarball', $this->t('The file could not be uploaded.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($path = $form_state->getValue('import_tarball')) {
      $directory = content_sync_get_content_directory(ContentSyncManagerInterface::DEFAULT_DIRECTORY);
      static::emptyDirectory($directory);
      try {
        $archiver = new ArchiveTar($path, 'gz');
        $files = [];
        foreach ($archiver->listContent() as $file) {
          $files[] = $file['filename'];
        }
        $archiver->extractList($files, $directory);
        $this->messenger()->addStatus($this->t('Your content files were successfully uploaded'));
        $this->logger('content_sync')->notice('Your content files were successfully uploaded', ['link' => 'Import Archive']);
        $form_state->setRedirect('content.sync');
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Could not extract the contents of the tar file. The error message is <em>@message</em>', ['@message' => $e->getMessage()]));
        $this->logger('content_sync')->error('Could not extract the contents of the tar file. The error message is <em>@message</em>', [
          '@message' => $e->getMessage(),
          'link' => 'Import Archive',
        ]);
      }
      drupal_flush_all_caches();
      unlink($path);
    }
  }

  /**
   * Help to empty a directory.
   */
  private static function emptyDirectory(string $dirname, bool $self_delete = FALSE) : bool {
    if (is_dir($dirname)) {
      $dir_handle = opendir($dirname);
    }
    else {
      // The passed name is not a directory?
      return FALSE;
    }

    if (!$dir_handle) {
      // We failed to open the given directory; missing permissions?
      return FALSE;
    }

    try {
      while ($file = readdir($dir_handle)) {
        if ($file !== "." && $file !== "..") {
          if (!is_dir($dirname . "/" . $file)) {
            @unlink($dirname . "/" . $file);
          }
          elseif (!static::emptyDirectory($dirname . '/' . $file, TRUE)) {
            // Failed to delete something inside...
            return FALSE;
          }
        }
      }
      if ($self_delete) {
        @rmdir($dirname);
      }
      return TRUE;
    }
    finally {
      closedir($dir_handle);
    }
  }

}
