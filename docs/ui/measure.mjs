/**
 * Responsive/a11y measurement harness for the ApexDocs UI.
 *
 * Drives headless Chrome over CDP (no npm deps - Node 22 has a global
 * WebSocket) so we can measure real layout at real viewport sizes instead of
 * eyeballing screenshots.
 *
 *   node cdp.mjs <url> [outDir]
 *
 * Reports per viewport: horizontal overflow, elements wider than the viewport,
 * which key panels are actually visible, undersized touch targets, and whether
 * a focus ring exists. Writes a screenshot per viewport.
 */
import { spawn } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { setTimeout as sleep } from 'node:timers/promises'

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
const PORT = 9400 + (process.pid % 500)
const PROFILE = `/tmp/apexdocs-cdp-${process.pid}`
const URL_ARG = process.argv[2] || 'http://127.0.0.1:8123/?theme=dark#op_post__/api/v2/shipments'
const OUT = process.argv[3] || '.'

const VIEWPORTS = [
  { name: 'phone-390', width: 390, height: 844, dpr: 3, mobile: true },
  { name: 'phone-430', width: 430, height: 932, dpr: 3, mobile: true },
  { name: 'tablet-768', width: 768, height: 1024, dpr: 2, mobile: true },
  { name: 'tablet-1024', width: 1024, height: 768, dpr: 2, mobile: false },
  { name: 'laptop-1280', width: 1280, height: 800, dpr: 2, mobile: false },
  { name: 'desktop-1680', width: 1680, height: 1050, dpr: 2, mobile: false },
]

mkdirSync(OUT, { recursive: true })

const chrome = spawn(CHROME, [
  '--headless=new',
  `--remote-debugging-port=${PORT}`,
  '--disable-gpu',
  '--no-first-run',
  `--user-data-dir=${PROFILE}`,
  'about:blank',
], { stdio: 'ignore' })

process.on('exit', () => { try { chrome.kill('SIGKILL') } catch {} })

async function targetWs() {
  for (let i = 0; i < 60; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const targets = await res.json()
      const page = targets.find(t => t.type === 'page')
      if (page?.webSocketDebuggerUrl) return page.webSocketDebuggerUrl
    } catch { /* not up yet */ }
    await sleep(250)
  }
  throw new Error('Chrome did not expose a debugging target')
}

const ws = new WebSocket(await targetWs())
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, { once: true })
  ws.addEventListener('error', reject, { once: true })
})

let nextId = 1
const pending = new Map()
ws.addEventListener('message', (event) => {
  const msg = JSON.parse(event.data)
  if (msg.id && pending.has(msg.id)) {
    const { resolve, reject } = pending.get(msg.id)
    pending.delete(msg.id)
    msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result)
  }
})

function send(method, params = {}) {
  const id = nextId++
  return new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject })
    ws.send(JSON.stringify({ id, method, params }))
  })
}

async function evaluate(expression) {
  const { result, exceptionDetails } = await send('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise: true,
  })
  if (exceptionDetails) throw new Error(exceptionDetails.text + ' ' + (exceptionDetails.exception?.description || ''))
  return result.value
}

await send('Page.enable')
await send('Runtime.enable')
await send('Log.enable')

const MEASURE = `(() => {
  const vw = window.innerWidth;
  const overflow = document.scrollingElement.scrollWidth - vw;

  const tooWide = [];
  const tiny = [];
  document.querySelectorAll('*').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return;
    const cs = getComputedStyle(el);
    if (cs.position === 'fixed' || cs.position === 'absolute') return;
    if (r.right > vw + 1 || r.width > vw + 1) {
      const id = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).slice(0, 2).join('.') : '');
      if (!tooWide.some(t => t.sel === id)) {
        tooWide.push({ sel: id, width: Math.round(r.width), right: Math.round(r.right), scrollW: el.scrollWidth, clientW: el.clientWidth });
      }
    }
    const interactive = el.matches('button,a,input,select,textarea,[role="button"],[onclick],summary');
    if (interactive && (r.width < 40 || r.height < 40) && cs.visibility !== 'hidden' && cs.display !== 'none') {
      const id = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/)[0] : '');
      if (!tiny.some(t => t.sel === id)) tiny.push({ sel: id, w: Math.round(r.width), h: Math.round(r.height) });
    }
  });

  const vis = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return 'absent';
    const cs = getComputedStyle(el);
    if (cs.display === 'none') return 'display:none';
    if (cs.visibility === 'hidden') return 'hidden';
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return 'zero-size';
    if (r.right <= 0 || r.left >= vw) return 'off-screen';
    return Math.round(r.width) + 'x' + Math.round(r.height);
  };

  return {
    viewport: vw + 'x' + window.innerHeight,
    horizontalOverflow: overflow,
    bodyScrollWidth: document.scrollingElement.scrollWidth,
    tooWide: tooWide.slice(0, 12),
    undersizedTargets: tiny.slice(0, 14),
    panels: {
      sidebar: vis('#axui-sidebar'),
      content: vis('#axui-content'),
      rightPanel: vis('#axui-panel'),
      codeSample: vis('#ax-code-wrap'),
      tryItOut: vis('.ax-try-section'),
      toolbar: vis('#apex-bar'),
      uiTabs: vis('#apexTabs'),
      sidebarToggle: vis('#axui-sb-toggle'),
    },
    endpointsRendered: document.querySelectorAll('.axi').length,
    opRendered: !!document.querySelector('.ax-op-path'),
    focusRingRules: [...document.styleSheets].reduce((n, s) => {
      try { return n + [...s.cssRules].filter(r => (r.selectorText || '').includes(':focus-visible')).length } catch { return n }
    }, 0),
    reducedMotionRules: [...document.styleSheets].reduce((n, s) => {
      try { return n + [...s.cssRules].filter(r => (r.conditionText || '').includes('prefers-reduced-motion')).length } catch { return n }
    }, 0),
  };
})()`

const report = {}
for (const vp of VIEWPORTS) {
  const metrics = { width: vp.width, height: vp.height, deviceScaleFactor: 1, mobile: vp.mobile }

  // about:blank between runs, otherwise a same-URL navigate is a no-op and the
  // page keeps the previous viewport's layout - which silently reported the
  // wrong width for every viewport after the first.
  await send('Page.navigate', { url: 'about:blank' })
  await sleep(150)
  await send('Emulation.setDeviceMetricsOverride', metrics)
  await send('Page.navigate', { url: URL_ARG })
  await sleep(2400)
  // The UI renders after fetching the spec; give the hash router a nudge.
  await evaluate(`(() => { if (location.hash) window.dispatchEvent(new HashChangeEvent('hashchange')); return true })()`).catch(() => {})
  // Re-assert: some pages reset the override during load.
  await send('Emulation.setDeviceMetricsOverride', metrics)
  await sleep(900)

  const actual = await evaluate('window.innerWidth')
  if (actual !== vp.width) console.log(`  ! ${vp.name}: asked for ${vp.width}px, got ${actual}px`)

  report[vp.name] = await evaluate(MEASURE)

  const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })
  writeFileSync(`${OUT}/${vp.name}.png`, Buffer.from(shot.data, 'base64'))
}

const consoleErrors = []
writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2))

for (const [name, r] of Object.entries(report)) {
  console.log(`\n=== ${name}  (${r.viewport}) ===`)
  console.log(`  horizontal overflow: ${r.horizontalOverflow}px  (scrollWidth ${r.bodyScrollWidth})`)
  console.log(`  endpoints in nav: ${r.endpointsRendered}   operation rendered: ${r.opRendered}`)
  console.log('  panels:')
  for (const [k, v] of Object.entries(r.panels)) console.log(`    ${k.padEnd(15)} ${v}`)
  if (r.tooWide.length) {
    console.log('  WIDER THAN VIEWPORT:')
    r.tooWide.forEach(t => console.log(`    ${t.sel}  w=${t.width} right=${t.right} scrollW=${t.scrollW}`))
  }
  if (r.undersizedTargets.length) {
    console.log(`  touch targets under 40px: ${r.undersizedTargets.map(t => `${t.sel}(${t.w}x${t.h})`).join(', ')}`)
  }
  console.log(`  :focus-visible rules: ${r.focusRingRules}   prefers-reduced-motion blocks: ${r.reducedMotionRules}`)
}

ws.close()
chrome.kill()
process.exit(0)
