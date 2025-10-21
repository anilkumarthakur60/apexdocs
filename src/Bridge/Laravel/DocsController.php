<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\ApexDocs;
use ApexDocs\Http\SpecPayload;
use ApexDocs\Http\UiRenderer;
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
        $ui = (string) $request->query('ui', $config->defaultUi);
        $specUrl = route('apexdocs.json');
        $html = $this->uiRenderer->render($ui, $specUrl, $config);

        return $this->respond(SpecPayload::html($html));
    }

    public function json(): Response
    {
        return $this->respond(SpecPayload::json($this->apexDocs));
    }

    public function yaml(): Response
    {
        return $this->respond(SpecPayload::yaml($this->apexDocs));
    }

    public function postman(): Response
    {
        return $this->respond(SpecPayload::postman($this->apexDocs));
    }

    public function insomnia(): Response
    {
        return $this->respond(SpecPayload::insomnia($this->apexDocs));
    }

    public function bruno(): Response
    {
        return $this->respond(SpecPayload::bruno($this->apexDocs));
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
