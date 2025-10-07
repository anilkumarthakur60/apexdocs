<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Route\ArrayRouteCollection;

/*
 * C1 regression coverage: the SpecBuilder must never read $_SERVER values.
 * Even if HTTP_HOST is set to an attacker value, the generated spec should
 * fall back to the configured server (or the constant default).
 */

it('ignores $_SERVER[HTTP_HOST] when no servers are configured', function () {
    $original = $_SERVER ?? [];
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $_SERVER['HTTPS'] = '1';

    try {
        $doc = ApexDocs::make(new Config())
            ->routes(new ArrayRouteCollection())
            ->generate();

        $servers = $doc->toArray()['servers'] ?? [];

        expect($servers)->toHaveCount(1)
            ->and($servers[0]['url'])->toBe('http://localhost')
            ->and($servers[0]['url'])->not->toContain('attacker.example');
    } finally {
        $_SERVER = $original;
    }
});

it('uses every server entry from Config::servers verbatim', function () {
    $config = new Config(servers: [
        ['url' => 'https://api.example.com', 'description' => 'production'],
        ['url' => 'https://staging.example.com', 'description' => 'staging'],
    ]);

    $doc = ApexDocs::make($config)
        ->routes(new ArrayRouteCollection())
        ->generate();

    $servers = $doc->toArray()['servers'] ?? [];

    expect($servers)->toHaveCount(2)
        ->and($servers[0]['url'])->toBe('https://api.example.com')
        ->and($servers[0]['description'])->toBe('production')
        ->and($servers[1]['url'])->toBe('https://staging.example.com');
});
