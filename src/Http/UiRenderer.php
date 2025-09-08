<?php

declare(strict_types=1);

namespace ApexDocs\Http;

use ApexDocs\Config;

/**
 * Renders the HTML documentation UI as a plain string.
 * No template engine dependency — pure PHP string building.
 *
 * Supported UIs: scalar (default), swagger, redoc, stoplight, rapidoc
 */
final class UiRenderer
{
    public function render(string $ui, string $specUrl, Config $config): string
    {
        $title = htmlspecialchars($config->title, ENT_QUOTES, 'UTF-8');
        $escapedSpec = htmlspecialchars($specUrl, ENT_QUOTES, 'UTF-8');
        $showSwitcher = $config->showUiSwitcher;

        $toolbar = $showSwitcher ? $this->toolbar($specUrl, $ui) : '';
        $content = match ($ui) {
            'swagger' => $this->swagger($escapedSpec),
            'redoc' => $this->redoc($escapedSpec),
            'stoplight' => $this->stoplight($escapedSpec),
            'rapidoc' => $this->rapidoc($escapedSpec),
            default => $this->scalar($escapedSpec),
        };

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>{$title}</title>
            <style>
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#18181b}
                #apex-toolbar{display:flex;align-items:center;gap:12px;padding:8px 16px;background:#18181b;border-bottom:1px solid #27272a;position:sticky;top:0;z-index:1000}
                .apex-brand{font-size:12px;font-weight:700;color:#a1a1aa;letter-spacing:.08em;text-transform:uppercase}
                .apex-spacer{flex:1}
                .apex-ui-btn{padding:3px 10px;border-radius:5px;font-size:12px;font-weight:500;cursor:pointer;text-decoration:none;color:#a1a1aa;background:transparent;border:1px solid transparent;transition:all .15s}
                .apex-ui-btn:hover{color:#f4f4f5;border-color:#3f3f46}
                .apex-ui-btn.active{color:#fff;background:#6366f1;border-color:#6366f1}
                .apex-export a{padding:3px 10px;border-radius:5px;font-size:12px;color:#71717a;text-decoration:none;border:1px solid #3f3f46;transition:all .15s;margin-left:4px}
                .apex-export a:hover{color:#f4f4f5;border-color:#52525b}
                #apex-content{height:calc(100vh - 41px)}
            </style>
        </head>
        <body>
            {$toolbar}
            <div id="apex-content">{$content}</div>
        </body>
        </html>
        HTML;
    }

    private function toolbar(string $specUrl, string $activeUi): string
    {
        $spec = htmlspecialchars($specUrl, ENT_QUOTES, 'UTF-8');
        $yaml = htmlspecialchars(str_replace('spec.json', 'spec.yaml', $specUrl), ENT_QUOTES, 'UTF-8');
        $pm = htmlspecialchars(str_replace('spec.json', 'postman', $specUrl), ENT_QUOTES, 'UTF-8');
        $ins = htmlspecialchars(str_replace('spec.json', 'insomnia', $specUrl), ENT_QUOTES, 'UTF-8');

        $uis = ['scalar', 'swagger', 'redoc', 'stoplight', 'rapidoc'];
        $btns = '';
        foreach ($uis as $ui) {
            $active = $ui === $activeUi ? ' active' : '';
            $label = ucfirst($ui);
            $btns .= "<a href=\"?ui={$ui}\" class=\"apex-ui-btn{$active}\">{$label}</a>";
        }

        return <<<HTML
        <div id="apex-toolbar">
            <span class="apex-brand">⚡ ApexDocs</span>
            <div class="apex-spacer"></div>
            {$btns}
            <div class="apex-export">
                <a href="{$spec}">JSON</a>
                <a href="{$yaml}">YAML</a>
                <a href="{$pm}">Postman</a>
                <a href="{$ins}">Insomnia</a>
            </div>
        </div>
        HTML;
    }

    private function scalar(string $specUrl): string
    {
        return <<<HTML
        <script id="api-reference" data-url="{$specUrl}"
            data-configuration='{"theme":"purple","darkMode":true,"showSidebar":true,"searchHotKey":"k"}'
        ></script>
        <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
        HTML;
    }

    private function swagger(string $specUrl): string
    {
        return <<<HTML
        <div id="swagger-ui" style="height:100%;background:#1a1a2e"></div>
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
        <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
        <script>
        SwaggerUIBundle({
            url:"{$specUrl}",dom_id:'#swagger-ui',deepLinking:true,
            presets:[SwaggerUIBundle.presets.apis,SwaggerUIBundle.SwaggerUIStandalonePreset],
            layout:'BaseLayout',tryItOutEnabled:true,persistAuthorization:true,
            displayRequestDuration:true,filter:true,
        });
        </script>
        HTML;
    }

    private function redoc(string $specUrl): string
    {
        return <<<HTML
        <div id="redoc-container" style="height:100%"></div>
        <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
        <script>
        Redoc.init("{$specUrl}",{
            theme:{colors:{primary:{main:'#6366f1'}},sidebar:{backgroundColor:'#18181b',textColor:'#d4d4d8'},rightPanel:{backgroundColor:'#1e1e2e'}},
            hideDownloadButton:false,disableSearch:false,expandDefaultServerVariables:true,showExtensions:true,
        },document.getElementById('redoc-container'));
        </script>
        HTML;
    }

    private function stoplight(string $specUrl): string
    {
        return <<<HTML
        <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements/styles.min.css">
        <script src="https://unpkg.com/@stoplight/elements/web-components.min.js"></script>
        <elements-api apiDescriptionUrl="{$specUrl}" router="hash" layout="sidebar"
            style="display:block;height:100%"></elements-api>
        HTML;
    }

    private function rapidoc(string $specUrl): string
    {
        return <<<HTML
        <script type="module" src="https://unpkg.com/rapidoc/dist/rapidoc-min.js"></script>
        <rapi-doc spec-url="{$specUrl}" theme="dark" bg-color="#18181b" primary-color="#6366f1"
            render-style="read" show-header="false" allow-authentication="true"
            style="width:100%;height:100%;display:block">
        </rapi-doc>
        HTML;
    }
}
