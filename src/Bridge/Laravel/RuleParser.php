<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

/**
 * Converts Laravel validation rules to an OpenAPI schema.
 * Pure PHP  no Illuminate runtime required for the parsing logic itself.
 *
 * Dotted rule keys become nested schemas, matching the JSON payload Laravel
 * actually validates:
 *
 *   'author.name'  => 'required|string'   → properties.author.properties.name
 *   'items.*.sku'  => 'required|string'   → properties.items.items.properties.sku
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
            if (! is_string($field) || $field === '') {
                continue;
            }

            // A trailing or doubled dot ("author." / "a..b") would otherwise
            // create a property with an empty name.
            $segments = array_values(array_filter(
                explode('.', $field),
                static fn (string $s): bool => $s !== '',
            ));
            if ($segments === []) {
                continue;
            }

            $leaf = $this->fieldSchema($field, $fieldRules, $rules);
            $isRequired = $this->isRequired($this->normalize($fieldRules));

            $this->insert($schema, $segments, $leaf, $isRequired);
        }

        // An empty PHP array encodes as `[]`, and `properties: []` is not a
        // valid JSON Schema  drop the key instead.
        if (($schema['properties'] ?? null) === []) {
            unset($schema['properties']);
        }

        return $schema;
    }

    /**
     * Top-level required field names.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    public function required(array $rules): array
    {
        $req = [];
        foreach ($rules as $field => $fieldRules) {
            if (! is_string($field) || $field === '' || $field === '*' || str_contains($field, '.')) {
                continue;
            }
            if ($this->isRequired($this->normalize($fieldRules))) {
                $req[] = $field;
            }
        }

        return $req;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Graft a leaf schema onto the tree at $segments, creating intermediate
     * object/array nodes as needed.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $leaf
     */
    private function insert(array &$node, array $segments, array $leaf, bool $isRequired): void
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            $node['type'] = self::retype($node, 'array');
            // `required` and the string keywords belong to the type we just left.
            unset($node['properties'], $node['required'], $node['minLength'],
                $node['maxLength'], $node['pattern'], $node['format']);
            if (! isset($node['items']) || ! is_array($node['items'])) {
                $node['items'] = [];
            }

            if ($segments === []) {
                $node['items'] = $this->mergeNode($node['items'], $leaf);

                return;
            }

            $this->insert($node['items'], $segments, $leaf, $isRequired);

            return;
        }

        // Parent must be an object to hold a named child. Compare on the primary
        // type: `nullable|array` produces the 3.1 type-array form
        // (["array","null"]), which a string comparison would miss  leaving
        // `items` and `properties` side by side and the nested fields ignored.
        if (self::primaryType($node) !== 'object') {
            $node['type'] = self::retype($node, 'object');
            unset($node['items'], $node['minItems'], $node['maxItems'], $node['uniqueItems']);
        }
        // A freshly created intermediate node has no type at all yet.
        $node['type'] ??= 'object';
        if (! isset($node['properties']) || ! is_array($node['properties'])) {
            $node['properties'] = [];
        }
        if (! isset($node['properties'][$segment]) || ! is_array($node['properties'][$segment])) {
            $node['properties'][$segment] = [];
        }

        if ($isRequired) {
            $existing = isset($node['required']) && is_array($node['required']) ? $node['required'] : [];
            if (! in_array($segment, $existing, true)) {
                $existing[] = $segment;
            }
            $node['required'] = $existing;
        }

        if ($segments === []) {
            $node['properties'][$segment] = $this->mergeNode($node['properties'][$segment], $leaf);

            return;
        }

        $this->insert($node['properties'][$segment], $segments, $leaf, $isRequired);
    }

    /**
     * The concrete type of a node, ignoring a `null` member of the 3.1 type array.
     *
     * @param  array<string, mixed>  $node
     */
    private static function primaryType(array $node): string
    {
        $type = $node['type'] ?? 'object';

        if (is_array($type)) {
            $concrete = array_values(array_filter($type, static fn ($t) => $t !== 'null'));
            $type = $concrete[0] ?? 'object';
        }

        return is_string($type) ? $type : 'object';
    }

    /**
     * Swap a node's concrete type while preserving its nullability.
     *
     * @param  array<string, mixed>  $node
     * @return string|list<string>
     */
    private static function retype(array $node, string $type): string|array
    {
        $current = $node['type'] ?? null;

        if (is_array($current) && in_array('null', $current, true)) {
            return [$type, 'null'];
        }

        return $type;
    }

    /**
     * Merge a leaf schema into a node that child rules may already have shaped.
     * Structural keys the children built win over the leaf's guesses.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $leaf
     * @return array<string, mixed>
     */
    private function mergeNode(array $node, array $leaf): array
    {
        $merged = array_merge($leaf, $node);

        // `items`/`properties` established by nested rules describe the shape
        // better than the leaf's placeholder  and keywords belonging to the
        // type we are discarding have to go with it.
        if (isset($node['properties'])) {
            $merged['type'] = self::retype($merged, 'object');
            unset($merged['items'], $merged['minItems'], $merged['maxItems'], $merged['uniqueItems'],
                $merged['minLength'], $merged['maxLength'], $merged['pattern'], $merged['format']);
        } elseif (isset($node['items'])) {
            $merged['type'] = self::retype($merged, 'array');
            unset($merged['properties'], $merged['required'], $merged['minLength'],
                $merged['maxLength'], $merged['pattern'], $merged['format']);
        }

        return $merged;
    }

    /** @param list<string> $list */
    private function isRequired(array $list): bool
    {
        // `sometimes` means the key may be absent altogether, and `nullable`
        // fields are conventionally documented as optional.
        if ($this->anyOf($list, ['sometimes', 'nullable', 'missing', 'prohibited'])) {
            return false;
        }

        return in_array('required', $list, true);
    }

    /**
     * @param  array<string, mixed>  $allRules
     * @return array<string, mixed>
     */
    private function fieldSchema(string $field, mixed $fieldRules, array $allRules): array
    {
        $list = $this->normalize($fieldRules);
        $type = $this->inferType($list);
        $schema = ['type' => $type];

        if ($this->anyOf($list, ['nullable'])) {
            // OpenAPI 3.1: nullable encoded as a type-array, not the deprecated `nullable` keyword.
            $schema['type'] = [$type, 'null'];
        }

        if ($type === 'string') {
            $schema = array_merge($schema, $this->stringConstraints($list));
        }

        if (in_array($type, ['integer', 'number'], true)) {
            $schema = array_merge($schema, $this->numericConstraints($list, $type));
        }

        if ($type === 'array') {
            $schema = array_merge($schema, $this->arrayConstraints($field, $list, $allRules));
        }

        foreach ($list as $r) {
            if (! str_starts_with($r, 'in:')) {
                continue;
            }
            $values = $this->splitList(substr($r, 3));
            if ($values !== []) {
                // `enum: []` allows nothing at all  worse than saying nothing.
                $schema['enum'] = $this->castEnum($values, $type);
            }
        }

        // A file upload is transferred as binary, whatever else the rules say.
        if ($this->anyOf($list, ['file', 'image', 'mimes', 'mimetypes'])) {
            $schema = ['type' => 'string', 'format' => 'binary'];
            if ($this->anyOf($list, ['nullable'])) {
                $schema['type'] = ['string', 'null'];
            }
        }

        return $schema;
    }

    /**
     * @param  list<string>  $list
     * @return array<string, mixed>
     */
    private function stringConstraints(array $list): array
    {
        $out = [];

        foreach ($list as $r) {
            match (true) {
                str_starts_with($r, 'min:') => $out['minLength'] = (int) substr($r, 4),
                str_starts_with($r, 'max:') => $out['maxLength'] = (int) substr($r, 4),
                str_starts_with($r, 'regex:') => $out['pattern'] = $this->unwrapRegex(substr($r, 6)),
                str_starts_with($r, 'starts_with:') => $out['pattern'] = '^('.implode('|', array_map('preg_quote', $this->splitList(substr($r, 12)))).')',
                str_starts_with($r, 'ends_with:') => $out['pattern'] = '('.implode('|', array_map('preg_quote', $this->splitList(substr($r, 10)))).')$',
                $r === 'email' => $out['format'] = 'email',
                $r === 'url', $r === 'active_url' => $out['format'] = 'uri',
                $r === 'uuid' => $out['format'] = 'uuid',
                $r === 'ulid' => $out['pattern'] = '^[0-9A-HJKMNP-TV-Z]{26}$',
                $r === 'date' => $out['format'] = 'date-time',
                $r === 'date_format:Y-m-d' => $out['format'] = 'date',
                $r === 'ip', $r === 'ipv4' => $out['format'] = 'ipv4',
                $r === 'ipv6' => $out['format'] = 'ipv6',
                $r === 'mac_address' => $out['pattern'] = '^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$',
                $r === 'hex_color' => $out['pattern'] = '^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$',
                $r === 'json' => $out['format'] = 'json',
                $r === 'password', $r === 'current_password' => $out['format'] = 'password',
                str_starts_with($r, 'size:') => [
                    $out['minLength'] = (int) substr($r, 5),
                    $out['maxLength'] = (int) substr($r, 5),
                ],
                default => null,
            };
        }

        return $out;
    }

    /**
     * @param  list<string>  $list
     * @return array<string, mixed>
     */
    private function numericConstraints(array $list, string $type): array
    {
        $out = [];
        $cast = static fn (string $v): int|float => $type === 'integer' ? (int) $v : (float) $v;

        foreach ($list as $r) {
            if (str_starts_with($r, 'min:') || str_starts_with($r, 'gte:')) {
                $out['minimum'] = $cast(substr($r, 4));
            } elseif (str_starts_with($r, 'max:') || str_starts_with($r, 'lte:')) {
                $out['maximum'] = $cast(substr($r, 4));
            } elseif (str_starts_with($r, 'gt:')) {
                $out['exclusiveMinimum'] = $cast(substr($r, 3));
            } elseif (str_starts_with($r, 'lt:')) {
                $out['exclusiveMaximum'] = $cast(substr($r, 3));
            } elseif (str_starts_with($r, 'multiple_of:')) {
                $out['multipleOf'] = $cast(substr($r, 12));
            } elseif (str_starts_with($r, 'between:')) {
                $bounds = $this->splitList(substr($r, 8));
                if (count($bounds) === 2) {
                    $out['minimum'] = $cast($bounds[0]);
                    $out['maximum'] = $cast($bounds[1]);
                }
            } elseif (str_starts_with($r, 'digits:')) {
                // "digits:4" accepts 1000-9999; a single digit may be 0.
                $digits = (int) substr($r, 7);
                if ($digits > 0 && $digits < 19) {
                    $out['minimum'] = $digits === 1 ? 0 : 10 ** ($digits - 1);
                    $out['maximum'] = 10 ** $digits - 1;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $list
     * @param  array<string, mixed>  $allRules
     * @return array<string, mixed>
     */
    private function arrayConstraints(string $field, array $list, array $allRules): array
    {
        $out = [];
        $itemKey = $field.'.*';

        // Nested rules build `items` themselves; only fill in a placeholder.
        $out['items'] = isset($allRules[$itemKey])
            ? $this->fieldSchema($itemKey, $allRules[$itemKey], $allRules)
            : new \stdClass;

        foreach ($list as $r) {
            if (str_starts_with($r, 'min:')) {
                $out['minItems'] = (int) substr($r, 4);
            } elseif (str_starts_with($r, 'max:')) {
                $out['maxItems'] = (int) substr($r, 4);
            } elseif (str_starts_with($r, 'size:')) {
                $out['minItems'] = (int) substr($r, 5);
                $out['maxItems'] = (int) substr($r, 5);
            } elseif ($r === 'distinct') {
                $out['uniqueItems'] = true;
            }
        }

        return $out;
    }

    /**
     * Flatten a rule definition to a list of rule strings.
     *
     * Rule objects (`Rule::in(...)`, `Password::defaults()`) are stringified
     * only when they can be. A closure rule or a `ValidationRule` object with
     * no __toString must never be cast  that raises an Error and would kill
     * the whole documentation build.
     *
     * @return list<string>
     */
    private function normalize(mixed $rules): array
    {
        if (is_string($rules)) {
            return array_values(array_filter(array_map('trim', explode('|', $rules)), static fn ($r) => $r !== ''));
        }

        if (! is_array($rules)) {
            $rules = [$rules];
        }

        $result = [];
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                foreach (explode('|', $rule) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $result[] = $part;
                    }
                }

                continue;
            }

            if (is_object($rule)) {
                foreach ($this->fromRuleObject($rule) as $part) {
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function fromRuleObject(object $rule): array
    {
        // Enum rule: expose the backing enum's cases.
        if (property_exists($rule, 'type') || str_contains($rule::class, 'Rules\\Enum')) {
            $cases = $this->enumCases($rule);
            if ($cases !== []) {
                return ['in:'.implode(',', $cases)];
            }
        }

        if ($rule instanceof \Stringable || method_exists($rule, '__toString')) {
            $string = trim((string) $rule);

            return $string === '' ? [] : [$string];
        }

        // Closures and ValidationRule objects carry no documentable metadata.
        return [];
    }

    /** @return list<string> */
    private function enumCases(object $rule): array
    {
        try {
            $ref = new \ReflectionClass($rule);
            if (! $ref->hasProperty('type')) {
                return [];
            }
            $prop = $ref->getProperty('type');
            $prop->setAccessible(true);
            $enum = $prop->getValue($rule);

            if (! is_string($enum) || ! enum_exists($enum)) {
                return [];
            }

            $values = [];
            foreach ($enum::cases() as $case) {
                $values[] = (string) (property_exists($case, 'value') ? $case->value : $case->name);
            }

            return $values;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<string> $rules */
    private function inferType(array $rules): string
    {
        if ($this->anyOf($rules, ['integer', 'int', 'digits', 'digits_between'])) {
            return 'integer';
        }
        if ($this->anyOf($rules, ['numeric', 'decimal'])) {
            return 'number';
        }
        if ($this->anyOf($rules, ['boolean', 'bool', 'accepted', 'declined'])) {
            return 'boolean';
        }
        if ($this->anyOf($rules, ['array', 'list'])) {
            return 'array';
        }

        return 'string';
    }

    /**
     * @param  list<string>  $values
     * @return list<string|int|float|bool>
     */
    private function castEnum(array $values, string $type): array
    {
        return array_map(static fn (string $v) => match ($type) {
            'integer' => (int) $v,
            'number' => (float) $v,
            'boolean' => in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true),
            default => $v,
        }, $values);
    }

    /** @return list<string> */
    private function splitList(string $value): array
    {
        return array_values(array_filter(
            array_map(static fn (string $v): string => trim(trim($v), '"'), explode(',', $value)),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * Laravel regex rules carry PHP delimiters; JSON Schema `pattern` must not.
     */
    private function unwrapRegex(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '' || ! in_array($pattern[0], ['/', '#', '~', '%'], true)) {
            return $pattern;
        }

        $delimiter = $pattern[0];
        $end = strrpos($pattern, $delimiter);
        if ($end === false || $end === 0) {
            return $pattern;
        }

        return substr($pattern, 1, $end - 1);
    }

    /**
     * @param  list<string>  $rules
     * @param  list<string>  $targets
     */
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
