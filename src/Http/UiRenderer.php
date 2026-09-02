<?php

declare(strict_types=1);

namespace ApexDocs\Http;

use ApexDocs\Config;

/**
 * Renders the full API documentation page.
 * Pure PHP  no template engine, and the page makes no outbound request.
 */
final class UiRenderer
{
    private const THEMES = ['dark', 'light', 'auto'];

    /**
     * Resolve a requested theme to one we can render, falling back to the
     * configured value. This backs the documented `?theme=` deep link, so the
     * value reaching the page is never raw query input.
     */
    public static function normalizeTheme(mixed $requested, string $fallback = 'dark'): string
    {
        if (is_string($requested) && in_array(strtolower($requested), self::THEMES, true)) {
            return strtolower($requested);
        }

        return in_array(strtolower($fallback), self::THEMES, true) ? strtolower($fallback) : 'dark';
    }

    /**
     * @param  string|null  $theme  overrides Config::$theme when the client asks
     *                              for a specific palette
     */
    public function render(string $specUrl, Config $config, ?string $theme = null): string
    {
        $theme    = self::normalizeTheme($theme, $config->theme);
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
            'theme'           => $theme,
            'tryItOut'        => $config->tryItOutEnabled,
            'defaultLanguage' => $config->defaultLanguage,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $bannerHtml = $this->announcementBanner($config);
        $toolbar    = $config->showToolbar
            ? $this->toolbar($title, $version, $spec, $yaml, $postman, $insomnia, $bruno, $specJs, $config, $specUrl)
            : '';
        $content    = $this->apexNativeUi();
        $customCss  = $this->customCss($config);
        $themeAttr  = $theme === 'auto' ? '' : ' data-theme="'.$theme.'"';

        // Body order is the phone order, and it is the only order: the layout is
        // decided entirely by CSS grid placement, so nothing here is ever
        // reparented, and no element's height is computed in PHP. The three
        // grid rows (banner / bar / app) collapse to zero when their optional
        // child is absent, which is why dismissing the banner cannot leave a
        // dead strip behind.
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en"{$themeAttr}>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="color-scheme" content="dark light">
            <title>{$title}  Docs</title>
            <style>{$this->css()}</style>
            {$customCss}
        </head>
        <body>
            <a class="ax-skip" href="#axui-content">Skip to documentation</a>
            <div id="apex-progress" aria-hidden="true"><div id="apex-progress-bar"></div></div>
            {$bannerHtml}
            {$toolbar}
            {$content}
            <div id="apex-toast" role="status" aria-live="polite"><span id="apex-toast-msg"></span></div>
            <p id="apex-live" class="ax-sr-only" role="status" aria-live="polite"></p>
            <p id="apex-alert" class="ax-sr-only" role="alert"></p>
            {$this->commandPalette()}
            <script>var APEX_CFG={$cfgJs};</script>
            <script>{$this->js()}</script>
        </body>
        </html>
        HTML;
    }

    /**
     * Author-supplied CSS goes into the page verbatim  HTML-escaping it would
     * mangle ordinary selectors (`.a > .b` becomes `.a &gt; .b`). Only a
     * `</style` sequence, which would end the block and open the door to
     * markup injection, is neutralised.
     */
    private function customCss(Config $config): string
    {
        if ($config->customCss === '') {
            return '';
        }

        $css = preg_replace('#</\s*style#i', '<\\/style', $config->customCss) ?? '';

        return '<style>'.$css.'</style>';
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
        <div id="apex-banner" data-type="{$type}" role="region" aria-label="Announcement">
            <span class="apex-banner-icon" aria-hidden="true">{$this->bannerIcon($config->announcementBannerType)}</span>
            <span class="apex-banner-msg">{$msg}</span>
            <button class="apex-banner-close" onclick="axBannerClose(this)" aria-label="Dismiss" type="button">✕</button>
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
        string $title,
        string $version,
        string $spec,
        string $yaml,
        string $postman,
        string $insomnia,
        string $bruno,
        string $specJs,
        Config $config,
        string $specUrlRaw,
    ): string {
        $brand   = $this->brandSection($title, $version, $config, $specUrlRaw);
        $actions = $this->actionsSection($spec, $yaml, $postman, $insomnia, $bruno, $specJs);

        return <<<HTML
        <header id="apex-bar" role="banner">
            {$brand}
            {$actions}
        </header>
        HTML;
    }

    private function brandSection(string $title, string $version, Config $config, string $specUrlRaw): string
    {
        $logo = $config->customLogo !== ''
            ? '<img src="'.htmlspecialchars($config->customLogo, ENT_QUOTES, 'UTF-8').'" class="apex-custom-logo" alt="Logo">'
            : $this->iconBolt();

        // The docs root, derived from the spec URL  "." would resolve to the
        // site root whenever the docs are mounted without a trailing slash.
        $home = htmlspecialchars(
            preg_replace('#/spec\.json$#', '', $specUrlRaw) ?: '.',
            ENT_QUOTES,
            'UTF-8',
        );

        // The drawer trigger is authored first in `.apex-left`, not last in the
        // document like the FAB it replaces: on a phone it is the first thing
        // Tab reaches, and it can never paint over the drawer it opens.
        return <<<HTML
        <div class="apex-left">
            <button id="apex-nav-btn" class="apex-icon-btn" onclick="axSidebarToggle()" type="button"
                    aria-controls="axui-sidebar" aria-expanded="false" aria-label="Open navigation">
                <svg width="18" height="18" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>
            <a href="{$home}" class="apex-brand" title="ApexDocs">
                {$logo}
                <span class="apex-brand-text">ApexDocs</span>
            </a>
            <span class="apex-vdiv apex-a-lg"></span>
            <span class="apex-api-title" title="{$title}">{$title}</span>
            <span class="apex-version">{$version}</span>
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
        $kbdIcon  = $this->iconKeyboard();
        $exports  = $this->exportLinks($spec, $yaml, $postman, $insomnia, $bruno, 'apex-export-item');
        $moreExp  = $this->exportLinks($spec, $yaml, $postman, $insomnia, $bruno, 'apex-more-item');

        // Widths, not luck, decide what fits: `.apex-a-md` appears at >=600px and
        // `.apex-a-lg` at >=900px, and everything hidden at a given width is
        // reachable from the `⋯` menu at that width. Nothing is ever both hidden
        // and unreachable.
        //
        // The env hide sits on the BUTTON, not on `.apex-env-wrap`: the wrapper is
        // the popover's positioned parent, and a display:none wrapper would take
        // the popover with it  leaving the `⋯` menu's Server entry with nothing
        // to open.
        return <<<HTML
        <div class="apex-right">
            <button class="apex-icon-btn" id="apex-palette-btn" title="Search endpoints (⌘K)" aria-label="Search">
                {$srchIcon}
                <kbd class="apex-kbd">⌘K</kbd>
            </button>
            <button class="apex-icon-btn apex-theme-btn apex-a-md" id="apexThemeBtn" title="Toggle theme" aria-label="Toggle theme">
                <span class="apex-icon-moon">{$moonIcon}</span>
                <span class="apex-icon-sun">{$sunIcon}</span>
            </button>
            <div class="apex-env-wrap">
                <button class="apex-icon-btn apex-a-md" id="apexEnvBtn" title="Switch server environment" aria-label="Environments">
                    {$srvIcon}
                </button>
                <div id="axui-env-popover" hidden>
                    <div class="axui-env-title">Server Environment</div>
                    <div id="axui-env-list"></div>
                </div>
            </div>
            <button class="apex-icon-btn apex-a-md" onclick="axShortcutsOpen()" title="Keyboard shortcuts (?)" aria-label="Shortcuts" type="button">
                {$kbdIcon}
            </button>
            <span class="apex-vdiv apex-a-lg"></span>
            <button class="apex-icon-btn apex-copy-btn apex-a-lg" title="Copy spec URL" aria-label="Copy spec URL"
                    onclick="apexCopy(this)" data-url={$specJs}>
                <span class="apex-icon-copy">{$copyIcon}</span>
                <span class="apex-icon-check">{$chkIcon}</span>
            </button>
            <div class="apex-export-wrap apex-a-lg" id="apexExportWrap">
                <button class="apex-export-trigger" id="apexExportBtn" aria-label="Export" title="Export spec">
                    {$dlIcon}
                    <span>Export</span>
                    <span class="apex-chevron">▾</span>
                </button>
                <div class="apex-export-menu" id="apexExportMenu" role="menu">
                    {$exports}
                </div>
            </div>
            <button class="apex-icon-btn" id="apex-more-btn" type="button" aria-haspopup="menu" aria-expanded="false" title="More" aria-label="More actions">⋯</button>
            <dialog id="apex-more" aria-label="More actions">
                <div class="apex-more-box">
                    <button class="apex-more-item apex-more-md" type="button" data-more="theme">{$moonIcon}<span>Toggle theme</span></button>
                    <button class="apex-more-item apex-more-md" type="button" data-more="env">{$srvIcon}<span>Server environment</span></button>
                    <button class="apex-more-item apex-more-md" type="button" data-more="kbd">{$kbdIcon}<span>Keyboard shortcuts</span></button>
                    <button class="apex-more-item" type="button" data-more="copy" data-url={$specJs}>{$copyIcon}<span>Copy spec URL</span></button>
                    <div class="apex-export-divider"></div>
                    {$moreExp}
                </div>
            </dialog>
        </div>
        HTML;
    }

    /**
     * The five download links, shared by the Export dropdown and the `⋯` menu so
     * the two lists cannot drift apart.
     */
    private function exportLinks(
        string $spec,
        string $yaml,
        string $postman,
        string $insomnia,
        string $bruno,
        string $class,
    ): string {
        return <<<HTML
        <a href="{$spec}"     class="{$class}" download="openapi.json">OpenAPI JSON</a>
                    <a href="{$yaml}"     class="{$class}" download="openapi.yaml">OpenAPI YAML</a>
                    <div class="apex-export-divider"></div>
                    <a href="{$postman}"  class="{$class}" download="postman-collection.json">Postman v2.1</a>
                    <a href="{$insomnia}" class="{$class}" download="insomnia.json">Insomnia</a>
                    <a href="{$bruno}"    class="{$class}" download="bruno-collection.json">Bruno</a>
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
                    <svg id="apex-palette-search-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M10 10L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <input id="apex-palette-input" type="search" placeholder="Search endpoints, schemas…" autocomplete="off" spellcheck="false">
                    <kbd class="apex-kbd" aria-hidden="true">Esc</kbd>
                    <button id="apex-palette-close" type="button" onclick="apexPaletteClose()" aria-label="Close search">✕</button>
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

    // ── Native Apex UI ────────────────────────────────────────────────────────

    private function apexNativeUi(): string
    {
        return <<<HTML
        <div id="axui">
            <nav id="axui-sidebar" aria-label="API reference">
                <div id="axui-sidebar-search">
                    <div class="axui-search-inner">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" class="axui-search-icon" aria-hidden="true">
                            <circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M10 10L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <label class="ax-sr-only" for="axui-filter">Filter endpoints</label>
                        <input id="axui-filter" type="search" placeholder="Filter endpoints…" autocomplete="off">
                        <kbd class="apex-kbd apex-kbd-slash" aria-hidden="true">/</kbd>
                    </div>
                    <button id="axui-nav-close" class="apex-icon-btn" onclick="axSidebarClose()" type="button" aria-label="Close navigation">✕</button>
                    <p id="axui-filter-count" class="ax-sr-only" role="status"></p>
                </div>
                <div id="axui-sidebar-body">
                    <div class="axui-loading-state">
                        <div class="axui-spinner" aria-hidden="true"></div>
                        <span>Loading spec…</span>
                    </div>
                </div>
                <div id="axui-sidebar-footer"></div>
            </nav>
            <main id="axui-content" tabindex="-1">
                <div id="ax-ctx" hidden></div>
                <div id="axui-content-inner">
                    <article id="axui-doc" aria-busy="true"></article>
                    <div id="axui-panel-slot">
                        <aside id="axui-panel" aria-labelledby="ax-panel-h" data-mode="tabs">
                            <h2 id="ax-panel-h" class="ax-panel-h">Request console</h2>
                            <div id="axui-panel-inner"></div>
                        </aside>
                    </div>
                </div>
            </main>
        </div>
        <div id="axui-sb-backdrop" onclick="axSidebarClose()"></div>
        <div id="ax-shortcuts" role="dialog" aria-modal="true" aria-label="Keyboard shortcuts" hidden>
            <div class="ax-sc-backdrop" onclick="axShortcutsClose()"></div>
            <div class="ax-sc-box">
                <div class="ax-sc-header"><h3>Keyboard shortcuts</h3><button onclick="axShortcutsClose()" class="ax-sc-close" aria-label="Close">✕</button></div>
                <div class="ax-sc-grid">
                    <div class="ax-sc-row"><kbd>⌘</kbd><kbd>K</kbd><span>Search endpoints</span></div>
                    <div class="ax-sc-row"><kbd>/</kbd><span>Focus sidebar filter</span></div>
                    <div class="ax-sc-row"><kbd>j</kbd> / <kbd>k</kbd><span>Next / previous endpoint</span></div>
                    <div class="ax-sc-row"><kbd>g</kbd><span>Back to overview</span></div>
                    <div class="ax-sc-row"><kbd>t</kbd><span>Toggle theme</span></div>
                    <div class="ax-sc-row"><kbd>c</kbd><span>Copy current code sample</span></div>
                    <div class="ax-sc-row"><kbd>?</kbd><span>Show this dialog</span></div>
                    <div class="ax-sc-row"><kbd>Esc</kbd><span>Close any overlay</span></div>
                </div>
                <div class="ax-sc-footer">
                    <span class="ax-sc-note">This browser remembers your theme, environment, auth token and request history for this API.</span>
                    <button type="button" class="ax-sc-clear" onclick="axClearStored()">Clear stored data</button>
                </div>
            </div>
        </div>
        HTML;
    }

    // ── CSS ───────────────────────────────────────────────────────────────────

    /**
     * The stylesheet, in one method per area of the page. The parts are literal
     * slices of what used to be a single 520-line heredoc, and the cascade still
     * depends on their order: a rule can only be overridden by a rule in a later
     * part. Keep the concatenation below in this sequence.
     */
    private function css(): string
    {
        // The palette lives in Theme so both modes define the same token set and
        // the system-preference fallback cannot drift from the explicit choice.
        $palette = Theme::css();

        return implode("\n", [
            $palette,
            $this->cssShell(),
            $this->cssNav(),
            $this->cssDoc(),
            $this->cssSchema(),
            $this->cssPanel(),
            $this->cssResponsive(),
        ]);
    }

    /**
     * Reset, progress bar, announcement banner, toolbar and its export menu,
     * command palette, HTTP method badges.
     */
    private function cssShell(): string
    {
        return <<<'CSS'
        /* ── Z-INDEX LADDER  the only place these numbers are decided ──
           400  #apex-progress
           1000 #apex-bar
           1150 #axui-sb-backdrop
           1200 #axui-sidebar (drawer)
           1400 toolbar popovers: .apex-export-menu, #axui-env-popover
           1500 #apex-toast
           1600 overlays that still hand-roll their own backdrop
                (#apex-palette, #ax-shortcuts, .ax-skip)  WS3 replaces these
                with <dialog>, whose top layer sits above everything anyway.
           Inside #axui-content there is a second, local sticky stack, topped by
           #ax-ctx at 6. Nothing there may exceed 9. */

        /* ── Reset ── */
        *{box-sizing:border-box;margin:0;padding:0}
        html{background:var(--bg)}
        body{min-height:100dvh;background:var(--bg);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,'Inter',sans-serif;-webkit-font-smoothing:antialiased;color:var(--t1)}

        /* Visually hidden but announced  labels, live regions, glyph names. */
        .ax-sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0}

        /* ── Skip link ── */
        .ax-skip{position:fixed;top:8px;left:8px;z-index:1600;padding:10px 14px;border-radius:var(--r);background:var(--elev);border:1px solid var(--border-s);color:var(--t1);font-size:13px;text-decoration:none;transform:translateY(calc(-100% - 12px));transition:transform .15s}
        .ax-skip:focus{transform:none}

        /* ── Loading bar ── */
        #apex-progress{position:fixed;top:0;left:0;right:0;height:2px;z-index:400;overflow:hidden}
        #apex-progress-bar{height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6,#a855f7,#ec4899,#f59e0b,#6366f1);background-size:300% 100%;animation:apex-shimmer 1.8s linear infinite}
        @keyframes apex-shimmer{0%{background-position:100% 0}100%{background-position:-200% 0}}

        /* ── Announcement banner ──
           min-height, never height: the message is author-supplied and wraps on
           a phone. It is grid row 1, so its real height is what pushes the rest
           of the page down  and removing the node collapses the row. */
        #apex-banner{
            display:flex;align-items:center;gap:10px;padding:9px 16px;min-height:40px;
            font-size:13px;font-weight:500;
        }
        #apex-banner[data-type="info"]{background:var(--accent-soft);border-bottom:1px solid var(--accent-soft-b);color:var(--accent-t)}
        #apex-banner[data-type="warning"]{background:var(--amber-soft);border-bottom:1px solid var(--amber-b);color:var(--amber-t)}
        #apex-banner[data-type="error"]{background:var(--red-soft);border-bottom:1px solid var(--red-b);color:var(--red-t)}
        .apex-banner-msg{flex:1;min-width:0}
        .apex-banner-close{background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:14px;padding:4px;border-radius:4px;transition:opacity .15s;flex-shrink:0}.apex-banner-close:hover{opacity:1}

        /* ── Toolbar ──
           `--bar-bg` is 93% opaque, so the backdrop-filter this rule used to
           carry bought a blur nobody could see at the cost of a compositing
           layer that repaints on every sticky scroll frame on iOS. */
        #apex-bar{
            position:sticky;top:0;z-index:1000;height:var(--bar-h);
            display:flex;align-items:center;gap:2px;padding:0 12px;
            background:var(--bar-bg);border-bottom:1px solid var(--border)
        }
        #apex-bar::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:1px;
            background:linear-gradient(90deg,transparent,var(--accent) 25%,var(--accent2) 75%,transparent);opacity:.55;
            pointer-events:none}

        /* Brand  `.apex-left` is the flexible half so the fixed-width action
           cluster on the right can never be pushed off the viewport. */
        .apex-left{display:flex;align-items:center;gap:9px;flex:1 1 auto;min-width:0;overflow:hidden}
        .apex-brand{display:flex;align-items:center;gap:7px;text-decoration:none;flex-shrink:0;padding:4px 6px;border-radius:var(--r);transition:background .15s}
        .apex-brand:hover{background:var(--s1)}
        .apex-brand-text{font-size:13.5px;font-weight:700;letter-spacing:-.025em;background:linear-gradient(135deg,var(--brand-1),var(--brand-2),var(--brand-3));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;white-space:nowrap}
        .apex-custom-logo{height:22px;width:auto;border-radius:4px}
        .apex-vdiv{width:1px;height:18px;background:var(--border);flex-shrink:0;margin:0 2px}
        .apex-api-title{display:none;font-size:13px;font-weight:500;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:170px}
        .apex-version{display:none;font-size:10.5px;font-weight:600;letter-spacing:.02em;padding:2px 8px;border-radius:999px;background:var(--accent-soft);border:1px solid var(--accent-soft-b);color:var(--accent-t);white-space:nowrap;flex-shrink:0}
        /* `.apex-a-md` / `.apex-a-lg` are hidden at the TOP of cssResponsive(),
           not here: the rules below set `display` on the very same elements at
           equal specificity, so a hide declared here would lose to whichever of
           them is authored last. */

        /* Right actions. `align-self:stretch` is what lets a child stretch to the
           full bar height and anchor a popover to the bar's bottom edge; the
           buttons stay centred on `align-items:center`. */
        .apex-right{display:flex;align-items:center;align-self:stretch;gap:4px;flex-shrink:0;margin-left:auto}
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
        /* `auto` follows the system, and says so: the glyph tracks what is
           actually rendered and a dot marks that the choice is automatic. */
        [data-theme-pref="auto"] .apex-theme-btn::after{content:'';position:absolute;right:3px;bottom:3px;width:4px;height:4px;border-radius:50%;background:var(--accent)}
        .apex-theme-btn{position:relative}
        @media (prefers-color-scheme:light){
            html:not([data-theme]) .apex-theme-btn .apex-icon-moon{display:none}
            html:not([data-theme]) .apex-theme-btn .apex-icon-sun{display:flex}
        }

        /* Export dropdown */
        .apex-export-wrap{position:relative}
        .apex-export-trigger{display:flex;align-items:center;gap:6px;padding:5px 10px;border-radius:var(--r);background:var(--s1);border:1px solid var(--border);color:var(--t2);font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;white-space:nowrap}
        .apex-export-trigger:hover{color:var(--t1);background:var(--s2);border-color:var(--border-s)}
        .apex-chevron{font-size:10px;transition:transform .15s}
        .apex-export-wrap.open .apex-chevron{transform:rotate(180deg)}
        /* `visibility` alongside the opacity, or the four links inside a closed
           menu stay in the tab order: invisible focus stops between the toolbar
           and the search field, with no way to tell where focus went. Kept as
           `visibility` rather than `display` so the transition still runs. */
        .apex-export-menu{position:absolute;right:0;top:calc(100% + 6px);min-width:160px;max-width:min(240px,calc(100vw - 24px));background:var(--elev);border:1px solid var(--border-s);border-radius:10px;padding:4px;box-shadow:var(--shadow-1);opacity:0;visibility:hidden;transform:translateY(-6px) scale(.97);pointer-events:none;transition:opacity .15s,transform .15s,visibility .15s;z-index:1400}
        .apex-export-wrap.open .apex-export-menu{opacity:1;visibility:visible;transform:none;pointer-events:all}
        .apex-export-item{display:block;padding:7px 12px;border-radius:6px;font-size:12.5px;color:var(--t2);text-decoration:none;transition:all .12s}
        .apex-export-item:hover{color:var(--t1);background:var(--s2)}
        .apex-export-divider{height:1px;background:var(--border);margin:4px 0}

        /* ── Overflow (⋯) menu ──
           Holds whatever the current width hides from the bar. A <dialog> so the
           focus trap, Escape and the backdrop come from the platform.
           A <dialog> is in the top layer, outside the grid, so it cannot see
           where the banner pushed the toolbar: `--anchor-y` is the toolbar's
           measured bottom edge, written once per open. A static offset from the
           viewport origin painted the sheet over the bar whenever a banner was
           present. `display:flex` needs the :not([open]) guard  an id-level
           `display` would otherwise beat the UA rule that hides a shut
           dialog. */
        #apex-more{border:none;padding:var(--anchor-y,64px) 8px 8px;background:none;max-width:none;max-height:none;width:100%;height:100%;color:inherit;display:flex;align-items:flex-start;justify-content:flex-end}
        #apex-more:not([open]){display:none}
        #apex-more::backdrop{background:var(--backdrop)}
        .apex-more-box{width:100%;max-width:320px;max-height:100%;overflow-y:auto;background:var(--elev);border:1px solid var(--border-s);border-radius:12px;padding:6px;box-shadow:var(--shadow-2)}
        .apex-more-item{display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border-radius:8px;background:none;border:none;color:var(--t2);font-family:inherit;font-size:13px;text-align:left;text-decoration:none;cursor:pointer;transition:background .12s}
        .apex-more-item:hover{background:var(--s2);color:var(--t1)}
        .apex-more-item svg{flex-shrink:0}

        /* ── Environment popover ──
           `align-self:stretch` makes the wrapper the full height of the bar, so
           the >=600px `top:calc(100% + 6px)` lands 6px under the bar's bottom
           edge wherever the banner has pushed the bar to. Below 600px the
           trigger lives in the `⋯` menu, so the list is a bottom sheet instead
           of a popover anchored to a button that is not on screen. */
        .apex-env-wrap{position:relative;display:flex;align-items:center;align-self:stretch}
        #axui-env-popover{position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));background:var(--elev);border:1px solid var(--border-s);border-radius:10px;padding:8px;box-shadow:var(--shadow-1);max-height:60dvh;overflow-y:auto;z-index:1400}
        #axui-env-popover[hidden]{display:none}

        /* ── Command Palette ──
           Sized in dvh and laid out as a flex column: the on-screen keyboard
           shrinks the results list instead of pushing the input off a page that
           cannot scroll. */
        #apex-palette{position:fixed;inset:0;z-index:1600;display:flex;align-items:flex-start;justify-content:center;padding:max(8vh,40px) 12px 12px}
        #apex-palette[hidden]{display:none}
        #apex-palette-backdrop{position:fixed;inset:0;background:var(--backdrop)}
        #apex-palette-box{position:relative;display:flex;flex-direction:column;width:100%;max-width:580px;max-height:calc(100dvh - max(8vh,40px) - 12px);background:var(--elev);border:1px solid var(--border-s);border-radius:14px;box-shadow:var(--shadow-2);overflow:hidden}
        #apex-palette-search-wrap{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);flex-shrink:0}
        #apex-palette-search-icon{color:var(--t3);flex-shrink:0}
        #apex-palette-input{flex:1;min-width:0;background:none;border:none;outline:none;font-size:16px;color:var(--t1);caret-color:var(--accent)}
        #apex-palette-input::placeholder{color:var(--t3)}
        #apex-palette-close{display:flex;align-items:center;justify-content:center;width:44px;height:44px;margin:-6px -6px -6px 0;flex-shrink:0;background:none;border:none;border-radius:var(--r);color:var(--t3);font-size:15px;cursor:pointer}
        #apex-palette-close:hover{background:var(--s2);color:var(--t1)}
        #apex-palette-results{flex:1;min-height:0;max-height:none;overflow-y:auto;padding:6px}
        .apex-pal-item{display:grid;grid-template-columns:auto minmax(0,1fr);grid-template-areas:"m path" ". sum";align-items:center;gap:2px 10px;padding:9px 10px;border-radius:8px;cursor:pointer;text-decoration:none;transition:background .1s}
        .apex-pal-item:hover,.apex-pal-item.focused{background:var(--s2)}
        .apex-pal-item .axm{grid-area:m}
        .apex-pal-item .apex-pal-path{grid-area:path;font-size:13px;color:var(--t1);font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .apex-pal-item .apex-pal-sum{grid-area:sum;font-size:12px;color:var(--t3);max-width:none;white-space:normal}
        .apex-pal-item .apex-pal-sum:empty{display:none}
        .apex-pal-group{padding:6px 10px 4px;font-size:11px;font-weight:600;color:var(--t3);letter-spacing:.05em;text-transform:uppercase}
        .apex-pal-empty{padding:32px;text-align:center;color:var(--t3);font-size:14px}
        #apex-palette-footer{display:none;gap:16px;padding:10px 16px;border-top:1px solid var(--border);font-size:11px;color:var(--t3);flex-shrink:0}
        #apex-palette-footer kbd{padding:1px 5px;border-radius:4px;background:var(--s2);border:1px solid var(--border);font-family:inherit;margin-right:4px}

        /* ── Method badges ── */
        .axm{display:inline-flex;align-items:center;justify-content:center;padding:2px 7px;border-radius:5px;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;flex-shrink:0;min-width:52px}
        .axm-get{background:var(--m-get);color:var(--m-get-c);border:1px solid var(--m-get-b)}
        .axm-post{background:var(--m-post);color:var(--m-post-c);border:1px solid var(--m-post-b)}
        .axm-put{background:var(--m-put);color:var(--m-put-c);border:1px solid var(--m-put-b)}
        .axm-patch{background:var(--m-patch);color:var(--m-patch-c);border:1px solid var(--m-patch-b)}
        .axm-delete{background:var(--m-delete);color:var(--m-delete-c);border:1px solid var(--m-delete-b)}
        .axm-head,.axm-options{background:var(--m-head);color:var(--m-head-c);border:1px solid var(--m-head-b)}

        CSS;
    }

    /**
     * The app shell container plus the sidebar: filter, scroller, tag groups,
     * endpoint rows and the overview block.
     */
    private function cssNav(): string
    {
        return <<<'CSS'
        /* ── App shell ──
           One column on a phone; a grid at >=900px. Neither mode computes a
           height: see cssResponsive(). */
        #axui{display:block;background:var(--bg)}
        #axui-sidebar{display:flex;flex-direction:column;min-height:0;border-right:1px solid var(--border);background:var(--bg)}
        #axui-sidebar-search{display:flex;align-items:center;gap:6px;padding:10px;border-bottom:1px solid var(--border);flex-shrink:0}
        .axui-search-inner{flex:1;min-width:0;display:flex;align-items:center;gap:8px;background:var(--inset);border:1px solid var(--border);border-radius:var(--r);padding:7px 10px;transition:border-color .15s}
        .axui-search-inner:focus-within{border-color:var(--focus)}
        .axui-search-icon{color:var(--t3);flex-shrink:0}
        /* 16px, not the designed 13px: below that iOS Safari zooms the viewport
           on focus, and this is the primary navigation control on a phone. */
        #axui-filter{flex:1;min-width:0;background:none;border:none;outline:none;font-size:16px;color:var(--t1);caret-color:var(--accent)}
        #axui-filter::placeholder{color:var(--t3)}
        .apex-kbd-slash{font-size:9px;opacity:.35}
        #axui-nav-close{flex-shrink:0;font-size:15px}
        #axui-sidebar-body{flex:1;overflow-y:auto;padding:8px 0}
        #axui-sidebar-body::-webkit-scrollbar{width:4px}
        #axui-sidebar-body::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px}

        /* Sidebar groups & items */
        .axg{margin-bottom:2px}
        .axg-count{font-size:10px;padding:1px 6px;border-radius:999px;background:var(--s2);color:var(--t3)}
        .axg-items{display:none}.axg.open .axg-items{display:block}
        /* An <a>, so: no underline, inherited colour, and the same box it had
           as a div. */
        .axi{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 20px;cursor:pointer;border-radius:0;transition:background .12s;position:relative;text-decoration:none;color:inherit}
        .axi:hover{background:var(--s1)}
        .axi.active{background:var(--accent-soft)}
        .axi.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:2px;background:var(--accent)}
        .axi-path{font-size:12px;color:var(--t2);font-family:'JetBrains Mono',monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;transition:color .12s}
        .axi:hover .axi-path,.axi.active .axi-path{color:var(--t1)}
        .axi-depr{opacity:.5;text-decoration:line-through}

        /* Sidebar overview section */
        .axs-overview{padding:12px 14px;border-bottom:1px solid var(--border);margin-bottom:4px}
        .axs-api-title{font-size:13px;font-weight:600;color:var(--t1);margin-bottom:4px}
        .axs-api-desc{font-size:12px;color:var(--t2);line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}

        CSS;
    }

    /**
     * The document pane: welcome screen, operation header, sections and the
     * parameters table.
     */
    private function cssDoc(): string
    {
        return <<<'CSS'
        /* ── Document pane ──
           The gutter lives on #axui-doc alone. It used to be stacked on three
           elements at once (#axui-content, #axui-content-inner and .ax-section),
           which left 282px of usable width on a 390px phone. */
        #axui-content{min-width:0;background:var(--bg);scroll-padding-top:calc(var(--ctx-h) + 12px)}
        #axui-content::-webkit-scrollbar{width:4px}
        #axui-content::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px}
        #axui-content-inner{min-width:0}
        /* clip, not hidden: `hidden` makes the article a scroll CONTAINER, which
           the browser is then free to scroll  focusing an off-screen cell in a
           wide table would slide the whole article sideways, with no scrollbar
           to undo it. `clip` clips at the same edge without creating a
           scrollport. Anything genuinely wide gets its own `.ax-tablewrap`. */
        /* Centred against a measure only while the article is a block in flow
           (below the rail threshold, where the viewport is the only bound). The
           auto margin is undone once it becomes a grid item — see >=1200px. */
        #axui-doc{max-width:var(--doc-max);margin-inline:auto;padding:16px var(--gutter) 28px;overflow-x:clip}
        [id]{scroll-margin-top:calc(var(--ctx-h) + 12px)}

        /* ── Operation context bar (filled in by the operation renderer) ──
           Below 900px the document is the scroller and #apex-bar is sticky at
           the viewport top, so this must pin BELOW it  `top:0` would put it
           behind the bar, which outranks it by 994 z-index tiers. At >=900px it
           is inside the #axui-content scrollport, whose own top already sits
           under the bar, so the offset goes back to 0 (see cssResponsive). */
        #ax-ctx{position:sticky;top:var(--bar-h);z-index:6;height:var(--ctx-h);display:flex;align-items:center;gap:8px;padding:0 var(--gutter);background:var(--bar-bg);border-bottom:1px solid var(--border);font-size:12px}
        #ax-ctx[hidden]{display:none}
        .axw-title{font-size:26px;font-weight:700;letter-spacing:-.025em;color:var(--t1);margin:0 0 6px}
        .axw-meta{display:flex;align-items:center;gap:10px;margin-bottom:16px}
        .axw-version{font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--accent-soft);border:1px solid var(--accent-soft-b);color:var(--accent-t)}
        .axw-openapi{font-size:11px;color:var(--t3)}
        .axw-desc{font-size:14px;color:var(--t2);line-height:1.7;margin-bottom:24px;max-width:580px}
        .axw-stats{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:28px}
        .axw-stat{display:flex;align-items:center;gap:8px;padding:12px 16px;background:var(--card);border:1px solid var(--border);border-radius:10px;min-width:0}
        .axw-stat-n{font-size:22px;font-weight:700;color:var(--t1);letter-spacing:-.02em}
        .axw-stat-l{font-size:11.5px;color:var(--t3);margin-top:1px}
        .axw-servers{margin-top:20px}
        .axw-servers-title{font-size:12px;font-weight:600;color:var(--t3);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px}
        .axw-server{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:var(--r);background:var(--card);border:1px solid var(--border);margin-bottom:6px;font-size:13px;color:var(--t2);font-family:monospace;overflow-wrap:anywhere}
        .axw-server-dot{width:6px;height:6px;border-radius:999px;background:var(--green-t);flex-shrink:0}
        .axw-hint{margin-top:24px;padding:16px;border-radius:var(--r);background:var(--accent-soft);border:1px solid var(--accent-soft-b);font-size:13px;color:var(--t2);text-align:center}

        /* Operation detail */
        .ax-op-header{display:flex;align-items:flex-start;gap:12px;margin-bottom:20px}
        .ax-op-title-wrap{flex:1;min-width:0}
        .ax-op-path{margin:0;font-size:18px;font-weight:600;font-family:'JetBrains Mono',monospace;color:var(--t1);letter-spacing:-.01em;word-break:break-all}
        .ax-op-summary{font-size:14px;color:var(--t2);margin-top:4px;line-height:1.5}
        .ax-depr-badge{display:inline-flex;align-items:center;font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:999px;background:rgba(245,158,11,.12);color:var(--amber);border:1px solid rgba(245,158,11,.3);margin-left:8px;vertical-align:middle}
        .ax-op-desc{font-size:14px;color:var(--t2);line-height:1.7;margin-bottom:20px;padding:14px 16px;background:var(--s1);border-left:2px solid var(--accent);border-radius:0 var(--r) var(--r) 0}
        .ax-section{margin-bottom:24px}
        .ax-section-title{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:10px;display:flex;align-items:center;gap:6px}
        .ax-section-title::after{content:'';flex:1;height:1px;background:var(--border)}

        /* ── Parameters ──
           Base is card mode: one block per parameter, the header row moved into
           each cell's ::before. A 5-column table cannot be read at 358px, and
           panning the whole pane sideways to reach the Description column is
           worse than stacking. `.ax-tablewrap` is the horizontal scroller for
           every width where it IS a table. */
        .ax-tablewrap{overflow-x:auto}
        .ax-params{width:100%;border-collapse:collapse;font-size:13px}
        .ax-params th{text-align:left;padding:6px 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);border-bottom:1px solid var(--border)}
        .ax-params td{padding:8px 12px;vertical-align:top;border-bottom:1px solid var(--border);color:var(--t2)}
        .ax-params tr:last-child td{border-bottom:none}
        .ax-params,.ax-params tbody,.ax-params tr,.ax-params td{display:block}
        /* Still announced as a header for each cell, just not painted. */
        .ax-params thead{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}
        .ax-params tr{padding:10px 0;border-bottom:1px solid var(--border)}
        .ax-params tr:last-child{border-bottom:none}
        .ax-params td{display:grid;grid-template-columns:82px minmax(0,1fr);gap:2px 8px;padding:2px 0;border-bottom:none}
        .ax-params td::before{content:attr(data-label);color:var(--t3);font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;padding-top:2px}
        .ax-param-name{color:var(--t1);font-family:monospace;font-size:12.5px;overflow-wrap:anywhere}
        .ax-req-badge{display:inline-flex;font-size:10px;font-weight:600;padding:1px 5px;border-radius:4px;background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
        .ax-in-badge{display:inline-flex;font-size:10px;font-weight:500;padding:1px 6px;border-radius:4px;background:var(--s2);color:var(--t3)}
        .ax-type-badge{display:inline-flex;font-size:11px;padding:1px 6px;border-radius:4px;font-family:monospace;color:var(--blue-t);background:var(--blue-soft)}

        CSS;
    }

    /** Schema tree, response accordion and the code / JSON blocks. */
    private function cssSchema(): string
    {
        return <<<'CSS'
        /* Schema tree */
        .ax-schema{font-size:13px}
        .ax-schema-obj{border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
        /* A property row is a 2-column grid on a phone (expander beside the name,
           badges and description on their own lines) and reverts to the designed
           single flex line at >=900px. As a flex row it needs ~240px for the name
           and badges alone, which is most of a 358px viewport. */
        .ax-prop-row{
            display:grid;gap:2px 8px;align-items:baseline;padding:8px 12px;
            grid-template-columns:auto auto minmax(0,1fr);
            grid-template-areas:"btn name type" ". badges badges" ". desc desc";
            border-bottom:1px solid var(--border);transition:background .1s
        }
        .ax-prop-row>*{min-width:0}
        .ax-prop-row:last-child{border-bottom:none}
        .ax-prop-row:hover{background:var(--s1)}
        .ax-prop-row .ax-schema-collapse-btn{grid-area:btn}
        .ax-prop-name{grid-area:name;font-family:'JetBrains Mono',monospace;font-size:12.5px;color:var(--t1);overflow-wrap:anywhere}
        .ax-prop-type{grid-area:type;justify-self:start;overflow-wrap:anywhere}
        .ax-prop-badges{grid-area:badges;margin-left:0}
        .ax-prop-desc{grid-area:desc;color:var(--t3);font-size:12px}
        .ax-prop-nested{padding-left:min(var(--ind),40px);border-top:1px solid var(--border);background:var(--s1)}
        .ax-enum-wrap{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}
        .ax-enum-val{font-size:11px;font-family:monospace;padding:1px 6px;border-radius:4px;background:var(--s2);color:var(--t2);overflow-wrap:anywhere}
        .ax-allof-label{font-size:11px;color:var(--t3);padding:4px 12px;background:var(--s1);border-bottom:1px solid var(--border)}

        /* Responses */
        .ax-resp{border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:8px}
        .ax-resp-header{display:flex;align-items:center;width:100%;gap:10px;padding:10px 14px;font-family:inherit;font-size:inherit;color:inherit;background:none;border:0;text-align:left;cursor:pointer;transition:background .12s;user-select:none}
        .ax-resp-header:hover{background:var(--s1)}
        .ax-resp-status{font-size:13px;font-weight:700;font-family:monospace;flex-shrink:0}
        .axs-2xx{color:var(--green)}.axs-3xx{color:var(--blue)}.axs-4xx{color:var(--amber)}.axs-5xx{color:var(--red)}
        /* An informational code is not a success, and `default` is not a server
           error — which is how both of them used to be painted. */
        .axs-1xx{color:var(--blue-t)}.axs-default{color:var(--t3)}
        .ax-resp-desc{font-size:13px;color:var(--t2);flex:1}
        .ax-resp-arrow{color:var(--t3);font-size:10px;transition:transform .2s}
        .ax-resp.open .ax-resp-arrow{transform:rotate(90deg)}
        .ax-resp-body{border-top:1px solid var(--border);padding:14px}
        .ax-resp-body:empty{display:none}

        /* Code / JSON */
        pre.ax-code{background:var(--inset);border:1px solid var(--border);border-radius:var(--r);padding:14px;overflow-x:auto;font-family:'JetBrains Mono',monospace;font-size:12.5px;line-height:1.6;color:var(--t2)}
        .ax-k{color:var(--syn-k)}.ax-s{color:var(--syn-s)}.ax-n{color:var(--syn-n)}.ax-b{color:var(--syn-b)}.ax-null{color:var(--syn-null)}.ax-p{color:var(--syn-p)}

        CSS;
    }

    /**
     * The right panel and everything reached from it  code sample tabs, the
     * try-it form, the response viewer, history, the bulk-JSON editor  followed
     * by the refinements that were appended after it: sidebar footer,
     * breadcrumbs, badges, markdown, error states and the shortcuts modal.
     */
    private function cssPanel(): string
    {
        return <<<'CSS'
        /* ── Request console ──
           ONE node with three presentations and no fourth: in flow below the
           article (`tabs`), in flow as two columns (`stack`), or promoted into a
           sticky right rail by grid placement (`rail`). It is never
           display:none  it holds the code samples, try-it-out, the response
           viewer, history and the schema JSON, so hiding it at any width makes
           all of them unreachable. `data-mode` is published by axPanelMode();
           what each mode shows is decided here, where it stays width-agnostic,
           and the placement that promotes it to a rail is in cssResponsive(). */
        #axui-panel{display:flex;flex-direction:column;background:var(--bg);border-top:1px solid var(--border);margin-top:12px}
        .ax-panel-h{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);padding:14px var(--gutter) 0}
        #axui-panel-inner{padding:8px var(--gutter) 28px}
        #axui-panel-inner::-webkit-scrollbar{width:4px}
        #axui-panel-inner::-webkit-scrollbar-thumb{background:var(--s3);border-radius:4px}
        /* The welcome, empty and error views have no console. The node still
           has to exist  hiding #axui-panel is what made every control inside it
           unreachable  so instead nothing it draws survives an empty inner: no
           heading, no divider, no top border over blank space. */
        #axui-panel-inner:empty{display:none}
        #axui-panel:has(#axui-panel-inner:empty){border-top-width:0;margin-top:0}
        #axui-panel:has(#axui-panel-inner:empty)>.ax-panel-h{display:none}
        #axui-panel-slot:has(#axui-panel-inner:empty){border-left-color:transparent}
        /* The segmented control belongs to `tabs` mode only: `stack` and `rail`
           show all three panes at once, so the control would switch nothing.
           It is visible by DEFAULT and suppressed by mode  the reverse would
           leave the only route to the Try-it and Response panes hidden if
           axPanelMode() never ran. The panes are emitted by renderPanel(); until
           they exist the panel stacks its sections, which is the correct narrow
           presentation anyway. */
        .ax-pseg{display:flex;gap:2px;padding:10px var(--gutter) 0}
        .ax-pseg button{flex:1;padding:8px 10px;border-radius:var(--r);background:var(--s1);border:1px solid var(--border);color:var(--t2);font-family:inherit;font-size:12.5px;cursor:pointer}
        .ax-pseg button[aria-selected="true"]{background:var(--accent-soft);border-color:var(--accent-soft-b);color:var(--accent-t)}
        .ax-pane[hidden]{display:none}
        #axui-panel:not([data-mode="tabs"]) .ax-pseg{display:none}
        /* Beats `.ax-pane[hidden]` on id specificity, which is the point: outside
           tabs mode there is no control to un-hide a pane. */
        #axui-panel:not([data-mode="tabs"]) .ax-pane{display:block}

        /* Code sample tabs */
        .ax-lang-tabs{display:flex;gap:2px;margin-bottom:12px;flex-wrap:wrap}
        .ax-lang-btn{padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:500;cursor:pointer;background:transparent;border:1px solid var(--border);color:var(--t3);transition:all .12s}
        .ax-lang-btn.active,.ax-lang-btn:hover{color:var(--t1);background:var(--s2);border-color:var(--border-s)}
        .ax-lang-btn.active{color:var(--accent-t);border-color:var(--accent-soft-b)}

        /* Try-it-out form */
        .ax-try-section{margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
        .ax-try-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:6px;display:block}
        /* 16px on every control, dropping to the designed size only at >=900px:
           below 16px iOS Safari zooms the viewport on focus, and there is no
           reliable way back out of that zoom. */
        .ax-try-input{width:100%;min-width:0;padding:8px 10px;background:var(--inset);border:1px solid var(--border);border-radius:var(--r);color:var(--t1);font-size:16px;outline:none;transition:border-color .15s;font-family:inherit}
        .ax-try-input:focus{border-color:var(--focus)}
        .ax-try-input::placeholder{color:var(--t3)}
        .ax-try-textarea{font-family:'JetBrains Mono',monospace;font-size:16px;resize:vertical;min-height:100px}
        .ax-try-send{width:100%;padding:9px;border-radius:var(--r);background:var(--accent);border:none;color:var(--accent-on);font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;margin-top:12px}
        .ax-try-send:hover{background:var(--accent-hover);transform:translateY(-1px);box-shadow:var(--glow)}
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

        /* Environment popover  positioning lives in cssShell() with its wrapper */
        .axui-env-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);padding:4px 8px 8px}
        .axui-env-item{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:6px;font-size:13px;color:var(--t2);cursor:pointer;transition:background .12s}
        .axui-env-item:hover{background:var(--s2);color:var(--t1)}
        .axui-env-item.active{color:var(--accent)}
        .axui-env-dot{width:7px;height:7px;border-radius:999px;background:var(--border);flex-shrink:0}
        .axui-env-item.active .axui-env-dot{background:var(--accent)}

        /* Toast  deliberately a dark chip in both themes, so the label colour
           is fixed rather than following --t1, which would go dark-on-dark.
           Clamped to the viewport and wrapping, because the messages carry full
           server URLs, and lifted clear of the home indicator. */
        #apex-toast{position:fixed;bottom:calc(16px + env(safe-area-inset-bottom));left:8px;right:8px;transform:translateY(8px);background:var(--toast-bg);border:1px solid var(--accent-soft-b);color:#f4f4f5;font-size:13px;font-weight:500;padding:8px 18px;border-radius:12px;box-shadow:var(--shadow-1);opacity:0;transition:opacity .2s,transform .2s;pointer-events:none;text-align:center;overflow-wrap:anywhere;z-index:1500}
        #apex-toast.show{opacity:1;transform:translateY(0)}

        /* ── Sidebar group name ── */
        /* A <button>, so the UA's own chrome has to go: it is a disclosure
           control, and it has to look exactly like the row it replaced. */
        .axg-header{display:flex;align-items:center;width:100%;padding:6px 12px 6px 14px;font-family:inherit;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);background:none;border:0;text-align:left;cursor:pointer;user-select:none;transition:color .15s;gap:5px}
        .axg-header:hover{color:var(--t2)}
        .axg-name{flex:1}
        .axg-arrow{font-size:9px;transition:transform .2s;flex-shrink:0}
        .axg.open .axg-arrow{transform:rotate(90deg)}

        /* ── Sidebar footer ── */
        #axui-sidebar-footer{border-top:1px solid var(--border);padding:10px 14px;flex-shrink:0}
        .axf-item{font-size:11px;color:var(--t3);margin-bottom:3px;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .axf-link{color:var(--t3);text-decoration:none;transition:color .15s}.axf-link:hover{color:var(--accent)}

        /* ── Deprecated dot in sidebar ── */
        .axi-depr-dot{font-size:9px;font-weight:700;padding:0 3px;border-radius:3px;background:var(--amber-soft);color:var(--amber-t);border:1px solid var(--amber-b);flex-shrink:0;line-height:1.4}

        /* ── Webhook badge ── */
        .ax-webhook-badge{font-size:9px;font-weight:700;padding:1px 4px;border-radius:3px;background:var(--purple-soft);color:var(--purple-t);border:1px solid var(--purple-b);flex-shrink:0;letter-spacing:.02em}

        /* ── Breadcrumb ── */
        .ax-breadcrumb{display:flex;align-items:center;gap:5px;padding:0 0 16px;font-size:12px;flex-wrap:wrap}
        .ax-breadcrumb-item{color:var(--t3)}
        .ax-breadcrumb-link{color:var(--t2);cursor:pointer;transition:color .15s}.ax-breadcrumb-link:hover{color:var(--accent)}
        .ax-breadcrumb-sep{color:var(--t3);opacity:.5}
        .ax-breadcrumb-current{font-family:'JetBrains Mono',monospace;font-size:11px;padding:1px 5px;border-radius:4px;background:var(--s1);border:1px solid var(--border)}
        .ax-breadcrumb-path{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--t2);overflow-wrap:anywhere;min-width:0}

        /* ── Security badges ── */
        .ax-sec-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
        .ax-sec-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:5px;font-size:11.5px;font-weight:500;background:var(--amber-soft);border:1px solid var(--amber-b);color:var(--amber-t);overflow-wrap:anywhere}
        .ax-sec-open{background:var(--green-soft);border-color:var(--green-b);color:var(--green-t)}
        .ax-sec-scopes{font-size:10.5px;opacity:.7;margin-left:2px}

        /* ── Permalink button ──
           Visible by default. The hover-only reveal is gated behind
           `(hover:hover) and (pointer:fine)` in cssResponsive(), because copying
           a deep link was otherwise a mouse-only feature. */
        .ax-permalink-btn{display:inline-flex;align-items:center;padding:2px;border-radius:4px;background:none;border:none;color:var(--t3);cursor:pointer;opacity:1;transition:opacity .15s,color .15s;margin-left:6px;vertical-align:middle}
        .ax-permalink-btn:hover{color:var(--accent)}

        /* ── Ext docs link ── */
        .ax-ext-docs-link{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--accent-t);text-decoration:none;margin-bottom:16px;padding:4px 8px;border-radius:5px;background:var(--accent-soft);border:1px solid var(--accent-soft-b);transition:background .15s}
        .ax-ext-docs-link:hover{background:var(--s2)}

        /* ── Schema collapse button ── */
        .ax-schema-collapse-btn{cursor:pointer;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;border-radius:3px;background:var(--s2);color:var(--t3);font-size:8px;flex-shrink:0;border:none;transition:all .12s;vertical-align:middle}
        .ax-schema-collapse-btn:hover{background:var(--s3);color:var(--t1)}

        /* ── $ref expanded/collapsible ── */
        .ax-ref-wrap{display:flex;flex-direction:column;gap:4px}
        .ax-ref-badge{cursor:default}
        /* A linked badge. The dotted underline is the same affordance the prose
           links use, and it has to be visible at rest: a reader cannot hover
           every badge to find out which of them go somewhere. */
        .ax-ref-link{cursor:pointer;text-decoration:underline dotted;text-decoration-thickness:1px;text-underline-offset:2px;transition:background .12s,color .12s}
        .ax-ref-link:hover{background:var(--accent-soft);color:var(--accent-t);text-decoration-style:solid}
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
        .ax-try-auth-type{flex:0 0 auto;padding:7px 8px;background:var(--inset);border:1px solid var(--border);border-radius:var(--r);color:var(--t2);font-size:16px;outline:none;cursor:pointer;font-family:inherit;-webkit-appearance:none;appearance:none}
        .ax-try-auth-type:focus{border-color:var(--focus)}

        /* ── Response headers accordion ── */
        .ax-res-headers{border-bottom:1px solid var(--border)}
        .ax-res-headers summary{list-style:none;cursor:pointer;outline:none;transition:background .12s}
        .ax-res-headers summary:hover{background:var(--s1)}
        .ax-res-headers[open] summary{background:var(--s1)}

        /* ── Error state ── */
        .ax-error-state{padding:28px 16px;text-align:center}
        .ax-error-icon{font-size:28px;margin-bottom:8px;opacity:.6}
        .ax-error-title{font-size:14px;font-weight:600;color:var(--red-t);margin-bottom:4px}
        .ax-error-msg{font-size:12px;color:var(--t3)}

        /* ── Welcome screen improvements ── */
        .axw-stat-icon{color:var(--t3);flex-shrink:0;margin-right:2px}
        .axw-server-dot.active{background:var(--green-t)}
        .axw-contact-block{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px;margin-bottom:16px}
        .axw-meta-item{font-size:12px;color:var(--t3);overflow-wrap:anywhere}
        .axw-stats{gap:10px;margin-bottom:24px}
        .axw-stat{display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--card);border:1px solid var(--border);border-radius:10px;min-width:0;transition:border-color .15s}
        .axw-stat:hover{border-color:var(--border-s)}

        /* ── Section title row with expand-toggle buttons ── */
        .ax-section-title-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
        .ax-expand-toggle{display:inline-flex;gap:4px}
        .ax-expand-toggle button{font-size:10.5px;padding:3px 8px;background:var(--s1);border:1px solid var(--border);color:var(--t2);border-radius:5px;cursor:pointer;font-family:inherit;transition:background .12s,color .12s}
        .ax-expand-toggle button:hover{background:var(--s2);color:var(--t1)}

        /* ── Property badges ── */
        .ax-prop-badges{display:inline-flex;flex-wrap:wrap;gap:4px}
        .ax-badge{font-size:9.5px;padding:1px 6px;border-radius:4px;font-weight:600;letter-spacing:.02em;line-height:1.5;border:1px solid transparent;white-space:nowrap}
        .ax-b-req{background:var(--red-soft);border-color:var(--red-b);color:var(--red-t)}
        .ax-b-null{background:var(--neutral-soft);border-color:var(--neutral-b);color:var(--neutral-t)}
        .ax-b-ro{background:var(--blue-soft);border-color:var(--blue-b);color:var(--blue-t)}
        .ax-b-wo{background:var(--purple-soft);border-color:var(--purple-b);color:var(--purple-t)}
        .ax-b-dep{background:var(--amber-soft);border-color:var(--amber-b);color:var(--amber-t)}
        .ax-b-fmt{background:var(--accent-soft);border-color:var(--accent-soft-b);color:var(--accent-t)}
        .ax-b-def{background:var(--green-soft);border-color:var(--green-b);color:var(--green-t)}
        .ax-b-rng{background:var(--s1);border-color:var(--border);color:var(--t2)}
        .ax-b-pat{background:var(--purple-soft);border-color:var(--purple-b);color:var(--purple-t);cursor:help}

        /* ── Schemas group icon in sidebar ── */
        .axm-schema{background:var(--purple-soft);color:var(--purple-t)}
        .axi-schema .axi-path{font-family:'JetBrains Mono','SF Mono',monospace;font-size:11.5px}
        .ax-resp-hdrs{margin-bottom:12px}
        .ax-resp-sub{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);margin-bottom:6px}
        .ax-hdr-name{font-family:'JetBrains Mono','SF Mono',monospace;font-size:11.5px;color:var(--t1)}
        .ax-link-name{font-family:'JetBrains Mono','SF Mono',monospace;font-size:11.5px;color:var(--t1)}
        .ax-link-target{font-size:11px;color:var(--accent-t)}
        .ax-link-desc{font-size:12px;color:var(--t3)}
        .ax-used-static{cursor:default}
        .ax-used-list{display:flex;flex-direction:column;gap:4px}
        .ax-used-item{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;cursor:pointer;background:var(--card);border:1px solid var(--border);color:inherit;text-decoration:none;transition:background .12s,border-color .12s}
        .ax-used-item:hover{background:var(--s2);border-color:var(--border-s)}

        /* ── Deprecation banner ── */
        .ax-dep-banner{display:flex;gap:14px;align-items:flex-start;padding:14px 16px;margin:14px 0 18px;border-radius:10px;background:var(--amber-soft);border:1px solid var(--amber-b)}
        .ax-dep-icon{font-size:22px;color:var(--amber-t);line-height:1;flex-shrink:0}
        .ax-dep-body{flex:1;min-width:0}
        .ax-dep-title{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--amber-t);margin-bottom:4px}
        .ax-dep-msg{font-size:13px;color:var(--t2);line-height:1.55}
        .ax-dep-sunset{font-size:12px;color:var(--t2);margin-top:8px}
        .ax-dep-mig{display:inline-block;margin-top:6px;font-size:12px;color:var(--accent-t);text-decoration:none}
        .ax-dep-mig:hover{text-decoration:underline}

        /* ── Examples switcher ── */
        .ax-ex-block{margin-top:14px;padding:12px;background:var(--card);border:1px solid var(--border);border-radius:8px}
        .ax-ex-title{font-size:11px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
        .ax-ex-tabs{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px}
        .ax-ex-tab{font-size:11.5px;padding:4px 10px;background:transparent;border:1px solid var(--border);color:var(--t2);border-radius:5px;cursor:pointer;font-family:inherit;transition:all .12s}
        .ax-ex-tab:hover{background:var(--s2)}
        .ax-ex-tab.active{background:var(--accent-soft);border-color:var(--accent-soft-b);color:var(--accent-t)}
        .ax-ex-desc{font-size:12px;color:var(--t2);margin-bottom:8px}

        /* ── Markdown block styling ── */
        .axw-md p{margin:0 0 8px;line-height:1.55}.axw-md p:last-child{margin-bottom:0}
        .axw-md h2{font-size:18px;margin:14px 0 8px;font-weight:600}
        .axw-md h3{font-size:15px;margin:12px 0 6px;font-weight:600;color:var(--t1)}
        .axw-md h4{font-size:13px;margin:10px 0 5px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.04em}
        .axw-md ul{margin:6px 0 8px 22px;padding:0;line-height:1.6}
        .axw-md li{margin:2px 0}
        .axw-md code{font-family:'JetBrains Mono','SF Mono',monospace;font-size:.9em;padding:1px 6px;border-radius:4px;background:var(--s2);border:1px solid var(--border);color:var(--brand-3)}
        .axw-md a{color:var(--accent-t);text-decoration:none;border-bottom:1px dashed var(--accent-soft-b)}
        .axw-md a:hover{border-bottom-style:solid}
        .axw-md ol{margin:6px 0 8px 22px;padding:0;line-height:1.6}
        .axw-md blockquote{margin:8px 0;padding:6px 12px;border-left:2px solid var(--border-s);color:var(--t3)}
        .axw-md hr{margin:12px 0;border:0;border-top:1px solid var(--border)}
        .ax-md-table{width:100%;border-collapse:collapse;font-size:12.5px;margin:8px 0}
        .ax-md-table th{text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--t3);padding:6px 10px;border-bottom:1px solid var(--border)}
        .ax-md-table td{padding:6px 10px;border-bottom:1px solid var(--border);color:var(--t2);vertical-align:top}
        .ax-md-table tr:last-child td{border-bottom:0}
        .axw-md strong{color:var(--t1);font-weight:600}
        .axw-md em{font-style:italic;color:var(--t1)}
        .ax-md-pre{margin:8px 0;padding:10px 12px;background:var(--inset);border:1px solid var(--border);border-radius:6px;overflow-x:auto;font-family:'JetBrains Mono','SF Mono',monospace;font-size:12px;color:var(--t1)}
        .ax-md-pre code{background:transparent;border:none;padding:0;color:inherit}

        /* ── Rich response viewer ── */
        .ax-res-panel{border:1px solid var(--border);border-radius:8px;background:var(--card);overflow:hidden;margin-top:10px}
        .ax-res-status-bar{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--border);background:var(--s2)}
        .ax-res-status{font-size:12px;font-weight:700;padding:3px 10px;border-radius:5px;letter-spacing:.02em}
        .ax-res-s-ok{background:var(--green-soft);color:var(--green-t);border:1px solid var(--green-b)}
        .ax-res-s-info{background:var(--blue-soft);color:var(--blue-t);border:1px solid var(--blue-b)}
        .ax-res-s-warn{background:var(--amber-soft);color:var(--amber-t);border:1px solid var(--amber-b)}
        .ax-res-s-err{background:var(--red-soft);color:var(--red-t);border:1px solid var(--red-b)}
        .ax-res-meta{font-size:11.5px;color:var(--t3);flex:1;font-family:'JetBrains Mono','SF Mono',monospace}
        .ax-res-copy{padding:4px 10px;font-size:11px;background:transparent;border:1px solid var(--border);color:var(--t2);border-radius:5px;cursor:pointer;font-family:inherit;transition:all .12s}
        .ax-res-copy:hover{background:var(--s3);color:var(--t1)}
        .ax-res-tabs{display:flex;border-bottom:1px solid var(--border);background:var(--s1)}
        .ax-res-tab{flex:0 0 auto;padding:8px 14px;font-size:11.5px;background:transparent;border:none;border-bottom:2px solid transparent;color:var(--t3);cursor:pointer;font-family:inherit;font-weight:500;transition:all .12s}
        .ax-res-tab:hover{color:var(--t2)}
        .ax-res-tab.active{color:var(--t1);border-bottom-color:var(--accent)}
        .ax-res-body-wrap{max-height:420px;overflow:auto}
        .ax-res-pane{padding:0}
        .ax-res-pre{padding:12px;margin:0;font-family:'JetBrains Mono','SF Mono',monospace;font-size:11.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word;color:var(--t1)}
        .ax-res-headers-tbl{width:100%;border-collapse:collapse;font-size:11.5px}
        .ax-res-headers-tbl td{padding:6px 12px;border-bottom:1px solid var(--border);vertical-align:top;color:var(--t2);font-family:'JetBrains Mono','SF Mono',monospace}
        .ax-res-headers-tbl td:first-child{color:var(--t1);font-weight:500;width:35%;overflow-wrap:anywhere}

        /* ── Request history ── */
        .ax-hist-section{margin-top:18px;padding-top:14px;border-top:1px solid var(--border)}
        .ax-hist-section.collapsed .ax-hist-list,.ax-hist-section.collapsed .ax-hist-clear{display:none}
        .ax-panel-section-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
        .ax-hist-toggle{background:none;border:none;color:var(--t3);font-size:11px;cursor:pointer;padding:2px 6px}
        .ax-hist-list{display:flex;flex-direction:column;gap:4px}
        .ax-hist-empty{font-size:11px;color:var(--t3);padding:8px;text-align:center;font-style:italic}
        .ax-hist-item{display:flex;align-items:center;gap:10px;padding:7px 10px;background:var(--card);border:1px solid var(--border);border-radius:6px;cursor:pointer;transition:all .12s}
        .ax-hist-item:hover{background:var(--s2);border-color:var(--border-s)}
        .ax-hist-time{font-size:11px;color:var(--t2);flex:1}
        .ax-hist-ms{font-size:10.5px;color:var(--t3);font-family:'JetBrains Mono','SF Mono',monospace}
        .ax-hist-clear{margin-top:8px;width:100%;padding:5px;background:none;border:1px dashed var(--border);color:var(--t3);font-size:10.5px;border-radius:5px;cursor:pointer;transition:all .12s;font-family:inherit}
        .ax-hist-clear:hover{background:var(--s1);color:var(--red-t);border-color:var(--red-b)}

        /* ── OAuth helper button ── */
        .ax-oauth-btn{padding:6px 12px;font-size:11px;background:var(--accent-soft);border:1px solid var(--accent-soft-b);color:var(--accent-t);border-radius:var(--r);cursor:pointer;font-family:inherit;font-weight:500;white-space:nowrap;transition:all .12s}
        .ax-oauth-btn:hover{background:var(--s2)}

        /* ── Bulk JSON edit toggle ── */
        .ax-bulk-row{display:flex;align-items:center;justify-content:space-between;margin-top:10px;margin-bottom:4px}
        .ax-bulk-row .ax-try-label{margin:0;color:var(--t3)}
        .ax-bulk-btn{font-size:10px;padding:2px 8px;background:transparent;border:1px solid var(--border);color:var(--t2);border-radius:4px;cursor:pointer;font-family:inherit;letter-spacing:.04em;text-transform:uppercase;transition:all .12s}
        .ax-bulk-btn:hover{background:var(--s2);color:var(--t1);border-color:var(--border-s)}
        .ax-bulk-btn.active{background:var(--accent-soft);border-color:var(--accent-soft-b);color:var(--accent-t)}
        .ax-bulk-area{display:none;width:100%;min-height:80px;padding:8px 10px;font-family:'JetBrains Mono','SF Mono',monospace;font-size:16px;color:var(--t1);background:var(--inset);border:1px solid var(--border);border-radius:var(--r);outline:none;resize:vertical}
        .ax-bulk-area:focus{border-color:var(--focus);background:var(--s2)}
        .ax-bulk-area.show{display:block}
        .ax-bulk-actions{display:flex;gap:6px;margin-top:6px;margin-bottom:8px}
        .ax-bulk-actions button{flex:1;font-size:10.5px;padding:5px 8px;background:var(--s1);border:1px solid var(--border);color:var(--t2);border-radius:5px;cursor:pointer;font-family:inherit;transition:all .12s}
        .ax-bulk-actions button:hover{background:var(--s2);color:var(--t1)}
        .ax-bulk-fields.hidden{display:none}
        .ax-bulk-err{font-size:10.5px;color:var(--red-t);margin-top:4px;padding:0 2px;min-height:14px;font-family:'JetBrains Mono','SF Mono',monospace}

        /* ── Error / empty states ── */
        .ax-error-url{font-size:10.5px;color:var(--t3);font-family:'JetBrains Mono','SF Mono',monospace;margin-top:6px;word-break:break-all;padding:0 8px}
        .ax-error-retry{margin-top:14px;padding:6px 14px;font-size:12px;background:var(--accent-soft);border:1px solid var(--accent-soft-b);color:var(--accent-t);border-radius:6px;cursor:pointer;font-family:inherit}
        .ax-error-retry:hover{background:var(--s2)}
        .ax-empty-spec{padding:36px 16px;text-align:center}
        .ax-empty-icon{font-size:38px;margin-bottom:10px;opacity:.7}
        .ax-empty-title{font-size:15px;font-weight:600;color:var(--t1);margin-bottom:4px}
        .ax-empty-msg{font-size:12.5px;color:var(--t3)}

        /* ── Shortcuts modal ── */
        #ax-shortcuts[hidden]{display:none}
        #ax-shortcuts{position:fixed;inset:0;z-index:1600;display:flex;align-items:center;justify-content:center;padding:12px}
        .ax-sc-backdrop{position:absolute;inset:0;background:var(--backdrop)}
        .ax-sc-box{position:relative;width:min(440px,100%);max-height:calc(100dvh - 24px);overflow-y:auto;background:var(--elev);border:1px solid var(--border-s);border-radius:14px;box-shadow:var(--shadow-2);padding:18px 22px}
        .ax-sc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
        .ax-sc-header h3{font-size:14px;font-weight:600;color:var(--t1);margin:0}
        .ax-sc-close{background:none;border:none;color:var(--t3);font-size:18px;cursor:pointer;width:28px;height:28px;border-radius:6px;transition:all .12s}
        .ax-sc-close:hover{background:var(--s2);color:var(--t1)}
        .ax-sc-grid{display:flex;flex-direction:column;gap:6px}
        .ax-sc-row{display:flex;align-items:center;gap:8px;padding:6px 4px;font-size:12.5px;color:var(--t2)}
        .ax-sc-footer{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--border)}
        .ax-sc-note{flex:1 1 220px;min-width:0;font-size:11.5px;color:var(--t3);line-height:1.5}
        .ax-sc-clear{padding:6px 10px;font-family:inherit;font-size:12px;color:var(--t2);background:var(--inset);border:1px solid var(--border);border-radius:var(--r);cursor:pointer}
        .ax-sc-clear:hover{color:var(--red);border-color:var(--red)}
        .ax-sc-row kbd{font-family:'JetBrains Mono','SF Mono',monospace;font-size:10.5px;padding:2px 7px;background:var(--s2);border:1px solid var(--border);border-bottom-width:2px;border-radius:4px;color:var(--t1);min-width:18px;text-align:center}
        .ax-sc-row span{margin-left:auto;color:var(--t2)}

        CSS;
    }

    /**
     * The nav drawer and every media query, mobile-first.
     *
     * The base styles in the parts above ARE the phone styles; the five width
     * blocks here are declared in ascending order and only ever ADD. Nothing is
     * `display:none`-ed as the viewport narrows  a control hidden at one width
     * is reachable from another control at that width. Layout dimensions come
     * from the tokens in Theme::STRUCTURE, so a breakpoint is a handful of token
     * writes rather than a pile of per-selector overrides.
     *
     * The five queries that are NOT about width follow them, then the single
     * `dvh` fallback.
     */
    private function cssResponsive(): string
    {
        return <<<'CSS'
        /* ══ BASE  phones, 0–599px (design target 320–430px) ══
           Progressive disclosure first, because it is a pure source-order
           question: these two classes are also `.apex-icon-btn`s and
           `.apex-env-wrap`s, which set `display` themselves at the same
           specificity, so the hide only wins from here  after every part that
           could set it, and before the width queries that reveal them. */
        .apex-a-md,.apex-a-lg{display:none}

        /* The document scrolls, everything is one column, and the sidebar is an
           overlay of the same grid child it is at desktop width  no node moves.
           `visibility:hidden` (not transform alone) is what takes the filter and
           the endpoint rows out of the tab order and the accessibility tree
           while the drawer is shut; axSidebarToggle() adds `inert` on top, and
           removes it again at >=900px where this node is the static nav. */
        #axui-sidebar{
            position:fixed;inset:0 auto 0 0;width:min(88vw,340px);z-index:1200;
            transform:translateX(-100%);visibility:hidden;
            transition:transform .22s cubic-bezier(.4,0,.2,1),visibility .22s;
            box-shadow:var(--shadow-2)
        }
        /* The state class goes on <html>, which is what lets a *sibling* backdrop
           and the scroll lock be expressed as CSS at all. The drawer starts at
           the viewport top on purpose: any offset would have to track the sticky
           toolbar's runtime position, and no static value is right at every
           scroll offset. */
        html.ax-nav-open #axui-sidebar{transform:none;visibility:visible}
        html.ax-nav-open{overflow:hidden}
        #axui-sb-backdrop{display:none;position:fixed;inset:0;background:var(--backdrop);z-index:1150}
        html.ax-nav-open #axui-sb-backdrop{display:block}

        /* ══ >=600px  small tablets and landscape phones ══ */
        @media (min-width:600px){
            :root{--gutter:24px}
            .apex-a-md{display:flex}
            #apex-more .apex-more-md{display:none}
            /* Enough width for a real table again, inside its own scroller. */
            .ax-params{display:table}
            .ax-params thead{position:static;width:auto;height:auto;clip-path:none}
            .ax-params tbody{display:table-row-group}
            .ax-params tr{display:table-row;padding:0;border-bottom:none}
            .ax-params td{display:table-cell;padding:8px 12px;border-bottom:1px solid var(--border)}
            .ax-params td::before{content:none}
            .axw-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
            #apex-palette-footer{display:flex}
            #apex-palette-close{display:none}
            /* Anchored to its trigger now that the trigger is in the bar. */
            #axui-env-popover{position:absolute;left:auto;right:0;top:calc(100% + 6px);bottom:auto;min-width:220px;max-width:min(320px,calc(100vw - 24px))}
        }

        /* ══ >=900px  THE APP-SHELL THRESHOLD ══
           Three panes, three scrollers, and the only place a height is declared:
           one grid row per band, so nothing can desync from its content. */
        @media (min-width:900px){
            :root{--gutter:32px;--ind:16px;--doc-max:820px}
            body{display:grid;grid-template-rows:auto auto minmax(0,1fr);height:100dvh;overflow:hidden}
            #apex-banner{grid-row:1}
            #apex-bar{grid-row:2;position:relative;top:auto}
            #axui{grid-row:3;display:grid;grid-template-columns:var(--nav-w) minmax(0,1fr);min-height:0}
            #axui-content{overflow-y:auto;overscroll-behavior:contain;min-height:0}
            /* Its scrollport already begins under the bar. */
            #ax-ctx{top:0}
            /* The drawer reverts to a static column and every ax-nav-open rule
               above becomes a no-op. */
            #axui-sidebar{position:static;width:auto;transform:none;visibility:visible;box-shadow:none;transition:none}
            #axui-sb-backdrop,html.ax-nav-open #axui-sb-backdrop{display:none}
            #apex-nav-btn,#axui-nav-close{display:none}
            .apex-a-lg{display:flex}
            .apex-api-title{display:block}
            .apex-version{display:inline-block}
            #axui-filter{font-size:13px}
            .ax-try-input,.ax-try-auth-type{font-size:13px}
            .ax-try-textarea{font-size:12px}
            .ax-bulk-area{font-size:11.5px}
            /* Back to the designed single-line property row. */
            .ax-prop-row{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px}
            .ax-prop-name{min-width:120px;flex-shrink:0}
            .ax-prop-type{flex-shrink:0}
            .ax-prop-badges{margin-left:6px}
            .ax-prop-desc{flex:1 1 240px;min-width:0;margin-left:4px}
            .axw-stats{grid-template-columns:repeat(4,minmax(0,1fr))}
        }

        /* ══ >=1024px  tablet landscape: panel mode `stack` ══
           Still in flow under the article, but the segmented control disappears
           (see cssPanel) and Code sits beside Try it. This is the width the old
           stylesheet hid the whole panel at. */
        @media (min-width:1024px){
            /* :not(:empty) so this cannot outrank the rule that keeps the rail
               blank on the views with no console. */
            #axui-panel[data-mode="stack"] #axui-panel-inner:not(:empty){display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start}
        }

        /* ══ >=1200px  panel mode `rail` ══
           The panel is promoted into a sticky right column by grid placement, not
           by revealing a hidden node. */
        @media (min-width:1200px){
            :root{--nav-w:264px;--rail-w:380px}
            #axui-content-inner{display:grid;grid-template-columns:minmax(0,1fr) var(--rail-w);align-items:start}
            /* The article now fills its cell: the rail already bounds it, so a
               measure of its own only opens a gap between the two columns.
               `margin-inline` MUST be reset with `max-width` — an auto inline
               margin on a grid item cancels the stretch and sizes the box to
               fit-content, which rendered the article 592px wide inside a
               1216px cell, narrower than even its own max-width, with all the
               slack showing as dead space either side of the documentation.
               Guarded by a CSS regression test. */
            #axui-doc{grid-area:1/1;max-width:none;margin-inline:0}
            /* #axui-panel-slot must keep overflow:visible and align-self:stretch.
               Give it any overflow value and it becomes the scroll container for
               its own sticky child, which degrades the rail to static silently 
               no error, no console warning. Guarded by a CSS regression test. */
            #axui-panel-slot{grid-area:1/2;align-self:stretch;border-left:1px solid var(--border)}
            /* The one height in the stylesheet that is arithmetic: a sticky child
               pinned at the top of a scrollport must not be taller than that
               scrollport, and no CSS unit names it. `--banner-h` is therefore
               measured  see the shell metrics in jsCore(). */
            #axui-panel{position:sticky;top:0;max-height:calc(100dvh - var(--bar-h) - var(--banner-h));overflow-y:auto;border-top:none;margin-top:0}
            /* No `display` reset for #axui-panel-inner here: the two-column rule
               is scoped to data-mode="stack", the modes are mutually exclusive,
               and an override at this specificity would beat the :empty rule
               that keeps the rail blank on the views with no console. */
        }

        /* ══ >=1560px — wide desktop ══
           No `--doc-max` here: past 1200px the article is a grid item that
           fills its cell, so a measure would have nothing to act on. */
        @media (min-width:1560px){
            :root{--gutter:40px;--rail-w:440px}
        }

        /* The single fallback: without dvh the shell falls back to vh, which is
           only wrong while mobile browser chrome is animating  and mobile is
           the one place the app-shell mode never applies. */
        @supports not (height:100dvh){
            body{min-height:100vh}
            @media (min-width:900px){body{height:100vh}}
        }

        /* ══ Touch ══
           A 44px minimum on BOTH axes of everything that is tapped: an icon
           button is 32px wide, so min-height alone leaves a 32x44 target. The
           two glyph-sized controls keep their glyph  the 16x16 schema expander
           is the only way into a nested object and growing it would push a deep
           tree off the screen; the permalink sits inside an 18px title line 
           and gain hit-slop instead, which is 44x44 to a finger at zero cost to
           the layout. */
        @media (pointer:coarse){
            .axi,.axg-header,.ax-resp-header,.ax-resp summary,.apex-icon-btn,.apex-export-trigger,
            .ax-lang-btn,.ax-res-tab,.ax-ex-tab,.ax-resp-ct-btn,.ax-bulk-btn,.axui-env-item,
            .apex-pal-item,.apex-banner-close,.ax-hist-item,.ax-pseg button,.ax-code-copy-btn,
            .ax-error-retry,.apex-more-item,.ax-hist-toggle,.ax-expand-toggle button,
            .ax-try-send{min-height:var(--tap);min-width:var(--tap)}
            .ax-schema-collapse-btn,.ax-permalink-btn{position:relative}
            .ax-schema-collapse-btn::after{content:'';position:absolute;inset:-14px}
            .ax-permalink-btn{padding:8px}
            .ax-permalink-btn::after{content:'';position:absolute;inset:-8px}
        }

        /* ══ Fine pointer with hover ══
           The ONLY place a hover-only reveal is allowed. */
        @media (hover:hover) and (pointer:fine){
            .ax-permalink-btn{opacity:0}
            .ax-op-path:hover .ax-permalink-btn,
            .ax-op-header:hover .ax-permalink-btn,
            .ax-permalink-btn:focus-visible{opacity:1}
        }

        /* ══ Focus ══
           There was no focus ring anywhere in this stylesheet: a keyboard user
           could not see where they were. One ring, on everything focusable,
           drawn with the palette's `--ring` token (both modes define it at 3:1
           against their surfaces). `:focus-visible`, so a mouse click never
           paints one, and `outline`, so it costs no layout and survives forced
           colours. */
        :focus-visible{outline:2px solid var(--ring);outline-offset:2px}
        /* A sidebar row and an accordion header are flush with their container's
           edge, where an outset ring is clipped by the scrollport. */
        .axi:focus-visible,.axg-header:focus-visible,.ax-resp-header:focus-visible,
        .ax-resp summary:focus-visible,.apex-pal-item:focus-visible{outline-offset:-2px}
        /* Against a filled control the ring needs the inner edge to read. */
        .ax-try-send:focus-visible,.apex-export-item:focus-visible{box-shadow:0 0 0 2px var(--ring-2)}
        /* These three set `outline:none` for their own borderless look and are
           addressed by id, which outranks the blanket rule above. */
        #axui-filter:focus-visible,#apex-palette-input:focus-visible,
        .ax-try-input:focus-visible,.ax-try-textarea:focus-visible,
        .ax-bulk-area:focus-visible,.ax-try-auth-type:focus-visible{outline:2px solid var(--ring);outline-offset:1px}

        /* ══ Reduced motion ══
           Stated as intent, per animation, then a blanket net for anything added
           later. Freezing a rotating arc at 0.01ms reads as a broken graphic, so
           the spinner becomes a static ring rather than a stopped one. */
        @media (prefers-reduced-motion:reduce){
            html{scroll-behavior:auto}
            #apex-progress-bar{animation:none;background:var(--accent)}
            .axui-spinner{animation:none;border-color:var(--accent)}
            .ax-skel{animation:none}
            *{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}
        }

        /* ══ Forced colours ══ */
        @media (forced-colors:active){
            .axm,.ax-badge,.ax-res-status,.ax-sec-badge,.axg-count,.axi[aria-current]{border:1px solid CanvasText;background:Canvas;color:CanvasText}
            :focus-visible{outline:2px solid Highlight;outline-offset:2px}
            .ax-k,.ax-s,.ax-n,.ax-b,.ax-null,.ax-p{forced-color-adjust:none}
        }

        /* ══ Print ══
           Because the panel is in the document flow, the printed page keeps the
           code sample and drops only the interactive form. */
        @media print{
            #apex-bar,#axui-sidebar,#axui-sb-backdrop,#apex-progress,#apex-toast,#ax-ctx,
            .ax-skip,.ax-permalink-btn,.ax-pseg,.ax-try-section,.ax-hist-section{display:none!important}
            body,#axui,#axui-content,#axui-content-inner,#axui-panel-slot,#axui-panel{
                display:block;height:auto;max-height:none;overflow:visible;position:static
            }
            #axui-doc{max-width:none;padding:0}
            .ax-section,.ax-resp{break-inside:avoid}
            a[href^="http"]::after{content:" (" attr(href) ")"}
            @page{margin:16mm}
        }
        CSS;
    }

    // ── JavaScript ────────────────────────────────────────────────────────────

    /**
     * The behaviour layer, in one method per area of the page. Every part is a
     * fragment of a single IIFE  jsCore() opens it, jsInit() closes it  so
     * they are only valid concatenated, in this order.
     */
    private function js(): string
    {
        return implode("\n", [
            $this->jsCore(),
            $this->jsChrome(),
            $this->jsNav(),
            $this->jsIndex(),
            $this->jsDoc(),
            $this->jsSchema(),
            $this->jsPanel(),
            $this->jsInit(),
        ]);
    }

    /**
     * Opens the IIFE and defines the primitives the rest of the file calls:
     * `toast`, the clipboard helpers, the progress bar, the export menu, the
     * theme cycle, the two write targets every render path goes through, and the
     * two shell measurements CSS cannot derive on its own.
     */
    private function jsCore(): string
    {
        return <<<'JS'
        (function(){
        'use strict';

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

        /* ── Theme ──
           Re-themes instantly through CSS custom properties, so nothing here
           needs to reload the page. */
        var themeBtn=document.getElementById('apexThemeBtn');
        var prefersLight=window.matchMedia?window.matchMedia('(prefers-color-scheme: light)'):null;
        function getTheme(){return localStorage.getItem('apex-theme')||APEX_CFG.theme;}
        function applyTheme(t){
            if(t==='auto')document.documentElement.removeAttribute('data-theme');
            else document.documentElement.setAttribute('data-theme',t);
            /* `auto` removes data-theme, so the moon/sun swap — which keys on
               [data-theme="light"] — always showed the moon and `auto` was
               indistinguishable from `dark`. State goes on the button. */
            document.documentElement.setAttribute('data-theme-pref',t);
            var btn=document.getElementById('apexThemeBtn');
            if(btn){
                btn.setAttribute('data-pref',t);
                btn.setAttribute('title','Theme: '+t+' (click to change)');
                btn.setAttribute('aria-label','Theme: '+t+'. Change theme');
            }
        }
        applyTheme(getTheme());
        if(prefersLight&&prefersLight.addEventListener){
            prefersLight.addEventListener('change',function(){
                if(getTheme()==='auto'){applyTheme('auto');}
            });
        }
        /* Global, not just a click handler: with the toolbar hidden #apexThemeBtn
           never exists, and the `t` shortcut is then the only way to switch. */
        window.apexThemeCycle=function(){
            var cur=getTheme();
            var next=cur==='dark'?'light':cur==='light'?'auto':'dark';
            localStorage.setItem('apex-theme',next);applyTheme(next);
            toast('Theme: '+next);
        };
        if(themeBtn)themeBtn.addEventListener('click',apexThemeCycle);

        /* ── The two write targets ──
           #axui-doc is the only node a view may replace, and #axui-panel-inner
           the only node the request console may replace. They are SIBLINGS, so
           neither can destroy the other. Writing #axui-content-inner  their
           parent  instead is what removed the console, and with it the code
           samples, try-it-out, the response viewer, the history and the schema
           JSON, from every viewport the moment anything was opened. */
        function setDoc(html){
            var doc=document.getElementById('axui-doc');if(!doc)return;
            doc.innerHTML=html;doc.setAttribute('aria-busy','false');
        }
        /* Views with no console pass ''. The rail then paints nothing at all 
           see `#axui-panel-inner:empty` in cssPanel  rather than leaving a
           heading and a divider standing over blank space. */
        function setPanel(html){
            var inner=document.getElementById('axui-panel-inner');if(!inner)return;
            inner.innerHTML=html;
        }

        /* ── Shell metrics ──
           Everything else about the layout is decided by CSS grid and media
           queries. These are the two facts CSS cannot state. */

        /* The panel's presentation, published as an attribute so all three modes
           stay in the stylesheet. Nothing here moves a node. */
        var _mqStack=window.matchMedia('(min-width:1024px)'),_mqRail=window.matchMedia('(min-width:1200px)');
        function axPanelMode(){
            var p=document.getElementById('axui-panel');if(!p)return;
            p.setAttribute('data-mode',_mqRail.matches?'rail':_mqStack.matches?'stack':'tabs');
        }
        axPanelMode();
        if(_mqStack.addEventListener){_mqStack.addEventListener('change',axPanelMode);_mqRail.addEventListener('change',axPanelMode);}

        /* The banner's height. The >=1200px sticky rail is bounded by the
           viewport minus the two grid rows above its scrollport; --bar-h is
           declared, but the banner carries author-supplied HTML that wraps at
           any width, so its height can only be measured. 0px  the token's
           default  is already correct when there is no banner. */
        var banner=document.getElementById('apex-banner');
        function publishBannerHeight(){
            document.documentElement.style.setProperty('--banner-h',(banner&&banner.isConnected?banner.offsetHeight:0)+'px');
        }
        window.axBannerClose=function(btn){
            var b=btn.closest('#apex-banner');if(b)b.remove();
            publishBannerHeight();
        };
        if(banner){
            publishBannerHeight();
            if(window.ResizeObserver)new ResizeObserver(publishBannerHeight).observe(banner);
        }

        JS;
    }

    /**
     * Chrome around the document: the environment popover (with the shared
     * `loadSpec` / `_specCache`), the command palette, the global key handler, the
     * shortcuts modal and the sidebar drawer  plus the shared render helpers
     * `md`, `propBadges`, request history, auth persistence, the bulk-JSON editor
     * and the OAuth token helper.
     */
    private function jsChrome(): string
    {
        return <<<'JS'
        /* ── Environment switcher ── */
        var envBtn=document.getElementById('apexEnvBtn'),envPop=document.getElementById('axui-env-popover');
        var _specCache=null;
        var _activeEnv=localStorage.getItem('apex-env')||null;
        function getSpecUrl(){return APEX_CFG.specUrl;}
        /* One in-flight fetch, aborted by its own deadline. The 10s watchdog
           used to paint an error while the request kept running, so an 11s
           response then painted the whole UI on top of that error; and every
           later consumer (the palette, the env list) re-fetched a spec that had
           already failed. `_specFailed` remembers, and `axRetrySpec` is the
           only thing that clears it — the Retry button re-fetches instead of
           reloading the page. */
        var _specPending=null,_specFailed=null,_specAbort=null;
        function loadSpec(cb,onErr){
            if(_specCache){cb(_specCache);return;}
            if(_specFailed){if(onErr)onErr(_specFailed);return;}
            if(!_specPending){
                _specAbort=(typeof AbortController!=='undefined')?new AbortController():null;
                var timer=setTimeout(function(){if(_specAbort)_specAbort.abort();},10000);
                _specPending=fetch(getSpecUrl(),_specAbort?{signal:_specAbort.signal}:undefined)
                    .then(function(r){
                        if(!r.ok)throw new Error('HTTP '+r.status+' '+r.statusText);

                        return r.text().then(function(t){
                            try{return JSON.parse(t);}catch(e){throw new Error('Invalid JSON response');}
                        });
                    })
                    .then(function(sp){clearTimeout(timer);_specCache=sp;_specPending=null;return sp;})
                    .catch(function(e){
                        clearTimeout(timer);
                        _specPending=null;
                        _specFailed=(e&&e.name==='AbortError')?'Request timed out after 10s':(e&&e.message?e.message:String(e));
                        throw new Error(_specFailed);
                    });
            }
            _specPending.then(cb).catch(function(e){if(onErr)onErr(e&&e.message?e.message:String(e));});
        }
        window.axRetrySpec=function(){
            _specFailed=null;_specCache=null;_specPending=null;
            init();
        };
        if(envBtn&&envPop){
            envBtn.addEventListener('click',function(e){
                e.stopPropagation();
                loadSpec(function(spec){
                    var servers=spec.servers||[{url:'http://localhost',description:'Default'}];
                    var list=document.getElementById('axui-env-list');
                    if(list){
                        list.innerHTML=servers.map(function(s,i){
                            var active=(_activeEnv===s.url||(!_activeEnv&&i===0))?' active':'';
                            return '<div class="axui-env-item'+active+'" data-url="'+escH(s.url)+'" onclick="apexSetEnv(\''+escH(s.url)+'\')">'
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
        function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
        function buildIndex(spec){
            _ops=[];
            var paths=spec.paths||{};
            for(var path in paths){
                var methods=paths[path];
                for(var method in methods){
                    var op=methods[method];
                    if(typeof op!=='object')continue;
                    /* `key` keeps the spec's own casing of the method: it is the
                       fallback deep-link id navigateHash() matches against. */
                    _ops.push({method:method.toUpperCase(),key:method+'__'+path,path:path,summary:op.summary||'',tag:(op.tags||['General'])[0],operationId:op.operationId||''});
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
                    /* Real href so the item is copyable and focusable, but the
                       default navigation is cancelled  see apexPaletteGo. */
                    html+='<a class="apex-pal-item" href="#op_'+escH(o.operationId||o.key)+'" data-idx="'+idx+'" onclick="return apexPaletteGo(this)">'
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
        /* Renders in place and returns false to cancel the link: a real navigation
           would drop a ?theme= override, which is never written to localStorage. */
        window.apexPaletteGo=function(el){
            apexPaletteClose();
            if(_specCache)navigateHash(el.getAttribute('href').slice(1),_specCache);
            return false;
        };
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
            if(e.key==='Escape'){apexPaletteClose();axShortcutsClose();axSidebarClose();return;}
            if(inField)return;
            if(e.key==='?'||(e.shiftKey&&e.key==='/')){e.preventDefault();axShortcutsOpen();return;}
            if(e.key==='/'){e.preventDefault();var f=document.getElementById('axui-filter');if(f){f.focus();f.select();}return;}
            if(e.ctrlKey||e.metaKey||e.altKey)return;
            /* j / k → next / previous endpoint */
            if(e.key==='j'||e.key==='k'){
                var items=Array.prototype.slice.call(document.querySelectorAll('#axui-sidebar-body .axi'));
                if(!items.length)return;
                var idx=items.findIndex(function(el){return el.classList.contains('active');});
                idx=e.key==='j'?Math.min(items.length-1,idx+1):Math.max(0,idx-1);
                if(idx<0)idx=0;items[idx].click();items[idx].scrollIntoView({block:'nearest'});return;
            }
            if(e.key==='g'){if(window.axGoWelcome)axGoWelcome();return;}
            if(e.key==='t'){apexThemeCycle();return;}
            if(e.key==='c'){var cb=document.querySelector('.ax-code-copy-btn');if(cb)cb.click();return;}
        });

        /* ── Native Apex UI ── */
        var METHODS=['get','post','put','patch','delete','head','options'];
        var LANGS=['curl','js','python','php','go'];
        var LANG_LABELS={curl:'cURL',js:'JavaScript',python:'Python',php:'PHP',go:'Go'};
        var _server=localStorage.getItem('apex-env')||'';
        var _activeOpKey=null;
        var _activeSchemaName=null;
        var _schemaIds=0;
        var _expandAll=false;

        /* ── Per-spec namespaced storage ── */
        var _ns='apex:'+(APEX_CFG.specUrl||'default').replace(/[^a-z0-9]/gi,'_');
        function lsGet(k,d){try{var v=localStorage.getItem(_ns+':'+k);return v==null?d:v;}catch(e){return d;}}
        function lsSet(k,v){try{localStorage.setItem(_ns+':'+k,v);}catch(e){}}
        function lsGetJson(k,d){var v=lsGet(k,null);if(v==null)return d;try{return JSON.parse(v);}catch(e){return d;}}
        function lsSetJson(k,v){lsSet(k,JSON.stringify(v));}

        /* Nothing pruned or expired anything, and the per-endpoint keys grow
           with every endpoint ever visited. LRU-keep the 60 newest of the
           per-view keys; the handful of settings keys are never touched. */
        function pruneStorage(){
            try{
                var keep=60,mine=[];
                for(var i=0;i<localStorage.length;i++){
                    var full=localStorage.key(i);
                    if(!full||full.indexOf(_ns+':')!==0)continue;
                    var k=full.slice(_ns.length+1);
                    if(!/^(hist:|resp\.|open\.|scroll\.)/.test(k))continue;
                    var ts=0;
                    try{
                        var v=JSON.parse(localStorage.getItem(full));
                        ts=(v&&v.length&&v[0]&&v[0].ts)||(v&&v.ts)||0;
                    }catch(e){}
                    mine.push({full:full,ts:ts});
                }
                if(mine.length<=keep)return;
                mine.sort(function(a,b){return b.ts-a.ts;});
                mine.slice(keep).forEach(function(e){localStorage.removeItem(e.full);});
            }catch(e){}
        }

        /* A bearer token was persisted in plaintext with no way to forget it. */
        window.axClearStored=function(){
            try{
                var doomed=[];
                for(var i=0;i<localStorage.length;i++){
                    var k=localStorage.key(i);
                    if(k&&k.indexOf(_ns+':')===0)doomed.push(k);
                }
                doomed.forEach(function(k){localStorage.removeItem(k);});
                var auth=document.getElementById('axi-auth');if(auth)auth.value='';
                toast(doomed.length+' stored item(s) cleared');
            }catch(e){toast('Could not clear storage');}
        };

        /* ── Minimal XSS-safe markdown renderer ── */
        function md(s){
            if(s==null||s==='')return '';
            s=String(s);
            /* Fenced code blocks first */
            var blocks=[];
            s=s.replace(/```([\s\S]*?)```/g,function(_,c){blocks.push(c);return '\uE000B'+(blocks.length-1)+'\uE000';});
            s=escH(s);
            /* Headings */
            s=s.replace(/^### (.+)$/gm,'<h4>$1</h4>')
                .replace(/^## (.+)$/gm,'<h3>$1</h3>')
                .replace(/^# (.+)$/gm,'<h2>$1</h2>');
            /* Bold / italic / inline code */
            s=s.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
                .replace(/(^|[^*])\*([^*\n]+)\*/g,'$1<em>$2</em>')
                .replace(/`([^`\n]+)`/g,'<code>$1</code>');
            /* Links  whitelist http/https/mailto */
            s=s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/g,'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
            /* Tables. An OpenAPI description routinely carries an error-code
               catalogue or a rate-limit tier list as a pipe table, and it
               rendered as literal pipes: header, separator, then body rows. */
            s=s.replace(/(^|\n)(\|.+\|)\n\|[ :\-|]+\|\n((?:\|.+\|\n?)*)/g,function(_,pre,head,body){
                var cells=function(row){return row.replace(/^\||\|$/g,'').split('|').map(function(c){return c.trim();});};
                var th=cells(head).map(function(c){return '<th>'+c+'</th>';}).join('');
                var tr=body.trim()===''?'':body.trim().split(/\n/).map(function(row){
                    return '<tr>'+cells(row).map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>';
                }).join('');

                return pre+'<div class="ax-tablewrap"><table class="ax-md-table"><thead><tr>'+th+'</tr></thead><tbody>'+tr+'</tbody></table></div>';
            });
            /* Blockquotes and thematic breaks. `&gt;` because escaping ran first. */
            s=s.replace(/(^|\n)((?:&gt; ?.*(?:\n|$))+)/g,function(_,pre,quote){
                return pre+'<blockquote>'+quote.trim().split(/\n/).map(function(l){return l.replace(/^&gt; ?/,'');}).join(' ')+'</blockquote>';
            });
            s=s.replace(/(^|\n)(?:---|\*\*\*|___)[-*_ ]*(?=\n|$)/g,'$1<hr>');
            /* Ordered lists, then unordered */
            s=s.replace(/(^|\n)((?:\d+[.)] .+\n?)+)/g,function(_,pre,list){
                var items=list.trim().split(/\n/).map(function(l){return '<li>'+l.replace(/^\d+[.)] /,'')+'</li>';}).join('');

                return pre+'<ol>'+items+'</ol>';
            });
            /* Unordered lists */
            s=s.replace(/(^|\n)((?:[-*] .+\n?)+)/g,function(_,pre,list){
                var items=list.trim().split(/\n/).map(function(l){return '<li>'+l.replace(/^[-*] /,'')+'</li>';}).join('');
                return pre+'<ul>'+items+'</ul>';
            });
            /* Fences come back BEFORE the paragraph pass: re-injected after it, a
               <pre> sits inside a <p>, which is invalid — the browser closes the
               paragraph early and the rest of the block escapes the code block.
               The sentinel is a PUA code point written as a JS escape: it used to
               be a literal NUL byte, which made this whole file grep as binary.
               A ```json info string is dropped from the rendered output. */
            s=s.replace(/\uE000B(\d+)\uE000/g,function(_,i){return '\n\n<pre class="ax-md-pre"><code>'+escH(blocks[+i]).replace(/^[a-zA-Z0-9]*\n/,'')+'</code></pre>\n\n';});
            /* Paragraphs. A single newline is a SOFT break — a space — as in
               every markdown renderer, so a PHPDoc hard-wrapped at 80 columns
               reflows to the width it is given instead of keeping the source's
               ragged line ends at every viewport. A hard break is markdown's
               own: two trailing spaces, or a trailing backslash. */
            s=s.split(/\n{2,}/).map(function(p){
                if(p.trim()==='')return '';
                if(/^<(h\d|ul|ol|pre|blockquote|div|hr|table)/.test(p.trim()))return p;
                return '<p>'+p.replace(/(?: {2,}|\\)\n/g,'<br>').replace(/\n/g,' ')+'</p>';
            }).join('');
            return s;
        }

        /* ── Property badges builder ── */
        function propBadges(pv,isRequired){
            var b='';
            if(isRequired)b+='<span class="ax-badge ax-b-req" title="Required">required</span>';
            if(pv.nullable||(Array.isArray(pv.type)&&pv.type.indexOf('null')!==-1))b+='<span class="ax-badge ax-b-null" title="Nullable">nullable</span>';
            if(pv.readOnly)b+='<span class="ax-badge ax-b-ro" title="Read-only">readOnly</span>';
            if(pv.writeOnly)b+='<span class="ax-badge ax-b-wo" title="Write-only">writeOnly</span>';
            if(pv.deprecated)b+='<span class="ax-badge ax-b-dep" title="Deprecated">deprecated</span>';
            if(pv.format)b+='<span class="ax-badge ax-b-fmt" title="Format">'+escH(pv.format)+'</span>';
            if(pv['default']!==undefined)b+='<span class="ax-badge ax-b-def" title="Default value">default: '+escH(JSON.stringify(pv['default']))+'</span>';
            if(pv.minimum!=null||pv.maximum!=null){var r='';if(pv.minimum!=null)r+='≥'+pv.minimum;if(pv.maximum!=null)r+=(r?' ':'')+'≤'+pv.maximum;b+='<span class="ax-badge ax-b-rng">'+escH(r)+'</span>';}
            if(pv.minLength!=null||pv.maxLength!=null){var r2='';if(pv.minLength!=null)r2+='≥'+pv.minLength;if(pv.maxLength!=null)r2+=(r2?' ':'')+'≤'+pv.maxLength;b+='<span class="ax-badge ax-b-rng">'+escH(r2)+' chars</span>';}
            if(pv.pattern)b+='<span class="ax-badge ax-b-pat" title="Pattern: '+escH(pv.pattern)+'">regex</span>';
            return b;
        }

        /* ── Request history (per-endpoint, last 10) ── */
        function histKey(method,path){return 'hist:'+method+':'+path;}
        function histPush(method,path,entry){
            var k=histKey(method,path);var arr=lsGetJson(k,[]);
            entry.ts=Date.now();
            arr.unshift(entry);arr=arr.slice(0,10);
            lsSetJson(k,arr);
        }
        function histLoad(method,path){return lsGetJson(histKey(method,path),[]);}
        function histClear(method,path){try{localStorage.removeItem(_ns+':'+histKey(method,path));}catch(e){}}
        window.axHistClear=function(method,path){histClear(method,path);renderHistory(method,path);toast('History cleared');};
        window.axHistRestore=function(method,path,idx){
            var arr=histLoad(method,path);var h=arr[+idx];if(!h)return;
            /* A v1 entry keyed its parameters by bare name, so path and query
               values are indistinguishable: replaying one would fill the wrong
               field. Restore the body and skip the rest. */
            if(h.v!==2){
                if(h.body!=null){var b1=document.getElementById('axi-body');if(b1)b1.value=h.body;}
                toast('Older history entry — only the body was restored');

                return;
            }
            if(h.params){for(var k in h.params){var el=document.getElementById(k);if(el)el.value=h.params[k];}}
            if(h.headers){for(var hk in h.headers){var hel=document.getElementById('axi-h-'+hk);if(hel)hel.value=h.headers[hk];}}
            if(h.body!=null){var b=document.getElementById('axi-body');if(b)b.value=h.body;}
            toast('Restored from history');
        };
        function renderHistory(method,path){
            var box=document.getElementById('ax-hist-list');if(!box)return;
            var arr=histLoad(method,path);
            if(!arr.length){box.innerHTML='<div class="ax-hist-empty">No history yet  send a request to record it.</div>';return;}
            box.innerHTML=arr.map(function(h,i){
                var ago=Math.round((Date.now()-h.ts)/1000);
                var rel=ago<60?ago+'s ago':ago<3600?Math.round(ago/60)+'m ago':Math.round(ago/3600)+'h ago';
                var sc=h.status||0;var cls=sc<300?'ax-res-s-ok':sc<400?'ax-res-s-info':sc<500?'ax-res-s-warn':'ax-res-s-err';
                return '<div class="ax-hist-item" onclick="axHistRestore(\''+method+'\',\''+escH(path)+'\','+i+')">'
                    +'<span class="'+cls+'" style="font-size:10px;padding:1px 6px;border-radius:4px">'+escH(String(sc||''))+'</span>'
                    +'<span class="ax-hist-time">'+rel+'</span>'
                    +(h.ms?'<span class="ax-hist-ms">'+h.ms+'ms</span>':'')
                    +'</div>';
            }).join('')+'<button class="ax-hist-clear" onclick="axHistClear(\''+method+'\',\''+escH(path)+'\')">Clear history</button>';
        }

        /* ── Auth token persistence ── */
        function authPersist(){
            var a=document.getElementById('axi-auth');var t=document.getElementById('axi-auth-type');
            if(a)lsSet('auth.token',a.value||'');
            if(t)lsSet('auth.type',t.value||'bearer');
        }
        function authRestore(){
            var a=document.getElementById('axi-auth');var t=document.getElementById('axi-auth-type');
            if(t){t.value=lsGet('auth.type','bearer');if(window.axAuthTypeChange)axAuthTypeChange();}
            if(a){a.value=lsGet('auth.token','');}
            if(a)a.addEventListener('input',authPersist);
            if(t)t.addEventListener('change',authPersist);
        }

        /* ── Bulk JSON edit for header/query/path groups ── */
        function bulkGroupEl(gid){return document.querySelector('.ax-bulk-group[data-gid="'+gid+'"]');}
        function bulkReadFields(group){
            var prefix=group.dataset.prefix;var names=JSON.parse(group.dataset.names||'[]');
            var out={};
            names.forEach(function(n){
                var el=document.getElementById(prefix+n);
                if(el&&el.value!=='')out[n]=el.value;
            });
            return out;
        }
        function bulkWriteFields(group,obj){
            var prefix=group.dataset.prefix;var names=JSON.parse(group.dataset.names||'[]');var unknown=[];
            Object.keys(obj||{}).forEach(function(k){
                var el=document.getElementById(prefix+k);
                if(el){el.value=obj[k]==null?'':typeof obj[k]==='string'?obj[k]:JSON.stringify(obj[k]);}
                else if(names.indexOf(k)===-1){unknown.push(k);}
            });
            return unknown;
        }
        window.axBulkToggle=function(gid){
            var g=bulkGroupEl(gid);if(!g)return;
            var area=document.getElementById(gid+'-area');var btn=g.querySelector('.ax-bulk-btn');
            var fields=g.querySelector('.ax-bulk-fields');var actions=g.querySelector('.ax-bulk-actions');
            var on=!area.classList.contains('show');
            if(on){
                area.value=JSON.stringify(bulkReadFields(g),null,2);
                area.classList.add('show');actions.style.display='';fields.classList.add('hidden');btn.classList.add('active');
                setTimeout(function(){area.focus();area.select();},10);
            } else {
                area.classList.remove('show');actions.style.display='none';fields.classList.remove('hidden');btn.classList.remove('active');
                var err=document.getElementById(gid+'-err');if(err)err.textContent='';
            }
        };
        window.axBulkApply=function(gid){
            var g=bulkGroupEl(gid);if(!g)return;
            var area=document.getElementById(gid+'-area');var err=document.getElementById(gid+'-err');
            err.textContent='';
            var txt=(area.value||'').trim();
            if(!txt){bulkWriteFields(g,{});axBulkToggle(gid);return;}
            var parsed;try{parsed=JSON.parse(txt);}catch(e){err.textContent='Invalid JSON: '+e.message;return;}
            if(typeof parsed!=='object'||Array.isArray(parsed)||parsed===null){err.textContent='Expected a JSON object {…}';return;}
            /* Clear existing field values first so removed keys are reflected */
            var prefix=g.dataset.prefix;var names=JSON.parse(g.dataset.names||'[]');
            names.forEach(function(n){var el=document.getElementById(prefix+n);if(el)el.value='';});
            var unknown=bulkWriteFields(g,parsed);
            if(unknown.length){err.textContent='Ignored unknown keys: '+unknown.join(', ');err.style.color='var(--amber)';}
            else{toast('Applied '+Object.keys(parsed).length+' values');axBulkToggle(gid);}
        };
        window.axBulkSyncFromFields=function(gid){
            var g=bulkGroupEl(gid);if(!g)return;
            var area=document.getElementById(gid+'-area');
            area.value=JSON.stringify(bulkReadFields(g),null,2);
            toast('Synced from fields');
        };
        window.axBulkCopy=function(gid){
            var area=document.getElementById(gid+'-area');if(!area)return;
            if(navigator.clipboard)navigator.clipboard.writeText(area.value).then(function(){toast('Copied JSON');});
            else{fb(area.value);toast('Copied JSON');}
        };

        /* ── OAuth2 implicit-flow helper ── */
        function findOAuth2Scheme(spec){
            var schemes=(spec.components&&spec.components.securitySchemes)||{};
            for(var k in schemes){if(schemes[k]&&schemes[k].type==='oauth2')return {name:k,scheme:schemes[k]};}
            return null;
        }
        function wireOAuthHelper(spec){
            var found=findOAuth2Scheme(spec);if(!found)return;
            var authWrap=document.querySelector('.ax-try-auth-wrap');if(!authWrap||authWrap.querySelector('.ax-oauth-btn'))return;
            var flows=found.scheme.flows||{};
            var implicit=flows.implicit||flows.authorizationCode;
            if(!implicit||!implicit.authorizationUrl)return;
            var btn=document.createElement('button');btn.className='ax-oauth-btn';btn.type='button';btn.textContent='Get token';btn.title='Open OAuth2 authorization';
            btn.onclick=function(){
                var cid=lsGet('oauth.client_id','')||prompt('OAuth2 client_id:','');
                if(!cid)return;
                lsSet('oauth.client_id',cid);
                var scopes=Object.keys(implicit.scopes||{}).join(' ');
                var redirect=location.origin+location.pathname;
                var url=implicit.authorizationUrl+(implicit.authorizationUrl.indexOf('?')>-1?'&':'?')
                    +'response_type=token&client_id='+encodeURIComponent(cid)
                    +'&redirect_uri='+encodeURIComponent(redirect)
                    +(scopes?'&scope='+encodeURIComponent(scopes):'')
                    +'&state='+Math.random().toString(36).slice(2);
                var w=window.open(url,'apex_oauth','width=600,height=720');
                var poll=setInterval(function(){
                    try{
                        if(!w||w.closed){clearInterval(poll);return;}
                        var h=w.location.hash||'';
                        var m=/access_token=([^&]+)/.exec(h);
                        if(m){
                            var token=decodeURIComponent(m[1]);
                            var a=document.getElementById('axi-auth');if(a){a.value=token;authPersist();toast('Token received');}
                            w.close();clearInterval(poll);
                        }
                    }catch(e){/* cross-origin until redirect lands */}
                },500);
            };
            authWrap.appendChild(btn);
        }

        /* ── Shortcuts modal ── */
        window.axShortcutsOpen=function(){var m=document.getElementById('ax-shortcuts');if(m)m.hidden=false;};
        window.axShortcutsClose=function(){var m=document.getElementById('ax-shortcuts');if(m)m.hidden=true;};

        /* ── Overflow (⋯) menu ──
           Whatever the current width hides from the toolbar lives here, so this
           handler is the only thing standing between a narrow viewport and an
           unreachable control. */
        var moreBtn=document.getElementById('apex-more-btn'),moreDlg=document.getElementById('apex-more');
        if(moreBtn&&moreDlg&&moreDlg.showModal){
            moreBtn.addEventListener('click',function(){
                /* Anchor the sheet under the whole toolbar, not under the button:
                   the button is shorter than the bar and centred in it, so its
                   own bottom edge is inside the bar. One read, at open time, is
                   the only way a top-layer element can know where a grid row
                   put the toolbar. */
                var anchor=moreBtn.closest('#apex-bar')||moreBtn;
                moreDlg.style.setProperty('--anchor-y',Math.round(anchor.getBoundingClientRect().bottom+6)+'px');
                moreDlg.showModal();moreBtn.setAttribute('aria-expanded','true');
            });
            /* `close` covers Escape and the native cancel path too. */
            moreDlg.addEventListener('close',function(){moreBtn.setAttribute('aria-expanded','false');moreBtn.focus();});
            moreDlg.addEventListener('click',function(e){
                /* The dialog fills the viewport and the sheet is a child, so a
                   click on the dialog itself is a click outside the sheet. */
                if(e.target===moreDlg){moreDlg.close();return;}
                var item=e.target.closest('.apex-more-item');if(!item)return;
                /* The export links are plain anchors: they download and the menu
                   closes behind them, with no action to dispatch. */
                moreDlg.close();
                /* The document-level click handlers below close the export menu
                   and the env popover. Letting this click reach them would shut
                   the popover we are about to open, in the same tick. */
                e.stopPropagation();
                var act=item.getAttribute('data-more');
                if(act==='theme')apexThemeCycle();
                else if(act==='env'){var eb=document.getElementById('apexEnvBtn');if(eb)eb.click();}
                else if(act==='kbd')axShortcutsOpen();
                else if(act==='copy')apexCopy(item);
            });
        }

        /* ── Nav drawer ──
           The state class goes on <html>: the backdrop and the scroll lock are a
           sibling and an ancestor of the drawer, and only a documentElement-scoped
           selector reaches all three. `inert` is applied on top of the CSS
           `visibility:hidden`, but ONLY while this node is the overlay  at
           >=900px it is the static navigation column and must stay reachable. */
        var _mqShell=window.matchMedia('(min-width:900px)');
        function drawerSync(){
            var open=document.documentElement.classList.contains('ax-nav-open');
            var btn=document.getElementById('apex-nav-btn');
            var sb=document.getElementById('axui-sidebar');
            if(btn)btn.setAttribute('aria-expanded',open?'true':'false');
            if(sb){if(!_mqShell.matches&&!open)sb.setAttribute('inert','');else sb.removeAttribute('inert');}
        }
        window.axSidebarToggle=function(){
            document.documentElement.classList.toggle('ax-nav-open');drawerSync();
        };
        window.axSidebarClose=function(){
            document.documentElement.classList.remove('ax-nav-open');drawerSync();
        };
        drawerSync();
        if(_mqShell.addEventListener)_mqShell.addEventListener('change',drawerSync);

        JS;
    }

    /**
     * Schema-view navigation, `init`, the error and empty states, and the
     * sidebar: `groupByTag`, `renderSidebar`, the footer and every nav entry point.
     */
    private function jsNav(): string
    {
        return <<<'JS'
        /* The hash this session wrote, so the listener does not re-render a view
           that has just rendered itself. */
        var _lastHash='';

        /* ── Schema browser navigation ── */
        window.axNavSchema=function(name){
            _activeSchemaName=name;_activeOpKey=null;
            var spec=_specCache;if(!spec||!spec.components||!spec.components.schemas)return;
            var schema=spec.components.schemas[name];if(!schema)return;
            renderSidebar(spec);
            renderSchemaView(name,schema,spec);
            _lastHash='schema_'+encodeURIComponent(name);history.replaceState(null,'','#'+_lastHash);
            var c=document.getElementById('axui-content');if(c)c.scrollTop=0;
            axSidebarClose();
        };
        /* Which operations really reference this schema. The old test was
           `JSON.stringify(op).indexOf('#/components/schemas/'+name)`: a
           substring match, so `User` was "used by" every operation touching
           `UserProfile` or `UserList`, and every operation was re-stringified
           on every schema view. This walks the operation structurally and
           compares whole ref strings, following refs one level so a schema used
           only through another schema still counts. */
        function refsSchema(node,target,spec,seen,refDepth){
            if(!node||typeof node!=='object')return false;
            if(Array.isArray(node)){
                for(var i=0;i<node.length;i++)if(refsSchema(node[i],target,spec,seen,refDepth))return true;

                return false;
            }
            if(typeof node['$ref']==='string'){
                if(node['$ref']===target)return true;
                /* Follow, so a schema reached only through another schema still
                   counts as used. `seen` makes a recursive model terminate; the
                   spec itself is acyclic, having come from JSON. */
                if(refDepth<3&&!seen[node['$ref']]){
                    seen[node['$ref']]=1;
                    if(refsSchema(resolveRef(node['$ref'],spec),target,spec,seen,refDepth+1))return true;
                }
            }
            for(var k in node){
                if(k==='$ref')continue;
                if(refsSchema(node[k],target,spec,seen,refDepth))return true;
            }

            return false;
        }

        function renderSchemaView(name,schema,spec){
            _schemaIds=0;
            var usedBy=[];
            var target='#/components/schemas/'+name;
            for(var p in spec.paths||{}){for(var m in spec.paths[p]){
                if(!METHODS.includes(m))continue;
                var op=spec.paths[p][m];if(!op||typeof op!=='object')continue;
                if(refsSchema(op,target,spec,{},0))usedBy.push({path:p,method:m,op:op});
            }}
            var usedHtml=usedBy.length?'<div class="ax-section"><div class="ax-section-title">Used by ('+usedBy.length+')</div><div class="ax-used-list">'
                +usedBy.map(function(u){return '<a class="ax-used-item" href="#'+escH(axHashFor(u.op,u.method+'__'+u.path))+'"><span class="axm axm-'+u.method+'">'+u.method.toUpperCase()+'</span><span>'+escH(u.path)+'</span></a>';}).join('')
                +'</div></div>':'';
            setDoc('<div class="ax-breadcrumb"><span class="ax-breadcrumb-item ax-breadcrumb-link" onclick="axGoWelcome()">Schemas</span><span class="ax-breadcrumb-sep">›</span><span class="ax-breadcrumb-current">'+escH(name)+'</span></div>'
                +'<div class="ax-op-header"><div class="ax-op-title-wrap"><h1 class="ax-op-path">'+escH(name)+'</h1>'
                +(schema.title?'<div class="ax-op-summary">'+escH(schema.title)+'</div>':'')+'</div></div>'
                +(schema.description?'<div class="ax-op-desc">'+md(schema.description)+'</div>':'')
                +'<div class="ax-section"><div class="ax-section-title-row"><div class="ax-section-title">Definition</div>'+expandToggle()+'</div>'+renderSchema(schema,spec,0)+'</div>'
                +usedHtml);
            setPanel('<div class="ax-panel-section-title">JSON Schema</div><pre class="ax-code">'+hlJson(escH(JSON.stringify(schema,null,2)))+'</pre>');
        }
        /* A Responses Object key is a status code, a wildcard range (`2XX`), or
           the literal `default`. */
        function statusClass(status){
            var key=String(status).toLowerCase();
            if(key==='default')return 'axs-default';
            var lead=key.charAt(0);

            return lead==='1'?'axs-1xx':lead==='2'?'axs-2xx':lead==='3'?'axs-3xx'
                :lead==='4'?'axs-4xx':lead==='5'?'axs-5xx':'axs-default';
        }

        /* Numeric codes first, then wildcard ranges, then `default` — the order
           a reader scans them in. */
        function respOrder(a,b){
            var rank=function(k){
                k=String(k).toLowerCase();
                if(k==='default')return [3,0];
                if(/^\d{3}$/.test(k))return [1,parseInt(k,10)];
                if(/^\dxx$/.test(k))return [2,parseInt(k.charAt(0),10)];

                return [4,0];
            };
            var ra=rank(a),rb=rank(b);

            return ra[0]-rb[0]||ra[1]-rb[1]||String(a).localeCompare(String(b));
        }

        /* @param headers  a Headers Object: name => Header Object|$ref */
        function respHeaders(headers,spec){
            if(!headers||typeof headers!=='object')return '';
            var names=Object.keys(headers);
            if(!names.length)return '';

            return '<div class="ax-resp-hdrs"><div class="ax-resp-sub">Headers</div><div class="ax-tablewrap"><table class="ax-params"><thead><tr>'
                +'<th>Name</th><th>Type</th><th>Description</th></tr></thead><tbody>'
                +names.map(function(name){
                    var h=deref(headers[name],spec)||{};
                    var sch=h.schema||{};
                    var type=Array.isArray(sch.type)?sch.type.filter(function(t){return t!=='null';})[0]:sch.type;
                    if(!type&&sch['$ref'])type=sch['$ref'].split('/').pop();

                    return '<tr><td data-label="Name"><code class="ax-hdr-name">'+escH(name)+'</code></td>'
                        +'<td data-label="Type"><span class="ax-type-badge">'+escH(type||'string')+'</span>'
                        +(h.required?'<span class="ax-badge ax-b-req">required</span>':'')
                        +(h.deprecated?'<span class="ax-badge">deprecated</span>':'')+'</td>'
                        +'<td data-label="Description" class="axw-md" style="color:var(--t3)">'+(h.description?md(h.description):'')+'</td></tr>';
                }).join('')
                +'</tbody></table></div></div>';
        }

        /* A Links Object names the operations reachable from this response. */
        function respLinks(links,spec){
            if(!links||typeof links!=='object')return '';
            var names=Object.keys(links);
            if(!names.length)return '';

            return '<div class="ax-resp-hdrs"><div class="ax-resp-sub">Links</div><div class="ax-used-list">'
                +names.map(function(name){
                    var l=deref(links[name],spec)||{};
                    var target=l.operationId||l.operationRef||'';
                    var href=l.operationId?'#op_'+escH(encodeURIComponent(l.operationId)):'';
                    var label='<span class="ax-link-name">'+escH(name)+'</span>'
                        +(target?'<span class="ax-link-target">'+escH(target)+'</span>':'')
                        +(l.description?'<span class="ax-link-desc">'+escH(l.description)+'</span>':'');

                    return href
                        ? '<a class="ax-used-item" href="'+href+'">'+label+'</a>'
                        : '<div class="ax-used-item ax-used-static">'+label+'</div>';
                }).join('')
                +'</div></div>';
        }

        function expandToggle(){return '<div class="ax-expand-toggle"><button type="button" onclick="axExpandAll(true,this)">Expand all</button><button type="button" onclick="axExpandAll(false,this)">Collapse all</button></div>';}
        /* Scoped to the section it was clicked in. Every section emits its own
           pair of buttons, but all of them called one page-global function, so
           "Collapse all" under Request Body collapsed every response schema
           too — and `_expandAll` then disagreed with the DOM. */
        window.axExpandAll=function(open,btn){
            _expandAll=open;
            var scope=(btn&&btn.closest&&btn.closest('.ax-section'))||document;
            scope.querySelectorAll('.ax-ref-expanded,.ax-prop-nested').forEach(function(el){el.style.display=open?'':'none';});
            scope.querySelectorAll('.ax-schema-collapse-btn').forEach(function(b){
                b.textContent=open?'▼':'▶';
                b.setAttribute('aria-expanded',open?'true':'false');
            });
        };

        function init(){
            pruneStorage();
            loadSpec(function(spec){
                if(!spec||typeof spec!=='object'){renderError('Spec is empty or invalid JSON');return;}
                _server=_server||(spec.servers&&spec.servers[0]&&spec.servers[0].url)||'';
                renderSidebar(spec);
                var pathCount=Object.keys(spec.paths||{}).length;
                if(pathCount===0&&!Object.keys(spec.webhooks||{}).length){renderEmptySpec(spec);}
                else{renderWelcome(spec);}
                renderSidebarFooter(spec);
                var hash=location.hash.slice(1);if(hash)navigateHash(hash,spec);
                /* The sidebar navigates by href, so the hash — not a click
                   handler — is what selects a view. This is also what makes the
                   browser's Back button walk the endpoints visited. */
                window.addEventListener('hashchange',function(){
                    var h=location.hash.slice(1);
                    if(!h){axGoWelcome();return;}
                    if(h!==_lastHash)navigateHash(h,_specCache||spec);
                });
                var filter=document.getElementById('axui-filter');
                if(filter){filter.addEventListener('input',function(){renderSidebar(spec,filter.value);});}
            },function(err){renderError(err);});
            /* No DOM probing: the fetch's own deadline reports the timeout, and
               a spec that arrives late can no longer paint over the error. */
        }
        function renderError(msg){
            /* Both panes, not just the sidebar: the main area is where the reader
               is looking, and a spec that failed to load leaves it empty. */
            var card='<div class="ax-error-state"><div class="ax-error-icon">⚠</div><div class="ax-error-title">Failed to load spec</div><div class="ax-error-msg">'+escH(String(msg||'Unknown error'))+'</div><div class="ax-error-url">'+escH(APEX_CFG.specUrl||'')+'</div><button class="ax-error-retry" onclick="axRetrySpec()">Retry</button></div>';
            var body=document.getElementById('axui-sidebar-body');
            if(body)body.innerHTML=card;
            setDoc(card);setPanel('');
        }
        function renderEmptySpec(spec){
            setDoc('<h1 class="axw-title">'+escH((spec.info&&spec.info.title)||'API')+'</h1>'
                +'<div class="ax-empty-spec"><div class="ax-empty-icon">📭</div><div class="ax-empty-title">No endpoints documented yet</div>'
                +'<div class="ax-empty-msg">Add controllers with route attributes, then regenerate the spec.</div></div>');
            setPanel('');
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
                    +'<button type="button" class="axg-header" aria-expanded="'+(open?'true':'false')+'" aria-controls="axg-items-'+escH(tag)+'" onclick="axToggleGroup(this)">'
                    +'<span class="axg-arrow" aria-hidden="true">▶</span>'
                    +'<span class="axg-name">'+escH(tag)+'</span>'
                    +'<span class="axg-count">'+items.length+'</span>'
                    +'</button><div class="axg-items" id="axg-items-'+escH(tag)+'">';
                items.forEach(function(i){
                    var dep=i.op.deprecated?' axi-depr':'';
                    /* A real <a href>: keyboard-reachable, focusable, and
                       middle-click/cmd-click opens the endpoint in a new tab —
                       none of which a <div onclick> can do. The hashchange
                       listener renders it, which also makes Back work. */
                    var active=i.key===_activeOpKey;
                    html+='<a class="axi'+(active?' active':'')+dep+'" href="#'+escH(axHashFor(i.op,i.key))+'"'+(active?' aria-current="page"':'')+' data-key="'+escH(i.key)+'" data-path="'+escH(i.path)+'" data-method="'+escH(i.method)+'">'
                        +'<span class="axm axm-'+i.method+'">'+i.method.toUpperCase()+'</span>'
                        +'<span class="axi-path">'+escH(i.path)+'</span>'
                        +(i.op.deprecated?'<span class="axi-depr-dot">D</span>':'')
                        +'</a>';
                });
                html+='</div></div>';
            }
            // Schemas section
            var schemas=(spec.components&&spec.components.schemas)||{};
            var schemaKeys=Object.keys(schemas);
            if(q)schemaKeys=schemaKeys.filter(function(n){return n.toLowerCase().includes(q);});
            if(schemaKeys.length){
                anyResult=true;
                var schOpen=(_activeSchemaName||q)?' open':'';
                html+='<div class="axg'+schOpen+'" id="axg-__schemas__">'
                    +'<button type="button" class="axg-header" aria-expanded="'+(schOpen?'true':'false')+'" aria-controls="axg-items-schemas" onclick="axToggleGroup(this)">'
                    +'<span class="axg-arrow" aria-hidden="true">▶</span>'
                    +'<span class="axg-name">Schemas</span>'
                    +'<span class="axg-count">'+schemaKeys.length+'</span>'
                    +'</button><div class="axg-items" id="axg-items-schemas">';
                schemaKeys.forEach(function(n){
                    var active=(n===_activeSchemaName);
                    html+='<a class="axi axi-schema'+(active?' active':'')+'" href="#schema_'+encodeURIComponent(n)+'"'+(active?' aria-current="page"':'')+'>'
                        +'<span class="axm axm-schema" aria-hidden="true">{}</span>'
                        +'<span class="axi-path">'+escH(n)+'</span>'
                        +'</a>';
                });
                html+='</div></div>';
            }
            // Webhooks section
            var wh=spec.webhooks||{};var whKeys=Object.keys(wh);
            if(whKeys.length&&!q){
                var whActive=_activeOpKey&&_activeOpKey.startsWith('webhook__');
                html+='<div class="axg'+(whActive?' open':'')+'" id="axg-__webhooks__">'
                    +'<button type="button" class="axg-header" aria-expanded="'+(whActive?'true':'false')+'" aria-controls="axg-items-webhooks" onclick="axToggleGroup(this)">'
                    +'<span class="axg-arrow" aria-hidden="true">▶</span>'
                    +'<span class="axg-name">Webhooks</span>'
                    +'<span class="axg-count">'+whKeys.length+'</span>'
                    +'</button><div class="axg-items" id="axg-items-webhooks">';
                whKeys.forEach(function(wname){
                    var wop=wh[wname];
                    for(var wm in wop){
                        if(!METHODS.includes(wm))continue;
                        var whKey='webhook__'+wname+'__'+wm;
                        html+='<a class="axi'+(whKey===_activeOpKey?' active':'')+'" href="#wh_'+encodeURIComponent(wname)+'_'+escH(wm)+'" data-key="'+escH(whKey)+'" data-wname="'+escH(wname)+'" data-wmethod="'+escH(wm)+'">'
                            +'<span class="axm axm-'+wm+'">'+wm.toUpperCase()+'</span>'
                            +'<span class="axi-path">'+escH(wname)+'</span>'
                            +'<span class="ax-webhook-badge">wh</span>'
                            +'</a>';
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

        /* The deep link an endpoint already answers to, so the sidebar can be a
           list of real links instead of click handlers. */
        function axHashFor(op,key){
            return 'op_'+((op&&op.operationId)||key);
        }
        window.axToggleGroup=function(el){
            var open=el.closest('.axg').classList.toggle('open');
            el.setAttribute('aria-expanded',open?'true':'false');
        };
        window.axNavEl=function(el){var g=el.closest('.axg');if(g)g.classList.add('open');axNav(el.dataset.key,el.dataset.path,el.dataset.method);};
        window.axNavWhEl=function(el){var g=el.closest('.axg');if(g)g.classList.add('open');axNavWebhook(el.dataset.wname,el.dataset.wmethod);};

        /* Every navigation closes the drawer. Tapping an endpoint on a phone that
           left the nav covering the page it just opened was the whole reason the
           drawer felt broken. */
        window.axNav=function(key,path,method){
            _activeOpKey=key;_activeSchemaName=null;
            var spec=_specCache;if(!spec)return;
            var op=spec.paths&&spec.paths[path]&&spec.paths[path][method];if(!op)return;
            renderSidebar(spec);
            renderOperation(path,method,op,spec,false);
            _lastHash='op_'+(op.operationId||key);history.replaceState(null,'','#'+_lastHash);
            var c=document.getElementById('axui-content');if(c)c.scrollTop=0;
            axSidebarClose();
        };

        window.axNavWebhook=function(name,method){
            _activeOpKey='webhook__'+name+'__'+method;
            var spec=_specCache;if(!spec)return;
            var op=spec.webhooks&&spec.webhooks[name]&&spec.webhooks[name][method];if(!op)return;
            renderSidebar(spec);
            renderOperation(name,method,op,spec,true);
            _lastHash='wh_'+name+'_'+method;history.replaceState(null,'','#'+_lastHash);
            var c=document.getElementById('axui-content');if(c)c.scrollTop=0;
            axSidebarClose();
        };

        JS;
    }

    /** Hash resolution: turning a deep link into a rendered view. */
    private function jsIndex(): string
    {
        return <<<'JS'
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
            if(hash.startsWith('schema_')){
                var sn=decodeURIComponent(hash.slice(7));axNavSchema(sn);
            }
        }

        JS;
    }

    /**
     * `renderWelcome`, `renderOperation` and the controls they emit 
     * content-type switchers, the response accordion, examples, permalinks.
     */
    private function jsDoc(): string
    {
        return <<<'JS'
        function renderWelcome(spec){
            var info=spec.info||{};var paths=spec.paths||{};
            var opCount=0;var tagSet=new Set();var deprCount=0;
            for(var p in paths)for(var m in paths[p])if(METHODS.includes(m)){opCount++;if(paths[p][m].deprecated)deprCount++;(paths[p][m].tags||['General']).forEach(function(t){tagSet.add(t);});}
            var schemaCount=Object.keys((spec.components&&spec.components.schemas)||{}).length;
            var whCount=Object.keys(spec.webhooks||{}).length;
            var svgEp='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon" aria-hidden="true"><path d="M1 4h14M1 8h14M1 12h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
            var svgGr='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon" aria-hidden="true"><rect x="1" y="1" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="10" y="1" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="1" y="10" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="10" y="10" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/></svg>';
            var svgSc='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon" aria-hidden="true"><rect x="1" y="3" width="14" height="10" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M5 7h6M5 10h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
            var svgWh='<svg width="18" height="18" viewBox="0 0 16 16" fill="none" class="axw-stat-icon" aria-hidden="true"><path d="M3 13c0-3 2-5 5-5s5 2 5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="8" cy="5" r="2.5" stroke="currentColor" stroke-width="1.4"/></svg>';
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
            setDoc('<h1 class="axw-title">'+escH(info.title||'API')+'</h1>'
                +'<div class="axw-meta"><span class="axw-version">v'+escH(info.version||'1.0')+'</span><span class="axw-openapi">'+escH(spec.openapi||'OpenAPI')+'</span>'+(deprCount?'<span style="font-size:11px;color:var(--amber);padding:2px 6px;border-radius:4px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2)">'+deprCount+' deprecated</span>':'')+'</div>'
                +(info.description?'<div class="axw-desc axw-md">'+md(info.description)+'</div>':'')
                +stats
                +(metaHtml?'<div class="axw-contact-block">'+metaHtml+'</div>':'')
                +(servers?'<div class="axw-servers"><div class="axw-servers-title">Servers</div>'+servers+'</div>':'')
                +'<div class="axw-hint">Select an endpoint from the sidebar, or press <kbd style="padding:2px 6px;border-radius:4px;background:var(--s2);border:1px solid var(--border);font-family:inherit">⌘K</kbd> to search</div>');
            /* The overview has no request to make. */
            setPanel('');
        }

        /* An operation may point at `components` instead of inlining: a Response,
           a Parameter and a Request Body are all $ref-able, and ApexDocs itself
           emits the inferred 401/422/429 as `#/components/responses/…`. Every
           consumer below reads plain objects — an unresolved response has no
           `description` and no `content`, so its accordion opened onto an empty
           body that `:empty{display:none}` then hid, and the click looked dead.
           Resolved once here, on a shallow copy: `op` belongs to the cached
           spec, which the JSON view of this page also shows. */
        function deref(node,spec){
            return node&&typeof node==='object'&&node['$ref']
                ? (resolveRef(node['$ref'],spec)||node)
                : node;
        }
        function derefOp(op,spec,pathItem){
            if(!op||typeof op!=='object')return op;
            var out={};
            for(var k in op)out[k]=op[k];
            if(out.requestBody)out.requestBody=deref(out.requestBody,spec);

            /* Parameters declared on the Path Item apply to every method under
               it and were dropped on all of them. Merged with the operation's
               own, deduped by name+in with the operation winning, as the spec
               requires. */
            var merged=[]
                .concat((pathItem&&Array.isArray(pathItem.parameters))?pathItem.parameters:[])
                .concat(Array.isArray(out.parameters)?out.parameters:[])
                .map(function(p){return deref(p,spec);});
            if(merged.length){
                var byKey={},order=[];
                merged.forEach(function(p){
                    if(!p||typeof p!=='object')return;
                    var key=(p.name||'')+'\u0000'+(p['in']||'');
                    if(!(key in byKey))order.push(key);
                    byKey[key]=p;
                });
                out.parameters=order.map(function(k){return byKey[k];});
            }

            if(out.responses){
                var resolved={};
                Object.keys(out.responses).forEach(function(status){resolved[status]=deref(out.responses[status],spec);});
                out.responses=resolved;
            }

            /* Document-level defaults. An API that declares its security once,
               at the root, read as unauthenticated on every operation. */
            if(out.security===undefined&&Array.isArray(spec&&spec.security))out.security=spec.security;
            if(!Array.isArray(out.servers)&&pathItem&&Array.isArray(pathItem.servers))out.servers=pathItem.servers;

            return out;
        }

        /* The base URL an operation is actually served from: its own `servers`
           override the document's, and the try-it panel and code samples must
           agree with the documentation above them. */
        function opServer(op){
            var own=op&&Array.isArray(op.servers)&&op.servers[0]&&op.servers[0].url;

            return own||_server||'';
        }

        function renderOperation(path,method,op,spec,isWebhook){
            var pathItem=(isWebhook?(spec.webhooks||{}):(spec.paths||{}))[path];
            op=derefOp(op,spec,pathItem);
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
            var plBtn='<button class="ax-permalink-btn" onclick="axCopyPermalink(\''+escH(opId)+'\')" title="Copy permalink"><svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M7 9a3 3 0 0 0 4.5.3l2-2A3 3 0 0 0 9.2 3L8.1 4.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9 7a3 3 0 0 0-4.5-.3l-2 2A3 3 0 0 0 6.8 13l1.1-1.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button>';
            /* Deprecation / sunset banner */
            var depBanner='';
            if(op.deprecated||op['x-sunset-date']||op['x-deprecation-notice']||op['x-migration-guide']){
                var notice=op['x-deprecation-notice']||'This endpoint is deprecated and will be removed in a future version.';
                var sunset=op['x-sunset-date']?'<div class="ax-dep-sunset">Sunset: <strong>'+escH(op['x-sunset-date'])+'</strong></div>':'';
                var mig=op['x-migration-guide']?'<a class="ax-dep-mig" href="'+escH(op['x-migration-guide'])+'" target="_blank" rel="noreferrer">Migration guide ↗</a>':'';
                depBanner='<div class="ax-dep-banner"><div class="ax-dep-icon">⚠</div><div class="ax-dep-body"><div class="ax-dep-title">Deprecated</div><div class="ax-dep-msg axw-md">'+md(notice)+'</div>'+sunset+mig+'</div></div>';
            }
            var html=bc+depBanner
                +'<div class="ax-op-header">'
                +'<span class="axm axm-'+method+'">'+method.toUpperCase()+'</span>'
                +'<div class="ax-op-title-wrap"><h1 class="ax-op-path">'+escH(path)+dep+plBtn+'</h1>'
                +(op.summary?'<div class="ax-op-summary">'+escH(op.summary)+'</div>':'')
                +'</div></div>'
                +(op.description?'<div class="ax-op-desc axw-md">'+md(op.description)+'</div>':'')
                +secHtml+extHtml;
            // Parameters
            var params=(op.parameters||[]);
            if(params.length){
                /* Two presentations, one markup: a table inside its own
                   horizontal scroller, and below 600px a card per parameter,
                   where cssDoc() moves each column heading into the cell's
                   ::before  hence data-label on every td. */
                html+='<div class="ax-section"><div class="ax-section-title">Parameters</div>'
                    +'<div class="ax-tablewrap" tabindex="0" role="group" aria-label="Parameters, scrollable">'
                    +'<table class="ax-params"><thead><tr><th>Name</th><th>In</th><th>Type</th><th>Required</th><th>Description</th></tr></thead><tbody>';
                params.forEach(function(p){
                    var sc=p.schema||{};var t=sc.type||(sc['$ref']?sc['$ref'].split('/').pop():'string');
                    var enums=sc.enum?'<div class="ax-enum-wrap">'+sc.enum.map(function(v){return '<span class="ax-enum-val">'+escH(String(v))+'</span>';}).join('')+'</div>':'';
                    html+='<tr><td class="ax-param-name" data-label="Name">'+escH(p.name)+(p.deprecated?'<sup style="color:var(--amber);font-size:9px"> dep</sup>':'')+'</td>'
                        +'<td data-label="In"><span class="ax-in-badge">'+escH(p.in)+'</span></td>'
                        +'<td data-label="Type"><span class="ax-type-badge">'+escH(t)+(sc.format?'<span style="opacity:.6"> ('+escH(sc.format)+')</span>':'')+'</span></td>'
                        +'<td data-label="Required">'+(p.required?'<span class="ax-req-badge">req</span>':'<span style="color:var(--t3);font-size:11px">opt</span>')+'</td>'
                        +'<td class="axw-md" data-label="Description" style="color:var(--t3)">'+(p.description?md(p.description):'')+enums+'</td></tr>';
                });
                html+='</tbody></table></div></div>';
            }
            // Request body
            if(op.requestBody){
                var ct=op.requestBody.content||{};var ctKeys=Object.keys(ct);
                html+='<div class="ax-section"><div class="ax-section-title-row"><div class="ax-section-title">Request Body'+(op.requestBody.required?'':' <span style="font-size:10px;font-weight:400;color:var(--t3)">(optional)</span>')+'</div>'+expandToggle()+'</div>';
                if(op.requestBody.description)html+='<div class="axw-md" style="font-size:13px;color:var(--t2);margin-bottom:10px">'+md(op.requestBody.description)+'</div>';
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
                /* `parseInt('default')` is NaN, which failed every comparison
                   and fell through to the 5xx branch: a `default` response
                   rendered as a red server error, and so did `2XX`. */
                html+='<div class="ax-section"><div class="ax-section-title-row"><div class="ax-section-title">Responses</div>'+expandToggle()+'</div>';
                var rKeys=Object.keys(op.responses).sort(respOrder);
                rKeys.forEach(function(status,ri){
                    var resp=op.responses[status];
                    var cls=statusClass(status);
                    var icon=cls==='axs-2xx'?'✓':cls==='axs-3xx'?'→':cls==='axs-4xx'||cls==='axs-5xx'?'✕':'•';
                    var isFirst=ri===0;
                    var bodyId='ax-resp-body-'+escH(status);
                    html+='<div class="ax-resp'+(isFirst?' open':'')+'" id="ax-resp-'+escH(status)+'">'
                        +'<button type="button" class="ax-resp-header" aria-expanded="'+(isFirst?'true':'false')+'" aria-controls="'+bodyId+'" onclick="axToggleResp(this)">'
                        +'<span class="ax-resp-status '+cls+'"><span aria-hidden="true">'+icon+'</span> '+escH(status)+'</span>'
                        +'<span class="ax-resp-desc">'+escH(resp.description||'')+'</span>'
                        +'<span class="ax-resp-arrow" aria-hidden="true">▶</span>'
                        +'</button>'
                        +'<div class="ax-resp-body" id="'+bodyId+'" style="'+(isFirst?'':'display:none')+'">';
                    /* `headers` and `links` were dropped outright, so the
                       package's own #[ResponseHeader] output — Retry-After,
                       X-RateLimit-*, Location, pagination cursors — was
                       invisible in the documentation it generates. */
                    html+=respHeaders(resp.headers,spec)+respLinks(resp.links,spec);

                    var rc=resp.content||{};var rck=Object.keys(rc);
                    if(rck.length>1){
                        html+='<div class="ax-resp-ct-tabs">';
                        rck.forEach(function(mime,i){html+='<button class="ax-resp-ct-btn'+(i===0?' active':'')+'" onclick="axSwitchRespCt(this,\'ax-resp-'+escH(status)+'\',\''+escH(mime)+'\')">'+escH(mime)+'</button>';});
                        html+='</div>';
                    }
                    rck.forEach(function(mime,i){
                        var mediaObj=rc[mime]||{};
                        var examples=mediaObj.examples||{};var exKeys=Object.keys(examples);
                        var exHtml='';
                        if(exKeys.length>=2){
                            var exId='ax-ex-'+escH(status)+'-'+i;
                            exHtml='<div class="ax-ex-block"><div class="ax-ex-title">Examples</div><div class="ax-ex-tabs">'
                                +exKeys.map(function(k,j){return '<button class="ax-ex-tab'+(j===0?' active':'')+'" onclick="axExSwitch(\''+exId+'\',\''+escH(k)+'\')">'+escH(examples[k].summary||k)+'</button>';}).join('')
                                +'</div><div id="'+exId+'" class="ax-ex-panels">'
                                +exKeys.map(function(k,j){
                                    var ex=examples[k]||{};
                                    var val=ex.value!==undefined?ex.value:'';
                                    var pretty=typeof val==='string'?val:JSON.stringify(val,null,2);
                                    return '<div class="ax-ex-panel" data-name="'+escH(k)+'" style="'+(j>0?'display:none':'')+'">'
                                        +(ex.description?'<div class="ax-ex-desc axw-md">'+md(ex.description)+'</div>':'')
                                        +'<pre class="ax-code">'+hlJson(escH(pretty))+'</pre></div>';
                                }).join('')+'</div></div>';
                        } else if(exKeys.length===1){
                            var only=examples[exKeys[0]];var v=only.value!==undefined?only.value:'';var p=typeof v==='string'?v:JSON.stringify(v,null,2);
                            exHtml='<div class="ax-ex-block"><div class="ax-ex-title">Example</div>'+(only.description?'<div class="ax-ex-desc axw-md">'+md(only.description)+'</div>':'')+'<pre class="ax-code">'+hlJson(escH(p))+'</pre></div>';
                        } else if(mediaObj.example!==undefined){
                            var e=mediaObj.example;var ep=typeof e==='string'?e:JSON.stringify(e,null,2);
                            exHtml='<div class="ax-ex-block"><div class="ax-ex-title">Example</div><pre class="ax-code">'+hlJson(escH(ep))+'</pre></div>';
                        }
                        html+='<div class="ax-resp-ct-panel" data-mime="'+escH(mime)+'" style="'+(i>0?'display:none':'')+'">'+renderSchema(mediaObj.schema||{},spec,0)+exHtml+'</div>';
                    });
                    html+='</div></div>';
                });
                html+='</div>';
            }
            setDoc(html);
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
            el.setAttribute('aria-expanded',open?'true':'false');
        };
        window.axGoWelcome=function(){
            _activeOpKey=null;
            if(_specCache){renderSidebar(_specCache);renderWelcome(_specCache);}
            var c=document.getElementById('axui-content');if(c)c.scrollTop=0;
            history.replaceState(null,'',location.pathname);
        };
        window.axExSwitch=function(id,name){
            var box=document.getElementById(id);if(!box)return;
            box.querySelectorAll('.ax-ex-panel').forEach(function(el){el.style.display=el.dataset.name===name?'':'none';});
            var parent=box.parentElement;if(parent)parent.querySelectorAll('.ax-ex-tab').forEach(function(t){t.classList.toggle('active',t.textContent.trim()===(box.querySelector('.ax-ex-panel[data-name="'+name+'"]')||{dataset:{name:name}}).dataset.name||t.textContent.includes(name));});
            /* simpler: mark by index match */
            var tabs=parent?parent.querySelectorAll('.ax-ex-tab'):[];
            var panels=box.querySelectorAll('.ax-ex-panel');
            for(var i=0;i<panels.length;i++){if(panels[i].dataset.name===name){tabs[i]&&tabs[i].classList.add('active');}else{tabs[i]&&tabs[i].classList.remove('active');}}
        };
        window.axCopyPermalink=function(id){
            var url=location.origin+location.pathname+'#op_'+id;
            if(navigator.clipboard)navigator.clipboard.writeText(url).then(function(){toast('Permalink copied');});
            else{fb(url);toast('Permalink copied');}
        };

        JS;
    }

    /** `resolveRef`, the recursive `renderSchema` and its expanders. */
    private function jsSchema(): string
    {
        return <<<'JS'
        function resolveRef(ref,spec){
            if(!ref||!ref.startsWith('#/'))return null;
            var parts=ref.slice(2).split('/');var cur=spec;
            for(var i=0;i<parts.length;i++){cur=cur[parts[i]];if(cur==null)return null;}
            return cur;
        }

        /* Any $ref is a jump, not a label: the component it names has a view of
           its own, and the badge is exactly where a reader is standing when
           they want it. Derived from the ref — no name is special — and linked
           only when the target really is a published schema, so a dangling ref
           cannot become a dead link. The href is the same deep link the sidebar
           uses, so the hashchange router renders it and Back returns here. */
        function refBadge(ref,spec,extraClass,label){
            var path=String(ref||'');
            var name=path.split('/').pop();
            var known=path.indexOf('#/components/schemas/')===0
                && !!(spec&&spec.components&&spec.components.schemas&&spec.components.schemas[name]);
            var cls=(extraClass?extraClass+' ':'')+'ax-type-badge ax-ref-badge';
            var text=escH(label||name);

            return known
                ? '<a class="'+cls+' ax-ref-link" href="#schema_'+escH(encodeURIComponent(name))+'" title="Go to '+escH(name)+'">'+text+'</a>'
                : '<span class="'+cls+'">'+text+'</span>';
        }

        function renderSchema(schema,spec,depth){
            if(!schema)return '';
            if(schema['$ref']){
                var refName=schema['$ref'].split('/').pop();
                var resolved=depth<3?resolveRef(schema['$ref'],spec):null;
                if(resolved&&depth<3){
                    var sid='axs'+(++_schemaIds);
                    return '<div class="ax-ref-wrap">'
                        +'<button type="button" class="ax-schema-collapse-btn" data-schema="'+sid+'" aria-expanded="true" aria-controls="'+sid+'" aria-label="Collapse '+escH(refName)+'" onclick="axToggleSchema(\''+sid+'\')">▼</button>'
                        +refBadge(schema['$ref'],spec)
                        +'<div id="'+sid+'" class="ax-ref-expanded">'+renderSchema(resolved,spec,depth+1)+'</div>'
                        +'</div>';
                }
                return refBadge(schema['$ref'],spec);
            }
            if(schema.allOf)return '<div class="ax-schema-obj"><div class="ax-allof-label">allOf</div>'+schema.allOf.map(function(s){return renderSchema(s,spec,depth+1);}).join('')+'</div>';
            if(schema.oneOf)return '<div class="ax-oneof-wrap"><div class="ax-oneof-label">One of:</div>'+schema.oneOf.map(function(s,i){return '<div class="ax-oneof-item'+(i>0?' ax-oneof-sep':'')+'">'+(i>0?'<div style="font-size:10px;color:var(--t3);margin:2px 0">or</div>':'')+renderSchema(s,spec,depth+1)+'</div>';}).join('')+'</div>';
            if(schema.anyOf)return '<div class="ax-oneof-wrap"><div class="ax-oneof-label">Any of:</div>'+schema.anyOf.map(function(s){return '<div class="ax-oneof-item">'+renderSchema(s,spec,depth+1)+'</div>';}).join('')+'</div>';
            var type=schema.type;
            if(Array.isArray(type)){type=type.filter(function(t){return t!=='null';})[0]||'any';}
            type=type||(schema.properties?'object':'any');
            if(type==='object'||schema.properties){
                if(depth>=3)return '<span class="ax-type-badge">object {…}</span>';
                var props=schema.properties||{};var req=schema.required||[];var propKeys=Object.keys(props);
                if(!propKeys.length)return '<span class="ax-type-badge">object {}</span>'+(schema.additionalProperties?'<span style="font-size:11px;color:var(--t3);margin-left:6px">+ extra fields</span>':'');
                var html='<div class="ax-schema-obj">';
                if(schema.description)html+='<div class="ax-schema-desc axw-md">'+md(schema.description)+'</div>';
                propKeys.forEach(function(pn){
                    var pv=props[pn];
                    /* OpenAPI 3.1 type-arrays: strip null and display the primary type */
                    var rawType=pv.type;
                    if(Array.isArray(rawType)){rawType=rawType.filter(function(t){return t!=='null';})[0]||'any';}
                    var pt=rawType||(pv['$ref']?pv['$ref'].split('/').pop():(pv.properties?'object':'any'));
                    /* `array` alone said nothing about what was in it — the
                       item's own $ref names it, and links to it. */
                    var itemRef=rawType==='array'&&pv.items&&pv.items['$ref']?pv.items['$ref']:null;
                    var typeHtml=pv['$ref']
                        ? refBadge(pv['$ref'],spec,'ax-prop-type')
                        : (itemRef
                            ? refBadge(itemRef,spec,'ax-prop-type',itemRef.split('/').pop()+'[]')
                            : '<span class="ax-prop-type ax-type-badge">'+escH(pt)+'</span>');
                    /* A $ref property was an un-openable name badge: `isNested`
                       required an inline object, so every nested model in the
                       tree could be read only by leaving the page. */
                    var isNested=(rawType==='object'||pv.properties||pv['$ref']||itemRef)&&depth<2;
                    var nestedId=isNested?'axs'+(++_schemaIds):'';
                    var isReq=req.indexOf(pn)!==-1;
                    html+='<div class="ax-prop-row">'
                        +(isNested?'<button type="button" class="ax-schema-collapse-btn" data-schema="'+nestedId+'" aria-expanded="'+(_expandAll?'true':'false')+'" aria-controls="'+nestedId+'" aria-label="Toggle '+escH(pn)+'" onclick="axToggleSchema(\''+nestedId+'\')">'+(_expandAll?'▼':'▶')+'</button>':'')
                        +'<span class="ax-prop-name">'+escH(pn)+'</span>'
                        +typeHtml
                        +'<span class="ax-prop-badges">'+propBadges(pv,isReq)+'</span>'
                        +(pv.description?'<span class="ax-prop-desc axw-md">'+md(pv.description)+'</span>':'')
                        +'</div>';
                    if(pv.enum)html+='<div style="padding:4px 12px 6px 12px"><div class="ax-enum-wrap">'+pv.enum.map(function(v){return '<span class="ax-enum-val">'+escH(String(v))+'</span>';}).join('')+'</div></div>';
                    if(isNested)html+='<div id="'+nestedId+'" class="ax-prop-nested" style="'+(_expandAll?'':'display:none')+'">'+renderSchema(pv,spec,depth+1)+'</div>';
                });
                return html+'</div>';
            }
            /* depth+1: passing the same depth let `array of array of …` recurse
               past the cap the object branch relies on. */
            if(type==='array')return '<span class="ax-type-badge">array</span><span style="font-size:11px;color:var(--t3);margin:0 4px">of</span>'+renderSchema(schema.items||{},spec,depth+1);
            if(schema.enum)return '<div class="ax-enum-wrap">'+schema.enum.map(function(v){return '<span class="ax-enum-val">'+escH(String(v))+'</span>';}).join('')+'</div>';
            return '<span class="ax-type-badge">'+escH(type)+(schema.format?'<span style="opacity:.6"> ('+escH(schema.format)+')</span>':'')+'</span>';
        }

        window.axToggleSchema=function(id){
            var el=document.getElementById(id);var btn=document.querySelector('[data-schema="'+id+'"]');if(!el)return;
            var open=el.style.display!=='none';
            el.style.display=open?'none':'';
            if(btn){btn.textContent=open?'▶':'▼';btn.setAttribute('aria-expanded',open?'false':'true');}
        };

        JS;
    }

    /**
     * `renderPanel`, the code generators, `buildExample`, `axSend`, the response
     * viewer tabs and the JSON highlighter.
     */
    private function jsPanel(): string
    {
        return <<<'JS'
        /* ── Right panel ── */
        var _activeLang=APEX_CFG.defaultLanguage||'curl';

        function renderPanel(path,method,op,spec){
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
                        +'<input class="ax-try-input" id="axi-auth" type="password" placeholder="Token…" aria-label="Authorization value" style="flex:1">'
                        +'</div>';
                }
                function paramFields(list,prefix){
                    var h='';
                    list.forEach(function(p){
                        var fid=(prefix||'axi-')+escH(p.name);
                        h+='<label class="ax-try-label" for="'+fid+'" style="font-size:11px;color:var(--t3)">'+escH(p.name)+(p.required?' <span style="color:var(--red)" aria-hidden="true">*</span>':'')+'</label>'
                            +'<input class="ax-try-input" id="'+fid+'" name="'+escH(p.name)+'"'+(p.required?' required aria-required="true"':'')+' placeholder="'+escH(paramSample(p))+'"><div style="margin-bottom:4px"></div>';
                    });
                    return h;
                }
                /* Wrap a group of fields with a bulk-JSON edit toggle */
                function bulkGroup(title,list,prefix,gid){
                    if(!list.length)return '';
                    var names=list.map(function(p){return p.name;});
                    return '<div class="ax-bulk-group" data-gid="'+gid+'" data-prefix="'+prefix+'" data-names="'+escH(JSON.stringify(names))+'">'
                        +'<div class="ax-bulk-row"><div class="ax-try-label">'+title+'</div>'
                        +'<button class="ax-bulk-btn" type="button" onclick="axBulkToggle(\''+gid+'\')">JSON</button></div>'
                        +'<div class="ax-bulk-fields">'+paramFields(list,prefix)+'</div>'
                        +'<textarea class="ax-bulk-area" id="'+gid+'-area" spellcheck="false" placeholder=\'{ "key": "value" }\'></textarea>'
                        +'<div class="ax-bulk-actions" style="display:none">'
                            +'<button type="button" onclick="axBulkApply(\''+gid+'\')">Apply</button>'
                            +'<button type="button" onclick="axBulkSyncFromFields(\''+gid+'\')">Refresh from fields</button>'
                            +'<button type="button" onclick="axBulkCopy(\''+gid+'\')">Copy</button>'
                        +'</div>'
                        +'<div class="ax-bulk-err" id="'+gid+'-err"></div>'
                        +'</div>';
                }
                /* Path and query shared the `axi-` prefix, so a parameter of
                   the same name in both collided on one id: getElementById
                   returned the first, Send posted the path value as the query
                   value, and the Path bulk editor wrote into the Query field. */
                if(pathParams.length)tryHtml+=bulkGroup('Path',pathParams,'axi-p-','axb-path');
                if(queryParams.length)tryHtml+=bulkGroup('Query',queryParams,'axi-q-','axb-query');
                if(headerParams.length)tryHtml+=bulkGroup('Headers',headerParams,'axi-h-','axb-hdr');
                if(hasBody&&op.requestBody){
                    var ct=op.requestBody.content||{};var ex='{}';
                    if(ct['application/json']&&ct['application/json'].schema)ex=JSON.stringify(buildExample(ct['application/json'].schema,spec),null,2);
                    tryHtml+='<label class="ax-try-label" style="margin-top:8px">Request Body</label><textarea class="ax-try-input ax-try-textarea" id="axi-body">'+escH(ex)+'</textarea>';
                }
                /* No server baked in: it is read at click time, or switching
                   environment leaves Send quietly hitting the previous host. */
                tryHtml+='<button class="ax-try-send" id="axi-send" onclick="axSend(\''+escH(path)+'\',\''+escH(method)+'\')">Send Request</button><div id="axi-result"></div></div>';
            }
            /* History section */
            var histHtml='<div class="ax-hist-section"><div class="ax-panel-section-title-row"><div class="ax-panel-section-title">Recent</div><button class="ax-hist-toggle" onclick="this.parentElement.parentElement.classList.toggle(\'collapsed\')">▾</button></div><div id="ax-hist-list" class="ax-hist-list"></div></div>';
            setPanel(codeHtml+tryHtml+histHtml);
            renderCode(path,method,op,spec,_server);
            /* Restore persisted auth + wire listeners */
            authRestore();
            /* Inject OAuth2 "Get token" button if any oauth2 scheme is defined */
            wireOAuthHelper(spec);
            /* Populate request history */
            renderHistory(method,path);
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

        /* One sample value per parameter: what the spec offers, else something
           of the right type, else the placeholder — so a copied sample is
           runnable and its blanks are obvious. */
        function paramSample(p){
            var sch=p.schema||{};
            if(p.example!==undefined)return String(p.example);
            if(sch.example!==undefined)return String(sch.example);
            if(sch.default!==undefined)return String(sch.default);
            if(Array.isArray(sch.enum)&&sch.enum.length)return String(sch.enum[0]);
            var t=Array.isArray(sch.type)?sch.type[0]:sch.type;

            return t==='integer'||t==='number'?'1':(t==='boolean'?'true':'{'+p.name+'}');
        }

        /* A sample value is encoded; a placeholder is not. Encoding it turns
           `{shipment}` into `%7Bshipment%7D`, which stops looking like a blank
           to fill in and starts looking like a value that works. */
        function sampleValue(p){
            var v=paramSample(p);

            return v==='{'+p.name+'}'?v:encodeURIComponent(v);
        }

        /* The URL a code sample should show. `{userId}` was left literal and the
           query string omitted entirely, so every copied sample 404'd. */
        function sampleUrl(server,path,op){
            var params=(op&&op.parameters)||[];
            var url=server+path;
            params.forEach(function(p){
                if(p&&p['in']==='path')url=url.replace('{'+p.name+'}',sampleValue(p));
            });
            var qs=params.filter(function(p){
                if(!p||p['in']!=='query')return false;
                var sch=p.schema||{};

                return p.required||p.example!==undefined||sch.example!==undefined||sch.default!==undefined;
            }).map(function(p){return encodeURIComponent(p.name)+'='+sampleValue(p);});

            return url+(qs.length?'?'+qs.join('&'):'');
        }

        /* The auth header the operation actually requires. Every sample claimed
           `Authorization: Bearer` — wrong for Basic, wrong for an API key, and
           wrong for an endpoint that needs nothing at all. */
        function authHeaderFor(op,spec){
            var reqs=(op&&op.security!==undefined)?op.security:((spec&&spec.security)||[]);
            if(!Array.isArray(reqs)||!reqs.length)return null;
            var schemes=(spec&&spec.components&&spec.components.securitySchemes)||{};

            for(var i=0;i<reqs.length;i++){
                var names=Object.keys(reqs[i]||{});
                for(var j=0;j<names.length;j++){
                    var sch=schemes[names[j]];
                    if(!sch)continue;
                    var type=String(sch.type||'').toLowerCase();
                    if(type==='http'){
                        var scheme=String(sch.scheme||'bearer');
                        var label=scheme.charAt(0).toUpperCase()+scheme.slice(1);

                        return scheme.toLowerCase()==='basic'
                            ? {name:'Authorization',value:'Basic {base64_credentials}'}
                            : {name:'Authorization',value:label+' {your_token}'};
                    }
                    if(type==='apikey'&&String(sch['in']||'header').toLowerCase()==='header'){
                        return {name:sch.name||'X-API-Key',value:'{your_api_key}'};
                    }
                    if(type==='oauth2'||type==='openidconnect'){
                        return {name:'Authorization',value:'Bearer {access_token}'};
                    }
                }
            }
            /* A requirement naming a scheme this document never defined: the
               endpoint is protected, and bearer is the honest guess. */
            return {name:'Authorization',value:'Bearer {your_token}'};
        }

        function genCode(lang,server,path,method,op,spec){
            var url=sampleUrl(server,path,op);var hasBody=['post','put','patch'].includes(method);
            var ct=(op.requestBody&&op.requestBody.content)||{};var bodyObj=null;
            if(hasBody&&ct['application/json']&&ct['application/json'].schema)bodyObj=buildExample(ct['application/json'].schema,spec);
            var authH=authHeaderFor(op,spec);var hasSec=!!authH;
            var hName=authH?authH.name:'Authorization';var hValue=authH?authH.value:'';
            switch(lang){
                case 'curl':{
                    var c="curl -X "+method.toUpperCase()+" \\\n  '"+url+"'";
                    c+=" \\\n  -H 'Accept: application/json'";
                    if(hasSec)c+=" \\\n  -H '"+hName+": "+hValue+"'";
                    if(bodyObj){c+=" \\\n  -H 'Content-Type: application/json'";c+=" \\\n  -d '"+JSON.stringify(bodyObj,null,2)+"'";}
                    return c;
                }
                case 'js':{
                    var o="const response = await fetch('"+url+"', {\n  method: '"+method.toUpperCase()+"',\n  headers: {\n    'Accept': 'application/json',";
                    if(hasSec)o+="\n    '"+hName+"': '"+hValue+"',";
                    if(bodyObj)o+="\n    'Content-Type': 'application/json',";
                    o+="\n  }"+(bodyObj?",\n  body: JSON.stringify("+JSON.stringify(bodyObj,null,2)+")":"")+"\n});\nconst data = await response.json();";
                    return o;
                }
                case 'python':{
                    var py="import requests\n\nresponse = requests."+method.toLowerCase()+"(\n    '"+url+"',\n    headers={'Accept': 'application/json'"+(hasSec?",'"+hName+"': '"+hValue+"'":"")+"},"+(bodyObj?"\n    json="+JSON.stringify(bodyObj,null,2):"");
                    return py+"\n)\ndata = response.json()";
                }
                case 'php':{
                    var php="$response = (new \\GuzzleHttp\\Client())\n    ->"+method.toLowerCase()+"('"+url+"', [\n        'headers' => ['Accept' => 'application/json'"+(hasSec?", '"+hName+"' => '"+hValue+"'":"")+"],"+(bodyObj?"\n        'json' => "+JSON.stringify(bodyObj,null,2):"");
                    return php+"\n    ]);\n$data = json_decode((string) $response->getBody(), true);";
                }
                case 'go':{
                    var go="package main\n\nimport (\n\t\"fmt\"\n\t\"io\"\n\t\"net/http\"\n"+(bodyObj?"\t\"bytes\"\n\t\"encoding/json\"\n":"")+"\n)\n\nfunc main() {\n";
                    if(bodyObj){go+="\tpayload, _ := json.Marshal("+JSON.stringify(bodyObj)+")\n";go+="\treq, _ := http.NewRequest(\""+method.toUpperCase()+"\", \""+url+"\", bytes.NewBuffer(payload))\n";}
                    else go+="\treq, _ := http.NewRequest(\""+method.toUpperCase()+"\", \""+url+"\", nil)\n";
                    go+="\treq.Header.Set(\"Accept\", \"application/json\")\n";
                    if(hasSec)go+="\treq.Header.Set(\""+hName+"\", \""+hValue+"\")\n";
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
            var t=schema.type;
            if(Array.isArray(t)){t=t.filter(function(x){return x!=='null';})[0]||'any';}
            t=t||(schema.properties?'object':'any');
            switch(t){
                case 'object':{var o={};for(var k in schema.properties||{})o[k]=buildExample(schema.properties[k],spec,depth+1);return o;}
                case 'array':return [buildExample(schema.items||{},spec,depth+1)];
                case 'integer':return 1;case 'number':return 1.0;case 'boolean':return true;case 'null':return null;
                default:return schema.enum?schema.enum[0]:'string';
            }
        }

        window.axSend=function(path,method){
            var btn=document.getElementById('axi-send'),result=document.getElementById('axi-result');
            if(!btn||!result)return;
            var authType=document.getElementById('axi-auth-type');
            var auth=document.getElementById('axi-auth');
            var body=document.getElementById('axi-body');
            /* Resolved here, not at render time: the operation's parameters may
               come from the Path Item or from `components`, and the server may
               have changed since this panel was drawn. */
            var spec=_specCache||{};
            var pathItem=(spec.paths||{})[path]||{};
            var op=derefOp(pathItem[method]||{},spec,pathItem);
            var server=opServer(op);
            var url=server+path;
            var specParams=op.parameters||[];
            specParams.forEach(function(p){
                var el=document.getElementById('axi-p-'+p.name);if(!el||!el.value)return;
                if(p['in']==='path')url=url.replace('{'+p.name+'}',encodeURIComponent(el.value));
            });
            var qp=specParams.filter(function(p){return p['in']==='query';}).map(function(p){var el=document.getElementById('axi-q-'+p.name);return el&&el.value?encodeURIComponent(p.name)+'='+encodeURIComponent(el.value):'';}).filter(Boolean).join('&');
            if(qp)url+='?'+qp;
            var headers={'Accept':'application/json'};
            if(auth&&auth.value){
                var at=authType?authType.value:'bearer';
                if(at==='bearer')headers['Authorization']='Bearer '+auth.value;
                else if(at==='basic')headers['Authorization']='Basic '+btoa(auth.value);
                else if(at==='apikey')headers['X-API-Key']=auth.value;
            }
            specParams.filter(function(p){return p['in']==='header';}).forEach(function(p){var el=document.getElementById('axi-h-'+p.name);if(el&&el.value)headers[p.name]=el.value;});
            var hasBodyMethod=!['get','head'].includes(method);
            if(body&&body.value&&hasBodyMethod)headers['Content-Type']='application/json';
            var opts={method:method.toUpperCase(),headers:headers};
            if(body&&body.value&&hasBodyMethod)opts.body=body.value;
            btn.disabled=true;btn.textContent='Sending…';
            var t0=Date.now();
            result.innerHTML='<div class="axui-loading-state"><div class="axui-spinner"></div><span>Waiting for response…</span></div>';
            /* Snapshot of params for history */
            /* Keyed by field id, so a restored entry lands in the group it
               came from. Version 2 entries; v1 ones had ambiguous keys and are
               ignored on restore rather than replayed into the wrong field. */
            var paramSnap={};specParams.forEach(function(p){
                var pre=p['in']==='path'?'axi-p-':p['in']==='query'?'axi-q-':p['in']==='header'?'axi-h-':null;
                if(!pre)return;
                var el=document.getElementById(pre+p.name);
                if(el&&el.value)paramSnap[pre+p.name]=el.value;
            });
            var headerSnap={};specParams.filter(function(p){return p['in']==='header';}).forEach(function(p){var el=document.getElementById('axi-h-'+p.name);if(el&&el.value)headerSnap[p.name]=el.value;});
            fetch(url,opts).then(function(r){
                var ms=Date.now()-t0;var sc=r.status;
                var cls=sc<300?'ax-res-s-ok':sc<400?'ax-res-s-info':sc<500?'ax-res-s-warn':'ax-res-s-err';
                var hdrPairs=[];r.headers.forEach(function(v,n){hdrPairs.push([n,v]);});
                var ctype=(r.headers.get('content-type')||'').toLowerCase();
                return r.text().then(function(raw){
                    var size=raw.length;var sizeStr=size<1024?size+' B':size<1048576?(size/1024).toFixed(1)+' KB':(size/1048576).toFixed(2)+' MB';
                    var isJson=ctype.indexOf('json')!==-1;var fmt=raw;
                    if(isJson){try{fmt=JSON.stringify(JSON.parse(raw),null,2);}catch(e){}}
                    var bodyHtml=isJson?'<pre class="ax-res-pre">'+hlJson(escH(fmt))+'</pre>':'<pre class="ax-res-pre">'+escH(fmt)+'</pre>';
                    var hdrTable='<table class="ax-res-headers-tbl">'+hdrPairs.map(function(kv){return '<tr><td>'+escH(kv[0])+'</td><td>'+escH(kv[1])+'</td></tr>';}).join('')+'</table>';
                    var rid='axres-'+(Date.now());
                    result.innerHTML='<div class="ax-res-panel">'
                        +'<div class="ax-res-status-bar">'
                            +'<span class="ax-res-status '+cls+'">'+sc+' '+escH(r.statusText)+'</span>'
                            +'<span class="ax-res-meta">'+ms+'ms · '+sizeStr+'</span>'
                            +'<button class="ax-res-copy" onclick="apexCopyText(this,\''+rid+'-body\')" title="Copy body">Copy</button>'
                        +'</div>'
                        +'<div class="ax-res-tabs">'
                            +'<button class="ax-res-tab active" data-t="body" onclick="axResTab(\''+rid+'\',\'body\')">Body</button>'
                            +'<button class="ax-res-tab" data-t="headers" onclick="axResTab(\''+rid+'\',\'headers\')">Headers ('+hdrPairs.length+')</button>'
                            +'<button class="ax-res-tab" data-t="raw" onclick="axResTab(\''+rid+'\',\'raw\')">Raw</button>'
                        +'</div>'
                        +'<div id="'+rid+'" class="ax-res-body-wrap">'
                            +'<div class="ax-res-pane" data-pane="body" id="'+rid+'-body" data-raw="'+escH(fmt)+'">'+bodyHtml+'</div>'
                            +'<div class="ax-res-pane" data-pane="headers" style="display:none">'+hdrTable+'</div>'
                            +'<div class="ax-res-pane" data-pane="raw" style="display:none"><pre class="ax-res-pre">'+escH(raw)+'</pre></div>'
                        +'</div></div>';
                    histPush(method,path,{v:2,server:server,status:sc,ms:ms,params:paramSnap,headers:headerSnap,body:(body&&body.value)||null});
                    renderHistory(method,path);
                });
            }).catch(function(err){
                result.innerHTML='<div class="ax-res-panel"><div class="ax-res-status-bar"><span class="ax-res-status ax-res-s-err">Network Error</span><span class="ax-res-meta">'+escH(err.message)+'</span></div><div class="ax-res-body-wrap"><div class="ax-res-pane">'
                    +'<div style="font-size:12px;color:var(--t3);padding:10px;line-height:1.6">'
                    +'<strong>Possible causes:</strong><ul style="margin:6px 0 0 18px;padding:0"><li>The server is offline or unreachable.</li><li>CORS is not configured to allow this origin.</li><li>The URL is wrong: <code>'+escH(url)+'</code></li></ul></div></div></div></div>';
                histPush(method,path,{v:2,server:server,status:0,ms:Date.now()-t0,params:paramSnap,headers:headerSnap,body:(body&&body.value)||null});
                renderHistory(method,path);
            }).finally(function(){btn.disabled=false;btn.textContent='Send Request';});
        };
        window.axResTab=function(rid,t){
            var box=document.getElementById(rid);if(!box)return;
            box.querySelectorAll('.ax-res-pane').forEach(function(el){el.style.display=el.dataset.pane===t?'':'none';});
            var tabs=box.parentElement.querySelectorAll('.ax-res-tab');
            tabs.forEach(function(tb){tb.classList.toggle('active',tb.dataset.t===t);});
        };
        window.apexCopyText=function(btn,id){
            var el=document.getElementById(id);if(!el)return;
            var raw=el.dataset.raw||el.textContent;
            if(navigator.clipboard)navigator.clipboard.writeText(raw).then(function(){btn.textContent='Copied!';setTimeout(function(){btn.textContent='Copy';},1500);});
            else{fb(raw);btn.textContent='Copied!';setTimeout(function(){btn.textContent='Copy';},1500);}
        };

        /* Highlights JSON that has ALREADY been HTML-escaped  every call site
           passes escH(...) output. Two consequences the naive version got wrong:
           string delimiters arrive as &quot;, not ", so nothing was ever marked
           up as a key or a string; and the digits inside an entity like &#39;
           matched the number pattern, so the entity was split by a <span> and
           the browser printed a literal "&#39;" in every cURL sample. */
        function hlJson(s){
            var pattern=/(&quot;(?:\\.|(?!&quot;).)*&quot;(?:\s*:)?|&[a-zA-Z]+;|&#\d+;|\b(?:true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g;
            return s.replace(pattern,function(m){
                if(m.charAt(0)==='&'&&m.indexOf('&quot;')!==0)return m; /* an entity: pass through untouched */
                var c='ax-n';
                if(m.indexOf('&quot;')===0)c=/:$/.test(m)?'ax-k':'ax-s';
                else if(m==='true'||m==='false')c='ax-b';
                else if(m==='null')c='ax-null';
                return '<span class="'+c+'">'+m+'</span>';
            });
        }

        JS;
    }

    /** Boots the UI and closes the IIFE. */
    private function jsInit(): string
    {
        return <<<'JS'
        init();

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

    private function iconKeyboard(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/><path d="M6.2 6.2c.3-1 1.1-1.6 2-1.6 1.1 0 1.9.7 1.9 1.7 0 .9-.5 1.3-1.3 1.6-.7.3-.9.7-.9 1.3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" fill="none"/><circle cx="8" cy="11.6" r=".8" fill="currentColor"/></svg>';
    }

    private function iconServer(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="2" width="14" height="4" rx="1.5" stroke="currentColor" stroke-width="1.4"/><rect x="1" y="10" width="14" height="4" rx="1.5" stroke="currentColor" stroke-width="1.4"/><circle cx="12.5" cy="4" r="1" fill="currentColor"/><circle cx="12.5" cy="12" r="1" fill="currentColor"/></svg>';
    }
}
