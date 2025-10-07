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

Report security issues privately to **security@apexdocs.dev**. Include:

- A description of the issue and the impact you believe it has.
- A minimal reproduction (spec, controller, route, config — whichever applies).
- The affected version(s) of ApexDocs.
- Optionally, a suggested patch.

You will receive an acknowledgement within 72 hours. We aim to publish a fix
and CVE within 14 days of confirmation, depending on severity. We will credit
you in the release notes unless you ask us not to.

## Out of scope

- Vulnerabilities in third-party UI backends (Scalar, Swagger, Redoc, Stoplight,
  RapiDoc) loaded via their own CDNs. Report those to their respective
  maintainers.
- Issues that require an attacker to already have write access to your
  controller source or `apexdocs` configuration.
- Findings in tooling-only dev dependencies (Orchestra Testbench, etc.).

## Hardening notes for operators

- Mount the documentation routes behind authentication in production. The
  default Laravel route group uses the `web` middleware — replace with
  `auth` (or a custom guard) if your spec is not public.
- Set `apexdocs.servers` explicitly in production. Without it, the spec falls
  back to a constant default and the Laravel bridge injects `config('app.url')`
  — make sure your `APP_URL` is correct.
- Disable the `apexdocs:mock` command in production by removing
  `MockCommand::class` from the service provider's command list. The mock
  server is for local development only.
