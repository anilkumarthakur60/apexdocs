<?php

declare(strict_types=1);

namespace ApexDocs\Cache;

use ApexDocs\Spec\Document;
use ApexDocs\Spec\Operation;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 backed spec cache. Framework-agnostic.
 * The concrete CacheInterface implementation is injected by the framework bridge.
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

        $doc = new Document;
        // Reconstruct from raw array stored in cache
        foreach ($data['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $op) {
                $operation = new Operation;
                // Restore key fields
                foreach ($op['parameters'] ?? [] as $p) {
                    $operation->addParameter($p);
                }
                foreach ($op['responses'] ?? [] as $status => $resp) {
                    $operation->addResponse((string) $status, $resp);
                }
                if (isset($op['summary'])) {
                    $operation->summary($op['summary']);
                }
                $doc->addOperation($path, $method, $operation);
            }
        }

        return $doc;
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
