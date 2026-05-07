<?php

declare(strict_types=1);

use ApexDocs\Config;
use ApexDocs\Http\Theme;
use ApexDocs\Http\UiRenderer;

/**
 * The palette is checked numerically, not by eye.
 *
 * The previous theme defined ten variables for light mode and left ~40
 * dark-tuned hex colours hard-coded in the stylesheet, so method badges, JSON
 * syntax highlighting, and every status colour rendered pale-on-white. Muted
 * text failed AA in *both* themes at ~2.5:1. These tests make that class of
 * regression a build failure.
 *
 * Ratios follow WCAG 2.1: 4.5:1 for body text, 3:1 for large text and UI
 * component boundaries.
 */
function srgbChannel(float $c): float
{
    $c /= 255;

    return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
}

function relativeLuminance(string $hex): float
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }

    return 0.2126 * srgbChannel((float) hexdec(substr($hex, 0, 2)))
        + 0.7152 * srgbChannel((float) hexdec(substr($hex, 2, 2)))
        + 0.0722 * srgbChannel((float) hexdec(substr($hex, 4, 2)));
}

/** Composite an rgba() colour over an opaque backdrop so tints can be measured. */
function compositeOver(string $color, string $backdrop): string
{
    $color = trim($color);
    if (! str_starts_with($color, 'rgba')) {
        return $color;
    }
    if (! preg_match('/rgba\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)[,\s\/]+([\d.]+)\s*\)/', $color, $m)) {
        return $backdrop;
    }

    $alpha = (float) $m[4];
    $base = ltrim($backdrop, '#');
    if (strlen($base) === 3) {
        $base = $base[0].$base[0].$base[1].$base[1].$base[2].$base[2];
    }

    $out = '';
    foreach ([1, 2, 3] as $i) {
        $mixed = (float) $m[$i] * $alpha + (float) hexdec(substr($base, ($i - 1) * 2, 2)) * (1 - $alpha);
        $out .= str_pad(dechex((int) round($mixed)), 2, '0', STR_PAD_LEFT);
    }

    return '#'.$out;
}

function contrastRatio(string $foreground, string $background): float
{
    $a = relativeLuminance($foreground);
    $b = relativeLuminance($background);
    [$lighter, $darker] = $a >= $b ? [$a, $b] : [$b, $a];

    return ($lighter + 0.05) / ($darker + 0.05);
}

/**
 * Resolve a token pair to a measurable ratio: tinted surfaces are composited
 * over the page background first, then the foreground over that.
 */
function tokenContrast(array $palette, string $foreground, string $surface): float
{
    $bg = $palette['--bg'];
    $solidSurface = compositeOver($palette[$surface] ?? $bg, $bg);

    return contrastRatio(compositeOver($palette[$foreground] ?? '#000000', $solidSurface), $solidSurface);
}

/** foreground token => [surface token, minimum ratio] */
const BODY_TEXT_PAIRS = [
    '--t1' => ['--bg', 4.5],
    '--t2' => ['--bg', 4.5],
    '--t3' => ['--bg', 4.5],
    '--accent-t' => ['--bg', 4.5],
    '--green-t' => ['--bg', 4.5],
    '--blue-t' => ['--bg', 4.5],
    '--amber-t' => ['--bg', 4.5],
    '--red-t' => ['--bg', 4.5],
    '--purple-t' => ['--bg', 4.5],
    '--neutral-t' => ['--bg', 4.5],
    // Muted text also appears on the elevated panels and inset surfaces.
    '--t3-on-s1' => ['--s1', 4.5],
    // JSON syntax highlighting renders inside a --s1 code block.
    '--syn-k' => ['--s1', 4.5],
    '--syn-s' => ['--s1', 4.5],
    '--syn-n' => ['--s1', 4.5],
    '--syn-b' => ['--s1', 4.5],
    '--syn-null' => ['--s1', 4.5],
    '--syn-p' => ['--s1', 4.5],
    // Every method badge is a tinted chip with its own text colour.
    '--m-get-c' => ['--m-get', 4.5],
    '--m-post-c' => ['--m-post', 4.5],
    '--m-put-c' => ['--m-put', 4.5],
    '--m-patch-c' => ['--m-patch', 4.5],
    '--m-delete-c' => ['--m-delete', 4.5],
    '--m-head-c' => ['--m-head', 4.5],
    // Semantic badges: same colour, but on their own soft fill.
    '--green-t-soft' => ['--green-soft', 4.5],
    '--blue-t-soft' => ['--blue-soft', 4.5],
    '--amber-t-soft' => ['--amber-soft', 4.5],
    '--red-t-soft' => ['--red-soft', 4.5],
    '--purple-t-soft' => ['--purple-soft', 4.5],
    '--accent-t-soft' => ['--accent-soft', 4.5],
];

it('defines the identical token set in both themes', function () {
    $dark = array_keys(Theme::palette('dark'));
    $light = array_keys(Theme::palette('light'));

    sort($dark);
    sort($light);

    expect($light)->toBe($dark);
});

it('meets WCAG AA for body text in every theme', function (string $mode) {
    $palette = Theme::palette($mode);

    foreach (BODY_TEXT_PAIRS as $token => [$surface, $min]) {
        // "--x-soft"/"--x-on-y" are assertions about an existing token on a
        // different surface, not tokens of their own.
        $foreground = str_replace(['-soft', '-on-s1'], '', $token);
        $ratio = tokenContrast($palette, $foreground, $surface);

        expect($ratio)->toBeGreaterThanOrEqual(
            $min,
            sprintf('%s on %s in %s theme is %.2f:1, needs %.1f:1', $foreground, $surface, $mode, $ratio, $min),
        );
    }
})->with(['dark', 'light']);

it('keeps interactive borders distinguishable from the page', function (string $mode) {
    $palette = Theme::palette($mode);

    expect(tokenContrast($palette, '--border-s', '--bg'))->toBeGreaterThanOrEqual(1.7);
})->with(['dark', 'light']);

it('keeps the focus ring visible as a non-text boundary', function (string $mode) {
    $palette = Theme::palette($mode);

    // WCAG 2.1 asks 3:1 of a non-text UI boundary, and the ring is the only
    // thing telling a keyboard user where they are. It has to hold on the
    // elevated surfaces too — dialogs and popovers are where focus mostly lands.
    foreach (['--bg', '--elev', '--card', '--inset'] as $surface) {
        $ratio = tokenContrast($palette, '--ring', $surface);

        expect($ratio)->toBeGreaterThanOrEqual(
            3.0,
            sprintf('--ring on %s in %s theme is %.2f:1, needs 3:1', $surface, $mode, $ratio),
        );
    }
})->with(['dark', 'light']);

it('renders no hard-coded hex colour outside the palette', function () {
    $css = (new ReflectionMethod(UiRenderer::class, 'css'));
    $css->setAccessible(true);
    $stylesheet = $css->invoke(new UiRenderer);

    // Strip the palette itself — that is where literal colours belong.
    $body = preg_replace('/^.*?@media\(prefers-color-scheme:light\)[^}]*}}/s', '', $stylesheet) ?? $stylesheet;

    preg_match_all('/#[0-9a-fA-F]{6}\b/', $body, $matches);
    $allowed = [
        // The loading shimmer is a fixed brand gradient, legible on either theme.
        '#6366f1', '#8b5cf6', '#a855f7', '#ec4899', '#f59e0b',
        // The toast is a dark chip in both themes, so its label cannot use --t1.
        '#f4f4f5',
    ];

    expect(array_values(array_diff(array_unique($matches[0]), $allowed)))->toBe([]);
});

it('honours a ?theme= override and rejects anything unknown', function () {
    expect(UiRenderer::normalizeTheme('light'))->toBe('light')
        ->and(UiRenderer::normalizeTheme('AUTO'))->toBe('auto')
        ->and(UiRenderer::normalizeTheme('"><script>', 'light'))->toBe('light')
        ->and(UiRenderer::normalizeTheme(null, 'dark'))->toBe('dark')
        ->and(UiRenderer::normalizeTheme(['x'], 'nonsense'))->toBe('dark');

    $html = (new UiRenderer)->render('/docs/spec.json', new Config(title: 'T', theme: 'dark'), 'light');
    expect($html)->toContain('data-theme="light"');
});
