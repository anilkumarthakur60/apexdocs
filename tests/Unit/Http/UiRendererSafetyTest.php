<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Http\Handler;
use ApexDocs\Http\UiRenderer;
use ApexDocs\Route\ArrayRouteCollection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

function renderHandler(Config $config): Handler
{
    $factory = new Psr17Factory;

    return new Handler(
        ApexDocs::make($config)->routes(new ArrayRouteCollection),
        $factory,
        $factory,
        new UiRenderer,
    );
}

it('derives the spec URL from the path, ignoring the query string', function () {
    $html = (string) renderHandler(new Config(title: 'T'))
        ->handle(new ServerRequest('GET', 'https://host/docs?theme=light'))
        ->getBody();

    // "…/docs?theme=light/spec.json" was the old, unfetchable result.
    expect($html)->toContain('https://host/docs/spec.json')
        ->and($html)->not->toContain('?theme=light/spec.json');
});

it('keeps child selectors intact in custom CSS', function () {
    $html = (new UiRenderer)->render('/docs/spec.json', new Config(
        title: 'T',
        customCss: '.sidebar > .item { color: red }',
    ));

    // HTML-escaping the block used to turn every child selector into "&gt;".
    expect($html)->toContain('<style>.sidebar > .item { color: red }</style>');
});

it('neutralises a style-block escape in custom CSS', function () {
    $html = (new UiRenderer)->render('/docs/spec.json', new Config(
        title: 'T',
        customCss: 'body{}</style><script>alert(1)</script>',
    ));

    expect($html)->not->toContain('</style><script>');
});

it('escapes the API title in the page', function () {
    $html = (new UiRenderer)->render('/docs/spec.json', new Config(title: '<img src=x onerror=alert(1)>'));

    expect($html)->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;img src=x');
});

it('ignores an unsupported theme rather than injecting it', function () {
    $html = (new UiRenderer)->render('/docs/spec.json', new Config(title: 'T', theme: '" onload="alert(1)'));

    expect($html)->toContain('data-theme="dark"')
        ->and($html)->not->toContain('onload="alert(1)');
});

it('points the brand link at the docs root, not the site root', function () {
    $html = (new UiRenderer)->render('https://host/docs/spec.json', new Config(title: 'T'));

    expect($html)->toContain('<a href="https://host/docs" class="apex-brand"');
});

/**
 * The JSON highlighter runs on text that has already been HTML-escaped, so it
 * must not treat the inside of an entity as content. It used to match the `39`
 * in `&#39;` as a number and wrap it in a span, which split the entity and made
 * every cURL sample render a literal "&#39;" instead of a quote. The same
 * oversight meant `&quot;` was never recognised, so no JSON key or string was
 * ever highlighted.
 *
 * Extract the shipped pattern and run it, rather than asserting on a copy.
 */
function highlighterPattern(): string
{
    $source = file_get_contents(__DIR__.'/../../../src/Http/UiRenderer.php');

    expect($source)->toBeString();
    preg_match('/var pattern=\/(.*)\/g;/', (string) $source, $m);

    expect($m[1] ?? '')->not->toBeEmpty('could not find the hlJson pattern');

    return '/'.$m[1].'/';
}

it('leaves HTML entities untouched when highlighting JSON', function () {
    $pattern = highlighterPattern();

    preg_match_all($pattern, 'curl -d &#39;{&quot;a&quot;: 1}&#39;', $matches);

    // The entity must be matched whole, so the replace callback can pass it
    // through — never as the bare digits inside it.
    expect($matches[0])->toContain('&#39;')
        ->and($matches[0])->not->toContain('39');
});

it('recognises escaped quotes as JSON strings and keys', function () {
    $pattern = highlighterPattern();

    preg_match_all($pattern, '{&quot;id&quot;: 1, &quot;name&quot;: &quot;a&quot;, &quot;ok&quot;: true, &quot;n&quot;: null}', $matches);

    expect($matches[0])->toContain('&quot;id&quot;:')
        ->and($matches[0])->toContain('&quot;a&quot;')
        ->and($matches[0])->toContain('true')
        ->and($matches[0])->toContain('null')
        ->and($matches[0])->toContain('1');
});

it('does not split an escaped ampersand or angle bracket', function () {
    $pattern = highlighterPattern();

    preg_match_all($pattern, '&quot;O&#39;Brien &amp; Sons &lt;Ltd&gt;&quot;', $matches);

    // The whole string is one match; the entities inside it stay intact.
    expect(implode('', $matches[0]))->toContain('&amp;')
        ->and(implode('', $matches[0]))->toContain('&lt;');
});
