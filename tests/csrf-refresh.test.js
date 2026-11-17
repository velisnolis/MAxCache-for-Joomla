// Tests pkg_maxcache/packages/plg_system_maxcache/media/js/csrf-refresh.js in an
// isolated context with doubles for Joomla core.js, fetch and the document.
// Run: node tests/csrf-refresh.test.js
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
  path.join(__dirname, '../pkg_maxcache/packages/plg_system_maxcache/media/js/csrf-refresh.js'),
  'utf8'
);
const endpoint = '/index.php?option=com_ajax&plugin=maxcachefragment&format=raw&maxcache_task=token';

// A form double: one token placeholder unless `placeholders` says otherwise.
function fakeForm(placeholders = 1) {
  const inputs = Array.from({length: placeholders}, () => ({
    name: '',
    value: '',
    pending: true,
    removeAttribute(attribute) {
      if (attribute === 'data-maxcache-form-token') {
        this.pending = false;
      }
    }
  }));
  const form = {
    inputs,
    nativeSubmits: 0,
    requestSubmits: [],
    querySelector: () => inputs.find((input) => input.pending) || null,
    querySelectorAll: () => inputs.filter((input) => input.pending),
    requestSubmit(submitter) {
      this.requestSubmits.push(submitter);
    }
  };

  return form;
}

function setup({token = '', responses = [], forms = []} = {}) {
  const options = {'csrf.token': token};
  const sent = [];
  const fetches = [];
  const queue = [...responses];
  const listeners = {};
  class HTMLFormElement {
    submit() {
      this.nativeSubmits += 1;
    }
  }

  forms.forEach((form) => Object.setPrototypeOf(form, HTMLFormElement.prototype));

  const context = {
    Promise,
    Error,
    String,
    Array,
    HTMLFormElement,
    document: {
      currentScript: {dataset: {maxcacheTokenEndpoint: endpoint}},
      addEventListener(type, handler) {
        (listeners[type] = listeners[type] || []).push(handler);
      },
      querySelectorAll: () => forms.flatMap((form) => form.querySelectorAll())
    },
    fetch(url, init) {
      fetches.push({url, init});
      const next = queue.shift() || {status: 500};

      return Promise.resolve({
        ok: next.status === 200,
        status: next.status,
        json: () => Promise.resolve(next.body)
      });
    }
  };

  context.window = context;
  context.Joomla = {
    getOptions: (key, fallback) => (key in options ? options[key] : fallback),
    loadOptions: (values) => Object.assign(options, values),
    request(requestOptions) {
      // Records the token core.js would send in X-CSRF-Token.
      sent.push({method: requestOptions.method || 'GET', token: options['csrf.token']});

      return requestOptions.promise ? Promise.resolve('response') : {xhr: true};
    }
  };

  vm.runInNewContext(source, context);

  const dispatch = (type, event) => (listeners[type] || []).forEach((handler) => handler(event));

  return {context, sent, fetches, options, dispatch, listeners};
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

function submitEvent(form, submitter = null) {
  return {
    target: form,
    submitter,
    prevented: false,
    stopped: false,
    preventDefault() {
      this.prevented = true;
    },
    stopImmediatePropagation() {
      this.stopped = true;
    }
  };
}

const fresh = 'a'.repeat(32);
const tests = [];
const test = (name, fn) => tests.push({name, fn});

test('GET requests pass through without fetching a token', async () => {
  const {context, sent, fetches} = setup();
  assert.deepEqual(context.Joomla.request({url: '/x', method: 'GET'}), {xhr: true});
  assert.equal(fetches.length, 0);
  assert.equal(sent.length, 1);
});

test('a page that still has its token sends it unchanged', async () => {
  const {context, sent, fetches} = setup({token: 'b'.repeat(32)});
  assert.deepEqual(context.Joomla.request({url: '/x', method: 'POST'}), {xhr: true});
  assert.equal(fetches.length, 0);
  assert.equal(sent[0].token, 'b'.repeat(32));
});

test('a cached page fetches the visitor token before a POST', async () => {
  const {context, sent, fetches} = setup({responses: [{status: 200, body: {token: fresh}}]});
  const result = await context.Joomla.request({url: '/x', method: 'post', promise: true});
  assert.equal(result, 'response');
  assert.equal(fetches.length, 1);
  assert.equal(fetches[0].url, endpoint);
  assert.equal(fetches[0].init.method, 'GET');
  assert.equal(fetches[0].init.cache, 'no-store');
  assert.equal(fetches[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.deepEqual(sent, [{method: 'post', token: fresh}]);
});

test('callback-style requests are sent after the token arrives', async () => {
  const {context, sent} = setup({responses: [{status: 200, body: {token: fresh}}]});
  assert.equal(context.Joomla.request({url: '/x', method: 'POST'}), undefined);
  assert.equal(sent.length, 0);
  await context.MAxCache.csrfToken();
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(sent, [{method: 'POST', token: fresh}]);
});

test('concurrent requests share one token fetch', async () => {
  const {context, fetches, sent} = setup({responses: [{status: 200, body: {token: fresh}}]});
  await Promise.all([
    context.Joomla.request({url: '/a', method: 'POST', promise: true}),
    context.Joomla.request({url: '/b', method: 'DELETE', promise: true})
  ]);
  assert.equal(fetches.length, 1);
  assert.deepEqual(sent.map((item) => item.token), [fresh, fresh]);
});

test('a failed token fetch still sends the request and retries next time', async () => {
  const {context, fetches, sent} = setup({responses: [{status: 503}, {status: 200, body: {token: fresh}}]});
  await context.Joomla.request({url: '/a', method: 'POST', promise: true});
  assert.equal(sent[0].token, '');
  await context.Joomla.request({url: '/b', method: 'POST', promise: true});
  assert.equal(fetches.length, 2);
  assert.equal(sent[1].token, fresh);
});

test('an invalid token response is not loaded', async () => {
  const {context, options} = setup({responses: [{status: 200, body: {token: '<script>'}}]});
  await context.Joomla.request({url: '/a', method: 'POST', promise: true});
  assert.equal(options['csrf.token'], '');
});

test('custom scripts can ask for the token', async () => {
  const {context} = setup({responses: [{status: 200, body: {token: fresh}}]});
  assert.equal(await context.MAxCache.csrfToken(), fresh);
  assert.equal(context.Joomla.getOptions('csrf.token', ''), fresh);
});

test('the wrapper is applied once', async () => {
  const {context, listeners} = setup();
  const patched = context.Joomla.request;
  const submitListeners = listeners.submit.length;
  vm.runInNewContext(source, context);
  assert.equal(context.Joomla.request, patched);
  assert.equal(listeners.submit.length, submitListeners);
});

test('touching a cached form fetches the token and fills the placeholder', async () => {
  const form = fakeForm(2);
  const {dispatch, fetches, options} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  dispatch('focusin', {target: {form}});
  dispatch('keydown', {target: {form}});
  await settle();
  assert.equal(fetches.length, 1);
  assert.deepEqual(form.inputs.map((input) => [input.name, input.value, input.pending]), [[fresh, '1', false], [fresh, '1', false]]);
  assert.equal(options['csrf.token'], fresh);
});

test('a form without a placeholder never fetches a token', async () => {
  const form = fakeForm(0);
  const {dispatch, fetches} = setup({forms: [form]});
  const event = submitEvent(form);
  dispatch('focusin', {target: {form}});
  dispatch('submit', event);
  form.submit();
  await settle();
  assert.equal(fetches.length, 0);
  assert.equal(event.prevented, false);
  assert.equal(form.nativeSubmits, 1);
});

test('a submit before the token is held and sent again with the token', async () => {
  const form = fakeForm();
  const button = {name: 'send'};
  const {dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  const first = submitEvent(form, button);
  dispatch('submit', first);
  assert.equal(first.prevented && first.stopped, true);
  assert.equal(form.requestSubmits.length, 0);
  await settle();
  assert.deepEqual(form.requestSubmits, [button]);
  assert.equal(form.inputs[0].name, fresh);
  const second = submitEvent(form, button);
  dispatch('submit', second);
  assert.equal(second.prevented, false);
});

test('form.submit() waits for the token', async () => {
  const form = fakeForm();
  const {fetches} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  form.submit();
  assert.equal(form.nativeSubmits, 0);
  await settle();
  assert.equal(fetches.length, 1);
  assert.equal(form.nativeSubmits, 1);
  assert.equal(form.inputs[0].name, fresh);
});

test('a form is still sent when the token cannot be fetched', async () => {
  const form = fakeForm();
  const {dispatch} = setup({forms: [form], responses: [{status: 503}]});
  const first = submitEvent(form);
  dispatch('submit', first);
  await settle();
  assert.equal(form.requestSubmits.length, 1);
  assert.equal(form.inputs[0].name, '');
  const second = submitEvent(form);
  dispatch('submit', second);
  assert.equal(second.prevented, false);
});

test('pages without core.js still get form tokens', async () => {
  const form = fakeForm();
  const {context, dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  delete context.Joomla;
  dispatch('pointerdown', {target: {closest: () => form}});
  await settle();
  assert.equal(form.inputs[0].name, fresh);
});

(async () => {
  let failures = 0;

  for (const {name, fn} of tests) {
    try {
      await fn();
    } catch (error) {
      failures += 1;
      console.error(`FAIL ${name}\n${error.stack}`);
    }
  }

  console.log(`csrf-refresh tests: ${tests.length - failures}/${tests.length} passed`);
  process.exitCode = failures === 0 ? 0 : 1;
})();
