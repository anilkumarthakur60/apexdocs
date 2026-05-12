<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Spec\Document;

final class JsonExporter
{
    public function toString(Document $doc, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($doc->toArray(), $flags);
    }

    public function toFile(Document $doc, string $path): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, $this->toString($doc));
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
