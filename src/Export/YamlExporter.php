<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Spec\Document;
use Symfony\Component\Yaml\Yaml;

final class YamlExporter
{
    use WritesFiles;

    public function toString(Document $doc): string
    {
        return Yaml::dump(
            $doc->toArray(),
            inline: 20,
            indent: 2,
            flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    public function toFile(Document $doc, string $path): void
    {
        $this->write($path, $this->toString($doc));
    }
}
