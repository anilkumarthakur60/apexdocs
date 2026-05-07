<?php

declare(strict_types=1);

namespace ApexDocs;

/**
 * Immutable configuration value object.
 * No framework dependency — just a plain PHP object.
 *
 * {@see fromArray()} reads the snake_case, nested shape published by the
 * framework bridges; {@see with()} takes camelCase property names.
 */
final class Config
{
    private const THEMES = ['dark', 'light', 'auto'];

    private const BANNER_TYPES = ['info', 'warning', 'error'];

    private const LANGUAGES = ['curl', 'js', 'python', 'php', 'go'];

    public function __construct(
        public readonly string $title = 'API',
        public readonly string $version = '1.0.0',
        public readonly string $description = '',
        public readonly array $servers = [],
        public readonly array $pathPrefixes = ['api'],
        public readonly array $excludePaths = [],
        public readonly bool $inferErrorResponses = true,
        public readonly bool $includeValidationErrors = true,
        public readonly bool $includePaginationMeta = true,
        public readonly int $maxSchemaDepth = 6,
        public readonly bool $cacheEnabled = false,
        public readonly int $cacheTtl = 3600,

        /** Show the toolbar header (brand, search, theme, servers, export). */
        public readonly bool $showToolbar = true,

        public readonly array $contact = [],
        public readonly array $license = [],
        public readonly string $termsOfService = '',
        public readonly array $documentTransformers = [],
        public readonly array $operationTransformers = [],
        public readonly array $securitySchemes = [],
        public readonly bool $autoDetectSecurity = true,
        public readonly bool $documentRateLimits = true,
        public readonly array $webhookScanPaths = [],
        public readonly string $exportPath = '',

        /** When non-empty, only routes tagged #[ApiGroup(name)] matching this value are included. */
        public readonly string $specGroup = '',

        // ── UI customisation ──────────────────────────────────────────────────

        /** 'dark' | 'light' | 'auto' (follows system preference). Applies to the native Apex UI and toolbar. */
        public readonly string $theme = 'dark',

        /** Optional URL to replace the default lightning-bolt brand icon. */
        public readonly string $customLogo = '',

        /** Raw CSS injected into every documentation page <head>. */
        public readonly string $customCss = '',

        /**
         * Banner shown above the toolbar. Plain text or basic HTML.
         * Leave empty to hide.
         */
        public readonly string $announcementBanner = '',

        /** 'info' | 'warning' | 'error' — controls the banner colour. */
        public readonly string $announcementBannerType = 'info',

        /** Enable the Try-It-Out panel in the native Apex UI. */
        public readonly bool $tryItOutEnabled = true,

        /** Default code-sample language in Apex UI: 'curl' | 'js' | 'python' | 'php' | 'go' */
        public readonly string $defaultLanguage = 'curl',
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            title: (string) ($config['info']['title'] ?? $config['title'] ?? 'API'),
            version: (string) ($config['info']['version'] ?? $config['version'] ?? '1.0.0'),
            description: (string) ($config['info']['description'] ?? $config['description'] ?? ''),
            servers: (array) ($config['servers'] ?? []),
            pathPrefixes: isset($config['api_path_prefix'])
                ? (array) $config['api_path_prefix']
                : (array) ($config['path_prefixes'] ?? ['api']),
            excludePaths: (array) ($config['exclude_paths'] ?? []),
            inferErrorResponses: (bool) ($config['responses']['infer_error_responses'] ?? true),
            includeValidationErrors: (bool) ($config['responses']['include_validation_errors'] ?? true),
            includePaginationMeta: (bool) ($config['responses']['include_pagination_meta'] ?? true),
            maxSchemaDepth: max(1, (int) ($config['responses']['max_depth'] ?? 6)),
            cacheEnabled: (bool) ($config['cache']['enabled'] ?? false),
            cacheTtl: max(1, (int) ($config['cache']['ttl'] ?? 3600)),
            showToolbar: (bool) ($config['ui']['show_toolbar'] ?? true),
            contact: array_filter((array) ($config['info']['contact'] ?? [])),
            license: array_filter((array) ($config['info']['license'] ?? [])),
            termsOfService: (string) ($config['info']['terms_of_service'] ?? ''),
            documentTransformers: (array) ($config['document_transformers'] ?? []),
            operationTransformers: (array) ($config['operation_transformers'] ?? []),
            securitySchemes: (array) ($config['security']['schemes'] ?? []),
            autoDetectSecurity: (bool) ($config['security']['auto_detect'] ?? true),
            documentRateLimits: (bool) ($config['rate_limits']['enabled'] ?? true),
            webhookScanPaths: (array) ($config['webhooks']['scan_paths'] ?? []),
            exportPath: (string) ($config['export']['default_path'] ?? ''),
            specGroup: (string) ($config['spec_group'] ?? ''),
            theme: self::oneOf($config['ui']['theme'] ?? null, self::THEMES, 'dark'),
            customLogo: (string) ($config['ui']['custom_logo'] ?? ''),
            customCss: (string) ($config['ui']['custom_css'] ?? ''),
            announcementBanner: (string) ($config['ui']['announcement_banner'] ?? ''),
            announcementBannerType: self::oneOf($config['ui']['announcement_banner_type'] ?? null, self::BANNER_TYPES, 'info'),
            tryItOutEnabled: (bool) ($config['ui']['try_it_out'] ?? true),
            defaultLanguage: self::oneOf($config['ui']['default_language'] ?? null, self::LANGUAGES, 'curl'),
        );
    }

    /**
     * Coerce a value to one of the supported options. A typo in config should
     * fall back to a working default, not render a broken docs page.
     *
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array(strtolower($value), $allowed, true)
            ? strtolower($value)
            : $default;
    }

    /** Where exports land when no path is given on the command line. */
    public function exportPath(): string
    {
        return $this->exportPath !== '' ? $this->exportPath : sys_get_temp_dir().'/apexdocs';
    }

    public function with(array $overrides): self
    {
        return new self(
            title: $overrides['title'] ?? $this->title,
            version: $overrides['version'] ?? $this->version,
            description: $overrides['description'] ?? $this->description,
            servers: $overrides['servers'] ?? $this->servers,
            pathPrefixes: $overrides['pathPrefixes'] ?? $this->pathPrefixes,
            excludePaths: $overrides['excludePaths'] ?? $this->excludePaths,
            inferErrorResponses: $overrides['inferErrorResponses'] ?? $this->inferErrorResponses,
            includeValidationErrors: $overrides['includeValidationErrors'] ?? $this->includeValidationErrors,
            includePaginationMeta: $overrides['includePaginationMeta'] ?? $this->includePaginationMeta,
            maxSchemaDepth: $overrides['maxSchemaDepth'] ?? $this->maxSchemaDepth,
            cacheEnabled: $overrides['cacheEnabled'] ?? $this->cacheEnabled,
            cacheTtl: $overrides['cacheTtl'] ?? $this->cacheTtl,
            showToolbar: $overrides['showToolbar'] ?? $this->showToolbar,
            contact: $overrides['contact'] ?? $this->contact,
            license: $overrides['license'] ?? $this->license,
            termsOfService: $overrides['termsOfService'] ?? $this->termsOfService,
            documentTransformers: $overrides['documentTransformers'] ?? $this->documentTransformers,
            operationTransformers: $overrides['operationTransformers'] ?? $this->operationTransformers,
            securitySchemes: $overrides['securitySchemes'] ?? $this->securitySchemes,
            autoDetectSecurity: $overrides['autoDetectSecurity'] ?? $this->autoDetectSecurity,
            documentRateLimits: $overrides['documentRateLimits'] ?? $this->documentRateLimits,
            webhookScanPaths: $overrides['webhookScanPaths'] ?? $this->webhookScanPaths,
            exportPath: $overrides['exportPath'] ?? $this->exportPath,
            specGroup: $overrides['specGroup'] ?? $this->specGroup,
            theme: $overrides['theme'] ?? $this->theme,
            customLogo: $overrides['customLogo'] ?? $this->customLogo,
            customCss: $overrides['customCss'] ?? $this->customCss,
            announcementBanner: $overrides['announcementBanner'] ?? $this->announcementBanner,
            announcementBannerType: $overrides['announcementBannerType'] ?? $this->announcementBannerType,
            tryItOutEnabled: $overrides['tryItOutEnabled'] ?? $this->tryItOutEnabled,
            defaultLanguage: $overrides['defaultLanguage'] ?? $this->defaultLanguage,
        );
    }
}
