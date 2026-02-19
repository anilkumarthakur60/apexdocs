<?php

declare(strict_types=1);

/**
 * Smoke + behavioural coverage for all Artisan commands shipped by the
 * Laravel bridge. These commands are part of the public surface (anyone who
 * `composer require`s the package can run them in CI) — they must keep
 * working across the matrix.
 */
afterEach(function () {
    $base = sys_get_temp_dir().'/apexdocs_cmd';
    if (is_dir($base)) {
        foreach (glob($base.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($base);
    }
});

// ── apexdocs:generate ──────────────────────────────────────────────────────────

it('apexdocs:generate prints JSON to stdout by default', function () {
    $this->artisan('apexdocs:generate')
        ->expectsOutputToContain('"openapi"')
        ->assertSuccessful();
});

it('apexdocs:generate writes JSON to a file when --output is given', function () {
    $path = sys_get_temp_dir().'/apexdocs_cmd/spec.json';

    $this->artisan('apexdocs:generate', ['--output' => $path])
        ->assertSuccessful();

    expect(file_exists($path))->toBeTrue();
    expect(json_decode((string) file_get_contents($path), true))
        ->toBeArray()
        ->and(json_decode((string) file_get_contents($path), true)['openapi'])
        ->toBe('3.1.0');
});

it('apexdocs:generate keeps stdout free of anything but the spec', function () {
    // `apexdocs:generate > openapi.json` has to produce a parseable file, so the
    // "N paths in Xms" summary must go to stderr, not stdout.
    $output = new SplitStreamOutput;
    $exit = $this->app[Illuminate\Contracts\Console\Kernel::class]->call('apexdocs:generate', [], $output);

    expect($exit)->toBe(0)
        ->and(json_decode(trim($output->fetch()), true))->toBeArray()
        ->and($output->stderr->fetch())->toContain('paths in');
});

it('apexdocs:generate rejects an unknown format', function () {
    $this->artisan('apexdocs:generate', ['--format' => 'toml'])
        ->expectsOutputToContain('Unknown format')
        ->assertFailed();
});

it('apexdocs:export fails on an unknown format', function () {
    $this->artisan('apexdocs:export', ['format' => 'nonsense'])
        ->expectsOutputToContain('Unknown format')
        ->assertFailed();
});

it('apexdocs:diff reports a corrupt baseline instead of crashing', function () {
    $path = sys_get_temp_dir().'/apexdocs_cmd/bad.json';
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, 'not json at all');

    $this->artisan('apexdocs:diff', ['base' => $path])
        ->expectsOutputToContain('not a valid OpenAPI JSON document')
        ->assertFailed();
});

it('apexdocs:generate writes YAML when --format=yaml', function () {
    $path = sys_get_temp_dir().'/apexdocs_cmd/spec.yaml';

    $this->artisan('apexdocs:generate', ['--format' => 'yaml', '--output' => $path])
        ->assertSuccessful();

    $content = (string) file_get_contents($path);
    expect($content)->toContain('openapi: 3.1.0');
});

// ── apexdocs:validate ──────────────────────────────────────────────────────────

it('apexdocs:validate flags missing required fields on an empty spec', function () {
    // The test harness has no routes registered → spec has no `paths`, which
    // validate() correctly reports as an error. This locks in the contract
    // that validate fails loudly rather than silently passing an empty spec.
    $this->artisan('apexdocs:validate')
        ->expectsOutputToContain('Missing required field: paths')
        ->assertFailed();
});

// ── apexdocs:export ────────────────────────────────────────────────────────────

it('apexdocs:export writes each supported format', function (string $fmt, string $ext) {
    $path = sys_get_temp_dir().'/apexdocs_cmd/out.'.$ext;

    $this->artisan('apexdocs:export', ['format' => $fmt, '--output' => $path])
        ->assertSuccessful();

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(0);
})->with([
    ['openapi-json', 'json'],
    ['openapi-yaml', 'yaml'],
    ['postman', 'json'],
    ['insomnia', 'json'],
    ['bruno', 'json'],
]);

// ── apexdocs:diff ──────────────────────────────────────────────────────────────

it('apexdocs:diff fails when the baseline file does not exist', function () {
    $this->artisan('apexdocs:diff', ['base' => '/nope/does/not/exist.json'])
        ->expectsOutputToContain('Baseline file not found')
        ->assertFailed();
});

it('apexdocs:diff succeeds when current spec equals the baseline', function () {
    $base = sys_get_temp_dir().'/apexdocs_cmd/baseline.json';
    @mkdir(dirname($base), 0755, true);

    // Generate the current spec, save it as the baseline, then diff against it.
    $this->artisan('apexdocs:generate', ['--output' => $base])->assertSuccessful();

    $this->artisan('apexdocs:diff', ['base' => $base])
        ->assertSuccessful();
});

it('apexdocs:diff reports an added path against an empty baseline', function () {
    $base = sys_get_temp_dir().'/apexdocs_cmd/empty.json';
    @mkdir(dirname($base), 0755, true);
    file_put_contents($base, json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'X', 'version' => '1'],
        'paths' => ['/api/old-route' => ['get' => ['responses' => []]]],
    ]));

    // The current generated spec doesn't have /api/old-route → BREAKING removal.
    $this->artisan('apexdocs:diff', ['base' => $base])
        ->expectsOutputToContain('BREAKING')
        ->assertFailed();
});

/**
 * A BufferedOutput has no separate error stream, so it cannot show whether the
 * command kept stdout clean. This double keeps the two apart the way a real
 * terminal does.
 */
class SplitStreamOutput extends Symfony\Component\Console\Output\BufferedOutput implements Symfony\Component\Console\Output\ConsoleOutputInterface
{
    public Symfony\Component\Console\Output\BufferedOutput $stderr;

    public function __construct()
    {
        parent::__construct();
        $this->stderr = new Symfony\Component\Console\Output\BufferedOutput;
    }

    public function getErrorOutput(): Symfony\Component\Console\Output\OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(Symfony\Component\Console\Output\OutputInterface $error): void
    {
        // Not needed for the assertion.
    }

    public function section(): Symfony\Component\Console\Output\ConsoleSectionOutput
    {
        throw new RuntimeException('sections are not used by these commands');
    }
}
