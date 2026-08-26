# What is inferred without any attribute

Per route (`OperationBuilder::build`), for each documentable verb (`GET PUT POST DELETE PATCH TRACE`
— HEAD/OPTIONS dropped, PROPFIND/PURGE etc. skipped; a route with *no* methods = GET):

| Field | Source |
|---|---|
| `operationId` | route name with non `[A-Za-z0-9_]` → `_` (prefixed `{verb}_` when the route answers several verbs); unnamed → `{verb}_{path segments joined by _}`; duplicates get `_2`, `_3`… |
| `summary` / `description` | PHPDoc first line / following lines until first `@tag` |
| `tags` | `#[Tag]` (method else class) → `#[Group]` → class short name minus `Controller` → `General`; closure routes: first non-`v\d+` path segment, ucfirst |
| path parameters | from the template; type: scalar type-hint → router constraint (`wheres`/Symfony requirements; `\d`-leading → integer, `a\|b` → enum, else pattern) → name heuristic (`id`, `*_id`, `*Id` → integer); description from `@param` |
| query/header/cookie parameters | **never inferred** — declare with attributes |
| request body | POST/PUT/PATCH: Laravel FormRequest type-hinted on the action (`rules()` executed on a constructor-less instance with the container set; failures → no body) / Symfony `#[MapRequestPayload]` param |
| 200 response | from `@return` / return type via `SchemaBuilder`; `{}` schema → plain `description: OK` |
| 401 | any middleware matching `\bauth\b` (`auth`, `auth:sanctum`, `auth.basic`) when `infer_error_responses` |
| 422 | write verbs when `include_validation_errors` (even without a FormRequest) |
| 429 | middleware containing `throttle` or `rate` when `rate_limits.enabled` |
| `security` | Laravel `SecurityDetector`: middleware containing `jwt` → `jwt`; `passport`/`oauth`/`client_credentials` → `passport`; `auth`, `auth:*`, `auth.*`, `*authenticate*` → `sanctum` if the middleware mentions sanctum, else the first detected scheme; each falls back to `bearerAuth` when the corresponding package is not installed. Symfony: `#[IsGranted]` on method or class → `bearerAuth`. Off when `security.auto_detect = false` (then no detected schemes are published either) |
| `securitySchemes` | `security.schemes` (keys sanitised to `[a-zA-Z0-9._-]`) + detector schemes: `sanctum` (http bearer, `bearerFormat: token`), `passport` (oauth2 authorizationCode + clientCredentials at `APP_URL/oauth/*`), `jwt` (http bearer JWT), or `bearerAuth` fallback |
| `servers` | config `servers` (entries without `url` dropped); Laravel injects `APP_URL` + env name when empty; core fallback `http://localhost` — never from `$_SERVER` |
| `tags` (document) | union of operation tags, with descriptions from `#[Tag]`/`#[Group]` |
| `webhooks` | `$apexDocs->webhook()` + `#[Webhook]` classes under `webhooks.scan_paths` |
| `x-*` extensions | `Deprecated`, `SunsetDate`, transformers (`extend()` adds the `x-` prefix if missing) |

Ordering: responses are `ksort`ed naturally; parameters keep path → class attrs → method attrs order.
Transformers run last: operation transformers per operation (config classes after fluent ones),
document transformers after everything (webhooks, registered schemas, standard components).

Closure routes / unreflectable handlers: operation with operationId, path-derived tag, path params
from the template, `200: OK`, transformers applied — nothing else.
