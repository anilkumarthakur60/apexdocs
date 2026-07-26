<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\ApexDocs;
use ApexDocs\Cache\SpecCache;
use ApexDocs\Http\SpecPayload;
use ApexDocs\Http\UiRenderer;
use ApexDocs\Spec\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Laravel HTTP entry point for the docs site.
 *
 * Body + headers are produced by the framework-agnostic {@see SpecPayload}
 * so this controller and the PSR-15 {@see \ApexDocs\Http\Handler} can never
 * drift apart on content-type, CORS, or download filenames.
 */
class DocsController extends Controller
{
    public function __construct(
        private ApexDocs $apexDocs,
        private UiRenderer $uiRenderer,
    ) {}

    public function ui(Request $request): Response
    {
        $config = $this->apexDocs->getConfig();
        $theme = UiRenderer::normalizeTheme($request->query('theme'), $config->theme);
        $html = $this->uiRenderer->render(route('apexdocs.json'), $config, $theme);

        return $this->respond(SpecPayload::html($html));
    }

    public function json(): Response
    {
        return $this->respond(SpecPayload::json($this->document()));
    }

    public function yaml(): Response
    {
        return $this->respond(SpecPayload::yaml($this->document()));
    }

    public function postman(): Response
    {
        return $this->respond(SpecPayload::postman($this->document()));
    }

    public function insomnia(): Response
    {
        return $this->respond(SpecPayload::insomnia($this->document()));
    }

    public function bruno(): Response
    {
        return $this->respond(SpecPayload::bruno($this->document()));
    }

    /**
     * Build the spec, reusing a cached copy when caching is enabled. Reflecting
     * every controller on every request is the single most expensive thing this
     * package does.
     */
    private function document(): Document
    {
        if (! config('apexdocs.cache.enabled', false)) {
            return $this->apexDocs->generate();
        }

        try {
            $cache = app(SpecCache::class);
            $cached = $cache->get();
            if ($cached !== null) {
                return $cached;
            }

            $document = $this->apexDocs->generate();
            $cache->put('default', $document);

            return $document;
        } catch (\Throwable) {
            // A misconfigured cache store must not take the docs offline.
            return $this->apexDocs->generate();
        }
    }

    private function respond(SpecPayload $payload): Response
    {
        $response = response($payload->body)->header('Content-Type', $payload->contentType);
        foreach ($payload->headers as $name => $value) {
            $response = $response->header($name, $value);
        }
        if ($payload->downloadName !== null) {
            $response = $response->header(
                'Content-Disposition',
                'attachment; filename="'.$payload->downloadName.'"',
            );
        }

        return $response;
    }
}
