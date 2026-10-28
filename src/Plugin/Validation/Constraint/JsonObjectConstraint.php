<?php

declare(strict_types=1);

namespace Drupal\schema_org\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a string is empty or a valid JSON object.
 *
 * Used for the "additional properties" escape hatch on the settings form and
 * on rooms and packages, where editors may add schema.org properties that are
 * not covered by dedicated fields.
 */
#[Constraint(
  id: 'SchemaOrgJsonObject',
  label: new TranslatableMarkup('JSON object', [], ['context' => 'Validation']),
)]
class JsonObjectConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    public string $message = 'The value must be a JSON object, for example {"award": "Best family hotel 2026"}. Error: @error',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($options, $groups, $payload);
  }

}
