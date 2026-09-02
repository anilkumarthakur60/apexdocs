# Documentation UI — rebuild plan and audit trail

The docs UI was consolidated from six interchangeable backends (a native one plus
Scalar, Swagger UI, ReDoc, Stoplight and RapiDoc) down to one. These files are the
working record of that change, kept because only the first two of seven
workstreams have landed.

| File | What it is |
|------|------------|
| `AUDIT-FINDINGS.md` | 159 findings from five parallel audits: feature inventory, responsive behaviour, accessibility, competitive gaps, and every reference to the multi-UI system. Each carries file/line numbers. |
| `REBUILD-PLAN.md` | The implementation plan: layout decision, the full breakpoint specification, and seven workstreams with scope, dependencies and a feature-parity checklist. |
| `measure.mjs` | Responsive/a11y harness. Drives headless Chrome over CDP (no npm deps) and reports horizontal overflow, elements wider than the viewport, which panels are actually visible, and undersized touch targets — per viewport. |

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

**Outstanding:** the rest of WS3 — live-region announcements for the
try-it-out result, roving `tabindex` inside the navigation tree, an audit of the
dialogs' focus traps — and WS4–WS7.

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
