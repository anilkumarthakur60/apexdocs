## API documentation: anil/apexdocs (OpenAPI 3.1)

This project generates its OpenAPI 3.1 spec with `anil/apexdocs` from routes + code — never
hand-edit a spec file.

- **Enrich with PHP attributes** (`ApexDocs\Attribute\*`) on controllers/methods: `#[Group]`,
  `#[Endpoint]`, `#[Tag]`, `#[Hidden]`, `#[Deprecated]`, `#[SunsetDate]`, `#[Security]`,
  `#[NoSecurity]`, `#[PathParam]`, `#[QueryParam]`, `#[HeaderParam]`, `#[CookieParam]`,
  `#[BodyParam]`, `#[RequestBody]`, `#[ApiResponse]`, `#[Example]`, `#[ResponseHeader]`,
  `#[Produces]`, `#[ExternalDocs]`, `#[ApiGroup]`; `#[Schema]` on DTOs; `#[Webhook]` on event classes.
- Summary/description fall back to the method PHPDoc; response schema to the `@return` /
  return type (`Dto[]`, `Collection<int, Dto>`, `LengthAwarePaginator<Dto>` are unwrapped);
  request body to the type-hinted Laravel `FormRequest::rules()` (or Symfony `#[MapRequestPayload]`).
- DTO schemas come from **public properties / promoted constructor params**; `@var`/`@param`
  give element types; backed enums become `enum`; nullable → `type: [T, "null"]`.
- Laravel: config `config/apexdocs.php` (`api_path_prefix`, `exclude_paths`, `environments`,
  `middleware`, `cache`, `security.auto_detect`…); UI at `/documentation/api`; commands
  `apexdocs:generate|validate|export|diff|watch|mock`. Docs routes exist only in `local`/`staging` by default.
- `ApexDocs` is **immutable** — every fluent call returns a new instance; in Laravel rebind with
  `app()->extend(ApexDocs::class, fn ($d) => $d->filterRoutes(...))`.
- After changing routes, controllers, FormRequests or DTOs run `php artisan apexdocs:validate --strict`.
- Full guidance: the `apexdocs` skill (`.claude/skills/apexdocs/SKILL.md` or `.agents/skills/apexdocs/SKILL.md`).
  Live inspection: the `apexdocs` MCP server (`php artisan apexdocs:mcp`) — tools `spec_summary`,
  `list_operations`, `describe_operation`, `list_routes` (with the reason a route is missing),
  `list_schemas`, `get_schema`, `validate_spec`, `diff_spec`, `export_spec`, `get_config`,
  `attribute_reference`, `read_reference`, `search_reference`.
