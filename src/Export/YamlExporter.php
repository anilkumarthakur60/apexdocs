<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Spec\Document;
use Symfony\Component\Yaml\Yaml;

final class YamlExporter
{
    public function toString(Document $doc): string
    {
        return Yaml::dump(
            $doc->toArray(),
            inline: 10,
            indent: 2,
            flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    public function toFile(Document $doc, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $this->toString($doc));
    }
}
