<?php

declare(strict_types=1);

use ApexDocs\Cache\SpecCache;
use Illuminate\Support\Facades\Route;

/**
 * `cache` and `environments` were documented options that nothing read: the
 * spec was rebuilt by reflection on every request, and the docs site was
 * registered in production regardless of the environment list.
 */
beforeEach(function () {
    config()->set('apexdocs.api_path_prefix', 'api');
    Route::get('api/cached', fn () => [])->name('cached.index');
    Route::getRoutes()->refreshNameLookups();
});

it('binds a PSR-16 backed spec cache', function () {
    expect(app(SpecCache::class))->toBeInstanceOf(SpecCache::class);
});

it('serves the spec from cache once populated', function () {
    config()->set('apexdocs.cache.enabled', true);

    $first = $this->get('/documentation/api/spec.json');
    $first->assertOk();

    expect(app(SpecCache::class)->has())->toBeTrue();

    // A second request must return byte-identical output from the cache.
    $second = $this->get('/documentation/api/spec.json');
    $second->assertOk();
    expect($second->getContent())->toBe($first->getContent());
});

it('does not touch the cache when caching is disabled', function () {
    config()->set('apexdocs.cache.enabled', false);
    app(SpecCache::class)->forget();

    $this->get('/documentation/api/spec.json')->assertOk();

    expect(app(SpecCache::class)->has())->toBeFalse();
});

it('keeps the docs routes out of the generated spec', function () {
    $spec = $this->get('/documentation/api/spec.json')->json();

    foreach (array_keys($spec['paths'] ?? []) as $path) {
        expect($path)->not->toContain('documentation/api');
    }
});

it('registers no docs routes outside the allowed environments', function () {
    // The provider reads apexdocs.environments when routes are registered, so
    // this asserts the gate itself rather than re-booting the container.
    $provider = new ApexDocs\Bridge\Laravel\ServiceProvider($this->app);
    $method = (new ReflectionClass($provider))->getMethod('docsAreVisible');
    $method->setAccessible(true);

    config()->set('apexdocs.environments', ['production']);
    expect($method->invoke($provider))->toBeFalse();

    config()->set('apexdocs.environments', ['testing']);
    expect($method->invoke($provider))->toBeTrue();

    config()->set('apexdocs.environments', []);
    expect($method->invoke($provider))->toBeTrue();
});
