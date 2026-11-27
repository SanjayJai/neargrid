<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use Illuminate\Validation\ValidationException;

final class AttributeValueValidator
{
    public function __construct(
        private readonly AttributeTypeRegistry $registry,
    ) {}

    /**
     * Validate an incoming attribute value against its definition and return
     * a normalized 4-column payload where only the matching typed column is populated.
     *
     * @return array{
     *     value_string: ?string,
     *     value_number: ?float,
     *     value_boolean: ?bool,
     *     value_json: ?list<string>,
     * }
     */
    public function validateAndNormalize(CategoryAttributeDefinition $definition, mixed $value, string $errorKey): array
    {
        if (! $this->registry->isSupported($definition->data_type)) {
            throw ValidationException::withMessages([
                $errorKey => ["Unsupported attribute type '{$definition->data_type}'."],
            ]);
        }

        $columns = [
            'value_string' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_json' => null,
        ];

        $options = $definition->options_json ?? [];

        switch ($definition->data_type) {
            case 'string':
                if (! is_string($value)) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be a text string."],
                    ]);
                }
                $trimmed = trim(str_replace("\0", '', $value));
                $maxLength = isset($options['max_length']) && is_numeric($options['max_length'])
                    ? min(500, max(1, (int) $options['max_length']))
                    : 500;
                $minLength = isset($options['min_length']) && is_numeric($options['min_length'])
                    ? max(0, (int) $options['min_length'])
                    : 1;

                if (mb_strlen($trimmed) < $minLength || mb_strlen($trimmed) > $maxLength) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be between {$minLength} and {$maxLength} characters."],
                    ]);
                }
                $columns['value_string'] = $trimmed;
                break;

            case 'number':
                if (is_bool($value) || is_array($value) || ! is_numeric($value) || ! is_finite((float) $value)) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be a valid finite number."],
                    ]);
                }
                $num = (float) $value;
                if (! empty($options['integer_only']) && floor($num) !== $num) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be a whole number."],
                    ]);
                }
                if (isset($options['min']) && is_numeric($options['min']) && $num < (float) $options['min']) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be at least {$options['min']}."],
                    ]);
                }
                if (isset($options['max']) && is_numeric($options['max']) && $num > (float) $options['max']) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be at most {$options['max']}."],
                    ]);
                }
                $columns['value_number'] = round($num, 4);
                break;

            case 'boolean':
                if (! is_bool($value)) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be a boolean (true or false)."],
                    ]);
                }
                $columns['value_boolean'] = $value;
                break;

            case 'enum':
                if (! is_string($value)) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be a valid option string."],
                    ]);
                }
                $trimmed = trim($value);
                $choices = $this->registry->extractChoices($definition);
                if (! in_array($trimmed, $choices, true)) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} contains an invalid selection '{$trimmed}'."],
                    ]);
                }
                $columns['value_string'] = $trimmed;
                break;

            case 'multi_enum':
                if (! is_array($value) || ! array_is_list($value)) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must be a list of selected options."],
                    ]);
                }
                $choices = $this->registry->extractChoices($definition);
                $normalized = [];
                foreach ($value as $item) {
                    if (! is_string($item)) {
                        throw ValidationException::withMessages([
                            $errorKey => ["{$definition->label_en} selections must be strings."],
                        ]);
                    }
                    $trimmed = trim($item);
                    if (! in_array($trimmed, $choices, true)) {
                        throw ValidationException::withMessages([
                            $errorKey => ["{$definition->label_en} contains an invalid selection '{$trimmed}'."],
                        ]);
                    }
                    $normalized[] = $trimmed;
                }
                $normalized = array_values(array_unique($normalized));
                if ($normalized === []) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} must contain at least one selection when provided."],
                    ]);
                }
                if (isset($options['max_items']) && is_numeric($options['max_items']) && count($normalized) > (int) $options['max_items']) {
                    throw ValidationException::withMessages([
                        $errorKey => ["{$definition->label_en} allows at most {$options['max_items']} selections."],
                    ]);
                }
                $columns['value_json'] = $normalized;
                break;
        }

        return $columns;
    }
}
