<?php

declare(strict_types=1);

use ApexDocs\Cache\SpecCache;
use ApexDocs\Spec\Document;
use Psr\SimpleCache\CacheInterface;

/**
 * Tiny in-memory PSR-16 stub. We can't ship a dependency on a cache adapter
 * just for testing, so we bind one inline.
 */
function memoryCache(): CacheInterface
{
    return new class implements CacheInterface
    {
        /** @var array<string, mixed> */
        private array $store = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->store[$key] ?? $default;
        }

        public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
        {
            $this->store[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->store[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->store = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = $this->get($k, $default);
            }

            return $out;
        }

        public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
        {
            foreach ($values as $k => $v) {
                $this->set($k, $v, $ttl);
            }

            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            foreach ($keys as $k) {
                $this->delete($k);
            }

            return true;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->store);
        }
    };
}

it('preserves info, servers, tags, components, and webhooks on round-trip', function () {
    $cache = new SpecCache(memoryCache());

    $doc = new Document;
    $doc->info('My API', '9.9.9', 'desc');
    $doc->addServer('https://prod.example', 'production');
    $doc->addTag('Users');
    $doc->addWebhook('user.created', ['post' => ['summary' => 'user created']]);
    $doc->components()->addSchema('User', ['type' => 'object']);

    $cache->put('default', $doc);
    $loaded = $cache->get('default');

    expect($loaded)->not->toBeNull()
        ->and($loaded->toArray())->toBe($doc->toArray());
});

it('returns the raw array via getArray() for fast HTTP paths', function () {
    $cache = new SpecCache(memoryCache());
    $doc = (new Document)->info('A', '1');

    $cache->put('k', $doc);

    expect($cache->getArray('k'))->toBe($doc->toArray());
});

it('returns null on a miss', function () {
    $cache = new SpecCache(memoryCache());

    expect($cache->get('missing'))->toBeNull()
        ->and($cache->getArray('missing'))->toBeNull();
});
