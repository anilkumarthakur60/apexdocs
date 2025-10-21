<?php

declare(strict_types=1);

use ApexDocs\Config;
use ApexDocs\Http\UiRenderer;

/**
 * UiRenderer is the package's biggest single file (~2.2k lines) but until
 * now had zero direct coverage. These tests don't pretend to validate the
 * generated HTML semantically — that's what a real browser test would do —
 * but they enforce the things that a unit test CAN catch: every supported
 * UI variant returns non-empty HTML, the title/version/spec URL are
 * properly HTML-escaped, the native "apex" UI inlines its assets (no CDN
 * required, per the renderer's contract), and rendering does not throw on
 * unknown UI names.
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
        defaultUi: $overrides['ui'] ?? 'apex',
    );
}

it('renders non-empty HTML for every supported UI variant', function (string $variant) {
    $html = ui()->render($variant, '/docs/spec.json', cfg(['ui' => $variant]));

    expect($html)->toBeString()
        ->and(strlen($html))->toBeGreaterThan(500)
        ->and($html)->toContain('<html')
        ->and($html)->toContain('</html>');
})->with(['apex', 'scalar', 'swagger', 'redoc', 'stoplight', 'rapidoc']);

it('HTML-escapes the title and version', function () {
    $html = ui()->render('apex', '/spec.json', cfg([
        'title' => '<script>alert(1)</script>',
        'version' => '1.0">x',
    ]));

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('1.0&quot;&gt;x');
});

it('escapes the spec URL where it is used in HTML attributes', function () {
    $html = ui()->render('apex', '/spec.json?x="><script>', cfg());

    expect($html)->not->toContain('"><script>')
        ->and($html)->toContain('&quot;');
});

it('falls back gracefully when an unknown UI is requested', function () {
    expect(fn () => ui()->render('not-a-real-ui', '/spec.json', cfg()))
        ->not->toThrow(\Throwable::class);
});

it('inlines its assets for the native apex UI (no external CDN)', function () {
    $html = ui()->render('apex', '/spec.json', cfg(['ui' => 'apex']));

    // The native UI is the whole point — must work fully offline.
    expect($html)->toContain('<style')
        ->and($html)->toContain('<script')
        ->and($html)->not->toMatch('#<script[^>]*src="https?://#'); // no remote script src
});
