<?php

namespace App\Support;

final class AnthropicJsonSchema
{
    /**
     * Anthropic Structured Outputs accepts a JSON Schema subset. The raw HTTP
     * integration must remove validation keywords that its SDKs would normally
     * transform automatically. Application-side normalization still enforces
     * these bounds after decoding the response.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function sanitize(array $schema): array
    {
        foreach ([
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
            'minLength', 'maxLength',
            'minItems', 'maxItems', 'uniqueItems', 'contains', 'minContains', 'maxContains',
            'minProperties', 'maxProperties',
        ] as $unsupported) {
            unset($schema[$unsupported]);
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = self::sanitize($value);
            }
        }

        return $schema;
    }
}
