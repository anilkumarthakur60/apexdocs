<?php

declare(strict_types=1);

use ApexDocs\Config;
use ApexDocs\Http\UiRenderer;

/**
 * UiRenderer is the package's biggest single file (~2.2k lines). These tests
 * don't pretend to validate the generated HTML semantically - that's what a
 * real browser test would do - but they enforce the things a unit test CAN
 * catch: the page renders, the title/version/spec URL are properly
 * HTML-escaped, and every asset is inlined so the page needs no network.
 */
function ui(): UiRenderer
{
    return new UiRenderer;
}

function cfg(array $overrides = []): Config
{
    return new Config(
        title: $overrides['title'] ?? 'My API',
        version: $overrides['version'] ?? '1.2.3',
    );
}

it('renders a complete HTML document', function () {
    $html = ui()->render('/docs/spec.json', cfg());

    expect($html)->toBeString()
        ->and(strlen($html))->toBeGreaterThan(500)
        ->and($html)->toContain('<html')
        ->and($html)->toContain('</html>');
});

it('HTML-escapes the title and version', function () {
    $html = ui()->render('/spec.json', cfg([
        'title' => '<script>alert(1)</script>',
        'version' => '1.0">x',
    ]));

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('1.0&quot;&gt;x');
});

it('escapes the spec URL where it is used in HTML attributes', function () {
    $html = ui()->render('/spec.json?x="><script>', cfg());

    expect($html)->not->toContain('"><script>')
        ->and($html)->toContain('&quot;');
});

it('navigates the command palette with a bare fragment', function () {
    $html = ui()->render('/spec.json', cfg());

    // The palette used to link to "?ui=apex#op_…": a full navigation, which
    // resurrected a deleted parameter and discarded any ?theme= override.
    expect($html)->not->toContain('?ui=')
        ->and($html)->toContain('href="#op_');
});

it('inlines its assets (no external CDN)', function () {
    $html = ui()->render('/spec.json', cfg());

    // Zero outbound requests is the property that justified deleting the
    // CDN-backed UIs - the page must work fully offline.
    expect($html)->toContain('<style')
        ->and($html)->toContain('<script')
        ->and($html)->not->toMatch('#<script[^>]*src="https?://#')      // no remote script
        ->and($html)->not->toMatch('#<link[^>]*href="https?://#')       // no remote stylesheet
        ->and($html)->not->toMatch('#<(elements-api|rapi-doc)\b#');     // no CDN web component
});
