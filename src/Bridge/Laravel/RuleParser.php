<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

/**
 * Converts Laravel validation rules to an OpenAPI schema.
 * Pure PHP — no Illuminate runtime required for the parsing logic itself.
 */
final class RuleParser
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function toSchema(array $rules): array
    {
        $schema = ['type' => 'object', 'properties' => []];

        foreach ($rules as $field => $fieldRules) {
            if (str_contains($field, '.*.') || str_ends_with($field, '.*')) {
                continue;
            }
            $schema['properties'][$field] = $this->fieldSchema($field, $fieldRules, $rules);
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return string[]
     */
    public function required(array $rules): array
    {
        $req = [];
        foreach ($rules as $field => $fieldRules) {
            if (str_contains($field, '.') || str_ends_with($field, '.*')) {
                continue;
            }
            $list = $this->normalize($fieldRules);
            if (in_array('required', $list, true) && ! in_array('nullable', $list, true)) {
                $req[] = $field;
            }
        }

        return $req;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function fieldSchema(string $field, mixed $fieldRules, array $allRules): array
    {
        $list = $this->normalize($fieldRules);
        $type = $this->inferType($list);
        $schema = ['type' => $type];

        if (in_array('nullable', $list, true)) {
            // OpenAPI 3.1: nullable encoded as a type-array, not the deprecated `nullable` keyword.
            $schema['type'] = [$type, 'null'];
        }

        // String-specific
        if ($type === 'string') {
            foreach ($list as $r) {
                if (str_starts_with($r, 'min:')) {
                    $schema['minLength'] = (int) substr($r, 4);
                }
                if (str_starts_with($r, 'max:')) {
                    $schema['maxLength'] = (int) substr($r, 4);
                }
                if ($r === 'email') {
                    $schema['format'] = 'email';
                }
                if ($r === 'url') {
                    $schema['format'] = 'uri';
                }
                if ($r === 'uuid') {
                    $schema['format'] = 'uuid';
                }
                if ($r === 'date') {
                    $schema['format'] = 'date';
                }
                if ($r === 'ip' || $r === 'ipv4') {
                    $schema['format'] = 'ipv4';
                }
                if ($r === 'ipv6') {
                    $schema['format'] = 'ipv6';
                }
                if (str_starts_with($r, 'regex:')) {
                    $schema['pattern'] = substr($r, 6);
                }
                if (str_starts_with($r, 'size:')) {
                    $n = (int) substr($r, 5);
                    $schema['minLength'] = $n;
                    $schema['maxLength'] = $n;
                }
            }
        }

        // Numeric
        if (in_array($type, ['integer', 'number'], true)) {
            foreach ($list as $r) {
                if (str_starts_with($r, 'min:')) {
                    $schema['minimum'] = (int) substr($r, 4);
                }
                if (str_starts_with($r, 'max:')) {
                    $schema['maximum'] = (int) substr($r, 4);
                }
                if (str_starts_with($r, 'between:')) {
                    [$mn, $mx] = explode(',', substr($r, 8), 2);
                    $schema['minimum'] = (int) $mn;
                    $schema['maximum'] = (int) $mx;
                }
            }
        }

        // Enum
        foreach ($list as $r) {
            if (str_starts_with($r, 'in:')) {
                $schema['enum'] = array_map('trim', explode(',', substr($r, 3)));
            }
        }

        // Array with items
        if ($type === 'array') {
            $itemKey = $field.'.*';
            if (isset($allRules[$itemKey])) {
                $schema['items'] = $this->fieldSchema($itemKey, $allRules[$itemKey], $allRules);
            } else {
                $schema['items'] = new \stdClass;
            }
            foreach ($list as $r) {
                if (str_starts_with($r, 'min:')) {
                    $schema['minItems'] = (int) substr($r, 4);
                }
                if (str_starts_with($r, 'max:')) {
                    $schema['maxItems'] = (int) substr($r, 4);
                }
            }
        }

        // File
        if ($this->anyOf($list, ['file', 'image', 'mimes', 'mimetypes'])) {
            $schema = ['type' => 'string', 'format' => 'binary'];
        }

        return $schema;
    }

    /** @return string[] */
    private function normalize(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }
        $result = [];
        foreach ((array) $rules as $r) {
            if (is_string($r)) {
                foreach (explode('|', $r) as $part) {
                    $result[] = trim($part);
                }
            } elseif (is_object($r)) {
                $result[] = (string) $r;
            }
        }

        return $result;
    }

    private function inferType(array $rules): string
    {
        if ($this->anyOf($rules, ['integer', 'digits', 'digits_between'])) {
            return 'integer';
        }
        if ($this->anyOf($rules, ['numeric', 'decimal'])) {
            return 'number';
        }
        if ($this->anyOf($rules, ['boolean', 'accepted', 'declined'])) {
            return 'boolean';
        }
        if ($this->anyOf($rules, ['array'])) {
            return 'array';
        }

        return 'string';
    }

    private function anyOf(array $rules, array $targets): bool
    {
        foreach ($rules as $r) {
            foreach ($targets as $t) {
                if ($r === $t || str_starts_with($r, $t.':')) {
                    return true;
                }
            }
        }

        return false;
    }
}
