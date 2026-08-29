<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Install AI-assistant integration files into the host project so coding
 * agents (Claude Code, AGENTS.md readers such as Codex, Cursor, GitHub
 * Copilot) know how to document APIs with apexdocs: a skill, a subagent, an
 * instructions block, and the MCP server registration.
 *
 * Idempotent: managed blocks are wrapped in marker comments and replaced on
 * re-run; JSON configs are merged, never clobbered.
 */
class InstallAiCommand extends Command
{
    public const MARKER_START = '<!-- apexdocs:start -->';

    public const MARKER_END = '<!-- apexdocs:end -->';

    /** @var list<string> */
    public const TARGETS = ['claude', 'agents', 'cursor', 'copilot'];

    protected $signature = 'apexdocs:install-ai
        {--target=claude,agents : Comma-separated targets: claude, agents, cursor, copilot, or all}
        {--force : Overwrite skill/agent files that were modified locally}';

    protected $description = 'Install the apexdocs skill, agent, instructions block and MCP server config for AI coding assistants';

    private Filesystem $files;

    private string $source;

    /** @var list<string> */
    private array $written = [];

    public function handle(Filesystem $files): int
    {
        $this->files = $files;
        $this->source = dirname(__DIR__, 5).'/resources/ai';

        $targets = $this->resolveTargets();
        if ($targets === null) {
            return self::INVALID;
        }

        foreach ($targets as $target) {
            match ($target) {
                'claude' => $this->installClaude(),
                'agents' => $this->installAgents(),
                'cursor' => $this->installCursor(),
                'copilot' => $this->installCopilot(),
                default => throw new \LogicException("Unhandled target {$target}"),
            };
        }

        $this->newLine();
        $this->components->info('AI assistant integration installed for: '.implode(', ', $targets));
        foreach ($this->written as $path) {
            $this->components->bulletList([$path]);
        }
        $this->newLine();
        $this->line('  The MCP server runs with <comment>php artisan apexdocs:mcp</comment> (stdio). Restart your editor/agent so it picks up the new config.');
        $this->line('  Re-run this command after upgrading the package to refresh the guidance.');

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function resolveTargets(): ?array
    {
        $option = $this->option('target');
        $raw = is_string($option) ? $option : 'claude,agents';
        $targets = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($raw))))));

        if (in_array('all', $targets, true)) {
            return self::TARGETS;
        }

        $unknown = array_diff($targets, self::TARGETS);
        if ($unknown !== []) {
            $this->components->error('Unknown target(s): '.implode(', ', $unknown).'. Valid: '.implode(', ', self::TARGETS).', all');

            return null;
        }

        return $targets === [] ? ['claude', 'agents'] : $targets;
    }

    private function installClaude(): void
    {
        $this->copyDirectory("{$this->source}/skills/apexdocs", $this->basePath('.claude/skills/apexdocs'));
        $this->copyFile("{$this->source}/agents/apexdocs.md", $this->basePath('.claude/agents/apexdocs.md'));
        $this->mergeMcpJson($this->basePath('.mcp.json'), 'mcpServers');
        $this->writeManagedBlock($this->basePath('CLAUDE.md'));
    }

    private function installAgents(): void
    {
        $this->copyDirectory("{$this->source}/skills/apexdocs", $this->basePath('.agents/skills/apexdocs'));
        $this->writeManagedBlock($this->basePath('AGENTS.md'));
    }

    private function installCursor(): void
    {
        $body = $this->instructionsBlock();
        $rule = <<<MDC
        ---
        description: Generating and improving OpenAPI 3.1 documentation with anil/apexdocs (attributes, DTO schemas, FormRequests, config, validation)
        globs: ["app/Http/Controllers/**/*.php", "app/Http/Requests/**/*.php", "app/Http/Resources/**/*.php", "app/Dto/**/*.php", "app/Data/**/*.php", "src/Controller/**/*.php", "routes/**/*.php", "config/apexdocs.php", "config/packages/apex_docs.yaml"]
        alwaysApply: false
        ---

        {$body}
        MDC;

        $this->putFile($this->basePath('.cursor/rules/apexdocs.mdc'), $rule);
        $this->copyDirectory("{$this->source}/skills/apexdocs", $this->basePath('.cursor/skills/apexdocs'));
        $this->mergeMcpJson($this->basePath('.cursor/mcp.json'), 'mcpServers');
    }

    private function installCopilot(): void
    {
        $this->writeManagedBlock($this->basePath('.github/copilot-instructions.md'));
        $this->mergeMcpJson($this->basePath('.vscode/mcp.json'), 'servers', vscode: true);
    }

    private function copyDirectory(string $from, string $to): void
    {
        foreach ($this->files->allFiles($from) as $file) {
            $this->copyFile($file->getPathname(), $to.'/'.$file->getRelativePathname());
        }
    }

    private function copyFile(string $from, string $to): void
    {
        $content = $this->files->get($from);

        if ($this->files->exists($to) && ! $this->option('force')) {
            if ($this->files->get($to) === $content) {
                return;
            }
            $this->components->warn("Skipped {$this->relative($to)} (differs locally  use --force to overwrite)");

            return;
        }

        $this->putFile($to, $content);
    }

    private function putFile(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);
        $this->written[] = $this->relative($path);
    }

    private function writeManagedBlock(string $path): void
    {
        $block = self::MARKER_START."\n".$this->instructionsBlock()."\n".self::MARKER_END;
        $existing = $this->files->exists($path) ? $this->files->get($path) : '';
        $pattern = '/'.preg_quote(self::MARKER_START, '/').'.*?'.preg_quote(self::MARKER_END, '/').'/s';

        $updated = preg_match($pattern, $existing) === 1
            ? (string) preg_replace($pattern, $block, $existing, 1)
            : ($existing === '' ? $block."\n" : rtrim($existing)."\n\n".$block."\n");

        if ($updated !== $existing) {
            $this->putFile($path, $updated);
        }
    }

    private function mergeMcpJson(string $path, string $key, bool $vscode = false): void
    {
        $config = [];
        if ($this->files->exists($path)) {
            $decoded = json_decode($this->files->get($path), true);
            if (! is_array($decoded)) {
                $this->components->warn("Skipped {$this->relative($path)} (not valid JSON  add the server manually, see resources/ai/mcp.json)");

                return;
            }
            $config = $decoded;
        }

        $servers = is_array($config[$key] ?? null) ? $config[$key] : [];
        $entry = ['command' => 'php', 'args' => ['artisan', 'apexdocs:mcp']];
        if ($vscode) {
            $entry = ['type' => 'stdio'] + $entry;
        }

        if (($servers['apexdocs'] ?? null) === $entry) {
            return;
        }

        $servers['apexdocs'] = $entry;
        $config[$key] = $servers;

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->components->warn("Could not encode {$this->relative($path)}");

            return;
        }

        $this->putFile($path, $json."\n");
    }

    private function instructionsBlock(): string
    {
        return trim($this->files->get("{$this->source}/AGENTS.md"));
    }

    private function basePath(string $path): string
    {
        return $this->laravel->basePath($path);
    }

    private function relative(string $path): string
    {
        return ltrim(Str::after($path, $this->laravel->basePath()), '/\\');
    }
}
