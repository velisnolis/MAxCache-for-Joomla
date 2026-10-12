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

function setup({token = '', responses = []} = {}) {
  const options = {'csrf.token': token};
  const sent = [];
  const fetches = [];
  const queue = [...responses];
  const context = {
    Promise,
    Error,
    String,
    document: {
      currentScript: {dataset: {maxcacheTokenEndpoint: endpoint}},
      addEventListener() {}
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

  return {context, sent, fetches, options};
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
  const {context} = setup();
  const patched = context.Joomla.request;
  vm.runInNewContext(source, context);
  assert.equal(context.Joomla.request, patched);
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
