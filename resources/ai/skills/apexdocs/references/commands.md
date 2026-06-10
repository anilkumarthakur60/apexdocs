# Artisan commands (Laravel bridge)

All run in every environment (the `environments` gate is HTTP-only). Exit codes: `0` success,
`1` failure, `2` (`INVALID`) bad arguments.

| Command | Options | Behaviour |
|---|---|---|
| `apexdocs:generate` | `--format=json\|yaml\|yml` (default json), `--output=path` | Without `--output` the spec is the **only** thing on stdout (progress → stderr), so `> openapi.json` is safe. With `--output` writes the file and prints `N paths in Xms`. |
| `apexdocs:validate` | `--strict` | Runs `SpecValidator`; prints errors then warnings; exit 1 on errors (or on warnings with `--strict`). |
| `apexdocs:export {format}` | `openapi-json\|openapi-yaml\|postman\|insomnia\|bruno`, `--output=path` | Default target `export.default_path/{openapi.json\|openapi.yaml\|{format}.json}`; unknown format → exit 2; write failure → exit 1. |
| `apexdocs:diff {base}` | `--format=text\|json` | Baseline must be a readable OpenAPI JSON file with map-shaped `paths`; exit 1 when any breaking change. JSON output `{breaking, added, changed}`. |
| `apexdocs:watch` | `--output=path`, `--interval=2` | Polls mtimes of `app/` and `routes/` PHP files; regenerates on change; Ctrl+C stops (pcntl when available). |
| `apexdocs:mock` | `--host=127.0.0.1`, `--port=8081` | Writes the spec to a 0600 temp file, starts `php -S` with `resources/mock/server.php`, which answers each path with an example built from its lowest 2xx response (+ documented headers); `?__status=404` selects another documented response. |
| `apexdocs:mcp` | `--in-process`, `--timeout=120` | MCP server over stdio. Default: every snapshot is built by running `apexdocs:snapshot` in a **fresh process** so code changes are visible; `--in-process` is faster but stale after edits. |
| `apexdocs:snapshot` (hidden) |  | Prints `{generated_at, duration_ms, config, routes[], spec}` JSON  what the MCP server consumes. |
| `apexdocs:install-ai` | `--target=claude,agents\|cursor\|copilot\|all`, `--force` | Installs the skill, agent, instructions block and MCP config into the project (idempotent, marker blocks, merged JSON). |

Publish tags: `apexdocs-config` (config file), `apexdocs-ai` (skill + agent into `.claude/`).

## CI recipe

```yaml
- run: php artisan apexdocs:validate --strict
- run: php artisan apexdocs:diff docs/openapi.baseline.json --format=json
# update the baseline deliberately:
- run: php artisan apexdocs:generate --output=docs/openapi.baseline.json
```

## Standalone MCP (non-Laravel)

```bash
vendor/bin/apexdocs-mcp --bootstrap=apexdocs.php            # subprocess mode (default)
vendor/bin/apexdocs-mcp --bootstrap=apexdocs.php --mode=in-process
```
`apexdocs.php` must `return` a configured `ApexDocs\ApexDocs` (routes attached). Register in
the MCP client as `{"command": "vendor/bin/apexdocs-mcp", "args": ["--bootstrap=apexdocs.php"]}`.
