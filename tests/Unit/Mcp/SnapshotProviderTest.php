<?php

declare(strict_types=1);

use ApexDocs\Mcp\Snapshot;
use ApexDocs\Mcp\SubprocessSnapshotProvider;

it('round-trips a snapshot through JSON', function () {
    $snapshot = new Snapshot(['openapi' => '3.1.0'], [['path' => '/x', 'included' => true]], ['title' => 'T'], '2026-01-01T00:00:00+00:00', 12);

    $restored = Snapshot::fromArray(json_decode(json_encode($snapshot->toArray()), true));

    expect($restored->spec)->toBe(['openapi' => '3.1.0'])
        ->and($restored->routes)->toBe([['path' => '/x', 'included' => true]])
        ->and($restored->config)->toBe(['title' => 'T'])
        ->and($restored->generatedAt)->toBe('2026-01-01T00:00:00+00:00')
        ->and($restored->durationMs)->toBe(12);
});

it('runs a subprocess and parses its JSON', function () {
    $provider = new SubprocessSnapshotProvider([PHP_BINARY, '-r', 'echo json_encode(["spec" => ["openapi" => "3.1.0"], "routes" => [], "config" => []]);']);

    expect($provider->snapshot()->spec['openapi'])->toBe('3.1.0');
});

it('surfaces subprocess failures with stderr', function () {
    $provider = new SubprocessSnapshotProvider([PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);']);

    expect(fn () => $provider->snapshot())->toThrow(RuntimeException::class, 'boom');
});

it('rejects output that is not a snapshot', function () {
    expect(fn () => (new SubprocessSnapshotProvider([PHP_BINARY, '-r', 'echo "not json";']))->snapshot())
        ->toThrow(RuntimeException::class, 'valid JSON');

    expect(fn () => (new SubprocessSnapshotProvider([PHP_BINARY, '-r', 'echo "{}";']))->snapshot())
        ->toThrow(RuntimeException::class, 'missing the "spec" key');
});

it('kills a subprocess that exceeds the timeout', function () {
    $provider = new SubprocessSnapshotProvider([PHP_BINARY, '-r', 'sleep(10);'], null, 1);

    $t = microtime(true);
    expect(fn () => $provider->snapshot())->toThrow(RuntimeException::class, 'exceeded');
    expect(microtime(true) - $t)->toBeLessThan(5);
});
