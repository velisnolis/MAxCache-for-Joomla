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

// A form double with `placeholders` stored token inputs.
function fakeForm(placeholders = 1, action = '/contact', method = 'POST') {
  const inputs = Array.from({length: placeholders}, () => ({name: '', value: ''}));
  const attributes = {action, method};
  const form = {
    inputs,
    attributes,
    getAttribute: (name) => attributes[name] ?? null,
    nativeSubmits: 0,
    requestSubmits: [],
    requestSubmit(...parameters) {
      this.requestSubmits.push(parameters);

      // A browser dispatches the submit event inside requestSubmit().
      if (this.onRequestSubmit) {
        this.onRequestSubmit(...parameters);
      }
    }
  };

  inputs.forEach((input) => {
    input.form = form;
  });

  return form;
}

function setup({token = '', responses = [], forms = [], controls = [], baseURI = 'https://www.example.org/contact'} = {}) {
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
    URL,
    WeakSet,

    location: {href: 'https://www.example.org/contact', origin: 'https://www.example.org'},
    HTMLFormElement,
    document: {
      currentScript: {dataset: {maxcacheTokenEndpoint: endpoint}},
      addEventListener(type, handler) {
        (listeners[type] = listeners[type] || []).push(handler);
      },
      baseURI,
      querySelectorAll: (selector) => (selector.includes('formaction')
        ? controls
        : forms.flatMap((form) => form.inputs))
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

const control = (form, attributes) => ({
  form,
  getAttribute: (name) => attributes[name] ?? null
});

// What a browser does inside requestSubmit(): dispatch submit, then build the data.
const browserResubmit = (form, dispatch, log) => {
  form.onRequestSubmit = (submitter) => {
    const event = submitEvent(form, submitter);
    dispatch('submit', event);
    log.push({prevented: event.prevented, token: form.inputs.map((input) => input.name).join(',')});
  };
};

test('nothing is fetched until a form is sent', async () => {
  const form = fakeForm();
  const {listeners, fetches} = setup({forms: [form]});
  assert.deepEqual(Object.keys(listeners).sort(), ['DOMContentLoaded', 'formdata', 'submit']);
  await settle();
  assert.equal(fetches.length, 0);
  assert.equal(form.inputs[0].name, '');
});

test('a form without a stored token input is left alone', async () => {
  const form = fakeForm(0);
  const {dispatch, fetches} = setup({forms: [form]});
  const event = submitEvent(form);
  dispatch('submit', event);
  form.submit();
  await settle();
  assert.equal(fetches.length, 0);
  assert.equal(event.prevented, false);
  assert.equal(form.nativeSubmits, 1);
});

test('a submit is held and sent again with the token and its submitter', async () => {
  const form = fakeForm(2);
  const button = {name: 'send', form};
  const {dispatch, fetches} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  const resent = [];
  browserResubmit(form, dispatch, resent);
  const first = submitEvent(form, button);
  dispatch('submit', first);
  assert.equal(first.prevented && first.stopped, true);
  assert.equal(form.requestSubmits.length, 0);
  await settle();
  assert.equal(fetches.length, 1);
  assert.deepEqual(form.requestSubmits, [[button]]);
  assert.deepEqual(resent, [{prevented: false, token: `${fresh},${fresh}`}]);
  assert.deepEqual(form.inputs.map((input) => input.value), ['1', '1']);
});

test('each submission fetches the token of the current session', async () => {
  const form = fakeForm();
  const current = 'c'.repeat(32);
  const {dispatch, fetches} = setup({
    forms: [form],
    responses: [{status: 200, body: {token: fresh}}, {status: 200, body: {token: current}}]
  });
  const resent = [];
  browserResubmit(form, dispatch, resent);
  dispatch('submit', submitEvent(form));
  await settle();
  const again = submitEvent(form);
  dispatch('submit', again);
  assert.equal(again.prevented, true);
  await settle();
  assert.equal(fetches.length, 2);
  assert.deepEqual(resent.map((entry) => entry.token), [fresh, current]);
});

test('a second attempt while the token is on its way is not sent twice', async () => {
  const form = fakeForm();
  const {dispatch, fetches} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  const first = submitEvent(form);
  const second = submitEvent(form);
  dispatch('submit', first);
  dispatch('submit', second);
  form.submit();
  assert.equal(second.prevented, true);
  await settle();
  assert.equal(fetches.length, 1);
  assert.equal(form.requestSubmits.length, 1);
  assert.equal(form.nativeSubmits, 0);
});

test('a submitter removed from the form is replaced by a copy', async () => {
  // Joomla.submitform() clicks a temporary button and removes it immediately.
  const form = fakeForm();
  const copies = [];
  form.appendChild = (node) => {
    node.form = form;
    node.parentNode = {removeChild: (child) => { child.form = null; child.parentNode = null; }};
  };
  const gone = {
    form: null,
    name: 'task',
    value: 'save',
    cloneNode() {
      const copy = {name: this.name, value: this.value, form: null, parentNode: null};
      copies.push(copy);

      return copy;
    }
  };
  const {dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  dispatch('submit', submitEvent(form, gone));
  await settle();
  assert.equal(form.requestSubmits.length, 1);
  assert.equal(form.requestSubmits[0][0], copies[0]);
  assert.deepEqual([copies[0].name, copies[0].value, copies[0].hidden], ['task', 'save', true]);
  assert.equal(copies[0].parentNode, null);
  assert.equal(form.inputs[0].name, fresh);
});

test('a removed submitter that cannot be copied still lets the form go', async () => {
  const form = fakeForm();
  const {dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  dispatch('submit', submitEvent(form, {form: null}));
  await settle();
  assert.deepEqual(form.requestSubmits, [[]]);
});

test('a failing requestSubmit falls back to submit and does not stay confirmed', async () => {
  const form = fakeForm();
  const {dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}, {status: 200, body: {token: fresh}}]});
  form.requestSubmit = () => {
    throw new Error('not owned by this form');
  };
  dispatch('submit', submitEvent(form));
  await settle();
  assert.equal(form.nativeSubmits, 1);
  const next = submitEvent(form);
  dispatch('submit', next);
  assert.equal(next.prevented, true);
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

test('a failed fetch sends the form without a token and the next attempt retries', async () => {
  const form = fakeForm();
  const {dispatch, fetches} = setup({forms: [form], responses: [{status: 503}, {status: 200, body: {token: fresh}}]});
  const resent = [];
  browserResubmit(form, dispatch, resent);
  dispatch('submit', submitEvent(form));
  await settle();
  assert.deepEqual(resent, [{prevented: false, token: ''}]);
  const retry = submitEvent(form);
  dispatch('submit', retry);
  assert.equal(retry.prevented, true);
  await settle();
  assert.equal(fetches.length, 2);
  assert.equal(resent[1].token, fresh);
});

test('forms that do not POST to this site never trigger a fetch or get a token', async () => {
  const cases = [
    {form: fakeForm(1, 'https://evil.example/collect')},
    {form: fakeForm(1, '//evil.example/collect')},
    {form: fakeForm(1, '/search', 'get')},
    {form: fakeForm(1, '/contact', ' post')},
    {form: fakeForm(1, 'contact'), baseURI: 'https://evil.example/'},
    {form: fakeForm(), controls: (form) => [control(form, {formaction: 'https://evil.example/x'})]},
    {form: fakeForm(), controls: (form) => [control(form, {formmethod: 'get'})]}
  ];

  for (const {form, baseURI, controls} of cases) {
    const options = {forms: [form], controls: controls ? controls(form) : []};

    if (baseURI) {
      options.baseURI = baseURI;
    }

    const {dispatch, fetches} = setup(options);
    const event = submitEvent(form);
    dispatch('submit', event);
    form.submit();
    await settle();
    assert.equal(fetches.length, 0);
    assert.equal(event.prevented, false);
    assert.equal(form.nativeSubmits, 1);
    assert.equal(form.inputs[0].name, '');
  }
});

test('a same-site formaction on another form does not block this one', async () => {
  const form = fakeForm();
  const other = fakeForm();
  const {dispatch} = setup({
    forms: [form, other],
    controls: [control(form, {formaction: '/contact?step=2', formmethod: 'POST'}), control(other, {formaction: 'https://evil.example/x'})],
    responses: [{status: 200, body: {token: fresh}}]
  });
  dispatch('submit', submitEvent(form));
  await settle();
  assert.equal(form.inputs[0].name, fresh);
  assert.equal(other.inputs[0].name, '');
});

test('an unreadable document or form is treated as unsafe', async () => {
  const form = fakeForm();
  const first = setup({forms: [form]});
  first.context.document.querySelectorAll = {shadowed: true};
  first.dispatch('submit', submitEvent(form));
  await settle();
  assert.equal(first.fetches.length, 0);

  const shadowed = fakeForm();
  shadowed.getAttribute = {shadowed: true};
  const second = setup({forms: [shadowed]});
  second.dispatch('submit', submitEvent(shadowed));
  await settle();
  assert.equal(second.fetches.length, 0);
  assert.equal(shadowed.inputs[0].name, '');
});

test('a target changed while the token was on its way gets no token', async () => {
  const viaEvent = fakeForm();
  const viaSubmit = fakeForm();
  const {dispatch} = setup({forms: [viaEvent, viaSubmit], responses: [{status: 200, body: {token: fresh}}]});
  dispatch('submit', submitEvent(viaEvent));
  viaSubmit.submit();
  viaEvent.attributes.action = 'https://evil.example/collect';
  viaSubmit.attributes.action = 'https://evil.example/collect';
  await settle();
  assert.equal(viaEvent.requestSubmits.length, 1);
  assert.equal(viaSubmit.nativeSubmits, 1);
  assert.deepEqual([viaEvent.inputs[0].name, viaSubmit.inputs[0].name], ['', '']);
});

test('a target changed by a later handler has the token removed from the data', async () => {
  const form = fakeForm();
  const {dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  dispatch('submit', submitEvent(form));
  await settle();
  assert.equal(form.inputs[0].name, fresh);
  form.attributes.method = 'get';
  const deleted = [];
  dispatch('formdata', {target: form, formData: {delete: (name) => deleted.push(name)}});
  assert.deepEqual(deleted, [fresh]);
  assert.equal(form.inputs[0].name, '');

  const untouched = [];
  const safe = fakeForm();
  const next = setup({forms: [safe]});
  next.dispatch('formdata', {target: safe, formData: {delete: (name) => untouched.push(name)}});
  assert.deepEqual(untouched, []);
});

test('a token input outside its form is filled through its form owner', async () => {
  const form = fakeForm(0);
  const outside = {name: '', value: '', form};
  form.inputs.push(outside);
  const {dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  dispatch('submit', submitEvent(form));
  await settle();
  assert.equal(outside.name, fresh);
});

test('pages without core.js still get form tokens', async () => {
  const form = fakeForm();
  const {context, dispatch} = setup({forms: [form], responses: [{status: 200, body: {token: fresh}}]});
  delete context.Joomla;
  dispatch('submit', submitEvent(form));
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
