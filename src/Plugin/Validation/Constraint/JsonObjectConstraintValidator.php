<?php

declare(strict_types=1);

namespace Drupal\schema_org\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the SchemaOrgJsonObject constraint.
 */
class JsonObjectConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof JsonObjectConstraint) {
      throw new UnexpectedTypeException($constraint, JsonObjectConstraint::class);
    }
    if ($value === NULL || trim((string) $value) === '') {
      return;
    }

    try {
      $decoded = json_decode((string) $value, FALSE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->context->addViolation($constraint->message, ['@error' => $e->getMessage()]);
      return;
    }

    if (!$decoded instanceof \stdClass) {
      $this->context->addViolation($constraint->message, ['@error' => 'Top-level value is not an object.']);
    }
  }

}
