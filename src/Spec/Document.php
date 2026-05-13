<?php

declare(strict_types=1);

namespace ApexDocs\Spec;

use JsonSerializable;

/**
 * OpenAPI 3.1 root document.
 * Pure PHP — no framework dependency.
 */
final class Document implements JsonSerializable
{
    private string $openapi = '3.1.0';

    private array $info = [];

    private array $servers = [];

    private array $paths = [];

    private array $webhooks = [];

    private Components $components;

    private array $security = [];

    private array $tags = [];

    private array $extensions = [];

    public function __construct()
    {
        $this->components = new Components;
    }

    /**
     * Faithfully reconstruct a Document from its array form (as produced by
     * {@see toArray()}). Used by {@see \ApexDocs\Cache\SpecCache} so cached
     * specs round-trip every section — not just paths.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $doc = new self;
        if (isset($data['openapi']) && is_string($data['openapi'])) {
            $doc->openapi = $data['openapi'];
        }
        if (isset($data['info']) && is_array($data['info'])) {
            $doc->info = $data['info'];
        }
        if (isset($data['servers']) && is_array($data['servers'])) {
            $doc->servers = $data['servers'];
        }
        if (isset($data['paths']) && is_array($data['paths'])) {
            $doc->paths = $data['paths'];
        }
        if (isset($data['webhooks']) && is_array($data['webhooks'])) {
            $doc->webhooks = $data['webhooks'];
        }
        if (isset($data['components']) && is_array($data['components'])) {
            $doc->components = Components::fromArray($data['components']);
        }
        if (isset($data['security']) && is_array($data['security'])) {
            $doc->security = $data['security'];
        }
        if (isset($data['tags']) && is_array($data['tags'])) {
            $doc->tags = $data['tags'];
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'x-')) {
                $doc->extensions[$k] = $v;
            }
        }

        return $doc;
    }

    // ── Info ──────────────────────────────────────────────────────────────────

    public function info(string $title, string $version, string $description = ''): self
    {
        $this->info = array_filter([
            'title' => $title,
            'version' => $version,
            'description' => $description,
        ]);

        return $this;
    }

    public function addInfoField(string $key, mixed $value): self
    {
        $this->info[$key] = $value;

        return $this;
    }

    // ── Servers ───────────────────────────────────────────────────────────────

    public function addServer(string $url, string $description = '', array $variables = []): self
    {
        $server = array_filter(['url' => $url, 'description' => $description, 'variables' => $variables]);
        $this->servers[] = $server;

        return $this;
    }

    // ── Paths ─────────────────────────────────────────────────────────────────

    public function addOperation(string $path, string $method, Operation $operation): self
    {
        $this->paths[$path][strtolower($method)] = $operation->toArray();

        return $this;
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    public function addWebhook(string $name, array $spec): self
    {
        $this->webhooks[$name] = $spec;

        return $this;
    }

    // ── Components ────────────────────────────────────────────────────────────

    public function components(): Components
    {
        return $this->components;
    }

    // ── Security ─────────────────────────────────────────────────────────────

    public function addGlobalSecurity(string $scheme, array $scopes = []): self
    {
        $this->security[] = [$scheme => $scopes];

        return $this;
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    public function addTag(string $name, string $description = ''): self
    {
        foreach ($this->tags as $tag) {
            if ($tag['name'] === $name) {
                return $this;
            }
        }
        $this->tags[] = array_filter(['name' => $name, 'description' => $description]);

        return $this;
    }

    // ── Extensions ────────────────────────────────────────────────────────────

    public function extend(string $key, mixed $value): self
    {
        $k = str_starts_with($key, 'x-') ? $key : 'x-'.$key;
        $this->extensions[$k] = $value;

        return $this;
    }

    // ── Serialisation ─────────────────────────────────────────────────────────

    public function toArray(): array
    {
        $doc = ['openapi' => $this->openapi];

        if ($this->info) {
            $doc['info'] = $this->info;
        }

        if ($this->servers) {
            $doc['servers'] = $this->servers;
        }

        if ($this->paths) {
            $doc['paths'] = $this->paths;
        }

        if ($this->webhooks) {
            $doc['webhooks'] = $this->webhooks;
        }

        $components = $this->components->toArray();
        if ($components) {
            $doc['components'] = $components;
        }

        if ($this->security) {
            $doc['security'] = $this->security;
        }

        if ($this->tags) {
            $doc['tags'] = $this->tags;
        }

        foreach ($this->extensions as $k => $v) {
            $doc[$k] = $v;
        }

        return $doc;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($this->toArray(), $flags);
    }
}
