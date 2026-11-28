import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , url, , chromeBinary] = process.argv;
if (!url || !chromeBinary) throw new Error('Missing local URL or Chrome binary');
const profile = await mkdtemp(join(tmpdir(), 'sylora-cdek-'));
const chrome = spawn(chromeBinary, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], {stdio: 'ignore'});
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket;
try {
 let port;
 for (let i = 0; i < 100; i++) {
  try { port = Number((await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split(/\r?\n/)[0]); break; } catch { await delay(100); }
 }
 if (!port) throw new Error('Chrome DevTools did not start');
 const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
 socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
 await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, {once: true}); socket.addEventListener('error', reject, {once: true}); });
 const pending = new Map(); let id = 0;
 socket.addEventListener('message', event => {const m = JSON.parse(event.data); if (m.id && pending.has(m.id)) {pending.get(m.id)(m); pending.delete(m.id);}});
 const command = (method, params = {}) => new Promise((resolve, reject) => { const key = ++id; pending.set(key, result => result.error ? reject(new Error(result.error.message)) : resolve(result.result || {})); socket.send(JSON.stringify({id: key, method, params})); });
 await command('Page.navigate', {url}); await delay(1600);
 const response = await command('Runtime.evaluate', {awaitPromise: true, returnByValue: true, expression: `(async () => {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const fixture = document.createElement('div');
  fixture.innerHTML = '<div id="checkout-summary-data"></div><button id="checkout-summary-retry" hidden>Повторить</button><div id="collapse-shipping-method"><input type="radio" name="shipping_method"></div><script id="checkout-config" type="application/json">{"logged":true,"summaryLoading":"Загрузка","summaryPending":"Подтвердите доставку","summaryError":"Ошибка загрузки"}<\/script>';
  document.body.appendChild(fixture);
  let calls = 0, finishDelayed;
  $.ajaxTransport('+*', function(options) {
   if (!options.url.includes('route=common/cart/info') && !options.url.includes('route=checkout/shipping_method/save') && !options.url.includes('route=checkout/payment_address')) return;
   let cancelled = false;
   return {send: function(headers, complete) {
    if (options.url.includes('route=checkout/payment_address')) {complete(200, 'OK', {text: ''}, 'Content-Type: text/html'); return;}
    if (options.url.includes('route=checkout/shipping_method/save')) {complete(200, 'OK', {text: '{}'}, 'Content-Type: application/json'); return;}
    calls++;
    if (calls === 1) {complete(503, 'Unavailable', {text: ''}, 'Content-Type: text/html'); return;}
    if (calls === 3) {finishDelayed = () => {if (!cancelled) complete(200, 'OK', {text: '<div id="cart"><div class="mini-cart__items">Устарело</div></div>'}, 'Content-Type: text/html');}; return;}
    complete(200, 'OK', {text: '<div id="cart"><div class="mini-cart__items"><span>Товар</span><button>Удалить</button></div><div class="mini-cart__summary"><table><tr><td>Всего</td><td>105 ₽</td></tr></table></div></div>'}, 'Content-Type: text/html');
   }, abort: function() {cancelled = true;}};
  });
  await new Promise((resolve, reject) => {const script = document.createElement('script'); script.src = 'catalog/view/javascript/checkout.js'; script.onload = resolve; script.onerror = reject; document.head.appendChild(script);});
  await wait(50);
  const data = document.querySelector('#checkout-summary-data');
  const failed = data.textContent.includes('Ошибка загрузки') && !document.querySelector('#checkout-summary-retry').hidden;
  document.querySelector('#checkout-summary-retry').click(); await wait(50);
  const retried = data.textContent.includes('105 ₽') && data.textContent.includes('Товар') && !data.querySelector('button');
  // Trigger another refresh via a completed change; the third cart request remains pending.
  $(document).trigger('ajaxSend', [null, {url:'index.php?route=checkout/cart/edit'}]);
  $(document).trigger('ajaxComplete', [{status:200}, {url:'index.php?route=checkout/cart/edit'}]); await wait(50);
  document.querySelector('#collapse-shipping-method input').dispatchEvent(new Event('change', {bubbles:true}));
  if (finishDelayed) finishDelayed(); await wait(50);
  const pending = data.textContent.includes('Подтвердите доставку') && !data.querySelector('table');
  await $.ajax({url:'index.php?route=checkout/shipping_method/save', method:'POST', dataType:'json'}); await wait(50);
  const refreshed = data.textContent.includes('105 ₽') && data.getAttribute('aria-busy') === 'false';
  return JSON.stringify({failed, retried, pending, refreshed, delayed: !!finishDelayed});
 })()`});
 if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
 process.stdout.write(response.result.value);
} finally { if (socket) socket.close(); chrome.kill('SIGTERM'); await rm(profile, {recursive: true, force: true, maxRetries: 10, retryDelay: 100}); }
