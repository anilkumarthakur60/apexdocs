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

    private function classFromFile(string $file): ?string
    {
        $src = file_get_contents($file);
        if ($src === false) {
            return null;
        }
        $ns = '';
        $class = '';
        if (preg_match('/^namespace\s+([^;{]+)/m', $src, $m)) {
            $ns = trim($m[1]);
        }
        if (preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $m)) {
            $class = trim($m[1]);
        }

        return $class !== '' ? ($ns !== '' ? $ns.'\\'.$class : $class) : null;
    }
}
