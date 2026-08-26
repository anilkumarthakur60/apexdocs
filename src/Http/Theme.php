<?php

declare(strict_types=1);

namespace ApexDocs\Http;

/**
 * The colour palette for the native documentation UI, as CSS custom properties.
 *
 * Both themes define the *same* token set. That is the whole point: the previous
 * palette overrode ten variables for light mode and left ~40 hard-coded dark
 * hexes behind, so method badges, JSON syntax highlighting, and every status
 * colour rendered as pale-on-white and could not be read.
 *
 * Every token that carries text is contrast-checked against the surface it sits
 * on — see tests/Unit/Http/ThemeContrastTest.php, which fails the build if a
 * pair drops below the WCAG 2.1 AA ratio of 4.5:1. Colours here are chosen to
 * satisfy that, not to look right in one designer's editor.
 */
final class Theme
{
    public const MODES = ['dark', 'light'];

    /**
     * Layout tokens, shared by both themes.
     *
     * These are the *phone* values. A breakpoint in UiRenderer::cssResponsive()
     * is a handful of writes to this token set on `:root`, not a pile of
     * per-selector overrides — so the only place a layout dimension is defined
     * is here and the five ascending min-width blocks.
     *
     * `--banner-h` is the one token whose value here is only a starting point —
     * 0px, correct for the common case of no announcement banner. When there is
     * one it carries author-supplied HTML that wraps at any width, so its height
     * is measured and republished by the shell metrics in UiRenderer::jsCore().
     * The >=1200px sticky rail is its only consumer.
     *
     * @var array<string, string>
     */
    private const STRUCTURE = [
        '--r' => '8px',
        '--bar-h' => '56px',
        '--banner-h' => '0px',
        '--ctx-h' => '38px',
        '--gutter' => '16px',
        '--ind' => '10px',
        '--tap' => '44px',
        '--nav-w' => '240px',
        '--rail-w' => '380px',
        '--doc-max' => 'none',
    ];

    /**
     * @var array<string, string>
     */
    private const DARK = [
        // ── Surfaces ─────────────────────────────────────────────────────────
        '--bg' => '#0a0a0c',
        '--elev' => '#111116',
        '--bar-bg' => 'rgba(10,10,14,0.93)',
        '--border' => 'rgba(255,255,255,0.07)',
        '--border-s' => 'rgba(255,255,255,0.22)',
        '--s1' => 'rgba(255,255,255,0.04)',
        '--s2' => 'rgba(255,255,255,0.07)',
        '--s3' => 'rgba(255,255,255,0.11)',
        // Raised (cards, panels) vs recessed (code blocks, inputs). On a dark
        // page both are lighter than the background, so one tint did the job;
        // on a light page a card must be *lighter* than the page and a code
        // block *darker*, which is why light mode looked uniformly flat.
        '--card' => 'rgba(255,255,255,0.04)',
        '--inset' => 'rgba(255,255,255,0.045)',

        // ── Text ─────────────────────────────────────────────────────────────
        '--t1' => '#f4f4f5',
        '--t2' => '#a1a1aa',
        // #52525b measured 2.56:1 here — below AA even for large text.
        '--t3' => '#85858f',

        // ── Accent ───────────────────────────────────────────────────────────
        '--accent' => '#6366f1',
        '--accent2' => '#a855f7',
        '--accent-hover' => '#4f46e5',
        '--accent-on' => '#ffffff',
        '--accent-t' => '#a5b4fc',
        '--accent-soft' => 'rgba(99,102,241,0.14)',
        '--accent-soft-b' => 'rgba(99,102,241,0.40)',
        '--focus' => 'rgba(99,102,241,0.55)',
        // The focus ring is a non-text UI boundary (3:1), but it is set to the
        // AA-checked accent tint so it also reads on the elevated surfaces.
        // --ring-2 is the inner ring drawn against a coloured fill.
        '--ring' => '#a5b4fc',
        '--ring-2' => '#0a0a0c',
        '--brand-1' => '#a5b4fc',
        '--brand-2' => '#c4b5fd',
        '--brand-3' => '#f0abfc',

        // ── Semantic ─────────────────────────────────────────────────────────
        '--green-t' => '#4ade80',
        '--green-soft' => 'rgba(74,222,128,0.13)',
        '--green-b' => 'rgba(74,222,128,0.30)',
        '--blue-t' => '#60a5fa',
        '--blue-soft' => 'rgba(96,165,250,0.13)',
        '--blue-b' => 'rgba(96,165,250,0.30)',
        '--amber-t' => '#fbbf24',
        '--amber-soft' => 'rgba(245,158,11,0.13)',
        '--amber-b' => 'rgba(245,158,11,0.30)',
        '--red-t' => '#f87171',
        '--red-soft' => 'rgba(239,68,68,0.13)',
        '--red-b' => 'rgba(239,68,68,0.30)',
        '--purple-t' => '#c4b5fd',
        '--purple-soft' => 'rgba(168,85,247,0.13)',
        '--purple-b' => 'rgba(168,85,247,0.30)',
        '--neutral-t' => '#a1a1aa',
        '--neutral-soft' => 'rgba(113,113,122,0.14)',
        '--neutral-b' => 'rgba(113,113,122,0.30)',

        // Legacy aliases — plenty of rules still say var(--green).
        '--green' => '#4ade80',
        '--blue' => '#60a5fa',
        '--amber' => '#fbbf24',
        '--red' => '#f87171',
        '--purple' => '#c4b5fd',

        // ── HTTP method badges ───────────────────────────────────────────────
        '--m-get' => 'rgba(59,130,246,0.14)',
        '--m-get-c' => '#60a5fa',
        '--m-get-b' => 'rgba(59,130,246,0.25)',
        '--m-post' => 'rgba(34,197,94,0.13)',
        '--m-post-c' => '#4ade80',
        '--m-post-b' => 'rgba(34,197,94,0.25)',
        '--m-put' => 'rgba(245,158,11,0.13)',
        '--m-put-c' => '#fbbf24',
        '--m-put-b' => 'rgba(245,158,11,0.25)',
        '--m-patch' => 'rgba(139,92,246,0.13)',
        '--m-patch-c' => '#a78bfa',
        '--m-patch-b' => 'rgba(139,92,246,0.25)',
        '--m-delete' => 'rgba(239,68,68,0.13)',
        '--m-delete-c' => '#f87171',
        '--m-delete-b' => 'rgba(239,68,68,0.25)',
        '--m-head' => 'rgba(113,113,122,0.14)',
        '--m-head-c' => '#a1a1aa',
        '--m-head-b' => 'rgba(113,113,122,0.25)',

        // ── JSON syntax highlighting (sits on --s1) ──────────────────────────
        '--syn-k' => '#7dd3fc',
        '--syn-s' => '#86efac',
        '--syn-n' => '#fca5a5',
        '--syn-b' => '#a5b4fc',
        '--syn-null' => '#9494a0',
        '--syn-p' => '#85858f',

        // ── Effects ──────────────────────────────────────────────────────────
        '--shadow-1' => '0 16px 48px rgba(0,0,0,0.45)',
        '--shadow-2' => '0 24px 70px rgba(0,0,0,0.6)',
        '--backdrop' => 'rgba(0,0,0,0.6)',
        '--toast-bg' => '#1e1e2a',
        '--glow' => '0 0 18px rgba(99,102,241,0.4)',
        // Loading skeletons: base tint and the sweep highlight.
        '--skel' => 'rgba(255,255,255,0.06)',
        '--skel-hi' => 'rgba(255,255,255,0.12)',
    ];

    /**
     * @var array<string, string>
     */
    private const LIGHT = [
        // ── Surfaces ─────────────────────────────────────────────────────────
        '--bg' => '#f8f8fc',
        '--elev' => '#ffffff',
        '--bar-bg' => 'rgba(255,255,255,0.94)',
        '--border' => 'rgba(15,15,20,0.09)',
        '--border-s' => 'rgba(15,15,20,0.26)',
        '--s1' => 'rgba(15,15,20,0.035)',
        '--s2' => 'rgba(15,15,20,0.07)',
        '--s3' => 'rgba(15,15,20,0.11)',
        '--card' => '#ffffff',
        '--inset' => 'rgba(15,15,20,0.045)',

        // ── Text ─────────────────────────────────────────────────────────────
        '--t1' => '#0f0f14',
        '--t2' => '#52525b',
        '--t3' => '#5f6774',

        // ── Accent ───────────────────────────────────────────────────────────
        // The fill stays indigo-500 so buttons look the same in both themes;
        // the *text* variant is darkened until it passes AA on white.
        '--accent' => '#6366f1',
        '--accent2' => '#9333ea',
        '--accent-hover' => '#4f46e5',
        '--accent-on' => '#ffffff',
        '--accent-t' => '#4338ca',
        '--accent-soft' => 'rgba(67,56,202,0.09)',
        '--accent-soft-b' => 'rgba(67,56,202,0.28)',
        '--focus' => 'rgba(67,56,202,0.45)',
        '--ring' => '#4338ca',
        '--ring-2' => '#ffffff',
        '--brand-1' => '#4338ca',
        '--brand-2' => '#7e22ce',
        '--brand-3' => '#a21caf',

        // ── Semantic ─────────────────────────────────────────────────────────
        '--green-t' => '#116533',
        '--green-soft' => 'rgba(17,101,51,0.09)',
        '--green-b' => 'rgba(17,101,51,0.26)',
        '--blue-t' => '#1d4ed8',
        '--blue-soft' => 'rgba(29,78,216,0.09)',
        '--blue-b' => 'rgba(29,78,216,0.26)',
        '--amber-t' => '#92400e',
        '--amber-soft' => 'rgba(146,64,14,0.09)',
        '--amber-b' => 'rgba(146,64,14,0.26)',
        '--red-t' => '#b91c1c',
        '--red-soft' => 'rgba(185,28,28,0.09)',
        '--red-b' => 'rgba(185,28,28,0.26)',
        '--purple-t' => '#7e22ce',
        '--purple-soft' => 'rgba(126,34,206,0.09)',
        '--purple-b' => 'rgba(126,34,206,0.26)',
        '--neutral-t' => '#52525b',
        '--neutral-soft' => 'rgba(82,82,91,0.09)',
        '--neutral-b' => 'rgba(82,82,91,0.26)',

        '--green' => '#116533',
        '--blue' => '#1d4ed8',
        '--amber' => '#92400e',
        '--red' => '#b91c1c',
        '--purple' => '#7e22ce',

        // ── HTTP method badges ───────────────────────────────────────────────
        '--m-get' => 'rgba(29,78,216,0.09)',
        '--m-get-c' => '#1d4ed8',
        '--m-get-b' => 'rgba(29,78,216,0.24)',
        '--m-post' => 'rgba(17,101,51,0.09)',
        '--m-post-c' => '#116533',
        '--m-post-b' => 'rgba(17,101,51,0.24)',
        '--m-put' => 'rgba(146,64,14,0.09)',
        '--m-put-c' => '#92400e',
        '--m-put-b' => 'rgba(146,64,14,0.24)',
        '--m-patch' => 'rgba(107,33,168,0.09)',
        '--m-patch-c' => '#6b21a8',
        '--m-patch-b' => 'rgba(107,33,168,0.24)',
        '--m-delete' => 'rgba(185,28,28,0.09)',
        '--m-delete-c' => '#b91c1c',
        '--m-delete-b' => 'rgba(185,28,28,0.24)',
        '--m-head' => 'rgba(82,82,91,0.09)',
        '--m-head-c' => '#52525b',
        '--m-head-b' => 'rgba(82,82,91,0.24)',

        // ── JSON syntax highlighting (sits on --s1) ──────────────────────────
        '--syn-k' => '#0369a1',
        '--syn-s' => '#116533',
        '--syn-n' => '#b91c1c',
        '--syn-b' => '#4338ca',
        '--syn-null' => '#5f6774',
        '--syn-p' => '#5f6774',

        // ── Effects ──────────────────────────────────────────────────────────
        // Light UIs need far less shadow: the same alpha reads as dirt.
        '--shadow-1' => '0 12px 32px rgba(15,15,20,0.12)',
        '--shadow-2' => '0 20px 50px rgba(15,15,20,0.18)',
        '--backdrop' => 'rgba(15,15,20,0.32)',
        '--toast-bg' => '#1f2030',
        '--glow' => '0 2px 10px rgba(99,102,241,0.35)',
        '--skel' => 'rgba(15,15,20,0.06)',
        '--skel-hi' => 'rgba(15,15,20,0.11)',
    ];

    private function __construct() {}

    /**
     * @return array<string, string>
     */
    public static function palette(string $mode): array
    {
        return $mode === 'light' ? self::LIGHT : self::DARK;
    }

    /** The declaration list for one theme, e.g. `--bg:#0a0a0c;--t1:#f4f4f5;…`. */
    public static function declarations(string $mode): string
    {
        $out = '';
        foreach (self::palette($mode) as $token => $value) {
            $out .= $token.':'.$value.';';
        }

        return $out;
    }

    /**
     * Every selector that needs a palette, generated from one definition so the
     * explicit choice and the system-preference fallback cannot drift apart.
     */
    public static function css(): string
    {
        $structure = '';
        foreach (self::STRUCTURE as $token => $value) {
            $structure .= $token.':'.$value.';';
        }

        $dark = self::declarations('dark');
        $light = self::declarations('light');

        // Print always uses the light palette: a dark-theme reader would
        // otherwise send a full-bleed near-black page to the printer. It sits
        // before the system-preference block so the more specific
        // `:root:not([data-theme])` selector still wins for auto + light.
        return <<<CSS
        :root{{$structure}{$dark}}
        [data-theme="dark"]{{$dark}}
        [data-theme="light"]{{$light}}
        @media print{:root,[data-theme="dark"],[data-theme="light"]{{$light}}}
        @media(prefers-color-scheme:light){:root:not([data-theme]){{$light}}}
        CSS;
    }
}
