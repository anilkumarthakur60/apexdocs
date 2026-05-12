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
        public readonly string $defaultUi = 'scalar',
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
            defaultUi: $config['ui']['default'] ?? 'scalar',
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
        );
    }
}
