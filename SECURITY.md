# Security Policy

## Supported versions

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | :white_check_mark: |

ApexDocs is pre-1.0. We support the latest minor release on the `0.x` line
with security patches. Older minors stop receiving patches once a new minor
is published.

## Reporting a vulnerability

**Do not file a public GitHub issue.**

Report security issues privately to **anilkumarthakur60@gmail.com** with the
subject prefix `[ApexDocs Security]`. Include:

- A description of the issue and the impact you believe it has.
- A minimal reproduction (spec, controller, route, config — whichever applies).
- The affected version(s) of ApexDocs.
- Optionally, a suggested patch.

You will receive an acknowledgement within 72 hours. We aim to publish a fix
and CVE within 14 days of confirmation, depending on severity. We will credit
you in the release notes unless you ask us not to.

## Out of scope

- Issues that require an attacker to already have write access to your
  controller source or `apexdocs` configuration.
- Findings in tooling-only dev dependencies (Orchestra Testbench, etc.).

## Hardening notes for operators

- **The docs routes are not registered in production by default.**
  `apexdocs.environments` defaults to `['local', 'staging']`. If you add
  `production` to that list, also replace the default `['web']` middleware with
  `['web', 'auth']` or a guard of your own — an OpenAPI document is a complete
  map of your API surface, including parameter names and validation rules.
- **Set `apexdocs.servers` explicitly in production.** Without it the Laravel
  bridge injects `config('app.url')`, so make sure `APP_URL` is correct. The
  generator never reads `$_SERVER`, so a forged `Host` header cannot end up in a
  cached spec served to every consumer.
- **Treat `apexdocs:mock` as a local tool.** It binds `127.0.0.1` by default and
  serves example data from your spec with no authentication; do not bind it to a
  public interface.
- **`ui.announcement_banner` is rendered as raw HTML** so it can carry links.
  Only put trusted content there — it is configuration, not user input, and is
  not escaped.
