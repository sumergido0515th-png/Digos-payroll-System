// driver.mjs - REPL driver for the Digos Payroll System (PHP + Bootstrap SPA).
// Run under plain Node (headless Chromium, no display needed - Playwright
// runs Chromium headless by default). Designed for agents: wrap in tmux,
// send-keys commands, capture-pane output.
//
// No chromium-cli in this environment, so this adapts its ergonomics
// (nav / wait-for / click / fill / screenshot / console) on top of Playwright
// directly. Playwright is a global npm install here, not a project
// dependency (this is a no-build-step PHP app) - NODE_PATH picks it up; see
// SKILL.md for the exact launch line.

import * as readline from 'node:readline';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { createRequire } from 'node:module';

// Playwright is a global npm install in this environment, not a project
// dependency (this is a no-build-step PHP app) - ESM `import` ignores
// NODE_PATH, but CJS require() honors it, so resolve it that way.
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8899';
const SHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/shots';
fs.mkdirSync(SHOT_DIR, { recursive: true });

const SKILL_DIR = path.dirname(new URL(import.meta.url).pathname);
const STUB_BOOTSTRAP = fs.readFileSync(path.join(SKILL_DIR, 'stub-bootstrap.js'), 'utf8');
const STUB_GCHARTS = fs.readFileSync(path.join(SKILL_DIR, 'stub-gcharts.js'), 'utf8');

let browser = null;
let page = null;
const consoleLog = [];

function need() {
  if (!page) { console.log('ERROR: launch first'); return false; }
  return true;
}

const COMMANDS = {
  async launch() {
    if (browser) return console.log('already launched');
    browser = await chromium.launch({ args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });

    // public/index.php pulls Bootstrap CSS/JS, the Material Icons stylesheet
    // and Google Charts from public CDNs (see README - "no bundler,
    // Bootstrap from a CDN"). This sandbox's outbound HTTPS can't reach them
    // (ERR_TUNNEL_CONNECTION_FAILED), which otherwise stalls first script
    // execution for 20s+ per page. Serve local stand-ins instead: empty CSS
    // (cosmetic only - unstyled but functional) and the two JS stubs beside
    // this driver, which implement just enough of window.bootstrap
    // (Toast/Modal) and window.google.charts for app.js and dashboard.php.
    await context.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
      route => route.fulfill({ status: 200, contentType: 'text/css', body: '/* stubbed offline */' }));
    await context.route('https://fonts.googleapis.com/icon*',
      route => route.fulfill({ status: 200, contentType: 'text/css', body: '/* stubbed offline */' }));
    await context.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
      route => route.fulfill({ status: 200, contentType: 'application/javascript', body: STUB_BOOTSTRAP }));
    await context.route('https://www.gstatic.com/charts/loader.js',
      route => route.fulfill({ status: 200, contentType: 'application/javascript', body: STUB_GCHARTS }));

    page = await context.newPage();
    page.on('console', msg => consoleLog.push({ type: msg.type(), text: msg.text() }));
    page.on('pageerror', err => consoleLog.push({ type: 'pageerror', text: String(err) }));
    console.log('launched. base url:', BASE_URL);
  },

  // path is relative to BASE_URL, e.g. "login.php" or "index.php".
  async nav(p) {
    if (!need()) return;
    const url = /^https?:\/\//.test(p) ? p : BASE_URL + '/' + p.replace(/^\//, '');
    // 'load' is unreliable here: index.php pulls Bootstrap/Material Icons
    // from a public CDN, and this sandbox's outbound HTTPS goes through an
    // agent proxy Chromium isn't configured to use, so those requests fail
    // slowly (ERR_TUNNEL_CONNECTION_FAILED) and can stall 'load' indefinitely.
    // domcontentloaded + an explicit wait-for the element you need is the
    // reliable path (page still renders - Bootstrap only handles chrome/JS
    // interactions, not the raw HTML).
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    console.log('nav ->', page.url());
  },

  // SPA hash routing: showPage() runs off `hashchange`, so this is how every
  // in-app link actually navigates (public/assets/js/app.js: parseHash/goToPage).
  // Usage: goto employees   |   goto payroll?OfficeCode=CMO
  async goto(hashRoute) {
    if (!need()) return;
    await page.evaluate(h => { location.hash = '#' + h; }, hashRoute);
    await page.waitForTimeout(150); // hashchange -> showPage() is synchronous JS, just a paint tick
    console.log('goto ->', hashRoute);
  },

  // Fills and submits public/login.php. Defaults to the seeded admin account
  // (README: admin@digos.gov.ph / ChangeMe!123). Waits for the redirect to
  // index.php that a successful POST produces.
  async login(rest) {
    if (!need()) return;
    const [email, password] = (rest || '').split(/\s+/).filter(Boolean);
    await page.goto(BASE_URL + '/login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('#f-email', email || 'admin@digos.gov.ph');
    await page.fill('#f-password', password || 'ChangeMe!123');
    // The submit button has no type="submit" attribute (it's the implicit
    // default for a <button> in a <form>), so an attribute selector matches
    // nothing and hangs - match on text instead. The form also has a
    // type="button" password-visibility toggle earlier in the DOM.
    //
    // Deliberately NOT `Promise.all([page.waitForNavigation(), page.click()])`
    // here - it raced and timed out even though the navigation had already
    // happened (this is a plain POST -> 302 -> GET, not a fetch-based login).
    // waitForSelector alone survives the navigation fine and is what actually
    // proves login worked: it targets #user-email, populated by app.js from
    // apiGetSession() after the SPA shell boots, not just "the URL changed."
    await page.click('#login-form button:has-text("Sign in")');
    // Generous timeout: this sandbox can't reach the CDNs index.php pulls in
    // (Bootstrap, Material Icons, Google Charts - see Gotchas in SKILL.md),
    // and each failed request's retry/timeout before the browser gives up on
    // it delays first script execution well past a normal page load.
    await page.waitForSelector('#user-email:not(:empty)', { timeout: 25_000 });
    console.log('login ->', page.url());
  },

  // wait-for text=Something   |   wait-for .some-css-selector
  async 'wait-for'(sel) {
    if (!need()) return;
    try {
      if (sel.startsWith('text=')) {
        await page.getByText(sel.slice(5), { exact: false }).first().waitFor({ timeout: 10_000 });
      } else {
        await page.waitForSelector(sel, { timeout: 10_000 });
      }
      console.log('found:', sel);
    } catch { console.log('TIMEOUT:', sel); }
  },

  async click(sel) {
    if (!need()) return;
    try { await page.click(sel, { timeout: 5000 }); console.log('click', sel, '-> OK'); }
    catch (e) { console.log('click', sel, '-> ERROR:', e.message.split('\n')[0]); }
  },

  async 'click-text'(text) {
    if (!need()) return;
    try { await page.getByText(text, { exact: false }).first().click({ timeout: 5000 }); console.log('click-text', JSON.stringify(text), '-> OK'); }
    catch (e) { console.log('click-text', JSON.stringify(text), '-> ERROR:', e.message.split('\n')[0]); }
  },

  // fill <selector> <text...>
  async fill(rest) {
    if (!need()) return;
    const sp = rest.indexOf(' ');
    const sel = sp === -1 ? rest : rest.slice(0, sp);
    const text = sp === -1 ? '' : rest.slice(sp + 1);
    try { await page.fill(sel, text); console.log('fill', sel, '-> OK'); }
    catch (e) { console.log('fill', sel, '-> ERROR:', e.message.split('\n')[0]); }
  },

  async press(key) { if (need()) { await page.keyboard.press(key); console.log('press', key); } },

  async screenshot(name) {
    if (!need()) return;
    const f = path.join(SHOT_DIR, (name || `ss-${Date.now()}`) + '.png');
    await page.screenshot({ path: f, fullPage: true });
    console.log('screenshot:', f);
  },
  async ss(name) { await COMMANDS.screenshot(name); },

  // console [--errors]
  async console(flag) {
    const rows = flag === '--errors'
      ? consoleLog.filter(m => m.type === 'error' || m.type === 'pageerror')
      : consoleLog;
    if (!rows.length) return console.log('(none)');
    for (const m of rows) console.log(`[${m.type}]`, m.text);
  },

  async eval(expr) {
    if (!need()) return;
    try { console.log(JSON.stringify(await page.evaluate(expr))); }
    catch (e) { console.log('ERROR:', e.message.split('\n')[0]); }
  },

  async text(sel) {
    if (!need()) return;
    console.log(await page.evaluate(s => (s ? document.querySelector(s) : document.body)?.innerText ?? '(null)', sel || null));
  },

  async quit() { if (browser) await browser.close().catch(() => {}); browser = null; page = null; },
  help() { console.log('commands:', Object.keys(COMMANDS).join(', ')); },
};

const rl = readline.createInterface({ input: process.stdin, output: process.stdout, prompt: 'driver> ' });

rl.on('line', async line => {
  const trimmed = line.trim();
  const sp = trimmed.indexOf(' ');
  const cmd = sp === -1 ? trimmed : trimmed.slice(0, sp);
  const rest = sp === -1 ? '' : trimmed.slice(sp + 1);
  if (!cmd) return rl.prompt();
  const fn = COMMANDS[cmd];
  if (!fn) { console.log('unknown:', cmd, '- try: help'); return rl.prompt(); }
  try { await fn(rest); } catch (e) { console.log('ERROR:', e.message); }
  if (cmd === 'quit') { rl.close(); process.exit(0); }
  rl.prompt();
});
rl.on('close', async () => { await COMMANDS.quit(); process.exit(0); });

console.log('digos-payroll driver - "help" for commands, "launch" to start');
rl.prompt();
