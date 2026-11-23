import { spawn } from 'node:child_process';
import { access, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [, , targetUrl, scenario, chromeBinary] = process.argv;

if (!targetUrl || scenario !== 'buyer' || !chromeBinary) {
  throw new Error('Usage: checkout_buyer_browser.mjs <local-url> buyer <chrome-binary>');
}

const localOrigin = new URL(targetUrl).origin;
const profile = await mkdtemp(join(tmpdir(), 'sylora-checkout-buyer-profile-'));
const artifactDirectory = await mkdtemp(join(tmpdir(), 'sylora-checkout-buyer-capture-'));
const chrome = spawn(chromeBinary, [
  '--headless=new',
  '--no-sandbox',
  '--disable-gpu',
  '--disable-background-networking',
  '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1',
  '--remote-debugging-port=0',
  `--user-data-dir=${profile}`,
  'about:blank',
], { stdio: 'ignore' });
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const routeRequests = [];
const blockedCrossOriginRequests = [];
const forbiddenRequests = [];
const observedExternalNetworkRequests = [];
const interceptionErrors = [];
const interceptionTasks = [];
let client;
let report;
let cleanup = { browserProcessExited: false, profileRemoved: false };

async function debuggingPort() {
  for (let attempt = 0; attempt < 100; attempt++) {
    try {
      const content = await readFile(join(profile, 'DevToolsActivePort'), 'utf8');
      const port = Number(content.split(/\r?\n/, 1)[0]);

      if (Number.isInteger(port) && port > 0) return port;
    } catch (error) {
      if (error?.code !== 'ENOENT') throw error;
    }

    await delay(100);
  }

  throw new Error('Chrome DevTools did not start.');
}

async function connect(port) {
  const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  const page = pages.find(item => item.type === 'page');

  if (!page) throw new Error('Chrome page target was not found.');

  const socket = new WebSocket(page.webSocketDebuggerUrl);
  const pending = new Map();
  const listeners = new Map();
  let id = 0;

  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);

    if (message.id && pending.has(message.id)) {
      pending.get(message.id)(message);
      pending.delete(message.id);
    }

    if (message.method && listeners.has(message.method)) {
      for (const listener of listeners.get(message.method)) listener(message.params || {});
    }
  });

  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  return {
    close: () => socket.close(),
    on(method, listener) {
      if (!listeners.has(method)) listeners.set(method, []);
      listeners.get(method).push(listener);
    },
    command(method, params = {}) {
      return new Promise((resolve, reject) => {
        const commandId = ++id;
        pending.set(commandId, message => message.error ? reject(new Error(message.error.message)) : resolve(message.result || {}));
        socket.send(JSON.stringify({ id: commandId, method, params }));
      });
    },
  };
}

function checkoutRoute(route, query = {}) {
  const url = new URL(targetUrl);
  const parameters = new URLSearchParams({ route });

  for (const [key, value] of Object.entries(query)) parameters.set(key, value);

  url.search = parameters.toString();
  return url.href;
}

function isForbiddenLocalRequest(url, method) {
  const route = url.searchParams.get('route') || '';

  return route === 'checkout/confirm'
    || route.startsWith('extension/payment/')
    || (method !== 'GET' && route !== 'checkout/cart/add')
    || route.endsWith('/save');
}

function installRequestInterception() {
  client.on('Network.requestWillBeSent', params => {
    const requestUrl = new URL(params.request.url);

    if (requestUrl.origin === localOrigin) {
      routeRequests.push({
        method: params.request.method,
        route: requestUrl.searchParams.get('route') || '',
      });
    }
  });

  client.on('Network.responseReceived', params => {
    const responseUrl = new URL(params.response.url);

    if ((responseUrl.protocol === 'http:' || responseUrl.protocol === 'https:') && responseUrl.origin !== localOrigin) {
      observedExternalNetworkRequests.push(params.response.url);
    }
  });

  client.on('Fetch.requestPaused', params => {
    const task = (async () => {
      const requestUrl = new URL(params.request.url);

      if (requestUrl.protocol !== 'http:' && requestUrl.protocol !== 'https:') {
        await client.command('Fetch.continueRequest', { requestId: params.requestId });
        return;
      }

      if (requestUrl.origin !== localOrigin) {
        blockedCrossOriginRequests.push({ method: params.request.method, url: params.request.url });
        await client.command('Fetch.failRequest', { requestId: params.requestId, errorReason: 'BlockedByClient' });
        return;
      }

      if (isForbiddenLocalRequest(requestUrl, params.request.method)) {
        forbiddenRequests.push({ method: params.request.method, route: requestUrl.searchParams.get('route') || '' });
        await client.command('Fetch.failRequest', { requestId: params.requestId, errorReason: 'BlockedByClient' });
        return;
      }

      await client.command('Fetch.continueRequest', { requestId: params.requestId });
    })();

    interceptionTasks.push(task.catch(error => {
      interceptionErrors.push(error);
    }));
  });
}

async function settleInterceptionTasks() {
  let settled = 0;

  while (settled < interceptionTasks.length) {
    const current = interceptionTasks.length;
    await Promise.all(interceptionTasks.slice(settled, current));
    settled = current;
  }
}

async function evaluate(expression) {
  const response = await client.command('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise: true,
  });

  if (response.exceptionDetails) {
    throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
  }

  return response.result?.value;
}

async function waitForSelector(selector) {
  const expression = `(async () => {
    for (let attempt = 0; attempt < 120; attempt++) {
      if (document.querySelector(${JSON.stringify(selector)})) return true;
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Timed out waiting for selector: ' + ${JSON.stringify(selector)});
  })()`;

  await evaluate(expression);
}

async function navigate(url, selector) {
  await client.command('Page.navigate', { url });
  await waitForSelector(selector);
  await delay(300);
}

async function acceptEssentialConsent() {
  const accepted = await evaluate(`(async () => {
    for (let attempt = 0; attempt < 50; attempt++) {
      const banner = document.getElementById('analytics-cookie-banner');
      const essentialButton = document.querySelector('[data-cookie-consent="essential"]');
      if (!banner || banner.hidden) return true;
      if (essentialButton) {
        essentialButton.click();
        for (let check = 0; check < 50; check++) {
          if (banner.hidden) return true;
          await new Promise(resolve => setTimeout(resolve, 100));
        }
        throw new Error('Essential-only consent did not close the cookie banner.');
      }
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    return false;
  })()`);

  return accepted === true;
}

async function readBuyerState() {
  return JSON.parse(await evaluate(`JSON.stringify((() => {
    const root = document.getElementById('checkout-buyer');
    const email = document.getElementById('input-email');
    const emailGroup = email?.closest('.checkout-buyer__email');
    const continueButton = document.getElementById('button-buyer-continue');
    const prompt = root?.querySelector('.checkout-buyer__prompt');
    const intro = document.querySelector('.checkout-head__intro');
    const summaryNote = document.querySelector('.checkout-summary__note');
    const buyerChevron = document.querySelector('#collapse-checkout-option')?.closest('.panel')?.querySelector('.panel-heading .atelier-icon--chevron-down');
    const accountPanel = document.querySelector('#collapse-payment-address')?.closest('.checkout-account-panel');
    const visible = element => Boolean(element && element.getBoundingClientRect().width && element.getBoundingClientRect().height);
    return {
      prompt: prompt?.innerText.replace(/\\s+/g, ' ').trim() || '',
      introText: intro?.innerText.replace(/\\s+/g, ' ').trim() || '',
      introColor: intro ? getComputedStyle(intro).color : '',
      summaryNoteText: summaryNote?.innerText.replace(/\\s+/g, ' ').trim() || '',
      summaryNoteColor: summaryNote ? getComputedStyle(summaryNote).color : '',
      buyerChevronDisplay: buyerChevron ? getComputedStyle(buyerChevron).display : '',
      buyerPanelExpanded: Boolean(document.getElementById('collapse-checkout-option')?.classList.contains('in')),
      accountPanelVisible: visible(accountPanel),
      emailVisible: visible(emailGroup),
      continueVisible: visible(continueButton),
      optionsHidden: document.getElementById('checkout-buyer-options')?.hidden ?? true,
      emailInputId: email?.id || '',
      emailWidth: emailGroup?.getBoundingClientRect().width || 0,
      continueWidth: continueButton?.getBoundingClientRect().width || 0,
      layoutWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      buyerWidth: root?.getBoundingClientRect().width || 0,
    };
  })())`));
}

async function setViewport(width) {
  await client.command('Emulation.setDeviceMetricsOverride', {
    width,
    height: width === 1440 ? 1254 : 1140,
    deviceScaleFactor: 1,
    mobile: width < 768,
  });
}

async function captureViewport(width) {
  await setViewport(width);
  await navigate(checkoutRoute('checkout/checkout'), '#button-buyer-continue');
  await waitForSelector('#checkout-buyer-options[hidden]');
  await waitForSelector('#collapse-checkout-option.in');
  await delay(400);

  const state = await readBuyerState();
  const captureOptions = {
    format: 'png',
    fromSurface: true,
    captureBeyondViewport: true,
  };

  if (width === 1440) {
    const metrics = await client.command('Page.getLayoutMetrics');
    captureOptions.clip = {
      x: 0,
      y: 0,
      width: 1440,
      height: metrics.cssContentSize?.height || metrics.contentSize?.height || 1254,
      scale: 1,
    };
  }

  const screenshot = await client.command('Page.captureScreenshot', captureOptions);
  const image = Buffer.from(screenshot.data, 'base64');
  const path = join(artifactDirectory, `checkout-buyer-${width}.png`);
  await writeFile(path, image);

  return {
    ...state,
    viewportWidth: width,
    imageWidth: image.readUInt32BE(16),
    imageHeight: image.readUInt32BE(20),
    path,
    byteLength: image.length,
  };
}

async function addSyntheticProductToTemporaryCart() {
  await navigate(checkoutRoute('product/product', { product_id: '1' }), '#product-product[data-product-id="1"]');
  const consentAccepted = await acceptEssentialConsent();

  await evaluate(`(() => {
    const button = document.querySelector('#button-cart');
    if (!button) throw new Error('Synthetic product add-to-cart button was not found.');
    button.click();
    return true;
  })()`);

  const added = await evaluate(`(async () => {
    for (let attempt = 0; attempt < 100; attempt++) {
      const notice = document.querySelector('#content .cart-notice[role="status"]');
      if (notice) return notice.textContent.replace(/\\s+/g, ' ').trim();
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Synthetic product was not added to the isolated cart.');
  })()`);

  return { consentAccepted, addToCartNotice: added };
}

async function setBuyerEmail(email) {
  await evaluate(`(() => {
    const input = document.getElementById('input-email');
    if (!input) throw new Error('Buyer email input was not found.');
    input.value = ${JSON.stringify(email)};
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return input.value;
  })()`);
}

async function selectAccountAndLoadForm(value) {
  await evaluate(`(() => {
    const radio = document.querySelector('input[name="account"][value="${value}"]');
    const button = document.getElementById('button-account');
    if (!radio || !button) throw new Error('The existing ${value} checkout option is unavailable.');
    radio.click();
    button.click();
    return true;
  })()`);

  await waitForSelector('#collapse-payment-address .panel-body #input-payment-email');
  await delay(150);

  return JSON.parse(await evaluate(`JSON.stringify((() => {
    const email = document.querySelector('#collapse-payment-address .panel-body #input-payment-email');
    const body = document.querySelector('#collapse-payment-address .panel-body');
    return {
      email: email?.value || '',
      selectedAccount: document.querySelector('input[name="account"]:checked')?.value || '',
      registrationFieldsVisible: Boolean(body?.querySelector('#input-payment-password, #input-payment-confirm')),
      guestAddressFieldsVisible: Boolean(body?.querySelector('#input-payment-firstname, #input-payment-address-1')),
      submitButtonPresent: Boolean(body?.querySelector('#button-register, #button-guest')),
      accountPanelVisible: Boolean(body?.closest('.checkout-account-panel')?.getBoundingClientRect().width),
    };
  })())`));
}

async function checkBuyerInteractions() {
  await setViewport(390);
  await navigate(checkoutRoute('checkout/checkout'), '#button-buyer-continue');
  await setBuyerEmail('buyer-flow@example.invalid');
  await evaluate('document.getElementById("button-buyer-continue").click()');
  await waitForSelector('#checkout-buyer-options:not([hidden])');

  const afterContinue = JSON.parse(await evaluate(`JSON.stringify((() => {
    const options = document.getElementById('checkout-buyer-options');
    const password = document.getElementById('input-password');
    return {
      registerAvailable: Boolean(options?.querySelector('input[name="account"][value="register"]')),
      guestAvailable: Boolean(options?.querySelector('input[name="account"][value="guest"]')),
      loginButtonAvailable: Boolean(options?.querySelector('#button-login')),
      passwordVisible: Boolean(password?.getBoundingClientRect().width && password?.getBoundingClientRect().height),
    };
  })())`));

  const registerFlow = await selectAccountAndLoadForm('register');
  const guestFlow = await selectAccountAndLoadForm('guest');

  await navigate(checkoutRoute('checkout/checkout'), '#button-buyer-login');
  await evaluate('document.getElementById("button-buyer-login").click()');
  await waitForSelector('#checkout-buyer-options:not([hidden])');
  const loginLink = JSON.parse(await evaluate(`JSON.stringify((() => {
    const password = document.getElementById('input-password');
    return {
      passwordVisible: Boolean(password?.getBoundingClientRect().width && password?.getBoundingClientRect().height),
      focusedPassword: document.activeElement?.id === 'input-password',
      loginButtonAvailable: Boolean(document.getElementById('button-login')),
    };
  })())`));

  return { afterContinue, registerFlow, guestFlow, loginLink };
}

async function stopChrome() {
  if (client) {
    try {
      await client.command('Fetch.disable');
    } catch {
      // Chrome may exit before interception can be disabled.
    }
    client.close();
  }

  if (chrome.exitCode === null) {
    await new Promise(resolve => {
      const timeout = setTimeout(() => {
        chrome.kill('SIGKILL');
        resolve();
      }, 2500);
      chrome.once('exit', () => {
        clearTimeout(timeout);
        resolve();
      });
      chrome.kill('SIGTERM');
    });
  }

  cleanup.browserProcessExited = chrome.exitCode !== null;
  await rm(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });

  try {
    await access(profile);
  } catch {
    cleanup.profileRemoved = true;
  }
}

try {
  client = await connect(await debuggingPort());
  installRequestInterception();
  await client.command('Network.enable');
  await client.command('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
  await addSyntheticProductToTemporaryCart();

  const screenshots = [];
  const initialStates = {};

  for (const width of [320, 390, 1440]) {
    const captured = await captureViewport(width);
    screenshots.push({
      viewportWidth: captured.viewportWidth,
      imageWidth: captured.imageWidth,
      imageHeight: captured.imageHeight,
      byteLength: captured.byteLength,
      path: captured.path,
    });
    initialStates[String(width)] = {
      prompt: captured.prompt,
      introText: captured.introText,
      introColor: captured.introColor,
      summaryNoteText: captured.summaryNoteText,
      summaryNoteColor: captured.summaryNoteColor,
      buyerChevronDisplay: captured.buyerChevronDisplay,
      buyerPanelExpanded: captured.buyerPanelExpanded,
      accountPanelVisible: captured.accountPanelVisible,
      emailVisible: captured.emailVisible,
      continueVisible: captured.continueVisible,
      optionsHidden: captured.optionsHidden,
      horizontalOverflow: captured.horizontalOverflow,
      emailWidth: captured.emailWidth,
      continueWidth: captured.continueWidth,
      buyerWidth: captured.buyerWidth,
    };
  }

  const interactions = await checkBuyerInteractions();
  await settleInterceptionTasks();

  if (interceptionErrors.length > 0) {
    throw new Error(`CDP request interception failed: ${interceptionErrors[0].message}`);
  }

  const routeCounts = {};
  for (const request of routeRequests) {
    if (!request.route) continue;
    const key = `${request.method} ${request.route}`;
    routeCounts[key] = (routeCounts[key] || 0) + 1;
  }

  report = {
    artifactDirectory,
    screenshots,
    initialStates,
    ...interactions,
    forbiddenRequests,
    blockedCrossOriginRequests,
    observedExternalNetworkRequests,
    routeCounts,
    requestCounts: {
      capturedScreenshots: screenshots.length,
      sameOriginRequests: routeRequests.length,
      blockedCrossOriginRequests: blockedCrossOriginRequests.length,
      forbiddenRequests: forbiddenRequests.length,
      observedExternalNetworkRequests: observedExternalNetworkRequests.length,
    },
  };
} finally {
  await stopChrome();
}

report.cleanup = cleanup;
report.reportPath = join(artifactDirectory, 'checkout-buyer-report.json');
await writeFile(report.reportPath, `${JSON.stringify(report, null, 2)}\n`);
process.stdout.write(JSON.stringify(report));
