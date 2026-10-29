<?php

declare(strict_types=1);

namespace Drupal\schema_org_llms\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Configures the additional text of llms.txt.
 */
class LlmsTxtSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'schema_org_llms_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The config names.
   */
  protected function getEditableConfigNames(): array {
    return ['schema_org_llms.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('@link is generated automatically from the hotel profile, rooms, packages and hotel areas, so it is always up to date.', [
        '@link' => Link::fromTextAndUrl('/llms.txt', Url::fromRoute('schema_org_llms.llms_txt'))->toString(),
      ]),
    ];
    $form['intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional information'),
      '#description' => $this->t('Optional Markdown text shown below the summary, e.g. arrival, parking or what makes the hotel special. Translatable in the "Translate schema.org" tab.'),
      '#rows' => 8,
      '#config_target' => 'schema_org_llms.settings:intro',
    ];
    return parent::buildForm($form, $form_state);
  }

}
