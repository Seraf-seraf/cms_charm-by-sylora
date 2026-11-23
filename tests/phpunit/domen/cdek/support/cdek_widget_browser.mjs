import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , url, , chromeBinary] = process.argv;
if (!url || !chromeBinary) throw new Error('Missing local URL or Chrome binary');
const applicationOrigin = new URL(url).origin;
const profile = await mkdtemp(join(tmpdir(), 'sylora-cdek-'));
const chrome = spawn(chromeBinary, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], {stdio: 'ignore'});
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const forbiddenRequests = [];
const externalHttpRequests = [];
const interceptionErrors = [];
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
 const pending = new Map();
 const eventHandlers = new Map();
 let id = 0;
 socket.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (message.id && pending.has(message.id)) {
   pending.get(message.id)(message);
   pending.delete(message.id);
   return;
  }
  if (message.method && eventHandlers.has(message.method)) {
   for (const handler of eventHandlers.get(message.method)) {
    Promise.resolve(handler(message.params || {})).catch(error => {
     interceptionErrors.push({method: message.method, error: error.message || String(error)});
    });
   }
  }
 });
 const on = (method, handler) => {
  if (!eventHandlers.has(method)) eventHandlers.set(method, []);
  eventHandlers.get(method).push(handler);
 };
 const command = (method, params = {}) => new Promise((resolve, reject) => {
  const key = ++id;
  pending.set(key, result => result.error ? reject(new Error(result.error.message)) : resolve(result.result || {}));
  socket.send(JSON.stringify({id: key, method, params}));
 });
 const isAllowedRequest = requestUrl => {
  if (requestUrl.protocol === 'data:' || requestUrl.protocol === 'about:') return true;
  if (requestUrl.protocol === 'blob:') return requestUrl.origin === applicationOrigin;
  return (requestUrl.protocol === 'http:' || requestUrl.protocol === 'https:') && requestUrl.origin === applicationOrigin;
 };
 on('Fetch.requestPaused', async event => {
  const request = event.request || {};
  let requestUrl;
  try {
   requestUrl = new URL(request.url);
  } catch {
   const forbidden = {origin: null, protocol: null, method: request.method || null, pathname: null};
   forbiddenRequests.push(forbidden);
   await command('Fetch.failRequest', {requestId: event.requestId, errorReason: 'BlockedByClient'});
   return;
  }
  if (isAllowedRequest(requestUrl)) {
   await command('Fetch.continueRequest', {requestId: event.requestId});
   return;
  }
  const forbidden = {
   origin: requestUrl.origin,
   protocol: requestUrl.protocol,
   method: request.method || null,
   pathname: requestUrl.pathname,
  };
  forbiddenRequests.push(forbidden);
  if ((requestUrl.protocol === 'http:' || requestUrl.protocol === 'https:') && requestUrl.origin !== applicationOrigin) {
   externalHttpRequests.push(forbidden);
  }
  await command('Fetch.failRequest', {requestId: event.requestId, errorReason: 'BlockedByClient'});
 });
 await command('Fetch.enable', {patterns: [{urlPattern: '*', requestStage: 'Request'}]});
 await command('Page.navigate', {url}); await delay(1600);
 const response = await command('Runtime.evaluate', {awaitPromise: true, returnByValue: true, expression: `(async () => {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  let attempts = 0, chosen = false;
  const params = () => ({apikey: 'test', city: 'Тестоград', office_code: chosen ? 'TEST-1' : null, office_address: chosen ? 'Тестовая улица 1' : null});
  $.getJSON = () => { const d = $.Deferred(); setTimeout(() => {d.resolve(params()); $(document).trigger('ajaxComplete');}, 0); return d.promise(); };
  $.post = () => { const d = $.Deferred(); setTimeout(() => {chosen = true; d.resolve({}); $(document).trigger('ajaxComplete');}, 0); return d.promise(); };
  const append = document.head.appendChild.bind(document.head);
  document.head.appendChild = node => {
   if (!node.src || !node.src.includes('cdek-widget.umd.js')) return append(node);
   attempts++;
   setTimeout(() => {
    if (attempts === 1) node.onerror();
    else { window.CDEKWidget = class { constructor(options) {window.testCdekOptions = options;} open() {window.testCdekOpened = true;} }; node.onload(); }
   }, 0);
   return node;
  };
  const fixture = document.createElement('div');
  fixture.innerHTML = '<div class="shipping-methods"><div class="shipping-methods__list"><label class="shipping-method-card"><input type="radio" name="shipping_method" value="cdek_official.office_136" checked>ПВЗ</label><label class="shipping-method-card"><input type="radio" name="shipping_method" value="cdek_official.door_137">Курьер</label></div></div>';
  document.body.appendChild(fixture);
  await new Promise((resolve, reject) => {const script = document.createElement('script'); script.src = 'catalog/view/javascript/shipping/cdek_official.js'; script.onload = resolve; script.onerror = reject; document.head.appendChild(script);});
  await wait(600); document.querySelector('.cdek_btn').click(); await wait(600);
  const error = document.querySelector('#cdek_official_office_error');
  const failed = {attempts, visible: !!error && getComputedStyle(error).display !== 'none', retry: document.querySelector('.cdek_btn').textContent, nested: !!document.querySelector('label .cdek_btn')};
  document.querySelector('.cdek_btn').click(); await wait(500);
  const opened = window.testCdekOpened === true;
  if (!window.testCdekOptions) return JSON.stringify({failed, attempts, opened});
  window.testCdekOptions.onChoose('office', {}, {code: 'TEST-1', address: 'Тестовая улица 1'}); await wait(600);
  const choice = document.querySelector('.cdek_office_info')?.textContent;
  const officeInCard = !!document.querySelector('.shipping-method-card .cdek_office_info');
  const change = document.querySelector('.cdek_btn')?.textContent;
  const door = document.querySelector('[value="cdek_official.door_137"]'); door.checked = true; door.dispatchEvent(new Event('change', {bubbles: true})); await wait(500);
  return JSON.stringify({failed, attempts, opened, choice, change, officeInCard, courierHasButton: !!document.querySelector('.cdek_btn')});
 })()`});
 if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
 const scenarioResult = JSON.parse(response.result.value);
 process.stdout.write(JSON.stringify({
  ...scenarioResult,
  networkPolicy: {
   enforced: true,
   applicationOrigin,
   forbiddenRequestCount: forbiddenRequests.length,
   forbiddenRequests,
   externalHttpRequestCount: externalHttpRequests.length,
   externalHttpRequests,
   interceptionErrors,
  },
 }));
} finally { if (socket) socket.close(); chrome.kill('SIGTERM'); await rm(profile, {recursive: true, force: true, maxRetries: 10, retryDelay: 100}); }
