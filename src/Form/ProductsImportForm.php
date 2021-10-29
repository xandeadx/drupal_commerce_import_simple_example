<?php

namespace Drupal\products_import\Form;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\ProductVariationStorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProductsImportForm extends FormBase {

  /**
   * {@inheritDoc}
   */
  public function getFormId(): string {
    return 'products_import_form';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('File'),
      '#description' => $this->t('Select CSV file.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start import'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Upload file to filesystem without create file entity
    $files = \Drupal::request()->files->get('files', []); /** @var UploadedFile[] $files */
    if ($files['file']) {
      $file_system = \Drupal::service('file_system'); /** @var FileSystemInterface $file_system */
      $file_system->move($files['file']->getRealPath(), 'private://products.csv', FileSystemInterface::EXISTS_REPLACE);
    }

    // Run batch
    if (file_exists('private://products.csv') && is_readable('private://products.csv')) {
      $operations = [];
      foreach ($this->csvToArray('private://products.csv') as $row) {
        $operations[] = [[static::class, 'importProduct'], [$row]];
      }

      batch_set([
        'title' => $this->t('Products import'),
        'operations' => $operations,
        'finished' => [static::class, 'batchFinished'],
      ]);
    }
  }

  /**
   * Convert csv file to array.
   */
  public function csvToArray(string $uri): array {
    $file_handle = fopen($uri, 'r');
    $header_row = fgetcsv($file_handle, 0, ';');
    $array = [];

    while (($row = fgetcsv($file_handle, 0, ';')) !== FALSE) {
      $array[] = array_combine($header_row, $row);
    }

    fclose($file_handle);
    return $array;
  }

  /**
   * Batch operation.
   */
  public static function importProduct(array $data, &$context): void {
    $variation_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation'); /** @var ProductVariationStorageInterface $variation_storage */
    $current_timestamp = \Drupal::time()->getCurrentTime();

    // Try load exists variation
    if ($variation = $variation_storage->loadBySku($data['sku'])) {
      $product = $variation->getProduct();
    }

    // Create variation if not exsists
    if (!$variation || !isset($product))  {
      if (!$variation) {
        $variation = ProductVariation::create([
          'type' => 'default',
          'sku' => $data['sku'],
        ]);
      }

      $product = Product::create([
        'type' => 'default',
        'stores' => 1,
      ]);

      $context['results']['created']++;
    }
    else {
      $context['results']['updated']++;
    }

    // Set fields
    $variation->setTitle($data['title']);
    $price_array = explode(' ', $data['price']);
    $variation->setPrice(new Price($price_array[0], $price_array[1]));
    $variation->setChangedTime($current_timestamp);
    $product->setTitle($data['title']);
    $product->setChangedTime($current_timestamp);

    // Save product and variation
    $variation->save();
    if ($product->isNew()) {
      $product->set('variations', $variation);
    }
    $product->save();
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations): void {
    \Drupal::messenger()->addMessage(t('Import finished. Created: @created, updated: @updated.', [
      '@created' => $results['created'] ?? 0,
      '@updated' => $results['updated'] ?? 0,
    ]));
  }

}
