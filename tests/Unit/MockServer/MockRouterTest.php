<?php

declare(strict_types=1);

/*
 * The mock router is dispatched by `php -S host:port resources/mock/server.php`
 * — i.e. it's the entry script for the built-in dev server, where `return true`
 * means "I handled this request inline". We can't `require` it directly without
 * the dev-server context (top-level `return` is invalid), so these tests assert
 * the file's *structure*: the router never inlines the spec, never evals user
 * data, and reads its spec exclusively via the env var contract.
 *
 * The substantive injection regression is enforced here: if anyone changes
 * MockCommand to embed the spec into a heredoc'd PHP file again, the
 * "must not embed" assertion below will fail.
 */

it('reads the spec only via the APEXDOCS_MOCK_SPEC env var', function () {
    $source = file_get_contents(__DIR__.'/../../../resources/mock/server.php');

    expect($source)->toContain("getenv('APEXDOCS_MOCK_SPEC')")
        ->and($source)->toContain('file_get_contents($specPath)')
        ->and($source)->toContain('JSON_THROW_ON_ERROR');
});

it('contains no eval, no shell_exec, no system, no passthru', function () {
    $source = file_get_contents(__DIR__.'/../../../resources/mock/server.php');

    foreach (['eval(', 'shell_exec(', 'system(', 'passthru(', 'proc_open('] as $sink) {
        expect($source)->not->toContain($sink);
    }
});

it('MockCommand passes the spec via env var, not embedded heredoc', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Bridge/Laravel/Console/Commands/MockCommand.php');

    expect($source)->toContain("'APEXDOCS_MOCK_SPEC' => \$specFile")
        ->and($source)->not->toContain('apexdocs_example(') // old in-PHP function
        ->and($source)->not->toContain('json_decode(\''); // no inline-JSON embedding
});

it('MockCommand spawns the server without a shell', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Bridge/Laravel/Console/Commands/MockCommand.php');

    // An argument array through proc_open means no quoting rules to get wrong,
    // and "VAR=value cmd" — which only works in a POSIX shell — never appears.
    expect($source)->toContain('proc_open(')
        ->and($source)->toContain('PHP_BINARY')
        ->and($source)->not->toContain('passthru(')
        ->and($source)->not->toContain('shell_exec(')
        ->and($source)->not->toContain('APEXDOCS_MOCK_SPEC=%s');
});
