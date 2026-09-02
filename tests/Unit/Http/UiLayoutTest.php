<?php

declare(strict_types=1);

use ApexDocs\Config;
use ApexDocs\Http\Theme;
use ApexDocs\Http\UiRenderer;

/**
 * Layout invariants of the shell — the ones a stylesheet or a script can break
 * silently, with a green build and no console error.
 *
 * Three of these describe defects that shipped: the request console was
 * destroyed at every viewport because a renderer wrote its parent node instead
 * of its sibling; the nav drawer could not be opened at all because the script
 * toggled a class the stylesheet had never heard of; and the `⋯` menu, which is
 * the only route to everything a narrow viewport hides, had no handler behind
 * its button. None of them are visible in a diff, and all of them are one string
 * comparison away from being caught.
 */
function uiStylesheet(): string
{
    $css = new ReflectionMethod(UiRenderer::class, 'css');
    $css->setAccessible(true);

    return (string) $css->invoke(new UiRenderer);
}

function uiScript(): string
{
    $js = new ReflectionMethod(UiRenderer::class, 'js');
    $js->setAccessible(true);

    return (string) $js->invoke(new UiRenderer);
}

function uiPage(): string
{
    return (new UiRenderer)->render('/docs/spec.json', new Config(title: 'T'));
}

/**
 * Flat selector => declarations pairs, at-rule preludes skipped: `[^{}]` can
 * never span a brace, so the match always starts after the innermost `{`. Which
 * media query a rule sits in is deliberately discarded — these invariants hold
 * at every width.
 *
 * @return list<array{0: string, 1: string}>
 */
function uiCssRules(): array
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', uiStylesheet(), $out, PREG_SET_ORDER);

    return array_map(static fn (array $r): array => [trim($r[1]), trim($r[2])], $out);
}

/**
 * The compound each selector in a list actually targets — `a b > c` targets `c`.
 * A rule that hides `.ax-pseg` inside `#axui-panel` is not a rule about
 * `#axui-panel`.
 *
 * @return list<string>
 */
function uiSelectorSubjects(string $selectorList): array
{
    $subjects = [];
    foreach (explode(',', $selectorList) as $selector) {
        $parts = preg_split('/\s*[>+~]\s*|\s+/', trim($selector)) ?: [];
        $subjects[] = (string) end($parts);
    }

    return $subjects;
}

/** True when any comma-separated selector in the list targets exactly `$id`. */
function uiTargets(string $selectorList, string $id): bool
{
    foreach (uiSelectorSubjects($selectorList) as $subject) {
        if (preg_match('/^'.preg_quote($id, '/').'(?![\w-])/', $subject) === 1) {
            return true;
        }
    }

    return false;
}

it('never hides the request console, at any width', function () {
    // The console holds the code samples, try-it-out, the response viewer, the
    // history and the schema JSON dump. Hiding the node is what made all five
    // unreachable below 1100px; the fix was to give it three presentations and
    // no fourth, so `display:none` on it is now always a regression.
    $offenders = [];
    foreach (uiCssRules() as [$selector, $body]) {
        if (uiTargets($selector, '#axui-panel') && preg_match('/display\s*:\s*none/i', $body) === 1) {
            $offenders[] = $selector.'{'.$body.'}';
        }
    }

    expect($offenders)->toBe([]);
});

it('never makes the panel slot a scroll container', function () {
    // `position:sticky` on the promoted rail needs #axui-content to be the
    // scroll container. Any overflow value other than the initial one turns the
    // slot into a scrollport for its own sticky child, which degrades the rail
    // to static — with no error and no console warning.
    $offenders = [];
    foreach (uiCssRules() as [$selector, $body]) {
        if (! uiTargets($selector, '#axui-panel-slot')) {
            continue;
        }
        preg_match_all('/overflow(?:-x|-y)?\s*:\s*([a-z]+)/i', $body, $m);
        foreach ($m[1] as $value) {
            if (! in_array(strtolower($value), ['visible', 'initial', 'unset'], true)) {
                $offenders[] = $selector.' → overflow:'.$value;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the console a sibling of the article, never a child', function () {
    // The whole architecture rests on this: two siblings, so writing one can
    // never destroy the other, and DOM order is the phone order.
    expect(uiPage())->toMatch('#<article id="axui-doc"[^>]*>\s*</article>\s*<div id="axui-panel-slot">#');
});

it('routes every view through one write target, and the console through the other', function () {
    $js = uiScript();

    // #axui-content-inner is the PARENT of both, so writing it destroys the
    // console; #axui-welcome no longer exists at all.
    expect($js)->not->toContain('axui-welcome')
        ->and($js)->not->toContain("getElementById('axui-content-inner')")
        // Exactly one lookup each: setDoc() and setPanel() are the only writers.
        ->and(substr_count($js, "getElementById('axui-doc')"))->toBe(1)
        ->and(substr_count($js, "getElementById('axui-panel-inner')"))->toBe(1);
});

it('toggles a drawer state class the stylesheet actually keys on', function () {
    $css = uiStylesheet();
    $js = uiScript();

    preg_match_all('/documentElement\.classList\.(?:toggle|add|remove)\(\'([^\']+)\'\)/', $js, $m);

    expect(array_unique($m[1]))->not->toBeEmpty('the drawer writes no state class at all');

    foreach (array_unique($m[1]) as $class) {
        expect($css)->toContain('html.'.$class);
    }

    // The class the script used to write, which no rule has ever matched.
    expect($css)->not->toContain('axui-sb-open')
        ->and($js)->not->toContain('axui-sb-open')
        // The drawer is not reachable by any other means below 900px, so both
        // the trigger's state and the tab-order removal have to be maintained.
        ->and($js)->toContain("setAttribute('aria-expanded'")
        ->and($js)->toContain("setAttribute('inert'");
});

it('closes the drawer on every navigation', function () {
    $js = uiScript();

    foreach (['axNav', 'axNavWebhook', 'axNavSchema'] as $fn) {
        preg_match('/^window\.'.$fn.'=function[^\n]*\{(.*?)\n\};/ms', $js, $m);

        expect($m[1] ?? '')->not->toBeEmpty('could not find '.$fn)
            ->and($m[1])->toContain('axSidebarClose()');
    }
});

it('backs the overflow menu with a handler for every item it offers', function () {
    $js = uiScript();
    $html = uiPage();

    // Below 600px this menu is the only route to the theme, the servers, the
    // shortcuts, the spec URL and the five exports.
    expect($js)->toContain("getElementById('apex-more-btn')")
        ->and($js)->toContain('showModal()');

    preg_match_all('/data-more="([a-z]+)"/', $html, $m);

    expect(array_unique($m[1]))->not->toBeEmpty();

    foreach (array_unique($m[1]) as $action) {
        expect($js)->toContain("==='".$action."'");
    }
});

it('publishes every panel mode the stylesheet styles', function () {
    $js = uiScript();

    expect($js)->toContain('function axPanelMode()')
        ->and($js)->toContain("matchMedia('(min-width:1024px)')")
        ->and($js)->toContain("matchMedia('(min-width:1200px)')");

    preg_match_all('/data-mode="([a-z]+)"/', uiStylesheet(), $m);

    expect(array_unique($m[1]))->not->toBeEmpty();

    foreach (array_unique($m[1]) as $mode) {
        expect($js)->toContain("'".$mode."'");
    }
});

it('hides the progressive-disclosure classes after every rule that sets their display', function () {
    $css = uiStylesheet();
    $hide = strpos($css, '.apex-a-md,.apex-a-lg{display:none}');

    expect($hide)->toBeInt('the base hide for .apex-a-md/.apex-a-lg is gone');

    // These carry the same classes and set `display` at the same specificity, so
    // the hide only wins from a later position in the cascade. Authored earlier,
    // it silently loses and the phone toolbar carries six buttons.
    foreach (['.apex-icon-btn{display:flex', '.apex-env-wrap{position:relative;display:flex'] as $rule) {
        expect(strpos($css, $rule))->toBeLessThan($hide, $rule.' is authored after the hide');
    }

    expect(strpos($css, '.apex-a-md{display:flex}'))->toBeGreaterThan($hide)
        ->and(strpos($css, '.apex-a-lg{display:flex}'))->toBeGreaterThan($hide);
});

it('measures the banner height it subtracts from the sticky rail', function () {
    // --banner-h is the only measured value in the layout. Left at its 0px
    // default with a banner on screen, the rail resolves taller than its
    // scrollport and its last rows cannot be reached.
    expect(uiStylesheet())->toContain('var(--banner-h)')
        ->and(Theme::css())->toContain('--banner-h:0px')
        ->and(uiScript())->toContain("setProperty('--banner-h'")
        ->and(uiScript())->toContain('offsetHeight');
});

it('pins the context bar below the toolbar wherever the toolbar is sticky', function () {
    $css = uiStylesheet();

    // Below 900px the document scrolls and #apex-bar is sticky at the viewport
    // top, so `top:0` here would park #ax-ctx behind it — the bar outranks it by
    // 994 z-index tiers. At >=900px it lives inside the #axui-content
    // scrollport, whose top already sits under the bar.
    expect($css)->toContain('#ax-ctx{position:sticky;top:var(--bar-h)')
        ->and($css)->toContain('#ax-ctx{top:0}')
        ->and(strpos($css, '#ax-ctx{top:0}'))->toBeGreaterThan(strpos($css, '#ax-ctx{position:sticky'));
});

it('gives every coarse-pointer target 44px on both axes', function () {
    // An icon button is 32px wide: min-height alone leaves a 32x44 target.
    $offenders = [];
    foreach (uiCssRules() as [$selector, $body]) {
        if (str_contains($body, 'min-height:var(--tap)') && ! str_contains($body, 'min-width:var(--tap)')) {
            $offenders[] = $selector;
        }
    }

    expect($offenders)->toBe([]);
});

it('stretches the article across its grid cell instead of shrink-wrapping it', function () {
    // Once the console is promoted to a rail, the article is a grid item — and
    // `margin-inline:auto` on a grid item cancels the stretch and sizes the box
    // to fit-content. It rendered 592px wide inside a 1216px cell, narrower
    // than even its own max-width, and every pixel of the slack read as dead
    // space between the documentation and the console. The measure that centred
    // it in flow has to be dropped with the margin, or it re-opens the gap.
    $railRules = [];
    foreach (uiCssRules() as [$selector, $body]) {
        if (uiTargets($selector, '#axui-doc') && str_contains($body, 'grid-area')) {
            $railRules[] = $selector.'{'.$body.'}';
        }
    }

    expect($railRules)->not->toBe([]);

    foreach ($railRules as $rule) {
        expect($rule)->toContain('margin-inline:0')
            ->and($rule)->toContain('max-width:none');
    }
});

it('treats a single newline in a description as a soft break', function () {
    // Every PHPDoc is hard-wrapped at ~80 columns. Turning each newline into a
    // <br> froze those line ends into the page, so a description kept the
    // source's ragged right edge at every viewport, however much room it had.
    // Markdown's own hard break — two trailing spaces, or a trailing backslash
    // — is the only one that survives.
    $softBreak = <<<'JS'
.replace(/(?: {2,}|\\)\n/g,'<br>').replace(/\n/g,' ')
JS;
    $hardWrapEverything = <<<'JS'
p.replace(/\n/g,'<br>')
JS;

    expect(uiScript())->toContain(trim($softBreak))
        ->and(uiScript())->not->toContain(trim($hardWrapEverything));
});

it('resolves a $ref-ed response, parameter and body before rendering them', function () {
    // ApexDocs emits the inferred 401/422/429 as `#/components/responses/…`.
    // Read unresolved, such a response has neither `description` nor `content`:
    // the accordion body renders empty, `.ax-resp-body:empty{display:none}`
    // hides it, and clicking the row looks like a dead control.
    $js = uiScript();

    expect($js)->toContain('op=derefOp(op,spec,pathItem)')
        ->and($js)->toContain('out.responses=resolved')
        ->and($js)->toContain('out.requestBody=deref(out.requestBody,spec)')
        // Path Item parameters apply to every method under it and were dropped
        // on all of them; merged, they dedupe by name+in with the operation
        // winning, as the spec requires.
        ->and($js)->toContain('pathItem.parameters:[]')
        ->and($js)->toContain("var key=(p.name||'')+'\u0000'+(p['in']||'')")
        // An API that declares security once at the root read as unauthenticated
        // on every operation.
        ->and($js)->toContain('if(out.security===undefined&&Array.isArray(spec&&spec.security))out.security=spec.security')
        // The panel is fed the resolved operation, so the code samples and the
        // try-it body see a $ref-ed request body too.
        ->and(strpos($js, 'op=derefOp(op,spec,pathItem)'))->toBeLessThan(strpos($js, 'renderPanel(path,method,op,spec)'));
});

/**
 * Measured in headless Chrome before this: 211 endpoint rows in the navigation,
 * none of them reachable by Tab; 34 group headers, none reachable and none
 * reporting their state; no `:focus-visible` rule anywhere in the stylesheet, so
 * a keyboard user could not see where they were; and no `h1` on the page. The
 * whole navigation was `<div onclick>`.
 */
it('navigates with real links, so the keyboard and the back button work', function () {
    $js = uiScript();

    // <a href> — focusable, activatable with Enter, openable in a new tab, and
    // routed by the hash listener rather than a click handler.
    expect($js)->toContain("html+='<a class=\"axi'")
        ->and($js)->not->toContain("html+='<div class=\"axi'")
        ->and($js)->toContain("href=\"#'+escH(axHashFor(i.op,i.key))+'\"")
        ->and($js)->toContain("window.addEventListener('hashchange'")
        // aria-current is what a screen reader reads as "you are here".
        ->and($js)->toContain('aria-current="page"');
});

it('gives every disclosure control a button and a reported state', function () {
    $js = uiScript();

    expect($js)->toContain('<button type="button" class="axg-header"')
        ->and($js)->toContain('<button type="button" class="ax-resp-header"')
        ->and($js)->not->toContain('<div class="axg-header"')
        ->and($js)->not->toContain('<div class="ax-resp-header"')
        // …and the state has to follow the toggle, not just the initial render.
        ->and($js)->toContain("el.setAttribute('aria-expanded',open?'true':'false')")
        ->and(substr_count($js, "aria-expanded"))->toBeGreaterThanOrEqual(6);
});

it('draws a focus ring, and only for the keyboard', function () {
    $css = uiStylesheet();

    expect($css)->toContain(':focus-visible{outline:2px solid var(--ring)')
        // An inset ring where an outset one would be clipped by a scrollport.
        ->and($css)->toContain('.axi:focus-visible')
        ->and($css)->toContain('outline-offset:-2px')
        // The token has to exist in both palettes or the ring is invisible in one.
        ->and(Theme::css())->toContain('--ring:');
});

it('names every view with a heading', function () {
    $js = uiScript();

    expect(substr_count($js, '<h1 class="ax-op-path"'))->toBe(2)   // operation + schema
        ->and(substr_count($js, '<h1 class="axw-title"'))->toBe(2); // welcome + empty spec
});

it('keeps a closed menu out of the tab order', function () {
    // opacity:0 alone leaves its four links focusable: invisible focus stops
    // between the toolbar and the search field.
    $css = uiStylesheet();

    expect($css)->toContain('.apex-export-menu{')
        ->and($css)->toMatch('/\.apex-export-menu\{[^}]*visibility:hidden/')
        ->and($css)->toMatch('/\.apex-export-wrap\.open \.apex-export-menu\{[^}]*visibility:visible/');
});

it('marks every decorative glyph hidden from assistive tech', function () {
    // A bare <svg> is announced as "graphic"; each of these sits next to the
    // text that already says what it means.
    expect(uiPage())->not->toMatch('/<svg(?![^>]*aria-hidden)/')
        ->and(uiScript())->not->toMatch('/<svg(?![^>]*aria-hidden)/');
});

it('makes every $ref a link to the schema it names', function () {
    // A reader looking at `carrier: CarrierResource` wants that schema, and the
    // badge is where they are standing. Built from the ref itself — no name is
    // special — and only linked when the component is really published, so a
    // dangling ref cannot become a dead link.
    $js = uiScript();

    $href = <<<'JS'
href="#schema_'+escH(encodeURIComponent(name))+'"
JS;
    $guard = <<<'JS'
spec.components.schemas&&spec.components.schemas[name]
JS;
    $arrayOfRefs = <<<'JS'
itemRef.split('/').pop()+'[]'
JS;

    expect($js)->toContain('function refBadge(ref,spec,extraClass,label)')
        ->and($js)->toContain(trim($href))
        ->and($js)->toContain(trim($guard))
        // `array` alone named nothing: an array of $refs now says what is in it.
        ->and($js)->toContain(trim($arrayOfRefs))
        // The reverse direction — "Used by" — was a <div onclick>, so it was
        // unreachable by keyboard as well as unlinkable.
        ->and($js)->toContain('<a class="ax-used-item" href="#')
        ->and($js)->not->toContain('<div class="ax-used-item"');
});

it('shows a $ref badge as navigable at rest, not only under the pointer', function () {
    // A reader cannot hover every badge to discover which of them go somewhere.
    expect(uiStylesheet())->toContain('.ax-ref-link{cursor:pointer;text-decoration:underline dotted');
});

/**
 * WS6/WS7 of docs/ui/REBUILD-PLAN.md — the items where the UI showed something
 * untrue or dropped what the generator emitted. Each assertion names the defect
 * it replaces.
 */
it('classifies and orders every kind of response key', function () {
    $js = uiScript();

    // `parseInt('default')` is NaN, which failed every comparison and fell
    // through to the 5xx branch: `default` rendered as a red server error.
    expect($js)->toContain("if(key==='default')return 'axs-default'")
        ->and($js)->toContain('function respOrder(a,b)')
        ->and($js)->toContain('Object.keys(op.responses).sort(respOrder)')
        ->and(uiStylesheet())->toContain('.axs-1xx{')
        ->and(uiStylesheet())->toContain('.axs-default{');
});

it('renders the response headers and links the generator emits', function () {
    // #[ResponseHeader] produces `headers`; the UI dropped them, so
    // Retry-After, X-RateLimit-* and Location were invisible in its own output.
    $js = uiScript();

    expect($js)->toContain('function respHeaders(headers,spec)')
        ->and($js)->toContain('function respLinks(links,spec)')
        ->and($js)->toContain('html+=respHeaders(resp.headers,spec)+respLinks(resp.links,spec)');
});

it('builds a code sample that can be pasted into a shell', function () {
    $js = uiScript();

    // `{userId}` was left literal, the query string omitted, and every sample
    // claimed `Authorization: Bearer` whatever the scheme said.
    expect($js)->toContain('function sampleUrl(server,path,op)')
        ->and($js)->toContain('function authHeaderFor(op,spec)')
        ->and($js)->toContain('var url=sampleUrl(server,path,op)')
        ->and($js)->toContain("value:'Basic {base64_credentials}'")
        ->and($js)->not->toContain("-H 'Authorization: Bearer {your_token}'");
});

it('keeps the try-it fields, the server and the history unambiguous', function () {
    $js = uiScript();

    // Path and query shared the `axi-` prefix, so same-named parameters
    // collided on one id and Send posted the path value as the query value.
    expect($js)->toContain("bulkGroup('Path',pathParams,'axi-p-'")
        ->and($js)->toContain("bulkGroup('Query',queryParams,'axi-q-'")
        ->and($js)->toContain("document.getElementById('axi-p-'+p.name)")
        ->and($js)->toContain("document.getElementById('axi-q-'+p.name)")
        // The server was baked into the button at render time, so Send kept
        // hitting the previous host after switching environment.
        ->and($js)->toContain('window.axSend=function(path,method)')
        ->and($js)->toContain('var server=opServer(op)')
        // A token is no longer written into every history entry.
        ->and($js)->not->toContain('auth:(auth&&auth.value)||null')
        ->and($js)->toContain('v:2,server:server');
});

it('scopes expand/collapse to the section it was clicked in', function () {
    $js = uiScript();

    expect($js)->toContain('window.axExpandAll=function(open,btn)')
        ->and($js)->toContain("btn.closest('.ax-section')")
        ->and($js)->toContain('axExpandAll(true,this)');
});

it('renders the markdown an OpenAPI description actually contains', function () {
    $js = uiScript();

    expect($js)->toContain('<table class="ax-md-table">')   // pipe tables
        ->and($js)->toContain("+'<ol>'+items+'</ol>'")       // ordered lists
        ->and($js)->toContain('<blockquote>')
        ->and($js)->toContain("'$1<hr>'")
        // Fences are re-injected before the paragraph pass, or a <pre> ends up
        // inside a <p> and the browser closes the paragraph early.
        ->and(strpos($js, 'ax-md-pre'))->toBeLessThan(strpos($js, "s=s.split(/\\n{2,}/)"))
        ->and(uiStylesheet())->toContain('.ax-md-table th{');
});

it('keeps the renderer source out of binary territory', function () {
    // The fence sentinel was a literal NUL byte, which made this file — the
    // largest in the package — grep as binary for every tool.
    $source = file_get_contents(__DIR__.'/../../../src/Http/UiRenderer.php');

    expect(str_contains($source, "\0"))->toBeFalse()
        ->and(uiScript())->toContain('\uE000B');
});

it('cancels the spec request it gave up on', function () {
    $js = uiScript();

    // The 10s watchdog painted an error while the request kept running, so an
    // 11s response painted the whole UI over that error.
    expect($js)->toContain('new AbortController()')
        ->and($js)->toContain('_specAbort.abort()')
        ->and($js)->toContain('window.axRetrySpec=function()')
        ->and($js)->toContain('onclick="axRetrySpec()"')
        ->and($js)->not->toContain("onclick=\"location.reload()\"");
});

it('prunes what it stores and offers to forget it', function () {
    $js = uiScript();

    // Nothing expired anything, and a bearer token was persisted in plaintext
    // with no forget control.
    expect($js)->toContain('function pruneStorage()')
        ->and($js)->toContain('window.axClearStored=function()')
        ->and($js)->toContain('pruneStorage();')
        ->and(uiPage())->toContain('Clear stored data');
});

it('tells you when the theme is following the system', function () {
    // `auto` removes data-theme, and the glyph swap keys on it, so `auto`
    // looked exactly like `dark`.
    expect(uiScript())->toContain("setAttribute('data-theme-pref',t)")
        ->and(uiStylesheet())->toContain('[data-theme-pref="auto"] .apex-theme-btn::after');
});

it('finds the operations that really use a schema', function () {
    $js = uiScript();

    // `JSON.stringify(op).indexOf('#/components/schemas/User')` is a substring
    // test: `User` was used by every operation touching `UserProfile`.
    expect($js)->toContain('function refsSchema(node,target,spec,seen,refDepth)')
        ->and($js)->toContain("if(node['\$ref']===target)return true")
        ->and($js)->not->toContain("JSON.stringify(op);if(s.indexOf('#/components/schemas/'+name)");
});
