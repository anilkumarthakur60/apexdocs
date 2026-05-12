<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\ApexDocs;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use ApexDocs\Http\UiRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class DocsController extends Controller
{
    public function __construct(
        private ApexDocs $apexDocs,
        private UiRenderer $uiRenderer,
        private JsonExporter $json,
        private YamlExporter $yaml,
        private PostmanExporter $postman,
        private InsomniaExporter $insomnia,
    ) {}

    public function ui(Request $request): Response
    {
        $ui = $request->query('ui', $this->apexDocs->getConfig()->defaultUi);
        $specUrl = route('apexdocs.json');
        $html = $this->uiRenderer->render((string) $ui, $specUrl, $this->apexDocs->getConfig());

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function json(): Response
    {
        $content = $this->json->toString($this->apexDocs->generate());

        return response($content)
            ->header('Content-Type', 'application/json')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'no-store');
    }

    public function yaml(): Response
    {
        $content = $this->yaml->toString($this->apexDocs->generate());

        return response($content)
            ->header('Content-Type', 'application/yaml')
            ->header('Access-Control-Allow-Origin', '*');
    }

    public function postman(): Response
    {
        $content = $this->postman->toString($this->apexDocs->generate());

        return response($content)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'attachment; filename="postman-collection.json"');
    }

    public function insomnia(): Response
    {
        $content = $this->insomnia->toString($this->apexDocs->generate());

        return response($content)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'attachment; filename="insomnia-collection.json"');
    }
}
