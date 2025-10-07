# Contributing

Thanks for considering a contribution. ApexDocs aims to stay small, framework
agnostic, and pleasant to use — please keep changes aligned with that.

## Getting set up

```bash
git clone https://github.com/apexdocs/apexdocs
cd apexdocs
composer install
vendor/bin/pest
```

You need PHP 8.2+ locally. The CI matrix runs 8.2, 8.3, and 8.4 against
Laravel 11 and 12.

## What to send

- **Bug fixes** — always welcome. Include a Pest test that fails without your
  change.
- **New features** — open an issue first so we can align on scope. The core
  ships intentionally lean; framework specifics live in bridges, UI specifics
  live behind the `UiBackend` strategy.
- **Documentation** — corrections, clarifications, and examples are always
  welcome and don't require an issue.

## What to avoid

- New runtime dependencies. The core has eight PSR / Symfony deps and zero
  framework deps — adding to that list needs strong justification.
- Tooling sprawl. The project uses Pest only. We do not run PHPStan, Pint,
  Rector, Infection, etc. in CI. Style is enforced by review, not by tools.
- Breaking changes to the public API (`ApexDocs\ApexDocs`, `ApexDocs\Config`,
  `ApexDocs\Contract\*`, `ApexDocs\Attribute\*`) without a deprecation cycle.
  Internals (`Generator/`, `Extractor/`, `Http/`) carry no BC promise.

## Pull request flow

1. Fork and create a topic branch off `main`.
2. Make the change. Add or update tests in `tests/Unit/` or `tests/Feature/`.
3. Run `vendor/bin/pest` locally — green.
4. Open the PR with a clear description: what changed, why, and any tradeoff
   you considered. Link the issue if one exists.
5. CI will run the test matrix. Reviews typically happen within a week.

## Commit messages

Conventional Commit prefixes are preferred but not required:

- `feat:` new feature
- `fix:` bug fix
- `docs:` docs only
- `refactor:` no behaviour change
- `test:` test only
- `chore:` infrastructure / repo hygiene

Squash-merge is the default — write the final commit message in the PR body.

## Security issues

Do not file a public issue. See `SECURITY.md`.
