<?php

declare(strict_types=1);

namespace Drupal\schema_org_llms\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for schema_org_llms.
 */
class SchemaOrgLlmsHooks {

  /**
   * Implements hook_config_translation_info_alter().
   *
   * Translates the llms.txt text in the same tab as the hotel profile.
   *
   * @param array<string, array<string, mixed>> $info
   *   The config translation mappers.
   */
  #[Hook('config_translation_info_alter')]
  public function configTranslationInfoAlter(array &$info): void {
    if (isset($info['schema_org.settings'])) {
      $info['schema_org.settings']['names'][] = 'schema_org_llms.settings';
    }
  }

}
