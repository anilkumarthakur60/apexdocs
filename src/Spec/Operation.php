<?php

declare(strict_types=1);

namespace ApexDocs\Spec;

/**
 * OpenAPI operation object.
 * Pure PHP  no framework dependency.
 */
final class Operation
{
    private string $operationId = '';

    private string $summary = '';

    private string $description = '';

    private array $tags = [];

    private bool $deprecated = false;

    private array $parameters = [];

    private ?array $requestBody = null;

    private array $responses = [];

    private ?array $security = null;

    private ?array $externalDocs = null;

    private array $extensions = [];

    // ── Setters ───────────────────────────────────────────────────────────────

    public function id(string $id): self
    {
        $this->operationId = $id;

        return $this;
    }

    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function tags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function deprecated(bool $deprecated = true): self
    {
        $this->deprecated = $deprecated;

        return $this;
    }

    public function addParameter(array $parameter): self
    {
        $this->parameters[] = $parameter;

        return $this;
    }

    public function requestBody(array $requestBody): self
    {
        $this->requestBody = $requestBody;

        return $this;
    }

    public function addResponse(string $statusCode, array $response): self
    {
        $this->responses[$statusCode] = $response;

        return $this;
    }

    public function security(?array $security): self
    {
        $this->security = $security;

        return $this;
    }

    public function externalDocs(string $url, string $description = ''): self
    {
        // `url` is the only required field of an External Documentation Object,
        // and "0" is a legitimate value  so filter on emptiness, not falsiness.
        if ($url === '') {
            return $this;
        }

        $this->externalDocs = ['url' => $url];
        if ($description !== '') {
            $this->externalDocs['description'] = $description;
        }

        return $this;
    }

    public function extend(string $key, mixed $value): self
    {
        $k = str_starts_with($key, 'x-') ? $key : 'x-'.$key;
        $this->extensions[$k] = $value;

        return $this;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public function getTags(): array
    {
        return $this->tags;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->toArray()[$key] ?? $default;
    }

    // ── Serialise ─────────────────────────────────────────────────────────────

    public function toArray(): array
    {
        $op = [];

        if ($this->tags) {
            $op['tags'] = $this->tags;
        }
        if ($this->summary) {
            $op['summary'] = $this->summary;
        }
        if ($this->description) {
            $op['description'] = $this->description;
        }
        if ($this->operationId) {
            $op['operationId'] = $this->operationId;
        }
        if ($this->externalDocs) {
            $op['externalDocs'] = $this->externalDocs;
        }
        if ($this->parameters) {
            $op['parameters'] = $this->parameters;
        }
        if ($this->requestBody !== null) {
            $op['requestBody'] = $this->requestBody;
        }
        if ($this->security !== null) {
            $op['security'] = $this->security;
        }
        if ($this->deprecated) {
            $op['deprecated'] = true;
        }

        $op['responses'] = $this->responses ?: ['200' => ['description' => 'OK']];

        foreach ($this->extensions as $k => $v) {
            $op[$k] = $v;
        }

        return $op;
    }
}
