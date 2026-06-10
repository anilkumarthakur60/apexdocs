<?php

declare(strict_types=1);

namespace ApexDocs\Cache;

use ApexDocs\Spec\Document;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 backed spec cache. Framework-agnostic.
 *
 * The concrete CacheInterface implementation is injected by the framework
 * bridge (e.g. Laravel's `cache.psr16`). Specs are stored as plain arrays
 * and round-tripped through {@see Document::fromArray()} on read so every
 * section  info, servers, components, tags, webhooks, security,
 * extensions  survives a cache hit.
 */
final class SpecCache
{
    public function __construct(
        private CacheInterface $psr16,
        private int $ttl = 3600,
        private string $prefix = 'apexdocs.',
    ) {}

    public function get(string $key = 'default'): ?Document
    {
        $data = $this->psr16->get($this->prefix.$key);
        if (! is_array($data)) {
            return null;
        }

        return Document::fromArray($data);
    }

    /**
     * Read the cached spec without materialising a {@see Document}. Cheaper
     * for HTTP handlers that just need to serialise  they can stream the
     * cached array directly through JSON/YAML exporters.
     *
     * @return array<string, mixed>|null
     */
    public function getArray(string $key = 'default'): ?array
    {
        $data = $this->psr16->get($this->prefix.$key);

        return is_array($data) ? $data : null;
    }

    public function put(string $key, Document $doc): void
    {
        $this->psr16->set($this->prefix.$key, $doc->toArray(), $this->ttl);
    }

    public function forget(string $key = 'default'): void
    {
        $this->psr16->delete($this->prefix.$key);
    }

    public function has(string $key = 'default'): bool
    {
        return $this->psr16->has($this->prefix.$key);
    }
}
