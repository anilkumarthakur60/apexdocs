<?php

declare(strict_types=1);

namespace ApexDocs\Http;

use ApexDocs\Config;

/**
 * Renders the full API documentation page.
 * Pure PHP — no template engine, no CDN required for the native "apex" mode.
 *
 * Supported UIs: apex (native, built-in), scalar, swagger, redoc, stoplight, rapidoc
 */
final class UiRenderer
{
    private const UIS = ['apex', 'scalar', 'swagger', 'redoc', 'stoplight', 'rapidoc'];

    private const UI_LABELS = [
        'apex'      => 'Apex',
        'scalar'    => 'Scalar',
        'swagger'   => 'Swagger',
        'redoc'     => 'Redoc',
        'stoplight' => 'Stoplight',
        'rapidoc'   => 'RapiDoc',
    ];

    public function render(string $ui, string $specUrl, Config $config): string
    {
        $title    = htmlspecialchars($config->title, ENT_QUOTES, 'UTF-8');
        $version  = htmlspecialchars($config->version, ENT_QUOTES, 'UTF-8');
        $spec     = htmlspecialchars($specUrl, ENT_QUOTES, 'UTF-8');
        $yaml     = htmlspecialchars(str_replace('spec.json', 'spec.yaml', $specUrl), ENT_QUOTES, 'UTF-8');
        $postman  = htmlspecialchars(str_replace('spec.json', 'postman', $specUrl), ENT_QUOTES, 'UTF-8');
        $insomnia = htmlspecialchars(str_replace('spec.json', 'insomnia', $specUrl), ENT_QUOTES, 'UTF-8');
        $bruno    = htmlspecialchars(str_replace('spec.json', 'bruno', $specUrl), ENT_QUOTES, 'UTF-8');
        $specJs   = json_encode($specUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $cfgJs    = json_encode([
            'specUrl'         => $specUrl,
            'theme'           => $config->theme,
            'activeUi'        => $ui,
            'tryItOut'        => $config->tryItOutEnabled,
            'defaultLanguage' => $config->defaultLanguage,
        ], JSON_HEX_TAG);

        $showBar    = $config->showUiSwitcher;
        $barPx      = $showBar ? 56 : 0;
        $bannerHtml = $this->announcementBanner($config);
        $bannerPx   = $config->announcementBanner !== '' ? 40 : 0;
        $totalTopPx = $barPx + $bannerPx;
        $toolbar    = $showBar ? $this->toolbar($ui, $title, $version, $spec, $yaml, $postman, $insomnia, $bruno, $specJs, $config) : '';
        $content    = $this->uiContent($ui, $spec, $specJs, $cfgJs, $config);
        $customCss  = $config->customCss !== '' ? '<style>'.htmlspecialchars($config->customCss, ENT_NOQUOTES, 'UTF-8').'</style>' : '';
        $themeAttr  = $config->theme === 'auto' ? '' : ' data-theme="'.$config->theme.'"';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en"{$themeAttr}>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="color-scheme" content="dark light">
            <title>{$title} — Docs</title>
            <style>{$this->css()}</style>
            {$customCss}
        </head>
        <body>
            <div id="apex-progress"><div id="apex-progress-bar"></div></div>
            {$bannerHtml}
            {$toolbar}
            {$this->commandPalette()}
            <main id="apex-main" style="height:calc(100vh - {$totalTopPx}px)">{$content}</main>
            <div id="apex-toast"><span id="apex-toast-msg"></span></div>
            <script>var APEX_CFG={$cfgJs};</script>
            <script>{$this->js()}</script>
        </body>
        </html>
        HTML;
    }

    // ── Announcement banner ───────────────────────────────────────────────────

    private function announcementBanner(Config $config): string
    {
        if ($config->announcementBanner === '') {
            return '';
        }
        $type = htmlspecialchars($config->announcementBannerType, ENT_QUOTES, 'UTF-8');
        $msg  = $config->announcementBanner; // user-controlled HTML allowed intentionally

        return <<<HTML
        <div id="apex-banner" data-type="{$type}" role="alert">
            <span class="apex-banner-icon">{$this->bannerIcon($config->announcementBannerType)}</span>
            <span class="apex-banner-msg">{$msg}</span>
            <button class="apex-banner-close" onclick="this.parentElement.remove()" aria-label="Dismiss">✕</button>
        </div>
        HTML;
    }

    private function bannerIcon(string $type): string
    {
        return match ($type) {
            'warning' => '⚠',
            'error'   => '✕',
            default   => 'ℹ',
        };
    }

    // ── Toolbar ───────────────────────────────────────────────────────────────

    private function toolbar(
        string $ui,
        string $title,
        string $version,
        string $spec,
        string $yaml,
        string $postman,
        string $insomnia,
        string $bruno,
        string $specJs,
        Config $config,
    ): string {
        $brand   = $this->brandSection($title, $version, $config);
        $tabs    = $this->tabSection($ui);
        $actions = $this->actionsSection($spec, $yaml, $postman, $insomnia, $bruno, $specJs);

        return <<<HTML
        <header id="apex-bar" role="banner">
            {$brand}
            <div class="apex-tabs-wrap">{$tabs}</div>
            {$actions}
        </header>
        HTML;
    }

    private function brandSection(string $title, string $version, Config $config): string
    {
        $logo = $config->customLogo !== ''
            ? '<img src="'.htmlspecialchars($config->customLogo, ENT_QUOTES, 'UTF-8').'" class="apex-custom-logo" alt="Logo">'
            : $this->iconBolt();

        return <<<HTML
        <div class="apex-left">
            <a href="." class="apex-brand" title="ApexDocs">
                {$logo}
                <span class="apex-brand-text">ApexDocs</span>
            </a>
            <span class="apex-vdiv"></span>
            <span class="apex-api-title" title="{$title}">{$title}</span>
            <span class="apex-version">{$version}</span>
        </div>
        HTML;
    }

    private function tabSection(string $activeUi): string
    {
        $tabs = '';
        foreach (self::UIS as $key) {
            $label  = self::UI_LABELS[$key];
            $active = $key === $activeUi ? ' active' : '';
            $tabs  .= "<a href=\"?ui={$key}\" class=\"apex-tab{$active}\" data-ui=\"{$key}\">{$label}</a>";
        }

        return <<<HTML
        <div class="apex-tabs" id="apexTabs">
            <span class="apex-tab-bg" id="apexTabBg"></span>
            {$tabs}
        </div>
        HTML;
    }

    private function actionsSection(
        string $spec,
        string $yaml,
        string $postman,
        string $insomnia,
        string $bruno,
        string $specJs,
    ): string {
        $dlIcon   = $this->iconDownload();
        $copyIcon = $this->iconCopy();
        $chkIcon  = $this->iconCheck();
        $srchIcon = $this->iconSearch();
        $moonIcon = $this->iconMoon();
        $sunIcon  = $this->iconSun();
        $srvIcon  = $this->iconServer();

        return <<<HTML
        <div class="apex-right">
            <button class="apex-icon-btn" id="apex-palette-btn" title="Search endpoints (⌘K)" aria-label="Search">
                {$srchIcon}
                <kbd class="apex-kbd">⌘K</kbd>
            </button>
            <button class="apex-icon-btn apex-theme-btn" id="apexThemeBtn" title="Toggle theme" aria-label="Toggle theme">
                <span class="apex-icon-moon">{$moonIcon}</span>
                <span class="apex-icon-sun">{$sunIcon}</span>
            </button>
            <button class="apex-icon-btn" id="apexEnvBtn" title="Switch server environment" aria-label="Environments">
                {$srvIcon}
            </button>
            <span class="apex-vdiv"></span>
            <button class="apex-icon-btn apex-copy-btn" title="Copy spec URL" aria-label="Copy spec URL"
                    onclick="apexCopy(this)" data-url={$specJs}>
                <span class="apex-icon-copy">{$copyIcon}</span>
                <span class="apex-icon-check">{$chkIcon}</span>
            </button>
            <div class="apex-export-wrap" id="apexExportWrap">
                <button class="apex-export-trigger" id="apexExportBtn" aria-label="Export" title="Export spec">
                    {$dlIcon}
                    <span>Export</span>
                    <span class="apex-chevron">▾</span>
                </button>
                <div class="apex-export-menu" id="apexExportMenu" role="menu">
                    <a href="{$spec}"     class="apex-export-item" download="openapi.json">OpenAPI JSON</a>
                    <a href="{$yaml}"     class="apex-export-item" download="openapi.yaml">OpenAPI YAML</a>
                    <div class="apex-export-divider"></div>
                    <a href="{$postman}"  class="apex-export-item" download="postman-collection.json">Postman v2.1</a>
                    <a href="{$insomnia}" class="apex-export-item" download="insomnia.json">Insomnia</a>
                    <a href="{$bruno}"    class="apex-export-item" download="bruno-collection.json">Bruno</a>
                </div>
            </div>
        </div>
        HTML;
    }

    // ── Command palette ───────────────────────────────────────────────────────

    private function commandPalette(): string
    {
        return <<<'HTML'
        <div id="apex-palette" role="dialog" aria-modal="true" aria-label="Search endpoints" hidden>
            <div id="apex-palette-backdrop" onclick="apexPaletteClose()"></div>
            <div id="apex-palette-box">
                <div id="apex-palette-search-wrap">
                    <svg id="apex-palette-search-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M10 10L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <input id="apex-palette-input" type="search" placeholder="Search endpoints, schemas…" autocomplete="off" spellcheck="false">
                    <kbd class="apex-kbd" onclick="apexPaletteClose()">Esc</kbd>
                </div>
                <div id="apex-palette-results"></div>
                <div id="apex-palette-footer">
                    <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                    <span><kbd>↵</kbd> open</span>
                    <span><kbd>Esc</kbd> close</span>
                </div>
            </div>
        </div>
        HTML;
    }

    // ── UI content ────────────────────────────────────────────────────────────

    private function uiContent(string $ui, string $specUrl, string $specJs, string $cfgJs, Config $config): string
    {
        return match ($ui) {
            'scalar'    => $this->scalar($specUrl),
            'swagger'   => $this->swagger($specUrl),
            'redoc'     => $this->redoc($specUrl),
            'stoplight' => $this->stoplight($specUrl),
            'rapidoc'   => $this->rapidoc($specUrl),
            default     => $this->apexNativeUi($specJs),
        };
    }

    // ── Native Apex UI ────────────────────────────────────────────────────────

    private function apexNativeUi(string $specJs): string
    {
        return <<<HTML
        <div id="axui">
            <aside id="axui-sidebar">
                <div id="axui-sidebar-search">
                    <div class="axui-search-inner">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" class="axui-search-icon">
                            <circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M10 10L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <input id="axui-filter" type="search" placeholder="Filter endpoints…" autocomplete="off">
                        <kbd class="apex-kbd" style="font-size:9px;opacity:.35;pointer-events:none">/</kbd>
                    </div>
                </div>
                <div id="axui-sidebar-body">
                    <div class="axui-loading-state">
                        <div class="axui-spinner"></div>
                        <span>Loading spec…</span>
                    </div>
                </div>
                <div id="axui-sidebar-footer"></div>
            </aside>
            <div id="axui-content">
                <div id="axui-content-inner">
                    <div id="axui-welcome"></div>
                </div>
            </div>
            <aside id="axui-panel">
                <div id="axui-panel-inner"></div>
            </aside>
        </div>
        <div id="axui-env-popover" hidden>
            <div class="axui-env-title">Server Environment</div>
            <div id="axui-env-list"></div>
        </div>
        HTML;
    }

    // ── Third-party UIs ───────────────────────────────────────────────────────

    private function scalar(string $specUrl): string
    {
        return <<<HTML
        <script
            id="api-reference"
            data-url="{$specUrl}"
            data-configuration='{"theme":"purple","darkMode":true,"layout":"modern","showSidebar":true,"searchHotKey":"k","hideModels":false,"hideDownloadButton":false,"defaultHttpClient":{"targetKey":"javascript","clientKey":"fetch"}}'
        ></script>
        <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
        HTML;
    }

    private function swagger(string $specUrl): string
    {
        return <<<HTML
        <div id="swagger-ui" style="height:100%;overflow:auto"></div>
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
        <style>
            .swagger-ui{font-family:inherit!important}.swagger-ui .topbar{display:none}
            body .swagger-ui,.swagger-ui .wrapper{background:#0a0a0c}
            .swagger-ui .info .title,.swagger-ui .opblock .opblock-summary-path{color:#f4f4f5}
            .swagger-ui .scheme-container{background:#111116;box-shadow:none;border:1px solid rgba(255,255,255,.06)}
            .swagger-ui select,.swagger-ui input{background:#1a1a22;color:#f4f4f5;border-color:rgba(255,255,255,.1)}
            .swagger-ui .model-box,.swagger-ui section.models{background:#111116}
        </style>
        <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
        <script>
        SwaggerUIBundle({url:"{$specUrl}",dom_id:'#swagger-ui',deepLinking:true,
            presets:[SwaggerUIBundle.presets.apis,SwaggerUIBundle.SwaggerUIStandalonePreset],
            layout:'BaseLayout',tryItOutEnabled:true,persistAuthorization:true,
            displayRequestDuration:true,filter:true,syntaxHighlight:{theme:'agate'},
            defaultModelsExpandDepth:1,defaultModelExpandDepth:3});
        </script>
        HTML;
    }

    private function redoc(string $specUrl): string
    {
        $theme = json_encode([
            'colors' => ['primary' => ['main' => '#6366f1'],'text' => ['primary' => '#f4f4f5','secondary' => '#a1a1aa']],
            'typography' => ['fontSize' => '14px','fontFamily' => "-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif",'code' => ['fontSize' => '13px','fontFamily' => "'JetBrains Mono',monospace",'backgroundColor' => 'rgba(255,255,255,0.05)']],
            'sidebar' => ['backgroundColor' => '#0c0c10','textColor' => '#a1a1aa','activeTextColor' => '#f4f4f5','arrow' => ['size' => '1.4em','color' => '#52525b']],
            'rightPanel' => ['backgroundColor' => '#111116'],
            'codeBlock' => ['backgroundColor' => '#0f0f14'],
            'shape' => ['borderRadius' => '6px'],
        ]);

        return <<<HTML
        <div id="redoc-container" style="height:100%;overflow:auto"></div>
        <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
        <script>
        Redoc.init("{$specUrl}",{theme:{$theme},hideDownloadButton:false,disableSearch:false,
            expandDefaultServerVariables:true,showExtensions:true},
            document.getElementById('redoc-container'));
        </script>
        HTML;
    }

    private function stoplight(string $specUrl): string
    {
        return <<<HTML
        <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements/styles.min.css">
        <script src="https://unpkg.com/@stoplight/elements/web-components.min.js"></script>
        <style>elements-api{--color-primary:#6366f1;--color-canvas-100:#0a0a0c}</style>
        <elements-api apiDescriptionUrl="{$specUrl}" router="hash" layout="sidebar"
            style="display:block;height:100%;overflow:auto"></elements-api>
        HTML;
    }

    private function rapidoc(string $specUrl): string
    {
        return <<<HTML
        <script type="module" src="https://unpkg.com/rapidoc/dist/rapidoc-min.js"></script>
        <rapi-doc spec-url="{$specUrl}" theme="dark" bg-color="#0a0a0c" text-color="#f4f4f5"
            primary-color="#6366f1" nav-bg-color="#0c0c10" nav-text-color="#a1a1aa"
            nav-hover-bg-color="#1a1a22" nav-hover-text-color="#f4f4f5" nav-accent-color="#6366f1"
            header-color="#0c0c10" render-style="read" show-header="false" show-info="true"
            show-components="true" allow-authentication="true" allow-server-selection="true"
            default-schema-tab="schema" font-size="default"
            style="width:100%;height:100%;display:block"></rapi-doc>
        HTML;
    }

    // ── CSS ───────────────────────────────────────────────────────────────────

    private function css(): string
    {
        return <<<'CSS'
        /* ── Reset & variables ── */
        *{box-sizing:border-box;margin:0;padding:0}
        :root{
            --bg:#0a0a0c;--bar-bg:rgba(10,10,14,0.93);--border:rgba(255,255,255,0.07);
            --border-s:rgba(255,255,255,0.12);--accent:#6366f1;--accent2:#a855f7;
            --t1:#f4f4f5;--t2:#a1a1aa;--t3:#52525b;
            --s1:rgba(255,255,255,0.04);--s2:rgba(255,255,255,0.07);--s3:rgba(255,255,255,0.11);
            --green:#4ade80;--blue:#60a5fa;--amber:#fbbf24;--red:#f87171;--purple:#a78bfa;
            --r:8px;--bar-h:56px;
            --m-get:rgba(59,130,246,.14);--m-get-c:#60a5fa;
            --m-post:rgba(34,197,94,.13);--m-post-c:#4ade80;
            --m-put:rgba(245,158,11,.13);--m-put-c:#fbbf24;
            --m-patch:rgba(139,92,246,.13);--m-patch-c:#a78bfa;
            --m-delete:rgba(239,68,68,.13);--m-delete-c:#f87171;
            --m-head:rgba(113,113,122,.1);--m-head-c:#a1a1aa;
        }
        [data-theme="light"]{
            --bg:#f8f8fc;--bar-bg:rgba(248,248,252,0.93);--border:rgba(0,0,0,0.08);
            --border-s:rgba(0,0,0,0.14);--t1:#0f0f14;--t2:#52525b;--t3:#a1a1aa;
            --s1:rgba(0,0,0,0.04);--s2:rgba(0,0,0,0.07);--s3:rgba(0,0,0,0.1);
        }
        @media(prefers-color-scheme:light){
            :root:not([data-theme]){
                --bg:#f8f8fc;--bar-bg:rgba(248,248,252,0.93);--border:rgba(0,0,0,0.08);
                --border-s:rgba(0,0,0,0.14);--t1:#0f0f14;--t2:#52525b;--t3:#a1a1aa;
                --s1:rgba(0,0,0,0.04);--s2:rgba(0,0,0,0.07);--s3:rgba(0,0,0,0.1);
            }
        }
        html,body{height:100%;overflow:hidden;background:var(--bg)}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,'Inter',sans-serif;-webkit-font-smoothing:antialiased;color:var(--t1)}

        /* ── Loading bar ── */
        #apex-progress{position:fixed;top:0;left:0;right:0;height:2px;z-index:9999;overflow:hidden}
        #apex-progress-bar{height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6,#a855f7,#ec4899,#f59e0b,#6366f1);background-size:300% 100%;animation:apex-shimmer 1.8s linear infinite}
        @keyframes apex-shimmer{0%{background-position:100% 0}100%{background-position:-200% 0}}

        /* ── Announcement banner ── */
        #apex-banner{
            display:flex;align-items:center;gap:10px;padding:0 20px;height:40px;
            font-size:13px;font-weight:500;position:sticky;top:0;z-index:999;
        }
        #apex-banner[data-type="info"]{background:rgba(99,102,241,.15);border-bottom:1px solid rgba(99,102,241,.3);color:#a5b4fc}
        #apex-banner[data-type="warning"]{background:rgba(245,158,11,.15);border-bottom:1px solid rgba(245,158,11,.3);color:#fbbf24}
        #apex-banner[data-type="error"]{background:rgba(239,68,68,.15);border-bottom:1px solid rgba(239,68,68,.3);color:#f87171}
        .apex-banner-msg{flex:1}
        .apex-banner-close{background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:14px;padding:4px;border-radius:4px;transition:opacity .15s}.apex-banner-close:hover{opacity:1}

        /* ── Toolbar ── */
        #apex-bar{
            position:sticky;top:0;left:0;right:0;z-index:1000;height:var(--bar-h);
            display:flex;align-items:center;gap:0;padding:0 16px;
            background:var(--bar-bg);backdrop-filter:blur(24px) saturate(1.8);
            -webkit-backdrop-filter:blur(24px) saturate(1.8);border-bottom:1px solid var(--border)
        }
        #apex-bar::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:1px;
            background:linear-gradient(90deg,transparent,rgba(99,102,241,.5) 25%,rgba(168,85,247,.5) 75%,transparent);
            pointer-events:none}

        /* Brand */
        .apex-left{display:flex;align-items:center;gap:9px;flex-shrink:0;min-width:0}
        .apex-brand{display:flex;align-items:center;gap:7px;text-decoration:none;flex-shrink:0;padding:4px 6px;border-radius:var(--r);transition:background .15s}
        .apex-brand:hover{background:var(--s1)}
        .apex-brand-text{font-size:13.5px;font-weight:700;letter-spacing:-.025em;background:linear-gradient(135deg,#a5b4fc,#c4b5fd,#f0abfc);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;white-space:nowrap}
        .apex-custom-logo{height:22px;width:auto;border-radius:4px}
        .apex-vdiv{width:1px;height:18px;background:var(--border);flex-shrink:0;margin:0 2px}
        .apex-api-title{font-size:13px;font-weight:500;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:170px}
        .apex-version{font-size:10.5px;font-weight:600;letter-spacing:.02em;padding:2px 8px;border-radius:999px;background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.28);color:#a5b4fc;white-space:nowrap;flex-shrink:0}

        /* Tabs */
        .apex-tabs-wrap{flex:1;display:flex;justify-content:center;align-items:center;padding:0 10px;min-width:0}
        .apex-tabs{position:relative;display:inline-flex;align-items:center;gap:1px;background:var(--s1);border:1px solid var(--border);border-radius:10px;padding:4px}
        .apex-tab-bg{position:absolute;border-radius:7px;background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 0 18px rgba(99,102,241,.4),inset 0 1px 0 rgba(255,255,255,.12);transition:left .22s cubic-bezier(.34,1.4,.64,1),width .22s cubic-bezier(.34,1.4,.64,1);pointer-events:none;top:4px;bottom:4px;opacity:0}
        .apex-tabs:has(.apex-tab.active) .apex-tab-bg{opacity:1}
        .apex-tab{position:relative;z-index:1;padding:5px 13px;border-radius:7px;font-size:12px;font-weight:500;color:var(--t2);text-decoration:none;transition:color .15s;white-space:nowrap;user-select:none}
        .apex-tab.active{color:#fff}.apex-tab:not(.active):hover{color:var(--t1)}

        /* Right actions */
        .apex-right{display:flex;align-items:center;gap:4px;flex-shrink:0}
        .apex-icon-btn{display:flex;align-items:center;justify-content:center;gap:4px;width:32px;height:32px;border-radius:var(--r);background:transparent;border:1px solid transparent;color:var(--t2);cursor:pointer;transition:all .15s;flex-shrink:0}
        .apex-icon-btn:hover{color:var(--t1);background:var(--s1);border-color:var(--border)}
        .apex-icon-btn .apex-icon-check{display:none;color:var(--green)}
        .apex-icon-btn.copied .apex-icon-copy{display:none}
        .apex-icon-btn.copied .apex-icon-check{display:flex}
        .apex-kbd{font-size:10px;font-family:inherit;padding:1px 4px;border-radius:4px;background:var(--s2);border:1px solid var(--border);color:var(--t3);pointer-events:none;line-height:1.4}
        #apex-palette-btn{width:auto;padding:0 8px;gap:6px}
        .apex-theme-btn .apex-icon-sun{display:none}
        [data-theme="light"] .apex-theme-btn .apex-icon-moon{display:none}
        [data-theme="light"] .apex-theme-btn .apex-icon-sun{display:flex}

        /* Export dropdown */
        .apex-export-wrap{position:relative}
        .apex-export-trigger{display:flex;align-items:center;gap:6px;padding:5px 10px;border-radius:var(--r);background:var(--s1);border:1px solid var(--border);color:var(--t2);font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;white-space:nowrap}
        .apex-export-trigger:hover{color:var(--t1);background:var(--s2);border-color:var(--border-s)}
        .apex-chevron{font-size:10px;transition:transform .15s}
        .apex-export-wrap.open .apex-chevron{transform:rotate(180deg)}
        .apex-export-menu{position:absolute;right:0;top:calc(100% + 6px);min-width:160px;background:var(--bar-bg);border:1px solid var(--border-s);border-radius:10px;padding:4px;box-shadow:0 16px 48px rgba(0,0,0,.35);backdrop-filter:blur(16px);opacity:0;transform:translateY(-6px) scale(.97);pointer-events:none;transition:opacity .15s,transform .15s;z-index:1100}
        .apex-export-wrap.open .apex-export-menu{opacity:1;transform:none;pointer-events:all}
        .apex-export-item{display:block;padding:7px 12px;border-radius:6px;font-size:12.5px;color:var(--t2);text-decoration:none;transition:all .12s}
        .apex-export-item:hover{color:var(--t1);background:var(--s2)}
        .apex-export-divider{height:1px;background:var(--border);margin:4px 0}

        /* ── Command Palette ── */
        #apex-palette{position:fixed;inset:0;z-index:9000;display:flex;align-items:flex-start;justify-content:center;padding-top:80px}
        #apex-palette[hidden]{display:none}
        #apex-palette-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px)}
        #apex-palette-box{position:relative;width:100%;max-width:580px;background:var(--bar-bg);border:1px solid var(--border-s);border-radius:14px;box-shadow:0 24px 64px rgba(0,0,0,.5);overflow:hidden;backdrop-filter:blur(20px)}
        #apex-palette-search-wrap{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
        #apex-palette-search-icon{color:var(--t3);flex-shrink:0}
        #apex-palette-input{flex:1;background:none;border:none;outline:none;font-size:15px;color:var(--t1);caret-color:var(--accent)}
        #apex-palette-input::placeholder{color:var(--t3)}
        #apex-palette-results{max-height:380px;overflow-y:auto;padding:6px}
        .apex-pal-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;cursor:pointer;text-decoration:none;transition:background .1s}
        .apex-pal-item:hover,.apex-pal-item.focused{background:var(--s2)}
        .apex-pal-item .apex-pal-path{font-size:13px;color:var(--t1);font-family:monospace;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .apex-pal-item .apex-pal-sum{font-size:12px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
        .apex-pal-item .apex-pal-tag{font-size:11px;color:var(--t3);flex-shrink:0}
        .apex-pal-group{padding:6px 10px 4px;font-size:11px;font-weight:600;color:var(--t3);letter-spacing:.05em;text-transform:uppercase}
        .apex-pal-empty{padding:32px;text-align:center;color:var(--t3);font-size:14px}
        #apex-palette-footer{display:flex;gap:16px;padding:10px 16px;border-top:1px solid var(--border);font-size:11px;color:var(--t3)}
        #apex-palette-footer kbd{padding:1px 5px;border-radius:4px;background:var(--s2);border:1px solid var(--border);font-family:inherit;margin-right:4px}

        /* ── Method badges ── */
        .axm{display:inline-flex;align-items:center;justify-content:center;padding:2px 7px;border-radius:5px;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;flex-shrink:0;min-width:52px}
        .axm-get{background:var(--m-get);color:var(--m-get-c);border:1px solid rgba(59,130,246,.25)}
        .axm-post{background:var(--m-post);color:var(--m-post-c);border:1px solid rgba(34,197,94,.25)}
        .axm-put{background:var(--m-put);color:var(--m-put-c);border:1px solid rgba(245,158,11,.25)}
        .axm-patch{background:var(--m-patch);color:var(--m-patch-c);border:1px solid rgba(139,92,246,.25)}
        .axm-delete{background:var(--m-delete);color:var(--m-delete-c);border:1px solid rgba(239,68,68,.25)}
        .axm-head,.axm-options{background:var(--m-head);color:var(--m-head-c);border:1px solid rgba(113,113,122,.2)}

        /* ── Native Apex UI layout ── */
        #axui{display:flex;height:100%;overflow:hidden;background:var(--bg)}
        #axui-sidebar{width:264px;flex-shrink:0;display:flex;flex-direction:column;border-right:1px solid var(--border);overflow:hidden;background:var(--bg)}
        #axui-sidebar-search{padding:10px;border-bottom:1px solid var(--border);flex-shrink:0}
        .axui-search-inner{display:flex;align-items:center;gap:8px;background:var(--s1);border:1px solid var(--border);border-radius:var(--r);padding:7px 10px;transition:border-color .15s}
        .axui-search-inner:focus-within{border-color:rgba(99,102,241,.5)}
        .axui-search-icon{color:var(--t3);flex-shrink:0}
        #axui-filter{flex:1;background:none;border:none;outline:none;font-size:13px;color:var(--t1);caret-color:var(--accent)}
        #axui-filter::placeholder{color:var(--t3)}
        #axui-sidebar-body{flex:1;overflow-y:auto;padding:8px 0}
        #axui-sidebar-body::-webkit-scrollbar{width:4px}
        #axui-sidebar-body::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px}

        /* Sidebar groups & items */
        .axg{margin-bottom:2px}
        .axg-count{font-size:10px;padding:1px 6px;border-radius:999px;background:var(--s2);color:var(--t3)}
        .axg-items{display:none}.axg.open .axg-items{display:block}
        .axi{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 20px;cursor:pointer;border-radius:0;transition:background .12s;position:relative}
        .axi:hover{background:var(--s1)}
        .axi.active{background:rgba(99,102,241,.1)}
        .axi.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:2px;background:var(--accent)}
        .axi-path{font-size:12px;color:var(--t2);font-family:'JetBrains Mono',monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;transition:color .12s}
        .axi:hover .axi-path,.axi.active .axi-path{color:var(--t1)}
        .axi-depr{opacity:.5;text-decoration:line-through}

        /* Sidebar overview section */
        .axs-overview{padding:12px 14px;border-bottom:1px solid var(--border);margin-bottom:4px}
        .axs-api-title{font-size:13px;font-weight:600;color:var(--t1);margin-bottom:4px}
        .axs-api-desc{font-size:12px;color:var(--t2);line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}

        /* Main content */
        #axui-content{flex:1;overflow-y:auto;min-width:0;background:var(--bg)}
        #axui-content::-webkit-scrollbar{width:4px}
        #axui-content::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px}
        #axui-content-inner{max-width:780px;margin:0 auto;padding:28px 32px}

        /* Welcome */
        #axui-welcome{padding:24px 0}
        .axw-title{font-size:26px;font-weight:700;letter-spacing:-.025em;color:var(--t1);margin-bottom:6px}
        .axw-meta{display:flex;align-items:center;gap:10px;margin-bottom:16px}
        .axw-version{font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.28);color:#a5b4fc}
        .axw-openapi{font-size:11px;color:var(--t3)}
        .axw-desc{font-size:14px;color:var(--t2);line-height:1.7;margin-bottom:24px;max-width:580px}
        .axw-stats{display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap}
        .axw-stat{display:flex;align-items:center;gap:8px;padding:12px 16px;background:var(--s1);border:1px solid var(--border);border-radius:10px;min-width:120px}
        .axw-stat-n{font-size:22px;font-weight:700;color:var(--t1);letter-spacing:-.02em}
        .axw-stat-l{font-size:11.5px;color:var(--t3);margin-top:1px}
        .axw-servers{margin-top:20px}
        .axw-servers-title{font-size:12px;font-weight:600;color:var(--t3);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px}
        .axw-server{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:var(--r);background:var(--s1);border:1px solid var(--border);margin-bottom:6px;font-size:13px;color:var(--t2);font-family:monospace}
        .axw-server-dot{width:6px;height:6px;border-radius:999px;background:var(--green);flex-shrink:0}
        .axw-hint{margin-top:24px;padding:16px;border-radius:var(--r);background:rgba(99,102,241,.07);border:1px solid rgba(99,102,241,.2);font-size:13px;color:var(--t2);text-align:center}

        /* Operation detail */
        .ax-op-header{display:flex;align-items:flex-start;gap:12px;margin-bottom:20px}
        .ax-op-title-wrap{flex:1;min-width:0}
        .ax-op-path{font-size:18px;font-weight:600;font-family:'JetBrains Mono',monospace;color:var(--t1);letter-spacing:-.01em;word-break:break-all}
        .ax-op-summary{font-size:14px;color:var(--t2);margin-top:4px;line-height:1.5}
        .ax-depr-badge{display:inline-flex;align-items:center;font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:999px;background:rgba(245,158,11,.12);color:var(--amber);border:1px solid rgba(245,158,11,.3);margin-left:8px;vertical-align:middle}
        .ax-op-desc{font-size:14px;color:var(--t2);line-height:1.7;margin-bottom:20px;padding:14px 16px;background:var(--s1);border-left:2px solid var(--accent);border-radius:0 var(--r) var(--r) 0}
        .ax-section{margin-bottom:24px}
        .ax-section-title{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:10px;display:flex;align-items:center;gap:6px}
        .ax-section-title::after{content:'';flex:1;height:1px;background:var(--border)}

        /* Parameters */
        .ax-params{width:100%;border-collapse:collapse;font-size:13px}
        .ax-params th{text-align:left;padding:6px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);border-bottom:1px solid var(--border)}
        .ax-params td{padding:8px 12px;vertical-align:top;border-bottom:1px solid var(--border);color:var(--t2)}
        .ax-params tr:last-child td{border-bottom:none}
        .ax-param-name{color:var(--t1);font-family:monospace;font-size:12.5px}
        .ax-req-badge{display:inline-flex;font-size:10px;font-weight:600;padding:1px 5px;border-radius:4px;background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
        .ax-in-badge{display:inline-flex;font-size:10px;font-weight:500;padding:1px 6px;border-radius:4px;background:var(--s2);color:var(--t3)}
        .ax-type-badge{display:inline-flex;font-size:11px;padding:1px 6px;border-radius:4px;font-family:monospace;color:#93c5fd;background:rgba(59,130,246,.1)}

        /* Schema tree */
        .ax-schema{font-size:13px}
        .ax-schema-obj{border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
        .ax-prop-row{display:flex;align-items:baseline;gap:8px;padding:7px 12px;border-bottom:1px solid var(--border);transition:background .1s}
        .ax-prop-row:last-child{border-bottom:none}
        .ax-prop-row:hover{background:var(--s1)}
        .ax-prop-name{font-family:'JetBrains Mono',monospace;font-size:12.5px;color:var(--t1);flex-shrink:0;min-width:120px}
        .ax-prop-type{flex-shrink:0}
        .ax-prop-req{flex-shrink:0}
        .ax-prop-desc{color:var(--t3);font-size:12px;flex:1;margin-left:4px}
        .ax-prop-nested{padding:0 0 0 16px;border-top:1px solid var(--border);background:var(--s1)}
        .ax-enum-wrap{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}
        .ax-enum-val{font-size:11px;font-family:monospace;padding:1px 6px;border-radius:4px;background:var(--s2);color:var(--t2)}
        .ax-allof-label{font-size:11px;color:var(--t3);padding:4px 12px;background:var(--s1);border-bottom:1px solid var(--border)}

        /* Responses */
        .ax-resp{border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:8px}
        .ax-resp-header{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;transition:background .12s;user-select:none}
        .ax-resp-header:hover{background:var(--s1)}
        .ax-resp-status{font-size:13px;font-weight:700;font-family:monospace;flex-shrink:0}
        .axs-2xx{color:var(--green)}.axs-3xx{color:var(--blue)}.axs-4xx{color:var(--amber)}.axs-5xx{color:var(--red)}
        .ax-resp-desc{font-size:13px;color:var(--t2);flex:1}
        .ax-resp-arrow{color:var(--t3);font-size:10px;transition:transform .2s}
        .ax-resp.open .ax-resp-arrow{transform:rotate(90deg)}
        .ax-resp-body{border-top:1px solid var(--border);padding:14px}
        .ax-resp-body:empty{display:none}

        /* Code / JSON */
        pre.ax-code{background:var(--s1);border:1px solid var(--border);border-radius:var(--r);padding:14px;overflow-x:auto;font-family:'JetBrains Mono',monospace;font-size:12.5px;line-height:1.6;color:var(--t2)}
        .ax-k{color:#7dd3fc}.ax-s{color:#86efac}.ax-n{color:#fca5a5}.ax-b{color:#a5b4fc}.ax-null{color:var(--t3)}.ax-p{color:var(--t3)}

        /* Right panel */
        #axui-panel{width:360px;flex-shrink:0;border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;background:var(--bg)}
        #axui-panel-inner{flex:1;overflow-y:auto;padding:16px}
        #axui-panel-inner::-webkit-scrollbar{width:4px}
        #axui-panel-inner::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px}

        /* Code sample tabs */
        .ax-lang-tabs{display:flex;gap:2px;margin-bottom:12px;flex-wrap:wrap}
        .ax-lang-btn{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:500;cursor:pointer;background:transparent;border:1px solid var(--border);color:var(--t3);transition:all .12s}
        .ax-lang-btn.active,.ax-lang-btn:hover{color:var(--t1);background:var(--s2);border-color:var(--border-s)}
        .ax-lang-btn.active{color:var(--accent);border-color:rgba(99,102,241,.4)}

        /* Try-it-out form */
        .ax-try-section{margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
        .ax-try-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:6px;display:block}
        .ax-try-input{width:100%;padding:7px 10px;background:var(--s1);border:1px solid var(--border);border-radius:var(--r);color:var(--t1);font-size:13px;outline:none;transition:border-color .15s;font-family:inherit}
        .ax-try-input:focus{border-color:rgba(99,102,241,.5)}
        .ax-try-input::placeholder{color:var(--t3)}
        .ax-try-textarea{font-family:'JetBrains Mono',monospace;font-size:12px;resize:vertical;min-height:100px}
        .ax-try-send{width:100%;padding:9px;border-radius:var(--r);background:var(--accent);border:none;color:#fff;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;margin-top:12px}
        .ax-try-send:hover{background:#4f46e5;transform:translateY(-1px);box-shadow:0 4px 16px rgba(99,102,241,.35)}
        .ax-try-send:active{transform:none;box-shadow:none}
        .ax-try-send:disabled{opacity:.5;cursor:not-allowed;transform:none}

        /* Response viewer */
        .ax-res-panel{margin-top:14px;border-radius:var(--r);overflow:hidden;border:1px solid var(--border)}
        .ax-res-status-bar{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--s1);font-size:12.5px;font-weight:600;border-bottom:1px solid var(--border)}
        .ax-res-s-ok{color:var(--green)}.ax-res-s-warn{color:var(--amber)}.ax-res-s-err{color:var(--red)}.ax-res-s-info{color:var(--blue)}
        .ax-res-ms{font-size:11px;color:var(--t3);font-weight:400;margin-left:auto}
        .ax-res-body{padding:10px;overflow-x:auto}
        pre.ax-res-pre{font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.5;color:var(--t2);white-space:pre-wrap;word-break:break-all}

        /* Loading state */
        .axui-loading-state{display:flex;align-items:center;gap:10px;padding:20px 16px;color:var(--t3);font-size:13px}
        .axui-spinner{width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:axspin .6s linear infinite;flex-shrink:0}
        @keyframes axspin{to{transform:rotate(360deg)}}
        .ax-empty{padding:32px 16px;text-align:center;color:var(--t3);font-size:13px}

        /* Environment popover */
        #axui-env-popover{position:absolute;top:calc(var(--bar-h) + 4px);right:80px;background:var(--bar-bg);border:1px solid var(--border-s);border-radius:10px;padding:8px;min-width:220px;box-shadow:0 16px 48px rgba(0,0,0,.35);backdrop-filter:blur(16px);z-index:1200}
        .axui-env-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);padding:4px 8px 8px}
        .axui-env-item{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:6px;font-size:13px;color:var(--t2);cursor:pointer;transition:background .12s}
        .axui-env-item:hover{background:var(--s2);color:var(--t1)}
        .axui-env-item.active{color:var(--accent)}
        .axui-env-dot{width:7px;height:7px;border-radius:999px;background:var(--border);flex-shrink:0}
        .axui-env-item.active .axui-env-dot{background:var(--accent)}

        /* Toast */
        #apex-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(8px);background:#1e1e2a;border:1px solid rgba(99,102,241,.3);color:var(--t1);font-size:13px;font-weight:500;padding:8px 18px;border-radius:999px;box-shadow:0 8px 32px rgba(0,0,0,.4);opacity:0;transition:opacity .2s,transform .2s;pointer-events:none;white-space:nowrap;z-index:9000}
        #apex-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

        /* ── Sidebar group name ── */
        .axg-header{display:flex;align-items:center;padding:6px 12px 6px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);cursor:pointer;user-select:none;transition:color .15s;gap:5px}
        .axg-header:hover{color:var(--t2)}
        .axg-name{flex:1}
        .axg-arrow{font-size:9px;transition:transform .2s;flex-shrink:0}
        .axg.open .axg-arrow{transform:rotate(90deg)}

        /* ── Sidebar footer ── */
        #axui-sidebar-footer{border-top:1px solid var(--border);padding:10px 14px;flex-shrink:0}
        .axf-item{font-size:11px;color:var(--t3);margin-bottom:3px;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .axf-link{color:var(--t3);text-decoration:none;transition:color .15s}.axf-link:hover{color:var(--accent)}

        /* ── Deprecated dot in sidebar ── */
        .axi-depr-dot{font-size:9px;font-weight:700;padding:0 3px;border-radius:3px;background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.25);flex-shrink:0;line-height:1.4}

        /* ── Webhook badge ── */
        .ax-webhook-badge{font-size:9px;font-weight:700;padding:1px 4px;border-radius:3px;background:rgba(168,85,247,.1);color:var(--purple);border:1px solid rgba(168,85,247,.25);flex-shrink:0;letter-spacing:.02em}

        /* ── Breadcrumb ── */
        .ax-breadcrumb{display:flex;align-items:center;gap:5px;padding:0 0 16px;font-size:12px;flex-wrap:wrap}
        .ax-breadcrumb-item{color:var(--t3)}
        .ax-breadcrumb-link{color:var(--t2);cursor:pointer;transition:color .15s}.ax-breadcrumb-link:hover{color:var(--accent)}
        .ax-breadcrumb-sep{color:var(--t3);opacity:.5}
        .ax-breadcrumb-current{font-family:'JetBrains Mono',monospace;font-size:11px;padding:1px 5px;border-radius:4px;background:var(--s1);border:1px solid var(--border)}
        .ax-breadcrumb-path{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--t2)}

        /* ── Security badges ── */
        .ax-sec-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
        .ax-sec-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:5px;font-size:11.5px;font-weight:500;background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.2);color:var(--amber)}
        .ax-sec-open{background:rgba(74,222,128,.07);border-color:rgba(74,222,128,.2);color:var(--green)}
        .ax-sec-scopes{font-size:10.5px;opacity:.7;margin-left:2px}

        /* ── Permalink button ── */
        .ax-permalink-btn{display:inline-flex;align-items:center;padding:2px;border-radius:4px;background:none;border:none;color:var(--t3);cursor:pointer;opacity:0;transition:opacity .15s,color .15s;margin-left:6px;vertical-align:middle}
        .ax-op-path:hover .ax-permalink-btn,.ax-op-header:hover .ax-permalink-btn{opacity:1}
        .ax-permalink-btn:hover{color:var(--accent)}

        /* ── Ext docs link ── */
        .ax-ext-docs-link{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--accent);text-decoration:none;margin-bottom:16px;padding:4px 8px;border-radius:5px;background:rgba(99,102,241,.07);border:1px solid rgba(99,102,241,.15);transition:background .15s}
        .ax-ext-docs-link:hover{background:rgba(99,102,241,.14)}

        /* ── Schema collapse button ── */
        .ax-schema-collapse-btn{cursor:pointer;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;border-radius:3px;background:var(--s2);color:var(--t3);font-size:8px;flex-shrink:0;border:none;transition:all .12s;vertical-align:middle}
        .ax-schema-collapse-btn:hover{background:var(--s3);color:var(--t1)}

        /* ── $ref expanded/collapsible ── */
        .ax-ref-wrap{display:flex;flex-direction:column;gap:4px}
        .ax-ref-badge{cursor:default}
        .ax-ref-expanded{margin-top:4px}

        /* ── oneOf/anyOf ── */
        .ax-oneof-wrap{display:flex;flex-direction:column;gap:4px}
        .ax-oneof-label{font-size:11px;color:var(--t3);font-weight:600;margin-bottom:2px}
        .ax-oneof-item{border-left:2px solid var(--border);padding-left:10px}
        .ax-oneof-sep{margin-top:4px;padding-top:4px}

        /* ── Schema description ── */
        .ax-schema-desc{font-size:12px;color:var(--t3);padding:8px 12px;border-bottom:1px solid var(--border);font-style:italic}

        /* ── Response content-type tabs ── */
        .ax-resp-ct-tabs{display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap}
        .ax-resp-ct-btn{padding:2px 8px;border-radius:4px;font-size:11px;cursor:pointer;background:var(--s1);border:1px solid var(--border);color:var(--t3);transition:all .1s;font-family:inherit}
        .ax-resp-ct-btn.active{color:var(--t1);background:var(--s2);border-color:var(--border-s)}

        /* ── Panel section title ── */
        .ax-panel-section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:10px;margin-top:20px;display:flex;align-items:center;gap:6px}
        .ax-panel-section-title::after{content:'';flex:1;height:1px;background:var(--border)}

        /* ── Code copy button ── */
        .ax-code-copy-btn{margin-top:6px;padding:4px 10px;border-radius:6px;background:var(--s1);border:1px solid var(--border);color:var(--t2);font-size:11px;cursor:pointer;transition:all .12s;font-family:inherit}
        .ax-code-copy-btn:hover{background:var(--s2);color:var(--t1);border-color:var(--border-s)}

        /* ── Try-it-out auth type selector ── */
        .ax-try-auth-wrap{display:flex;gap:6px;align-items:stretch;margin-bottom:8px}
        .ax-try-auth-type{flex:0 0 auto;padding:7px 8px;background:var(--s1);border:1px solid var(--border);border-radius:var(--r);color:var(--t2);font-size:12px;outline:none;cursor:pointer;font-family:inherit;-webkit-appearance:none;appearance:none}
        .ax-try-auth-type:focus{border-color:rgba(99,102,241,.5)}

        /* ── Response headers accordion ── */
        .ax-res-headers{border-bottom:1px solid var(--border)}
        .ax-res-headers summary{list-style:none;cursor:pointer;outline:none;transition:background .12s}
        .ax-res-headers summary:hover{background:var(--s1)}
        .ax-res-headers[open] summary{background:var(--s1)}

        /* ── Error state ── */
        .ax-error-state{padding:28px 16px;text-align:center}
        .ax-error-icon{font-size:28px;margin-bottom:8px;opacity:.6}
        .ax-error-title{font-size:14px;font-weight:600;color:var(--red);margin-bottom:4px}
        .ax-error-msg{font-size:12px;color:var(--t3)}

        /* ── Welcome screen improvements ── */
        .axw-stat-icon{color:var(--t3);flex-shrink:0;margin-right:2px}
        .axw-server-dot.active{background:var(--green)}
        .axw-contact-block{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px;margin-bottom:16px}
        .axw-meta-item{font-size:12px;color:var(--t3)}
        .axw-stats{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
        .axw-stat{display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--s1);border:1px solid var(--border);border-radius:10px;flex:1;min-width:100px;transition:border-color .15s}
        .axw-stat:hover{border-color:var(--border-s)}

        /* ── Responsive ── */
        @media(max-width:1100px){#axui-panel{display:none}}
        @media(max-width:820px){#axui-sidebar{width:220px}}
        @media(max-width:680px){#axui-sidebar{display:none}.apex-tabs-wrap{display:none}}
        @media(max-width:960px){.apex-api-title,.apex-version{display:none}.apex-export-trigger span:not(.apex-chevron){display:none}}
        CSS;
    }

    // ── JavaScript ────────────────────────────────────────────────────────────

    private function js(): string
    {
        return <<<'JS'
        (function(){
        'use strict';

        /* ── Tab slider ── */
        var bg=document.getElementById('apexTabBg'),tabsEl=document.getElementById('apexTabs');
        if(bg&&tabsEl){
            function moveBg(el){bg.style.left=el.offsetLeft+'px';bg.style.width=el.offsetWidth+'px';}
            var at=tabsEl.querySelector('.apex-tab.active');
            if(at){bg.style.transition='none';moveBg(at);requestAnimationFrame(function(){bg.style.transition=''});}
            tabsEl.querySelectorAll('.apex-tab').forEach(function(t){
                t.addEventListener('mouseenter',function(){moveBg(t);});
                t.addEventListener('mouseleave',function(){var a=tabsEl.querySelector('.apex-tab.active');if(a)moveBg(a);});
            });
            window.addEventListener('resize',function(){var a=tabsEl.querySelector('.apex-tab.active');if(a){bg.style.transition='none';moveBg(a);requestAnimationFrame(function(){bg.style.transition=''});}});
        }
        /* ── NOTE: axg-header CSS override (remove old inline rule if present) ── */

        /* ── Loading bar ── */
        var prog=document.getElementById('apex-progress');
        if(prog){window.addEventListener('load',function(){prog.style.opacity='0';prog.style.transition='opacity .4s';setTimeout(function(){prog.style.display='none';},500);});}

        /* ── Toast ── */
        function toast(msg){
            var el=document.getElementById('apex-toast'),txt=document.getElementById('apex-toast-msg');
            if(!el||!txt)return;txt.textContent=msg;el.classList.add('show');
            clearTimeout(el._t);el._t=setTimeout(function(){el.classList.remove('show');},2200);
        }

        /* ── Copy ── */
        window.apexCopy=function(btn){
            var url=btn.dataset.url;if(!url)return;
            function done(){btn.classList.add('copied');toast('Spec URL copied');clearTimeout(btn._t);btn._t=setTimeout(function(){btn.classList.remove('copied');},2200);}
            if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done).catch(function(){fb(url);done();});}
            else{fb(url);done();}
        };
        function fb(text){var t=document.createElement('textarea');t.value=text;t.style.cssText='position:fixed;top:-9999px';document.body.appendChild(t);t.select();try{document.execCommand('copy');}catch(e){}document.body.removeChild(t);}

        /* ── Export dropdown ── */
        var expWrap=document.getElementById('apexExportWrap'),expBtn=document.getElementById('apexExportBtn'),expMenu=document.getElementById('apexExportMenu');
        if(expBtn&&expWrap){
            expBtn.addEventListener('click',function(e){e.stopPropagation();expWrap.classList.toggle('open');});
            document.addEventListener('click',function(){expWrap.classList.remove('open');});
            if(expMenu)expMenu.addEventListener('click',function(e){e.stopPropagation();});
        }

        /* ── Theme toggle ── */
        var themeBtn=document.getElementById('apexThemeBtn');
        function getTheme(){return localStorage.getItem('apex-theme')||APEX_CFG.theme;}
        function applyTheme(t){
            if(t==='auto')document.documentElement.removeAttribute('data-theme');
            else document.documentElement.setAttribute('data-theme',t);
        }
        applyTheme(getTheme());
        if(themeBtn){
            themeBtn.addEventListener('click',function(){
                var cur=getTheme();
                var next=cur==='dark'?'light':cur==='light'?'auto':'dark';
                localStorage.setItem('apex-theme',next);applyTheme(next);
                toast('Theme: '+next);
            });
        }

        /* ── Environment switcher ── */
        var envBtn=document.getElementById('apexEnvBtn'),envPop=document.getElementById('axui-env-popover');
        var _specCache=null;
        var _activeEnv=localStorage.getItem('apex-env')||null;
        function getSpecUrl(){return APEX_CFG.specUrl;}
        function loadSpec(cb){if(_specCache){cb(_specCache);return;}fetch(getSpecUrl()).then(function(r){return r.json();}).then(function(s){_specCache=s;cb(s);}).catch(function(){});}
        if(envBtn&&envPop){
            envBtn.addEventListener('click',function(e){
                e.stopPropagation();
                loadSpec(function(spec){
                    var servers=spec.servers||[{url:'http://localhost',description:'Default'}];
                    var list=document.getElementById('axui-env-list');
                    if(list){
                        list.innerHTML=servers.map(function(s,i){
                            var active=(_activeEnv===s.url||(!_activeEnv&&i===0))?' active':'';
                            return '<div class="axui-env-item'+active+'" data-url="'+s.url+'" onclick="apexSetEnv(\''+escH(s.url)+'\')">'
                                +'<span class="axui-env-dot"></span><span>'+escH(s.description||s.url)+'</span></div>';
                        }).join('');
                    }
                    envPop.hidden=!envPop.hidden;
                });
            });
            document.addEventListener('click',function(){if(envPop)envPop.hidden=true;});
        }
        window.apexSetEnv=function(url){
            _activeEnv=url;localStorage.setItem('apex-env',url);
            if(envPop)envPop.hidden=true;
            toast('Server: '+url);
            if(window._axui)window._axui.setServer(url);
        };

        /* ── Command palette ── */
        var pal=document.getElementById('apex-palette'),palInput=document.getElementById('apex-palette-input'),palResults=document.getElementById('apex-palette-results');
        var _palIdx=document.getElementById('apex-palette-btn');
        var _ops=[];var _palFocus=0;
        function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
        function buildIndex(spec){
            _ops=[];
            var paths=spec.paths||{};
            for(var path in paths){
                var methods=paths[path];
                for(var method in methods){
                    var op=methods[method];
                    if(typeof op!=='object')continue;
                    _ops.push({method:method.toUpperCase(),path:path,summary:op.summary||'',tag:(op.tags||['General'])[0],operationId:op.operationId||''});
                }
            }
        }
        function renderPalette(query){
            if(!_ops.length){palResults.innerHTML='<div class="apex-pal-empty">Loading…</div>';return;}
            var q=query.trim().toLowerCase();
            var res=q?_ops.filter(function(o){return o.path.toLowerCase().includes(q)||o.summary.toLowerCase().includes(q)||o.tag.toLowerCase().includes(q)||o.method.toLowerCase().includes(q);}):_ops.slice(0,30);
            if(!res.length){palResults.innerHTML='<div class="apex-pal-empty">No results for "'+escH(query)+'"</div>';return;}
            var byTag={};res.forEach(function(o){(byTag[o.tag]=byTag[o.tag]||[]).push(o);});
            var html='';var idx=0;
            for(var tag in byTag){
                html+='<div class="apex-pal-group">'+escH(tag)+'</div>';
                byTag[tag].forEach(function(o){
                    html+='<a class="apex-pal-item" href="?ui=apex#op_'+escH(o.operationId||o.method+'_'+o.path)+'" data-idx="'+idx+'" onclick="apexPaletteClose()">'
                        +'<span class="axm axm-'+o.method.toLowerCase()+'">'+escH(o.method)+'</span>'
                        +'<span class="apex-pal-path">'+escH(o.path)+'</span>'
                        +'<span class="apex-pal-sum">'+escH(o.summary)+'</span>'
                        +'</a>';idx++;
                });
            }
            palResults.innerHTML=html;_palFocus=0;focusPalItem(0);
        }
        function focusPalItem(i){
            palResults.querySelectorAll('.apex-pal-item').forEach(function(el,j){el.classList.toggle('focused',j===i);});
            var f=palResults.querySelector('.apex-pal-item.focused');if(f)f.scrollIntoView({block:'nearest'});
        }
        function palOpen(){
            pal.hidden=false;palInput.value='';palInput.focus();
            loadSpec(function(spec){buildIndex(spec);renderPalette('');});
        }
        window.apexPaletteClose=function(){pal.hidden=true;};
        if(_palIdx)_palIdx.addEventListener('click',palOpen);
        if(palInput){
            palInput.addEventListener('input',function(){renderPalette(palInput.value);_palFocus=0;});
            palInput.addEventListener('keydown',function(e){
                var items=palResults.querySelectorAll('.apex-pal-item');
                if(e.key==='ArrowDown'){e.preventDefault();_palFocus=Math.min(_palFocus+1,items.length-1);focusPalItem(_palFocus);}
                else if(e.key==='ArrowUp'){e.preventDefault();_palFocus=Math.max(_palFocus-1,0);focusPalItem(_palFocus);}
                else if(e.key==='Enter'){e.preventDefault();var f=palResults.querySelector('.apex-pal-item.focused');if(f)f.click();}
                else if(e.key==='Escape'){apexPaletteClose();}
            });
        }

        /* ── Global keyboard shortcuts ── */
        document.addEventListener('keydown',function(e){
            var inField=e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.isContentEditable||e.isComposing;
            if((e.metaKey||e.ctrlKey)&&e.key==='k'){e.preventDefault();palOpen();return;}
            if(e.key==='Escape'){apexPaletteClose();return;}
            if(inField)return;
            if(e.key==='/'){e.preventDefault();var f=document.getElementById('axui-filter');if(f){f.focus();f.select();}return;}
            if(e.ctrlKey||e.metaKey||e.altKey)return;
            var uis=['apex','scalar','swagger','redoc','stoplight','rapidoc'];
            var n=parseInt(e.key);if(n>=1&&n<=6){var t=document.querySelector('.apex-tab[data-ui="'+uis[n-1]+'"]');if(t)t.click();}
        });

        /* ── Native Apex UI ── */
        if(APEX_CFG.activeUi==='apex'){
            var METHODS=['get','post','put','patch','delete','head','options'];
            var LANGS=['curl','js','python','php','go'];
            var LANG_LABELS={curl:'cURL',js:'JavaScript',python:'Python',php:'PHP',go:'Go'};
            var _server=localStorage.getItem('apex-env')||'';
            var _activeOpKey=null;
            var _schemaIds=0;

            function init(){
                loadSpec(function(spec){
                    _server=_server||(spec.servers&&spec.servers[0]&&spec.servers[0].url)||'';
                    renderSidebar(spec);
                    renderWelcome(spec);
                    renderSidebarFooter(spec);
                    var hash=location.hash.slice(1);if(hash)navigateHash(hash,spec);
                    var filter=document.getElementById('axui-filter');
                    if(filter){filter.addEventListener('input',function(){renderSidebar(spec,filter.value);});}
                });
                // Error state if fetch fails
                setTimeout(function(){
                    var body=document.getElementById('axui-sidebar-body');
                    if(body&&body.querySelector('.axui-loading-state')){
                        body.innerHTML='<div class="ax-error-state"><div class="ax-error-icon">⚠</div><div class="ax-error-title">Failed to load spec</div><div class="ax-error-msg">Check network or spec URL</div></div>';
                    }
                },10000);
            }

            window._axui={setServer:function(url){_server=url;}};

            function groupByTag(spec){
                var g={};
                for(var path in spec.paths||{}){
                    for(var m in spec.paths[path]){
                        if(!METHODS.includes(m))continue;
                        var op=spec.paths[path][m];var tag=(op.tags||['General'])[0];
                        (g[tag]=g[tag]||[]).push({path:path,method:m,op:op,key:m+'__'+path});
                    }
                }
                return g;
            }

            function renderSidebar(spec,filter){
                var body=document.getElementById('axui-sidebar-body');if(!body)return;
                var groups=groupByTag(spec);var q=(filter||'').toLowerCase().trim();
                var html='<div class="axs-overview">'
                    +'<div class="axs-api-title">'+escH(spec.info&&spec.info.title||'API')+'</div>'
                    +(spec.info&&spec.info.description?'<div class="axs-api-desc">'+escH(spec.info.description)+'</div>':'')
                    +'</div>';
                var anyResult=false;
                for(var tag in groups){
                    var items=groups[tag];
                    if(q)items=items.filter(function(i){return i.path.toLowerCase().includes(q)||i.method.toLowerCase().includes(q)||(i.op.summary||'').toLowerCase().includes(q)||(i.op.operationId||'').toLowerCase().includes(q);});
                    if(!items.length)continue;
                    anyResult=true;
                    var hasActive=items.some(function(i){return i.key===_activeOpKey;});
                    var open=(q||tag===Object.keys(groups)[0]||hasActive)?' open':'';
                    html+='<div class="axg'+open+'" id="axg-'+escH(tag)+'">'
                        +'<div class="axg-header" onclick="axToggleGroup(this)">'
                        +'<span class="axg-arrow">▶</span>'
                        +'<span class="axg-name">'+escH(tag)+'</span>'
                        +'<span class="axg-count">'+items.length+'</span>'
                        +'</div><div class="axg-items">';
                    items.forEach(function(i){
                        var dep=i.op.deprecated?' axi-depr':'';
                        html+='<div class="axi'+(i.key===_activeOpKey?' active':'')+dep+'" data-key="'+escH(i.key)+'" data-path="'+escH(i.path)+'" data-method="'+escH(i.method)+'" onclick="axNavEl(this)">'
                            +'<span class="axm axm-'+i.method+'">'+i.method.toUpperCase()+'</span>'
                            +'<span class="axi-path">'+escH(i.path)+'</span>'
                            +(i.op.deprecated?'<span class="axi-depr-dot">D</span>':'')
                            +'</div>';
                    });
                    html+='</div></div>';
                }
                // Webhooks section
                var wh=spec.webhooks||{};var whKeys=Object.keys(wh);
                if(whKeys.length&&!q){
                    var whActive=_activeOpKey&&_activeOpKey.startsWith('webhook__');
                    html+='<div class="axg'+(whActive?' open':'')+'" id="axg-__webhooks__">'
                        +'<div class="axg-header" onclick="axToggleGroup(this)">'
                        +'<span class="axg-arrow">▶</span>'
                        +'<span class="axg-name">Webhooks</span>'
                        +'<span class="axg-count">'+whKeys.length+'</span>'
                        +'</div><div class="axg-items">';
                    whKeys.forEach(function(wname){
                        var wop=wh[wname];
                        for(var wm in wop){
                            if(!METHODS.includes(wm))continue;
                            html+='<div class="axi" data-key="webhook__'+escH(wname)+'__'+escH(wm)+'" data-wname="'+escH(wname)+'" data-wmethod="'+escH(wm)+'" onclick="axNavWhEl(this)">'
                                +'<span class="axm axm-'+wm+'">'+wm.toUpperCase()+'</span>'
                                +'<span class="axi-path">'+escH(wname)+'</span>'
                                +'<span class="ax-webhook-badge">wh</span>'
                                +'</div>';
                        }
                    });
                    html+='</div></div>';
                }
                if(q&&!anyResult)html+='<div class="ax-empty">No results for "<strong>'+escH(q)+'</strong>"</div>';
                body.innerHTML=html;
            }

            function renderSidebarFooter(spec){
                var footer=document.getElementById('axui-sidebar-footer');if(!footer)return;
                var info=spec.info||{};var items=[];
                if(info.contact){
                    var c=info.contact;var lbl=escH(c.name||c.email||c.url||'Contact');
                    if(c.email)items.push('<div class="axf-item"><a href="mailto:'+escH(c.email)+'" class="axf-link">'+lbl+'</a></div>');
                    else if(c.url)items.push('<div class="axf-item"><a href="'+escH(c.url)+'" class="axf-link" target="_blank" rel="noreferrer">'+lbl+'</a></div>');
                }
                if(info.license){
                    var l=info.license;var lname=escH(l.name||l.identifier||'License');
                    items.push('<div class="axf-item">'+(l.url?'<a href="'+escH(l.url)+'" class="axf-link" target="_blank" rel="noreferrer">'+lname+'</a>':lname)+'</div>');
                }
                if(info.termsOfService)items.push('<div class="axf-item"><a href="'+escH(info.termsOfService)+'" class="axf-link" target="_blank" rel="noreferrer">Terms of Service</a></div>');
                footer.innerHTML=items.join('');
            }

            window.axToggleGroup=function(el){el.closest('.axg').classList.toggle('open');};
            window.axNavEl=function(el){var g=el.closest('.axg');if(g)g.classList.add('open');axNav(el.dataset.key,el.dataset.path,el.dataset.method);};
            window.axNavWhEl=function(el){var g=el.closest('.axg');if(g)g.classList.add('open');axNavWebhook(el.dataset.wname,el.dataset.wmethod);};

            window.axNav=function(key,path,method){
                _activeOpKey=key;
                var spec=_specCache;if(!spec)return;
                var op=spec.paths&&spec.paths[path]&&spec.paths[path][method];if(!op)return;
                renderSidebar(spec);
                renderOperation(path,method,op,spec,false);
                history.replaceState(null,'','#op_'+(op.operationId||key));
                var c=document.getElementById('axui-content');if(c)c.scrollTop=0;
            };

            window.axNavWebhook=function(name,method){
                _activeOpKey='webhook__'+name+'__'+method;
                var spec=_specCache;if(!spec)return;
                var op=spec.webhooks&&spec.webhooks[name]&&spec.webhooks[name][method];if(!op)return;
                renderSidebar(spec);
                renderOperation(name,method,op,spec,true);
                history.replaceState(null,'','#wh_'+name+'_'+method);
                var c=document.getElementById('axui-content');if(c)c.scrollTop=0;
            };

            function navigateHash(hash,spec){
                if(hash.startsWith('op_')){
                    var id=hash.slice(3);
                    for(var path in spec.paths||{}){
                        for(var m in spec.paths[path]){
                            var op=spec.paths[path][m];
                            if((op.operationId||m+'__'+path)===id||m+'__'+path===id){axNav(m+'__'+path,path,m);return;}
                        }
                    }
                }
                if(hash.startsWith('wh_')){
                    var p2=hash.slice(3).split('_');if(p2.length>=2)axNavWebhook(p2[0],p2[1]);
                }
            }

            function renderWelcome(spec){
                var info=spec.info||{};var paths=spec.paths||{};
                var opCount=0;var tagSet=new Set();var deprCount=0;
                for(var p in paths)for(var m in paths[p])if(METHODS.includes(m)){opCount++;if(paths[p][m].deprecated)deprCount++;(paths[p][m].tags||['General']).forEach(function(t){tagSet.add(t);});}
                var schemaCount=Object.keys((spec.components&&spec.components.schemas)||{}).length;
                var whCount=Object.keys(spec.webhooks||{}).length;
                var svgEp='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon"><path d="M1 4h14M1 8h14M1 12h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
                var svgGr='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon"><rect x="1" y="1" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="10" y="1" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="1" y="10" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="10" y="10" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/></svg>';
                var svgSc='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon"><rect x="1" y="3" width="14" height="10" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M5 7h6M5 10h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
                var svgWh='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon"><path d="M3 13c0-3 2-5 5-5s5 2 5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="8" cy="5" r="2.5" stroke="currentColor" stroke-width="1.4"/></svg>';
                var stats='<div class="axw-stats">'
                    +'<div class="axw-stat">'+svgEp+'<div><div class="axw-stat-n">'+opCount+'</div><div class="axw-stat-l">Endpoints</div></div></div>'
                    +'<div class="axw-stat">'+svgGr+'<div><div class="axw-stat-n">'+tagSet.size+'</div><div class="axw-stat-l">Groups</div></div></div>'
                    +(schemaCount?'<div class="axw-stat">'+svgSc+'<div><div class="axw-stat-n">'+schemaCount+'</div><div class="axw-stat-l">Schemas</div></div></div>':'')
                    +(whCount?'<div class="axw-stat">'+svgWh+'<div><div class="axw-stat-n">'+whCount+'</div><div class="axw-stat-l">Webhooks</div></div></div>':'')
                    +'</div>';
                var servers=(spec.servers||[]).map(function(s,i){
                    var active=(_activeEnv===s.url||(!_activeEnv&&i===0));
                    return '<div class="axw-server"><span class="axw-server-dot'+(active?' active':'')+'"></span>'+escH(s.url)+(s.description?'<span style="margin-left:8px;color:var(--t3);font-size:11px">'+escH(s.description)+'</span>':'')+'</div>';
                }).join('');
                var metaHtml='';
                if(info.contact){
                    var c=info.contact;var lbl=escH(c.name||c.email||c.url||'Contact');
                    if(c.email)metaHtml+='<div class="axw-meta-item">Contact: <a href="mailto:'+escH(c.email)+'" style="color:var(--accent);text-decoration:none">'+lbl+'</a></div>';
                    else if(c.url)metaHtml+='<div class="axw-meta-item">Contact: <a href="'+escH(c.url)+'" target="_blank" style="color:var(--accent);text-decoration:none">'+lbl+'</a></div>';
                }
                if(info.license){var l=info.license;var ln=escH(l.name||l.identifier||'License');metaHtml+='<div class="axw-meta-item">License: '+(l.url?'<a href="'+escH(l.url)+'" target="_blank" style="color:var(--accent);text-decoration:none">'+ln+'</a>':ln)+'</div>';}
                if(info.termsOfService)metaHtml+='<div class="axw-meta-item"><a href="'+escH(info.termsOfService)+'" target="_blank" style="color:var(--accent);text-decoration:none">Terms of Service</a></div>';
                document.getElementById('axui-welcome').innerHTML=
                    '<div class="axw-title">'+escH(info.title||'API')+'</div>'
                    +'<div class="axw-meta"><span class="axw-version">v'+escH(info.version||'1.0')+'</span><span class="axw-openapi">'+escH(spec.openapi||'OpenAPI')+'</span>'+(deprCount?'<span style="font-size:11px;color:var(--amber);padding:2px 6px;border-radius:4px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2)">'+deprCount+' deprecated</span>':'')+'</div>'
                    +(info.description?'<div class="axw-desc">'+escH(info.description)+'</div>':'')
                    +stats
                    +(metaHtml?'<div class="axw-contact-block">'+metaHtml+'</div>':'')
                    +(servers?'<div class="axw-servers"><div class="axw-servers-title">Servers</div>'+servers+'</div>':'')
                    +'<div class="axw-hint">Select an endpoint from the sidebar, or press <kbd style="padding:2px 6px;border-radius:4px;background:var(--s2);border:1px solid var(--border);font-family:inherit">⌘K</kbd> to search</div>';
            }

            function renderOperation(path,method,op,spec,isWebhook){
                var wrap=document.getElementById('axui-content-inner');if(!wrap)return;
                _schemaIds=0;
                var dep=op.deprecated?'<span class="ax-depr-badge">deprecated</span>':'';
                var tag=(op.tags||['General'])[0];
                // Breadcrumb
                var bc='<div class="ax-breadcrumb">'
                    +(isWebhook
                        ?'<span class="ax-breadcrumb-item">Webhooks</span>'
                        :'<span class="ax-breadcrumb-item ax-breadcrumb-link" onclick="axGoWelcome()">'+escH(tag)+'</span>')
                    +'<span class="ax-breadcrumb-sep">›</span>'
                    +'<span class="ax-breadcrumb-current">'+method.toUpperCase()+'</span>'
                    +'<span class="ax-breadcrumb-sep">›</span>'
                    +'<span class="ax-breadcrumb-path">'+escH(path)+'</span>'
                    +'</div>';
                // Security
                var secHtml='';
                if(op.security!==undefined){
                    if(!op.security.length){
                        secHtml='<div class="ax-sec-badges"><span class="ax-sec-badge ax-sec-open">No authentication required</span></div>';
                    } else {
                        var badges='';
                        op.security.forEach(function(s){Object.keys(s).forEach(function(sn){var sc=s[sn];badges+='<span class="ax-sec-badge">'+escH(sn)+(sc&&sc.length?'<span class="ax-sec-scopes"> ['+escH(sc.join(', '))+']</span>':'')+'</span>';});});
                        secHtml='<div class="ax-sec-badges">'+badges+'</div>';
                    }
                }
                // External docs
                var extHtml=op.externalDocs?'<a href="'+escH(op.externalDocs.url)+'" target="_blank" rel="noreferrer" class="ax-ext-docs-link">'+escH(op.externalDocs.description||'External Docs')+' ↗</a>':'';
                // Permalink
                var opId=op.operationId||(isWebhook?'wh_':'op_')+method+'__'+path;
                var plBtn='<button class="ax-permalink-btn" onclick="axCopyPermalink(\''+escH(opId)+'\')" title="Copy permalink"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M7 9a3 3 0 0 0 4.5.3l2-2A3 3 0 0 0 9.2 3L8.1 4.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9 7a3 3 0 0 0-4.5-.3l-2 2A3 3 0 0 0 6.8 13l1.1-1.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button>';
                var html=bc
                    +'<div class="ax-op-header">'
                    +'<span class="axm axm-'+method+'">'+method.toUpperCase()+'</span>'
                    +'<div class="ax-op-title-wrap"><div class="ax-op-path">'+escH(path)+dep+plBtn+'</div>'
                    +(op.summary?'<div class="ax-op-summary">'+escH(op.summary)+'</div>':'')
                    +'</div></div>'
                    +(op.description?'<div class="ax-op-desc">'+escH(op.description)+'</div>':'')
                    +secHtml+extHtml;
                // Parameters
                var params=(op.parameters||[]);
                if(params.length){
                    html+='<div class="ax-section"><div class="ax-section-title">Parameters</div>'
                        +'<table class="ax-params"><thead><tr><th>Name</th><th>In</th><th>Type</th><th>Required</th><th>Description</th></tr></thead><tbody>';
                    params.forEach(function(p){
                        var sc=p.schema||{};var t=sc.type||(sc['$ref']?sc['$ref'].split('/').pop():'string');
                        var enums=sc.enum?'<div class="ax-enum-wrap">'+sc.enum.map(function(v){return '<span class="ax-enum-val">'+escH(String(v))+'</span>';}).join('')+'</div>':'';
                        html+='<tr><td class="ax-param-name">'+escH(p.name)+(p.deprecated?'<sup style="color:var(--amber);font-size:9px"> dep</sup>':'')+'</td>'
                            +'<td><span class="ax-in-badge">'+escH(p.in)+'</span></td>'
                            +'<td><span class="ax-type-badge">'+escH(t)+(sc.format?'<span style="opacity:.6"> ('+escH(sc.format)+')</span>':'')+'</span></td>'
                            +'<td>'+(p.required?'<span class="ax-req-badge">req</span>':'<span style="color:var(--t3);font-size:11px">opt</span>')+'</td>'
                            +'<td style="color:var(--t3)">'+escH(p.description||'')+enums+'</td></tr>';
                    });
                    html+='</tbody></table></div>';
                }
                // Request body
                if(op.requestBody){
                    var ct=op.requestBody.content||{};var ctKeys=Object.keys(ct);
                    html+='<div class="ax-section"><div class="ax-section-title">Request Body'+(op.requestBody.required?'':' <span style="font-size:10px;font-weight:400;color:var(--t3)">(optional)</span>')+'</div>';
                    if(op.requestBody.description)html+='<div style="font-size:13px;color:var(--t2);margin-bottom:10px">'+escH(op.requestBody.description)+'</div>';
                    if(ctKeys.length>1){
                        html+='<div class="ax-resp-ct-tabs">';
                        ctKeys.forEach(function(mime,i){html+='<button class="ax-resp-ct-btn'+(i===0?' active':'')+'" onclick="axSwitchReqCt(this,\''+escH(mime)+'\')">'+escH(mime)+'</button>';});
                        html+='</div>';
                    }
                    ctKeys.forEach(function(mime,i){
                        html+='<div class="ax-req-ct-panel" data-mime="'+escH(mime)+'" style="'+(i>0?'display:none':'')+'">'
                            +(ctKeys.length<=1?'<div style="font-size:11px;color:var(--t3);margin-bottom:6px;font-family:monospace">'+escH(mime)+'</div>':'')
                            +renderSchema(ct[mime].schema||{},spec,0)+'</div>';
                    });
                    html+='</div>';
                }
                // Responses
                if(op.responses){
                    html+='<div class="ax-section"><div class="ax-section-title">Responses</div>';
                    var rKeys=Object.keys(op.responses);
                    rKeys.forEach(function(status,ri){
                        var resp=op.responses[status];var sc=parseInt(status);
                        var cls=sc<300?'axs-2xx':sc<400?'axs-3xx':sc<500?'axs-4xx':'axs-5xx';
                        var icon=sc<300?'✓':sc<400?'→':'✕';
                        var isFirst=ri===0;
                        html+='<div class="ax-resp'+(isFirst?' open':'')+'" id="ax-resp-'+escH(status)+'">'
                            +'<div class="ax-resp-header" onclick="axToggleResp(this)">'
                            +'<span class="ax-resp-status '+cls+'">'+icon+' '+escH(status)+'</span>'
                            +'<span class="ax-resp-desc">'+escH(resp.description||'')+'</span>'
                            +'<span class="ax-resp-arrow">▶</span>'
                            +'</div>'
                            +'<div class="ax-resp-body" style="'+(isFirst?'':'display:none')+'">';
                        var rc=resp.content||{};var rck=Object.keys(rc);
                        if(rck.length>1){
                            html+='<div class="ax-resp-ct-tabs">';
                            rck.forEach(function(mime,i){html+='<button class="ax-resp-ct-btn'+(i===0?' active':'')+'" onclick="axSwitchRespCt(this,\'ax-resp-'+escH(status)+'\',\''+escH(mime)+'\')">'+escH(mime)+'</button>';});
                            html+='</div>';
                        }
                        rck.forEach(function(mime,i){html+='<div class="ax-resp-ct-panel" data-mime="'+escH(mime)+'" style="'+(i>0?'display:none':'')+'">'+renderSchema(rc[mime].schema||{},spec,0)+'</div>';});
                        html+='</div></div>';
                    });
                    html+='</div>';
                }
                wrap.innerHTML=html;
                renderPanel(path,method,op,spec);
            }

            window.axSwitchReqCt=function(btn,mime){
                var sec=btn.closest('.ax-section');
                sec.querySelectorAll('.ax-req-ct-panel').forEach(function(el){el.style.display=el.dataset.mime===mime?'':'none';});
                btn.closest('.ax-resp-ct-tabs').querySelectorAll('.ax-resp-ct-btn').forEach(function(b){b.classList.toggle('active',b===btn);});
            };
            window.axSwitchRespCt=function(btn,respId,mime){
                var r=document.getElementById(respId);if(!r)return;
                r.querySelectorAll('.ax-resp-ct-panel').forEach(function(el){el.style.display=el.dataset.mime===mime?'':'none';});
                btn.closest('.ax-resp-ct-tabs').querySelectorAll('.ax-resp-ct-btn').forEach(function(b){b.classList.toggle('active',b===btn);});
            };
            window.axToggleResp=function(el){
                var resp=el.closest('.ax-resp');var body=el.nextElementSibling;
                var open=resp.classList.toggle('open');
                body.style.display=open?'':'none';
            };
            window.axGoWelcome=function(){
                _activeOpKey=null;
                if(_specCache){renderSidebar(_specCache);renderWelcome(_specCache);}
                var c=document.getElementById('axui-content');if(c)c.scrollTop=0;
                history.replaceState(null,'',location.pathname);
            };
            window.axCopyPermalink=function(id){
                var url=location.origin+location.pathname+'#op_'+id;
                if(navigator.clipboard)navigator.clipboard.writeText(url).then(function(){toast('Permalink copied');});
                else{fb(url);toast('Permalink copied');}
            };

            function resolveRef(ref,spec){
                if(!ref||!ref.startsWith('#/'))return null;
                var parts=ref.slice(2).split('/');var cur=spec;
                for(var i=0;i<parts.length;i++){cur=cur[parts[i]];if(cur==null)return null;}
                return cur;
            }

            function renderSchema(schema,spec,depth){
                if(!schema)return '';
                if(schema['$ref']){
                    var refName=schema['$ref'].split('/').pop();
                    var resolved=depth<3?resolveRef(schema['$ref'],spec):null;
                    if(resolved&&depth<3){
                        var sid='axs'+(++_schemaIds);
                        return '<div class="ax-ref-wrap">'
                            +'<button class="ax-schema-collapse-btn" data-schema="'+sid+'" onclick="axToggleSchema(\''+sid+'\')">▼</button>'
                            +'<span class="ax-type-badge ax-ref-badge">'+escH(refName)+'</span>'
                            +'<div id="'+sid+'" class="ax-ref-expanded">'+renderSchema(resolved,spec,depth+1)+'</div>'
                            +'</div>';
                    }
                    return '<span class="ax-type-badge ax-ref-badge">'+escH(refName)+'</span>';
                }
                if(schema.allOf)return '<div class="ax-schema-obj"><div class="ax-allof-label">allOf</div>'+schema.allOf.map(function(s){return renderSchema(s,spec,depth+1);}).join('')+'</div>';
                if(schema.oneOf)return '<div class="ax-oneof-wrap"><div class="ax-oneof-label">One of:</div>'+schema.oneOf.map(function(s,i){return '<div class="ax-oneof-item'+(i>0?' ax-oneof-sep':'')+'">'+(i>0?'<div style="font-size:10px;color:var(--t3);margin:2px 0">or</div>':'')+renderSchema(s,spec,depth+1)+'</div>';}).join('')+'</div>';
                if(schema.anyOf)return '<div class="ax-oneof-wrap"><div class="ax-oneof-label">Any of:</div>'+schema.anyOf.map(function(s){return '<div class="ax-oneof-item">'+renderSchema(s,spec,depth+1)+'</div>';}).join('')+'</div>';
                var type=schema.type||(schema.properties?'object':'any');
                if(type==='object'||schema.properties){
                    if(depth>=3)return '<span class="ax-type-badge">object {…}</span>';
                    var props=schema.properties||{};var req=schema.required||[];var propKeys=Object.keys(props);
                    if(!propKeys.length)return '<span class="ax-type-badge">object {}</span>'+(schema.additionalProperties?'<span style="font-size:11px;color:var(--t3);margin-left:6px">+ extra fields</span>':'');
                    var html='<div class="ax-schema-obj">';
                    if(schema.description)html+='<div class="ax-schema-desc">'+escH(schema.description)+'</div>';
                    propKeys.forEach(function(pn){
                        var pv=props[pn];var pt=pv.type||(pv['$ref']?pv['$ref'].split('/').pop():(pv.properties?'object':'any'));
                        var isNested=(pv.type==='object'||pv.properties)&&depth<2;
                        var nestedId=isNested?'axs'+(++_schemaIds):'';
                        html+='<div class="ax-prop-row">'
                            +(isNested?'<button class="ax-schema-collapse-btn" data-schema="'+nestedId+'" onclick="axToggleSchema(\''+nestedId+'\')">▼</button>':'')
                            +'<span class="ax-prop-name">'+escH(pn)+'</span>'
                            +'<span class="ax-prop-type ax-type-badge">'+escH(pt)+(pv.format?'<span style="opacity:.6"> ('+escH(pv.format)+')</span>':'')+(pv.nullable?'<span style="opacity:.6"> | null</span>':'')+'</span>'
                            +(req.includes(pn)?'<span class="ax-req-badge ax-prop-req">req</span>':'')
                            +(pv.description?'<span class="ax-prop-desc">'+escH(pv.description)+'</span>':'')
                            +'</div>';
                        if(pv.enum)html+='<div style="padding:4px 12px 6px 12px"><div class="ax-enum-wrap">'+pv.enum.map(function(v){return '<span class="ax-enum-val">'+escH(String(v))+'</span>';}).join('')+'</div></div>';
                        if(isNested)html+='<div id="'+nestedId+'" class="ax-prop-nested">'+renderSchema(pv,spec,depth+1)+'</div>';
                    });
                    return html+'</div>';
                }
                if(type==='array')return '<span class="ax-type-badge">array</span><span style="font-size:11px;color:var(--t3);margin:0 4px">of</span>'+renderSchema(schema.items||{},spec,depth);
                if(schema.enum)return '<div class="ax-enum-wrap">'+schema.enum.map(function(v){return '<span class="ax-enum-val">'+escH(String(v))+'</span>';}).join('')+'</div>';
                return '<span class="ax-type-badge">'+escH(type)+(schema.format?'<span style="opacity:.6"> ('+escH(schema.format)+')</span>':'')+'</span>';
            }

            window.axToggleSchema=function(id){
                var el=document.getElementById(id);var btn=document.querySelector('[data-schema="'+id+'"]');if(!el)return;
                var open=el.style.display!=='none';
                el.style.display=open?'none':'';
                if(btn)btn.textContent=open?'▶':'▼';
            };

            /* ── Right panel ── */
            var _activeLang=APEX_CFG.defaultLanguage||'curl';

            function renderPanel(path,method,op,spec){
                var panel=document.getElementById('axui-panel-inner');if(!panel)return;
                var tabs=LANGS.map(function(l){return '<button class="ax-lang-btn'+(l===_activeLang?' active':'')+'" onclick="axLang(\''+l+'\')">'+LANG_LABELS[l]+'</button>';}).join('');
                var codeHtml='<div class="ax-panel-section-title">Code Sample</div><div class="ax-lang-tabs">'+tabs+'</div><div id="ax-code-wrap"></div>';
                var tryHtml='';
                if(APEX_CFG.tryItOut){
                    var params=op.parameters||[];
                    var pathParams=params.filter(function(p){return p.in==='path';});
                    var queryParams=params.filter(function(p){return p.in==='query';});
                    var headerParams=params.filter(function(p){return p.in==='header';});
                    var hasBody=['post','put','patch'].includes(method);
                    var hasSec=op.security===undefined||op.security.length>0;
                    tryHtml='<div class="ax-try-section"><div class="ax-panel-section-title">Try it out</div>'
                        +'<div style="font-size:11px;color:var(--t3);margin-bottom:10px;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+escH(_server)+'">'+escH(_server||'(no server)')+'</div>';
                    if(hasSec){
                        tryHtml+='<label class="ax-try-label">Authorization</label>'
                            +'<div class="ax-try-auth-wrap">'
                            +'<select id="axi-auth-type" class="ax-try-auth-type" onchange="axAuthTypeChange()">'
                            +'<option value="bearer">Bearer</option>'
                            +'<option value="basic">Basic</option>'
                            +'<option value="apikey">API Key</option>'
                            +'</select>'
                            +'<input class="ax-try-input" id="axi-auth" type="password" placeholder="Token…" style="flex:1">'
                            +'</div>';
                    }
                    function paramFields(list,prefix){
                        var h='';
                        list.forEach(function(p){
                            h+='<label class="ax-try-label" style="font-size:11px;color:var(--t3)">'+escH(p.name)+(p.required?' <span style="color:var(--red)">*</span>':'')+'</label>'
                                +'<input class="ax-try-input" id="'+(prefix||'axi-')+escH(p.name)+'" placeholder="'+escH(p.example!=null?String(p.example):'')+'"><div style="margin-bottom:4px"></div>';
                        });
                        return h;
                    }
                    if(pathParams.length)tryHtml+='<div class="ax-try-label" style="margin-top:8px;color:var(--t3)">Path</div>'+paramFields(pathParams,'axi-');
                    if(queryParams.length)tryHtml+='<div class="ax-try-label" style="margin-top:8px;color:var(--t3)">Query</div>'+paramFields(queryParams,'axi-');
                    if(headerParams.length)tryHtml+='<div class="ax-try-label" style="margin-top:8px;color:var(--t3)">Headers</div>'+paramFields(headerParams,'axi-h-');
                    if(hasBody&&op.requestBody){
                        var ct=op.requestBody.content||{};var ex='{}';
                        if(ct['application/json']&&ct['application/json'].schema)ex=JSON.stringify(buildExample(ct['application/json'].schema,spec),null,2);
                        tryHtml+='<label class="ax-try-label" style="margin-top:8px">Request Body</label><textarea class="ax-try-input ax-try-textarea" id="axi-body">'+escH(ex)+'</textarea>';
                    }
                    tryHtml+='<button class="ax-try-send" id="axi-send" onclick="axSend(\''+escH(_server)+'\',\''+escH(path)+'\',\''+escH(method)+'\')">Send Request</button><div id="axi-result"></div></div>';
                }
                panel.innerHTML=codeHtml+tryHtml;
                renderCode(path,method,op,spec,_server);
            }

            window.axAuthTypeChange=function(){
                var t=document.getElementById('axi-auth-type');var i=document.getElementById('axi-auth');if(!t||!i)return;
                var ph={bearer:'Token…',basic:'user:password',apikey:'API key…'};
                i.placeholder=ph[t.value]||'Token…';i.type=t.value==='basic'?'text':'password';
            };

            window.axLang=function(lang){
                _activeLang=lang;
                document.querySelectorAll('.ax-lang-btn').forEach(function(b){b.classList.toggle('active',b.textContent===LANG_LABELS[lang]);});
                var spec=_specCache;if(!spec)return;
                var key=_activeOpKey;if(!key)return;
                var parts=key.split('__');var m=parts[0];var p=parts.slice(1).join('__');
                var op=spec.paths&&spec.paths[p]&&spec.paths[p][m];
                if(op)renderCode(p,m,op,spec,_server);
            };

            function renderCode(path,method,op,spec,server){
                var wrap=document.getElementById('ax-code-wrap');if(!wrap)return;
                var code=genCode(_activeLang,server||'http://localhost',path,method,op,spec);
                wrap.innerHTML='<pre class="ax-code">'+hlJson(escH(code))+'</pre>'
                    +'<button onclick="apexCopyCode(this)" class="ax-code-copy-btn" data-code="'+escH(code)+'">Copy</button>';
            }

            window.apexCopyCode=function(btn){
                fb(btn.dataset.code);btn.textContent='Copied!';btn.style.color='var(--green)';
                setTimeout(function(){btn.textContent='Copy';btn.style.color='';},1800);
            };

            function genCode(lang,server,path,method,op,spec){
                var url=server+path;var hasBody=['post','put','patch'].includes(method);
                var ct=(op.requestBody&&op.requestBody.content)||{};var bodyObj=null;
                if(hasBody&&ct['application/json']&&ct['application/json'].schema)bodyObj=buildExample(ct['application/json'].schema,spec);
                var hasSec=op.security===undefined||(op.security&&op.security.length>0);
                switch(lang){
                    case 'curl':{
                        var c="curl -X "+method.toUpperCase()+" \\\n  '"+url+"'";
                        c+=" \\\n  -H 'Accept: application/json'";
                        if(hasSec)c+=" \\\n  -H 'Authorization: Bearer {your_token}'";
                        if(bodyObj){c+=" \\\n  -H 'Content-Type: application/json'";c+=" \\\n  -d '"+JSON.stringify(bodyObj,null,2)+"'";}
                        return c;
                    }
                    case 'js':{
                        var o="const response = await fetch('"+url+"', {\n  method: '"+method.toUpperCase()+"',\n  headers: {\n    'Accept': 'application/json',";
                        if(hasSec)o+="\n    'Authorization': 'Bearer {your_token}',";
                        if(bodyObj)o+="\n    'Content-Type': 'application/json',";
                        o+="\n  }"+(bodyObj?",\n  body: JSON.stringify("+JSON.stringify(bodyObj,null,2)+")":"")+"\n});\nconst data = await response.json();";
                        return o;
                    }
                    case 'python':{
                        var py="import requests\n\nresponse = requests."+method.toLowerCase()+"(\n    '"+url+"',\n    headers={'Accept': 'application/json'"+(hasSec?",'Authorization': 'Bearer {your_token}'":"")+"},"+(bodyObj?"\n    json="+JSON.stringify(bodyObj,null,2):"");
                        return py+"\n)\ndata = response.json()";
                    }
                    case 'php':{
                        var php="$response = (new \\GuzzleHttp\\Client())\n    ->"+method.toLowerCase()+"('"+url+"', [\n        'headers' => ['Accept' => 'application/json'"+(hasSec?", 'Authorization' => 'Bearer {your_token}'"  :"")+"],"+(bodyObj?"\n        'json' => "+JSON.stringify(bodyObj,null,2):"");
                        return php+"\n    ]);\n$data = json_decode((string) $response->getBody(), true);";
                    }
                    case 'go':{
                        var go="package main\n\nimport (\n\t\"fmt\"\n\t\"io\"\n\t\"net/http\"\n"+(bodyObj?"\t\"bytes\"\n\t\"encoding/json\"\n":"")+"\n)\n\nfunc main() {\n";
                        if(bodyObj){go+="\tpayload, _ := json.Marshal("+JSON.stringify(bodyObj)+")\n";go+="\treq, _ := http.NewRequest(\""+method.toUpperCase()+"\", \""+url+"\", bytes.NewBuffer(payload))\n";}
                        else go+="\treq, _ := http.NewRequest(\""+method.toUpperCase()+"\", \""+url+"\", nil)\n";
                        go+="\treq.Header.Set(\"Accept\", \"application/json\")\n";
                        if(hasSec)go+="\treq.Header.Set(\"Authorization\", \"Bearer {your_token}\")\n";
                        if(bodyObj)go+="\treq.Header.Set(\"Content-Type\", \"application/json\")\n";
                        go+="\tclient := &http.Client{}\n\tresp, err := client.Do(req)\n\tif err != nil { panic(err) }\n\tdefer resp.Body.Close()\n\tbody, _ := io.ReadAll(resp.Body)\n\tfmt.Println(string(body))\n}";
                        return go;
                    }
                    default: return '// '+lang;
                }
            }

            function buildExample(schema,spec,depth){
                if(!schema)return null;depth=depth||0;if(depth>3)return null;
                if(schema['$ref']){var r=resolveRef(schema['$ref'],spec);return r?buildExample(r,spec,depth+1):null;}
                if(schema.example!=null)return schema.example;
                if(schema.allOf)return buildExample(schema.allOf[0],spec,depth+1);
                var t=schema.type||(schema.properties?'object':'any');
                switch(t){
                    case 'object':{var o={};for(var k in schema.properties||{})o[k]=buildExample(schema.properties[k],spec,depth+1);return o;}
                    case 'array':return [buildExample(schema.items||{},spec,depth+1)];
                    case 'integer':return 1;case 'number':return 1.0;case 'boolean':return true;case 'null':return null;
                    default:return schema.enum?schema.enum[0]:'string';
                }
            }

            window.axSend=function(server,path,method){
                var btn=document.getElementById('axi-send'),result=document.getElementById('axi-result');
                if(!btn||!result)return;
                var authType=document.getElementById('axi-auth-type');
                var auth=document.getElementById('axi-auth');
                var body=document.getElementById('axi-body');
                var url=server+path;
                var specParams=(_specCache&&_specCache.paths&&_specCache.paths[path]&&_specCache.paths[path][method]&&_specCache.paths[path][method].parameters)||[];
                specParams.forEach(function(p){
                    var el=document.getElementById('axi-'+p.name);if(!el||!el.value)return;
                    if(p.in==='path')url=url.replace('{'+p.name+'}',encodeURIComponent(el.value));
                });
                var qp=specParams.filter(function(p){return p.in==='query';}).map(function(p){var el=document.getElementById('axi-'+p.name);return el&&el.value?p.name+'='+encodeURIComponent(el.value):'';}).filter(Boolean).join('&');
                if(qp)url+='?'+qp;
                var headers={'Accept':'application/json'};
                if(auth&&auth.value){
                    var at=authType?authType.value:'bearer';
                    if(at==='bearer')headers['Authorization']='Bearer '+auth.value;
                    else if(at==='basic')headers['Authorization']='Basic '+btoa(auth.value);
                    else if(at==='apikey')headers['X-API-Key']=auth.value;
                }
                specParams.filter(function(p){return p.in==='header';}).forEach(function(p){var el=document.getElementById('axi-h-'+p.name);if(el&&el.value)headers[p.name]=el.value;});
                var hasBodyMethod=!['get','head'].includes(method);
                if(body&&body.value&&hasBodyMethod)headers['Content-Type']='application/json';
                var opts={method:method.toUpperCase(),headers:headers};
                if(body&&body.value&&hasBodyMethod)opts.body=body.value;
                btn.disabled=true;btn.textContent='Sending…';
                var t0=Date.now();
                result.innerHTML='<div class="axui-loading-state"><div class="axui-spinner"></div><span>Waiting for response…</span></div>';
                fetch(url,opts).then(function(r){
                    var ms=Date.now()-t0;var sc=r.status;
                    var cls=sc<300?'ax-res-s-ok':sc<400?'ax-res-s-info':sc<500?'ax-res-s-warn':'ax-res-s-err';
                    var hdrLines='';r.headers.forEach(function(v,n){hdrLines+=escH(n)+': '+escH(v)+'\n';});
                    return r.text().then(function(raw){
                        var fmt=raw;try{fmt=JSON.stringify(JSON.parse(raw),null,2);}catch(e){}
                        result.innerHTML='<div class="ax-res-panel">'
                            +'<div class="ax-res-status-bar"><span class="'+cls+'">'+sc+' '+escH(r.statusText)+'</span><span class="ax-res-ms">'+ms+'ms</span></div>'
                            +(hdrLines?'<details class="ax-res-headers"><summary style="font-size:11px;color:var(--t3);padding:6px 10px;cursor:pointer;user-select:none">Response headers</summary><pre style="padding:4px 10px 8px;font-size:11px;color:var(--t3);overflow-x:auto">'+hdrLines+'</pre></details>':'')
                            +'<div class="ax-res-body"><pre class="ax-res-pre">'+hlJson(escH(fmt))+'</pre></div></div>';
                    });
                }).catch(function(err){
                    result.innerHTML='<div class="ax-res-panel"><div class="ax-res-status-bar"><span class="ax-res-s-err">Network Error: '+escH(err.message)+'</span></div><div class="ax-res-body" style="font-size:12px;color:var(--t3);padding:8px">Check CORS headers or verify the server is reachable.</div></div>';
                }).finally(function(){btn.disabled=false;btn.textContent='Send Request';});
            };

            function hlJson(s){
                return s.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,function(m){
                    var c='ax-n';
                    if(/^"/.test(m)){c=/:$/.test(m)?'ax-k':'ax-s';}
                    else if(/true|false/.test(m))c='ax-b';
                    else if(/null/.test(m))c='ax-null';
                    return '<span class="'+c+'">'+m+'</span>';
                });
            }

            init();
        }

        })();
        JS;
    }

    // ── SVG Icons ─────────────────────────────────────────────────────────────

    private function iconBolt(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M10.5 2.25L3.75 10.5H8.25L7.5 15.75L14.25 7.5H9.75L10.5 2.25Z" fill="url(#bolt-g)" stroke="url(#bolt-g)" stroke-width=".4" stroke-linejoin="round"/><defs><linearGradient id="bolt-g" x1="3.75" y1="2.25" x2="14.25" y2="15.75" gradientUnits="userSpaceOnUse"><stop stop-color="#818cf8"/><stop offset="1" stop-color="#c084fc"/></linearGradient></defs></svg>';
    }

    private function iconDownload(): string
    {
        return '<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M6.5 1v7.5M3.5 6l3 3 3-3M1.5 11h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    private function iconCopy(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><rect x="1" y="4.5" width="8.5" height="8.5" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M4.5 4.5V3A1.5 1.5 0 0 1 6 1.5h5A1.5 1.5 0 0 1 12.5 3v5A1.5 1.5 0 0 1 11 9.5H9.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
    }

    private function iconCheck(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M2 7L5.5 10.5L12 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    private function iconSearch(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 10L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
    }

    private function iconMoon(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2a6 6 0 1 0 6 6 4.5 4.5 0 0 1-6-6Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
    }

    private function iconSun(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.05 3.05l1.41 1.41M11.54 11.54l1.41 1.41M3.05 12.95l1.41-1.41M11.54 4.46l1.41-1.41" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
    }

    private function iconServer(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="2" width="14" height="4" rx="1.5" stroke="currentColor" stroke-width="1.4"/><rect x="1" y="10" width="14" height="4" rx="1.5" stroke="currentColor" stroke-width="1.4"/><circle cx="12.5" cy="4" r="1" fill="currentColor"/><circle cx="12.5" cy="12" r="1" fill="currentColor"/></svg>';
    }
}
