<?php

declare(strict_types=1);

use ApexDocs\Tests\Fixtures\Controllers\UserController;

/*
 * End-to-end: the standalone `bin/apexdocs-mcp` executable, in its default
 * subprocess mode - a client speaks JSON-RPC over stdio, the server spawns a
 * fresh PHP process per snapshot that runs the bootstrap file. This is the
 * exact path a Symfony/Slim/plain-PHP project uses.
 */

function runStandaloneMcp(array $messages, string $mode = 'subprocess'): array
{
    $root = dirname(__DIR__, 3);
    $bootstrap = sys_get_temp_dir().'/apexdocs_bootstrap_'.uniqid().'.php';
    $controller = UserController::class;
    file_put_contents($bootstrap, <<<PHP
    <?php
    require '{$root}/vendor/autoload.php';

    return ApexDocs\\ApexDocs::make(['title' => 'Standalone API', 'version' => '9.9.9'])
        ->routes((new ApexDocs\\Route\\ArrayRouteCollection)
            ->add('GET', '/api/users', '{$controller}@index', metadata: ['name' => 'users.index'])
            ->add('GET', '/api/users/{id}', '{$controller}@show', metadata: ['name' => 'users.show']));
    PHP);

    $process = proc_open(
        [PHP_BINARY, $root.'/bin/apexdocs-mcp', '--bootstrap='.$bootstrap, '--mode='.$mode, '--timeout=60'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect($process)->toBeResource();

    foreach ($messages as $message) {
        fwrite($pipes[0], json_encode($message)."\n");
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    @unlink($bootstrap);

    expect($exit)->toBe(0, $err);

    return array_map(fn (string $l) => json_decode($l, true), array_values(array_filter(explode("\n", $out))));
}

it('serves a spec built by a fresh subprocess per snapshot', function () {
    $responses = runStandaloneMcp([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'spec_summary', 'arguments' => []]],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'validate_spec', 'arguments' => ['strict' => true]]],
    ]);

    expect($responses)->toHaveCount(3)
        ->and($responses[0]['result']['serverInfo']['name'])->toBe('apexdocs');

    $summary = json_decode($responses[1]['result']['content'][0]['text'], true);
    expect($summary['info']['title'])->toBe('Standalone API')
        ->and($summary['counts']['operations'])->toBe(2);

    $validation = json_decode($responses[2]['result']['content'][0]['text'], true);
    expect($validation['valid'])->toBeTrue();
});

it('also works in in-process mode', function () {
    $responses = runStandaloneMcp([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_operations', 'arguments' => []]],
    ], mode: 'in-process');

    $ops = json_decode($responses[0]['result']['content'][0]['text'], true);
    expect(array_column($ops, 'operationId'))->toBe(['users_index', 'users_show']);
});

it('explains usage and fails cleanly without a bootstrap', function () {
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/bin/apexdocs-mcp'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $err = stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(64)->and($err)->toContain('--bootstrap');
});
