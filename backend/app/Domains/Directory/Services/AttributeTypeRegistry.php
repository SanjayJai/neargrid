<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AttributeTypeRegistry
{
    public const SUPPORTED_TYPES = [
        'string',
        'number',
        'boolean',
        'enum',
        'multi_enum',
    ];

    public const VALUE_COLUMNS = [
        'value_string',
        'value_number',
        'value_boolean',
        'value_json',
    ];

    public function isSupported(string $dataType): bool
    {
        return in_array($dataType, self::SUPPORTED_TYPES, true);
    }

    public function typedColumnFor(string $dataType): string
    {
        return match ($dataType) {
            'string', 'enum' => 'value_string',
            'number' => 'value_number',
            'boolean' => 'value_boolean',
            'multi_enum' => 'value_json',
            default => throw new InvalidArgumentException("Unsupported attribute data_type: {$dataType}"),
        };
    }

    /**
     * Extract normalized allowed choice keys for enum / multi_enum definitions.
     *
     * @return list<string>
     */
    public function extractChoices(CategoryAttributeDefinition $definition): array
    {
        $options = $definition->options_json ?? [];
        $rawChoices = $options['choices'] ?? $options['options'] ?? [];
        if (! is_array($rawChoices)) {
            return [];
        }

        $choices = [];
        foreach ($rawChoices as $item) {
            if (is_string($item) && trim($item) !== '') {
                $choices[] = trim($item);
            } elseif (is_array($item)) {
                $val = $item['value'] ?? $item['key'] ?? null;
                if (is_string($val) && trim($val) !== '') {
                    $choices[] = trim($val);
                }
            }
        }

        return array_values(array_unique($choices));
    }

    /**
     * Validate admin-configured options_json for a given data_type.
     *
     * @param  array<string, mixed>|null  $options
     */
    public function validateDefinitionOptions(string $dataType, ?array $options, string $fieldPrefix = 'options_json'): void
    {
        $options ??= [];

        if (in_array($dataType, ['enum', 'multi_enum'], true)) {
            $rawChoices = $options['choices'] ?? $options['options'] ?? null;
            if (! is_array($rawChoices) || $rawChoices === []) {
                throw ValidationException::withMessages([
                    $fieldPrefix => ["Attribute type '{$dataType}' requires a non-empty choices list."],
                ]);
            }

            foreach ($rawChoices as $item) {
                $val = is_string($item) ? trim($item) : (is_array($item) ? trim((string) ($item['value'] ?? $item['key'] ?? '')) : '');
                if ($val === '' || mb_strlen($val) > 120) {
                    throw ValidationException::withMessages([
                        $fieldPrefix => ["Each choice for '{$dataType}' must be a non-empty string up to 120 characters."],
                    ]);
                }
            }
        }

        if ($dataType === 'number') {
            $min = $options['min'] ?? null;
            $max = $options['max'] ?? null;
            $this->validateRangeFilterBounds($min, $max, "{$fieldPrefix}.min", "{$fieldPrefix}.max");

            if (isset($options['step']) && (! is_numeric($options['step']) || (float) $options['step'] <= 0)) {
                throw ValidationException::withMessages([
                    "{$fieldPrefix}.step" => ['Number step must be greater than zero.'],
                ]);
            }
        }
    }

    /**
     * Validate exact range filter minimum and maximum bounds.
     *
     * @return array{min: ?float, max: ?float}
     */
    public function validateRangeFilterBounds(
        mixed $min,
        mixed $max,
        string $minKey = 'min',
        string $maxKey = 'max',
    ): array {
        $parsedMin = null;
        $parsedMax = null;

        if ($min !== null && $min !== '') {
            if (is_bool($min) || ! is_numeric($min) || ! is_finite((float) $min)) {
                throw ValidationException::withMessages([
                    $minKey => ['Range minimum must be a finite number.'],
                ]);
            }
            $parsedMin = (float) $min;
        }

        if ($max !== null && $max !== '') {
            if (is_bool($max) || ! is_numeric($max) || ! is_finite((float) $max)) {
                throw ValidationException::withMessages([
                    $maxKey => ['Range maximum must be a finite number.'],
                ]);
            }
            $parsedMax = (float) $max;
        }

        if ($parsedMin !== null && $parsedMax !== null && $parsedMin > $parsedMax) {
            throw ValidationException::withMessages([
                $minKey => ['Range minimum cannot exceed range maximum.'],
            ]);
        }

        return [
            'min' => $parsedMin,
            'max' => $parsedMax,
        ];
    }
}
