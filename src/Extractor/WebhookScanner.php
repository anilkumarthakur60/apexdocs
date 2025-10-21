<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ApexDocs\Attribute\Webhook;

/**
 * Scans PHP files for classes annotated with #[Webhook].
 * No framework dependency — just filesystem and Reflection.
 */
final class WebhookScanner
{
    /** @var list<string> Absolute directory paths to scan */
    private array $paths;

    public function __construct(array $paths = [])
    {
        $this->paths = $paths;
    }

    /**
     * @return array<string, array<string, mixed>> webhook name => OpenAPI path-item
     */
    public function scan(): array
    {
        $webhooks = [];

        foreach ($this->paths as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach ($this->phpFiles($dir) as $file) {
                $class = $this->classFromFile($file);
                if ($class === null || ! class_exists($class)) {
                    continue;
                }
                try {
                    $ref = new \ReflectionClass($class);
                } catch (\ReflectionException) {
                    continue;
                }
                foreach (AttributeReader::all($ref, Webhook::class) as $attr) {
                    /** @var Webhook $attr */
                    $webhooks[$attr->name] = $this->buildSpec($attr, $ref);
                }
            }
        }

        return $webhooks;
    }

    /** @return array<string, mixed> */
    private function buildSpec(Webhook $attr, \ReflectionClass $ref): array
    {
        $op = [
            'summary' => $attr->summary ?: $ref->getShortName(),
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => $attr->schema ?? ['type' => 'object'],
                    ],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'Webhook received'],
            ],
        ];

        if ($attr->description !== '') {
            $op['description'] = $attr->description;
        }
        if ($attr->tags) {
            $op['tags'] = $attr->tags;
        }

        return ['post' => $op];
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    /**
     * Resolve the fully-qualified class name declared in a PHP file.
     *
     * Uses the PHP tokenizer rather than regex because the regex approach
     * breaks on real-world code: multi-line `namespace` blocks, `class` in
     * doc comments, classes nested inside conditionals, attributes on the
     * class line, and `class` used as a const reference all defeated the
     * previous implementation.
     */
    private function classFromFile(string $file): ?string
    {
        $src = @file_get_contents($file);
        if ($src === false || $src === '') {
            return null;
        }

        $tokens = @token_get_all($src);
        $namespace = '';
        $class = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $i, $count);

                continue;
            }

            if ($token[0] === T_CLASS && ! $this->isClassConst($tokens, $i)) {
                $class = $this->readClassName($tokens, $i, $count);
                if ($class !== '') {
                    break;
                }
            }
        }

        if ($class === '') {
            return null;
        }

        return $namespace !== '' ? $namespace.'\\'.$class : $class;
    }

    /**
     * Skip `::class` — that's a class-name resolution constant, not a class
     * declaration.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private function isClassConst(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $prev = $tokens[$j];
            if (is_array($prev) && $prev[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($prev) && $prev[0] === T_DOUBLE_COLON;
        }

        return false;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private function readNamespace(array $tokens, int $i, int $count): string
    {
        $ns = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === ';' || $t === '{') {
                break;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $ns .= $t[1];
            }
        }

        return trim($ns, '\\');
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private function readClassName(array $tokens, int $i, int $count): string
    {
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }

            return '';
        }

        return '';
    }
}
