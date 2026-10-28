<?php

declare(strict_types=1);

namespace Drupal\schema_org\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\field\FieldConfigInterface as FieldEntityConfigInterface;
use Drupal\schema_org\SchemaFormDisplay;
use Drupal\schema_org\SchemaGraphBuilder;

/**
 * Hook implementations for schema_org.
 */
class SchemaOrgHooks {

  use StringTranslationTrait;

  public function __construct(
    protected SchemaGraphBuilder $graphBuilder,
    protected SchemaFormDisplay $formDisplay,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for field_config.
   *
   * Places schema fields in the "Schema.org" tab when they are created, e.g.
   * on module install or when a room content type is added later. During
   * config sync, the synced form displays already contain the tab.
   */
  #[Hook('field_config_insert')]
  public function fieldConfigInsert(FieldEntityConfigInterface $field): void {
    if (!$field->isSyncing()) {
      $this->formDisplay->addField($field);
    }
  }

  /**
   * Implements hook_page_attachments().
   *
   * Adds the schema.org graph as a single JSON-LD script to the <head>.
   *
   * @param array<mixed> $attachments
   *   The page attachments.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $cacheability = CacheableMetadata::createFromRenderArray($attachments);
    $document = $this->graphBuilder->build($cacheability);
    $cacheability->applyTo($attachments);
    if ($document === []) {
      return;
    }

    // JSON_HEX_TAG encodes "<" and ">", so the JSON can never close the
    // <script> element, which makes it safe to mark as markup.
    $json = json_encode($document, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $attachments['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        '#value' => Markup::create($json),
      ],
      'schema_org_graph',
    ];
  }

  /**
   * Implements hook_entity_bundle_field_info_alter().
   *
   * Validates the "additional schema.org properties" field as JSON object.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $fields
   *   The bundle field definitions.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() !== 'node' || !isset($fields[SchemaGraphBuilder::FIELD_JSON])) {
      return;
    }
    $field = $fields[SchemaGraphBuilder::FIELD_JSON];
    if ($field instanceof FieldConfigInterface) {
      $field->addPropertyConstraints('value', ['SchemaOrgJsonObject' => []]);
    }
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'schema_org.settings') {
      return NULL;
    }
    $validator = Url::fromUri('https://validator.schema.org/')->toString();
    $richResults = Url::fromUri('https://search.google.com/test/rich-results')->toString();
    return '<p>' . $this->t('These details describe the hotel in the schema.org JSON-LD graph that is rendered into every frontend page. Search engines and AI assistants use it to understand the hotel, its rooms and its offers. Rooms and packages have an additional "Schema.org" tab for data not covered by the regular fields.') . '</p>'
      . '<p>' . $this->t('Check the output with the <a href=":validator">Schema.org validator</a> or the <a href=":rich">Google Rich Results Test</a>.', [
        ':validator' => $validator,
        ':rich' => $richResults,
      ]) . '</p>';
  }

}
