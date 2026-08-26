<?php

declare(strict_types=1);

use ApexDocs\Bridge\Laravel\Console\Commands\InstallAiCommand;
use ApexDocs\Mcp\Snapshot;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->projectRoot = sys_get_temp_dir().'/apexdocs_ai_'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->projectRoot);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->projectRoot);
});

// ── apexdocs:snapshot ────────────────────────────────────────────────────────

it('apexdocs:snapshot prints a JSON snapshot with spec, routes and config', function () {
    Route::get('api/ping', fn () => [])->name('ping');
    Route::get('admin/dashboard', fn () => [])->name('admin');
    Route::getRoutes()->refreshNameLookups();

    $exit = Artisan::call('apexdocs:snapshot');
    $snapshot = Snapshot::fromArray(json_decode(trim(Artisan::output()), true));

    expect($exit)->toBe(0)
        ->and($snapshot->spec['openapi'])->toBe('3.1.0')
        ->and($snapshot->config['title'])->not->toBe('');

    $byPath = array_column($snapshot->routes, null, 'path');
    expect($byPath['/api/ping']['included'])->toBeTrue()
        ->and($byPath['/admin/dashboard']['included'])->toBeFalse()
        ->and($byPath['/admin/dashboard']['reason'])->toBe('api_path_prefix');
});

it('apexdocs:snapshot is hidden from the command list', function () {
    $command = $this->app[Illuminate\Contracts\Console\Kernel::class]->all()['apexdocs:snapshot'];

    expect($command->isHidden())->toBeTrue();
});

// ── apexdocs:install-ai ──────────────────────────────────────────────────────

it('installs Claude Code and AGENTS.md integration by default', function () {
    $this->app->setBasePath($this->projectRoot);

    $this->artisan('apexdocs:install-ai')->assertSuccessful();

    $root = $this->projectRoot;
    expect("{$root}/.claude/skills/apexdocs/SKILL.md")->toBeFile()
        ->and("{$root}/.claude/skills/apexdocs/references/attributes.md")->toBeFile()
        ->and("{$root}/.claude/agents/apexdocs.md")->toBeFile()
        ->and("{$root}/.agents/skills/apexdocs/SKILL.md")->toBeFile()
        ->and("{$root}/.cursor")->not->toBeDirectory();

    $mcp = json_decode(file_get_contents("{$root}/.mcp.json"), true);
    expect($mcp['mcpServers']['apexdocs'])->toBe(['command' => 'php', 'args' => ['artisan', 'apexdocs:mcp']]);

    foreach (['CLAUDE.md', 'AGENTS.md'] as $file) {
        expect(file_get_contents("{$root}/{$file}"))
            ->toContain(InstallAiCommand::MARKER_START)
            ->toContain('anil/apexdocs');
    }
});

it('merges into existing files, replaces stale blocks, and is idempotent', function () {
    $this->app->setBasePath($this->projectRoot);
    $root = $this->projectRoot;
    file_put_contents("{$root}/CLAUDE.md", "# Project\n\nKeep.\n\n".InstallAiCommand::MARKER_START."\nold\n".InstallAiCommand::MARKER_END."\n");
    file_put_contents("{$root}/.mcp.json", json_encode(['mcpServers' => ['other' => ['command' => 'npx']]]));

    $this->artisan('apexdocs:install-ai')->assertSuccessful();
    $this->artisan('apexdocs:install-ai')->assertSuccessful();

    $claude = file_get_contents("{$root}/CLAUDE.md");
    expect($claude)->toStartWith("# Project\n\nKeep.")
        ->and($claude)->not->toContain("\nold\n")
        ->and(substr_count($claude, InstallAiCommand::MARKER_START))->toBe(1);

    $mcp = json_decode(file_get_contents("{$root}/.mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKeys(['other', 'apexdocs']);
});

it('installs cursor and copilot targets and rejects unknown ones', function () {
    $this->app->setBasePath($this->projectRoot);
    $root = $this->projectRoot;

    $this->artisan('apexdocs:install-ai', ['--target' => 'cursor,copilot'])->assertSuccessful();

    expect("{$root}/.cursor/rules/apexdocs.mdc")->toBeFile()
        ->and("{$root}/.cursor/skills/apexdocs/SKILL.md")->toBeFile()
        ->and("{$root}/.github/copilot-instructions.md")->toBeFile()
        ->and(json_decode(file_get_contents("{$root}/.vscode/mcp.json"), true)['servers']['apexdocs']['type'])->toBe('stdio')
        ->and("{$root}/.claude")->not->toBeDirectory();

    $this->artisan('apexdocs:install-ai', ['--target' => 'vim'])->assertExitCode(2);
});

it('does not overwrite a locally modified skill unless forced', function () {
    $this->app->setBasePath($this->projectRoot);
    $skill = "{$this->projectRoot}/.claude/skills/apexdocs/SKILL.md";

    $this->artisan('apexdocs:install-ai', ['--target' => 'claude'])->assertSuccessful();
    file_put_contents($skill, "mine\n");

    $this->artisan('apexdocs:install-ai', ['--target' => 'claude'])->assertSuccessful();
    expect(file_get_contents($skill))->toBe("mine\n");

    $this->artisan('apexdocs:install-ai', ['--target' => 'claude', '--force' => true])->assertSuccessful();
    expect(file_get_contents($skill))->toContain('name: apexdocs');
});

