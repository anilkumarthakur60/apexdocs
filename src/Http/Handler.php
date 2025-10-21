<?php

declare(strict_types=1);

namespace ApexDocs\Http;

use ApexDocs\ApexDocs;
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
 *   GET /bruno     → Bruno Collection JSON (download)
 *
 * Payload construction is delegated to {@see SpecPayload} so this handler
 * and the Laravel {@see \ApexDocs\Bridge\Laravel\DocsController} stay in
 * lockstep — same bytes, same headers, no drift.
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

        $payload = match (true) {
            str_ends_with($path, '/spec.json') => SpecPayload::json($this->apexDocs),
            str_ends_with($path, '/spec.yaml') => SpecPayload::yaml($this->apexDocs),
            str_ends_with($path, '/postman') => SpecPayload::postman($this->apexDocs),
            str_ends_with($path, '/insomnia') => SpecPayload::insomnia($this->apexDocs),
            str_ends_with($path, '/bruno') => SpecPayload::bruno($this->apexDocs),
            default => SpecPayload::html($this->renderUi($request)),
        };

        return $this->toPsrResponse($payload);
    }

    private function renderUi(ServerRequestInterface $request): string
    {
        $params = $request->getQueryParams();
        $config = $this->apexDocs->getConfig();
        $ui = is_string($params['ui'] ?? null) ? $params['ui'] : $config->defaultUi;
        $specUrl = $this->specUrlFromRequest($request);

        return $this->uiRenderer->render($ui, $specUrl, $config);
    }

    private function specUrlFromRequest(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        return rtrim((string) $uri, '/').'/spec.json';
    }

    private function toPsrResponse(SpecPayload $payload): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', $payload->contentType)
            ->withBody($this->streamFactory->createStream($payload->body));

        foreach ($payload->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if ($payload->downloadName !== null) {
            $response = $response->withHeader(
                'Content-Disposition',
                'attachment; filename="'.$payload->downloadName.'"',
            );
        }

        return $response;
    }
}
