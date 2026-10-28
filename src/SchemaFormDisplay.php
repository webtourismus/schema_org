<?php

declare(strict_types=1);

namespace Drupal\schema_org;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\FieldConfigInterface;

/**
 * Places the schema fields in a "Schema.org" tab of the node edit form.
 */
class SchemaFormDisplay {

  use StringTranslationTrait;

  /**
   * Name of the field group holding the schema fields.
   */
  public const GROUP = 'group_schema';

  /**
   * Name of the starterkit's horizontal tabs container.
   */
  protected const TABS_CONTAINER = 'group_container';

  /**
   * Widgets of the schema fields, in display order.
   */
  protected const WIDGETS = [
    SchemaGraphBuilder::FIELD_BED_TYPE => ['type' => 'options_select'],
    SchemaGraphBuilder::FIELD_BED_COUNT => ['type' => 'number', 'settings' => ['placeholder' => '']],
    SchemaGraphBuilder::FIELD_SMOKING => ['type' => 'options_select'],
    SchemaGraphBuilder::FIELD_PETS => ['type' => 'options_select'],
    SchemaGraphBuilder::FIELD_CATEGORY => ['type' => 'options_buttons'],
    SchemaGraphBuilder::FIELD_AUDIENCE => ['type' => 'options_buttons'],
    SchemaGraphBuilder::FIELD_INCLUSIONS => [
      'type' => 'string_textfield',
      'settings' => ['size' => 60, 'placeholder' => ''],
    ],
    SchemaGraphBuilder::FIELD_PRICE_UNIT => ['type' => 'options_select'],
    SchemaGraphBuilder::FIELD_VALIDITY => ['type' => 'daterange_default'],
    SchemaGraphBuilder::FIELD_JSON => [
      'type' => 'string_textarea',
      'settings' => ['rows' => 6, 'placeholder' => '{"award": "…"}'],
    ],
  ];

  public function __construct(
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Adds a newly created schema field to the default node form display.
   */
  public function addField(FieldConfigInterface $field): void {
    $fieldName = $field->getName();
    if ($field->getTargetEntityTypeId() !== 'node' || !isset(static::WIDGETS[$fieldName])) {
      return;
    }

    $display = $this->entityDisplayRepository->getFormDisplay('node', $field->getTargetBundle());
    $weight = 100 + (int) array_search($fieldName, array_keys(static::WIDGETS), TRUE);
    $display->setComponent($fieldName, static::WIDGETS[$fieldName] + [
      'weight' => $weight,
      'region' => 'content',
    ]);
    if ($this->moduleHandler->moduleExists('field_group')) {
      $this->addToGroup($display, $fieldName);
    }
    $display->save();
  }

  /**
   * Removes the schema field group from all node form displays.
   */
  public function removeGroups(): void {
    if (!$this->moduleHandler->moduleExists('field_group')) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('entity_form_display');
    foreach ($storage->loadByProperties(['targetEntityType' => 'node']) as $display) {
      assert($display instanceof EntityFormDisplayInterface);
      $groups = $display->getThirdPartySettings('field_group');
      if (!isset($groups[static::GROUP])) {
        continue;
      }
      $parent = $groups[static::GROUP]['parent_name'] ?? '';
      if ($parent && isset($groups[$parent]['children'])) {
        $groups[$parent]['children'] = array_values(array_diff($groups[$parent]['children'], [static::GROUP]));
        $display->setThirdPartySetting('field_group', $parent, $groups[$parent]);
      }
      $display->unsetThirdPartySetting('field_group', static::GROUP);
      $display->save();
    }
  }

  /**
   * Adds a field to the schema group, creating the group if needed.
   *
   * If the form uses the starterkit's horizontal tabs container, the group
   * becomes its last tab, otherwise a collapsed details element.
   */
  protected function addToGroup(EntityFormDisplayInterface $display, string $fieldName): void {
    $groups = $display->getThirdPartySettings('field_group');

    if (!isset($groups[static::GROUP])) {
      $container = $groups[static::TABS_CONTAINER] ?? [];
      $parent = ($container['format_type'] ?? '') === 'tabs' ? static::TABS_CONTAINER : '';
      $groups[static::GROUP] = [
        'children' => [],
        'label' => 'Schema.org',
        'region' => 'content',
        'parent_name' => $parent,
        'weight' => 100,
        'format_type' => $parent ? 'tab' : 'details',
        'format_settings' => [
          'classes' => '',
          'show_empty_fields' => TRUE,
          'id' => '',
          'description' => (string) $this->t('Additional data for search engines and AI assistants (schema.org). Not visible on the website.'),
          'required_fields' => FALSE,
        ] + ($parent ? ['formatter' => 'closed'] : ['open' => FALSE]),
      ];
      if ($parent && !in_array(static::GROUP, $container['children'] ?? [], TRUE)) {
        $container['children'][] = static::GROUP;
        $display->setThirdPartySetting('field_group', $parent, $container);
      }
    }

    if (!in_array($fieldName, $groups[static::GROUP]['children'], TRUE)) {
      $groups[static::GROUP]['children'][] = $fieldName;
      // Keep the children in the order of the widgets.
      $order = array_flip(array_keys(static::WIDGETS));
      usort($groups[static::GROUP]['children'], static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    }
    $display->setThirdPartySetting('field_group', static::GROUP, $groups[static::GROUP]);

    // Field groups render their children by weight. Re-weight all children,
    // so fields added in later versions are sorted in as well.
    foreach ($groups[static::GROUP]['children'] as $index => $child) {
      $component = $display->getComponent($child);
      if ($component !== NULL) {
        $display->setComponent($child, ['weight' => 100 + $index] + $component);
      }
    }
  }

}
