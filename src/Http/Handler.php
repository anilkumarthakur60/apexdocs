<?php

declare(strict_types=1);

namespace ApexDocs\Http;

use ApexDocs\ApexDocs;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 request handler — serves the docs UI, spec files, and exports.
 * Works with any PSR-7/PSR-15 compatible framework or middleware stack.
 *
 * Routes it handles (relative to the prefix you mount it on):
 *   GET /          → UI (HTML)
 *   GET /spec.json → OpenAPI JSON
 *   GET /spec.yaml → OpenAPI YAML
 *   GET /postman   → Postman Collection JSON (download)
 *   GET /insomnia  → Insomnia Export JSON (download)
 */
final class Handler implements RequestHandlerInterface
{
    public function __construct(
        private ApexDocs $apexDocs,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private UiRenderer $uiRenderer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');

        return match (true) {
            str_ends_with($path, '/spec.json') => $this->jsonSpec(),
            str_ends_with($path, '/spec.yaml') => $this->yamlSpec(),
            str_ends_with($path, '/postman') => $this->postman(),
            str_ends_with($path, '/insomnia') => $this->insomnia(),
            default => $this->ui($request),
        };
    }

    private function jsonSpec(): ResponseInterface
    {
        $doc = $this->apexDocs->generate();
        $content = (new JsonExporter)->toString($doc);

        return $this->respond($content, 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function yamlSpec(): ResponseInterface
    {
        $doc = $this->apexDocs->generate();
        $content = (new YamlExporter)->toString($doc);

        return $this->respond($content, 'application/yaml')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function postman(): ResponseInterface
    {
        $doc = $this->apexDocs->generate();
        $json = (new PostmanExporter)->toString($doc);
        $name = 'postman-collection.json';

        return $this->respond($json, 'application/json')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$name}\"");
    }

    private function insomnia(): ResponseInterface
    {
        $doc = $this->apexDocs->generate();
        $json = (new InsomniaExporter)->toString($doc);
        $name = 'insomnia-collection.json';

        return $this->respond($json, 'application/json')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$name}\"");
    }

    private function ui(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $ui = $params['ui'] ?? $this->apexDocs->getConfig()->defaultUi;
        $specUrl = $this->specUrlFromRequest($request);
        $html = $this->uiRenderer->render($ui, $specUrl, $this->apexDocs->getConfig());

        return $this->respond($html, 'text/html; charset=UTF-8');
    }

    private function specUrlFromRequest(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        return rtrim((string) $uri, '/').'/spec.json';
    }

    private function respond(string $body, string $contentType): ResponseInterface
    {
        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streamFactory->createStream($body));
    }
}
