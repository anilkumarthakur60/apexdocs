<?php

declare(strict_types=1);

namespace ApexDocs\Spec;

/**
 * OpenAPI components object — reusable schemas, responses, parameters, etc.
 */
final class Components
{
    private array $schemas = [];

    private array $responses = [];

    private array $parameters = [];

    private array $examples = [];

    private array $requestBodies = [];

    private array $headers = [];

    private array $securitySchemes = [];

    public function addSchema(string $name, array $schema): self
    {
        $this->schemas[$name] = $schema;

        return $this;
    }

    public function hasSchema(string $name): bool
    {
        return isset($this->schemas[$name]);
    }

    public function addResponse(string $name, array $response): self
    {
        $this->responses[$name] = $response;

        return $this;
    }

    public function addParameter(string $name, array $parameter): self
    {
        $this->parameters[$name] = $parameter;

        return $this;
    }

    public function addExample(string $name, array $example): self
    {
        $this->examples[$name] = $example;

        return $this;
    }

    public function addRequestBody(string $name, array $body): self
    {
        $this->requestBodies[$name] = $body;

        return $this;
    }

    public function addHeader(string $name, array $header): self
    {
        $this->headers[$name] = $header;

        return $this;
    }

    public function addSecurityScheme(string $name, array $scheme): self
    {
        $this->securitySchemes[$name] = $scheme;

        return $this;
    }

    /**
     * Inverse of {@see toArray()} — populate from a serialised form (e.g. a
     * cached spec). Round-trips every supported section so callers get the
     * exact components map back.
     *
     * @param  array<string, array<string, mixed>>  $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self;
        $c->schemas = $data['schemas'] ?? [];
        $c->responses = $data['responses'] ?? [];
        $c->parameters = $data['parameters'] ?? [];
        $c->examples = $data['examples'] ?? [];
        $c->requestBodies = $data['requestBodies'] ?? [];
        $c->headers = $data['headers'] ?? [];
        $c->securitySchemes = $data['securitySchemes'] ?? [];

        return $c;
    }

    public function toArray(): array
    {
        return array_filter([
            'schemas' => $this->schemas,
            'responses' => $this->responses,
            'parameters' => $this->parameters,
            'examples' => $this->examples,
            'requestBodies' => $this->requestBodies,
            'headers' => $this->headers,
            'securitySchemes' => $this->securitySchemes,
        ]);
    }
}
