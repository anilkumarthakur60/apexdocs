<?php

declare(strict_types=1);

namespace ApexDocs;

/**
 * Immutable configuration value object.
 * No framework dependency — just a plain PHP object.
 */
final class Config
{
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

        /** UI renderer: 'apex' (native, no CDN) | 'scalar' | 'swagger' | 'redoc' | 'stoplight' | 'rapidoc' */
        public readonly string $defaultUi = 'apex',
        public readonly bool $showUiSwitcher = true,

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
            title: $config['info']['title'] ?? $config['title'] ?? 'API',
            version: $config['info']['version'] ?? $config['version'] ?? '1.0.0',
            description: $config['info']['description'] ?? $config['description'] ?? '',
            servers: $config['servers'] ?? [],
            pathPrefixes: isset($config['api_path_prefix'])
                ? (array) $config['api_path_prefix']
                : ($config['path_prefixes'] ?? ['api']),
            excludePaths: $config['exclude_paths'] ?? [],
            inferErrorResponses: $config['responses']['infer_error_responses'] ?? true,
            includeValidationErrors: $config['responses']['include_validation_errors'] ?? true,
            includePaginationMeta: $config['responses']['include_pagination_meta'] ?? true,
            maxSchemaDepth: $config['responses']['max_depth'] ?? 6,
            cacheEnabled: $config['cache']['enabled'] ?? false,
            cacheTtl: $config['cache']['ttl'] ?? 3600,
            defaultUi: $config['ui']['default'] ?? 'apex',
            showUiSwitcher: $config['ui']['show_ui_switcher'] ?? true,
            contact: array_filter($config['info']['contact'] ?? []),
            license: array_filter($config['info']['license'] ?? []),
            termsOfService: $config['info']['terms_of_service'] ?? '',
            documentTransformers: $config['document_transformers'] ?? [],
            operationTransformers: $config['operation_transformers'] ?? [],
            securitySchemes: $config['security']['schemes'] ?? [],
            autoDetectSecurity: $config['security']['auto_detect'] ?? true,
            documentRateLimits: $config['rate_limits']['enabled'] ?? true,
            webhookScanPaths: $config['webhooks']['scan_paths'] ?? [],
            exportPath: $config['export']['default_path'] ?? sys_get_temp_dir().'/apexdocs',
            specGroup: $config['spec_group'] ?? '',
            theme: $config['ui']['theme'] ?? 'dark',
            customLogo: $config['ui']['custom_logo'] ?? '',
            customCss: $config['ui']['custom_css'] ?? '',
            announcementBanner: $config['ui']['announcement_banner'] ?? '',
            announcementBannerType: $config['ui']['announcement_banner_type'] ?? 'info',
            tryItOutEnabled: $config['ui']['try_it_out'] ?? true,
            defaultLanguage: $config['ui']['default_language'] ?? 'curl',
        );
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
            defaultUi: $overrides['defaultUi'] ?? $this->defaultUi,
            showUiSwitcher: $overrides['showUiSwitcher'] ?? $this->showUiSwitcher,
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
