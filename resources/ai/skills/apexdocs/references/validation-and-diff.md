# Validation & diff  exact rules

## `SpecValidator` (`apexdocs:validate`, MCP `validate_spec`)

| Message | Cause | Fix |
|---|---|---|
| `Missing required field: openapi/info` | not produced by the generator  corrupt baseline / transformer removed it | check document transformers |
| `Missing required field: info.title/version` | empty `info.title`/`version` config | set `info.*` |
| `Missing required field: paths (no routes matched  …)` | nothing passed the route gates | `list_routes`; fix `api_path_prefix`/`exclude_paths`/`spec_group` |
| `servers[i]: missing url` | server entry without url (core drops those; a transformer may add one) | |
| `METHOD /path: no responses` | transformer removed responses | |
| `… response N has no description (required by the spec)` | `#[ApiResponse]` with empty description **and** unknown status (no reason phrase), or a transformer | add `description:` |
| `… 'N' is not a valid response key` | status outside `1XX-5XX`/`default` (core maps to `default`) | |
| `… duplicate operationId 'x' (also on …)` | two routes share a name, or unnamed routes differing only in punctuation and a transformer overrode ids | name routes uniquely |
| `… path template declares {x} but no matching parameter` | `#[PathParam]` name differs from the template, or a transformer removed it | match names |
| `… path parameter 'x' must be required` | transformer set `required: false` | |
| `… parameter missing required field 'name'/'in'` | malformed parameter from a transformer | |
| `Unresolved $ref: #/components/... (referenced N×)` | class schema not registered (transformer added a `$ref`; class removed) | |
| `Security requirement 'x' has no matching securityScheme` | `#[Security('x')]` or transformer names a scheme not in `security.schemes` and not detected | declare the scheme or fix the name |
| *warning* `… missing operationId` | transformer cleared it | |
| *warning* `… missing summary` | no PHPDoc first line and no `#[Endpoint(summary:)]` | add one |

`--strict` turns warnings into a failing exit.

## `SpecDiff` (`apexdocs:diff`, MCP `diff_spec`)

| Category | Rule |
|---|---|
| **breaking** | path removed (`METHOD /path removed`); method removed from a path; new required parameter (`name:in`); new required request-body field (JSON schema `required`); a 2xx/3xx response status removed |
| **added** | new path or new method |
| **changed** | parameter no longer required; operation newly `deprecated` |

Not detected (by design, keep in mind): schema property changes, type changes, enum changes,
security changes, response body changes. Exit code 1 only for breaking changes.
