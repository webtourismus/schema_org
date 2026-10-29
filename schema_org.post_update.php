<?php

/**
 * @file
 * Post update functions for the schema_org module.
 */

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\node\Entity\NodeType;
use Drupal\schema_org\SchemaGraphBuilder;

/**
 * Adds category, target group and inclusions fields to packages.
 */
function schema_org_post_update_package_category_audience_inclusions(): TranslatableMarkup {
  $created = _schema_org_install_fields([
    SchemaGraphBuilder::FIELD_CATEGORY,
    SchemaGraphBuilder::FIELD_AUDIENCE,
    SchemaGraphBuilder::FIELD_INCLUSIONS,
  ]);
  return new TranslatableMarkup('Created schema.org fields: @fields.', [
    '@fields' => $created ? implode(', ', $created) : '-',
  ]);
}

/**
 * Adds the page type and additional JSON fields to pages.
 */
function schema_org_post_update_page_type(): TranslatableMarkup {
  $created = _schema_org_install_fields([
    SchemaGraphBuilder::FIELD_PAGE_TYPE,
    SchemaGraphBuilder::FIELD_JSON,
  ]);
  return new TranslatableMarkup('Created schema.org fields: @fields.', [
    '@fields' => $created ? implode(', ', $created) : '-',
  ]);
}

/**
 * Creates schema fields from the module's shipped configuration.
 *
 * Field storages are created from config/install (with their translations),
 * fields from config/optional, if their content type exists. Existing
 * configuration is never overwritten.
 *
 * @param string[] $fieldNames
 *   The field names.
 *
 * @return string[]
 *   The IDs of the created field storages and fields.
 */
function _schema_org_install_fields(array $fieldNames): array {
  $path = \Drupal::service('extension.list.module')->getPath('schema_org');
  $install = new FileStorage($path . '/config/install');
  $optional = new FileStorage($path . '/config/optional');
  $languageManager = \Drupal::languageManager();
  $entityTypeManager = \Drupal::entityTypeManager();
  $created = [];

  foreach ($fieldNames as $fieldName) {
    $storageName = "field.storage.node.$fieldName";
    if (!FieldStorageConfig::load("node.$fieldName") && ($data = $install->read($storageName))) {
      // Like the config installer, create from the storage record, so the
      // config format of settings (e.g. allowed values) is converted.
      $entityTypeManager->getStorage('field_storage_config')->createFromStorageRecord($data)->save();
      $created[] = "node.$fieldName";

      // Install shipped translations, e.g. English option labels.
      if ($languageManager instanceof ConfigurableLanguageManagerInterface) {
        foreach ($install->getAllCollectionNames() as $collection) {
          $langcode = substr($collection, strlen('language.'));
          if (!str_starts_with($collection, 'language.') || !$languageManager->getLanguage($langcode)) {
            continue;
          }
          $translation = $install->createCollection($collection)->read($storageName);
          $override = $languageManager->getLanguageConfigOverride($langcode, $storageName);
          if ($translation && $override->isNew()) {
            $override->setData($translation)->save();
          }
        }
      }
    }

    foreach ($optional->listAll("field.field.node.") as $fieldConfigName) {
      [, , , $bundle, $name] = explode('.', $fieldConfigName);
      if ($name !== $fieldName || !NodeType::load($bundle) || FieldConfig::loadByName('node', $bundle, $name)) {
        continue;
      }
      $data = $optional->read($fieldConfigName);
      if (!$data) {
        continue;
      }
      // Creating the field also places it in the "Schema.org" form tab.
      // @see \Drupal\schema_org\Hook\SchemaOrgHooks::fieldConfigInsert()
      $entityTypeManager->getStorage('field_config')->createFromStorageRecord($data)->save();
      $created[] = "node.$bundle.$name";
    }
  }

  return $created;
}
