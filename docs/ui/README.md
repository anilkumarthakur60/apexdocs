# Documentation UI - rebuild plan and audit trail

The docs UI was consolidated from six interchangeable backends (a native one plus
Scalar, Swagger UI, ReDoc, Stoplight and RapiDoc) down to one. These files are the
working record of that change, kept because only the first two of seven
workstreams have landed.

| File | What it is |
|------|------------|
| `AUDIT-FINDINGS.md` | 159 findings from five parallel audits: feature inventory, responsive behaviour, accessibility, competitive gaps, and every reference to the multi-UI system. Each carries file/line numbers. |
| `REBUILD-PLAN.md` | The implementation plan: layout decision, the full breakpoint specification, and seven workstreams with scope, dependencies and a feature-parity checklist. |
| `measure.mjs` | Responsive/a11y harness. Drives headless Chrome over CDP (no npm deps) and reports horizontal overflow, elements wider than the viewport, which panels are actually visible, and undersized touch targets - per viewport. |

## Status

**Landed:** WS1 (remove the five CDN UIs and split the two mega-heredocs into
part-methods) and WS2 (the CSS-grid shell, the mobile-first breakpoint rewrite,
and making the code/try-it panel reachable at every viewport width).

**Landed since:** the keyboard layer of WS3. The navigation is real `<a href>`
links routed by a `hashchange` listener (so Back/Forward walk the endpoints, and
an endpoint opens in a new tab), every disclosure control is a `<button>`
carrying `aria-expanded`/`aria-controls`, each view names itself with an `h1`,
the active row carries `aria-current="page"`, decorative glyphs are
`aria-hidden`, a closed menu no longer holds invisible focus stops, and there is
one keyboard-only focus ring drawn from `--ring`. Measured with the harness
below: 222/222 navigation rows reachable by Tab, up from 0/211.

**Landed since:** the correctness half of WS6 and WS7 - Path Item and `$ref`ed
parameters merged, document-level `security`/`servers` honoured, `default`/`2XX`
response keys classified and ordered, response `headers` and `links` rendered,
namespaced try-it field ids, click-time server resolution, runnable code samples
(path substitution, query string, real auth scheme), section-scoped
expand/collapse, `$ref` properties expandable, the structural schema→operation
index, markdown tables/ordered lists/quotes/rules with fences outside `<p>`, an
abortable spec fetch with a working Retry, storage pruning plus a *Clear stored
data* control, and a distinct `auto` theme indicator.

**Outstanding**, in the order the plan gives them:

| Item | Where |
|---|---|
| Event delegation for the ~40 inline `onclick` sites, plus the CSP nonce it enables | WS3 |
| Native `<details>`/`<summary>` and `<dialog>` conversion, `axModal` focus stack | WS3 |
| Live-region announcements (`#apex-live`, `#apex-alert`, `aria-busy` on results) | WS3 |
| Try-it as a real `<form>` with `<fieldset>`/`<legend>` and Enter-to-submit | WS3 |
| Export menu / env popover ARIA (`menuitem`, arrow keys, focus restore) | WS3 |
| Build-once navigation tree (`renderNavOnce`/`applyNavState`/`filterNav`), persisted collapse, two-line rows, tag ordering from `spec.tags` | WS4 |
| One weighted search index feeding both the palette and the sidebar filter; combobox ARIA; `pushState` router | WS5 |
| `#ax-ctx` jump chips, persisted open response codes, constraint badges, `allOf` merging, `discriminator` | WS6 |
| `axSend` abort/cancel button, `hlJson` size cap, 204 handling, response download | WS6 |
| Loading skeletons, `#apex-progress` tied to the fetch, server-URL `variables`, OAuth2 helper cleanup | WS7 |

## Measuring

```bash
# serve the UI against a spec fixture, then:
node docs/ui/measure.mjs 'http://127.0.0.1:8123/?theme=dark#op_post__/api/v2/shipments' /tmp/out
```

Every viewport must report `horizontalOverflow: 0`, an empty `tooWide`, and real
dimensions for `rightPanel` / `codeSample` / `tryItOut`. That last one is the
regression this rebuild exists to prevent: the panel used to be `display:none`
below 1100px, which silently removed code samples and try-it-out from every
phone, tablet and 1024px laptop.
