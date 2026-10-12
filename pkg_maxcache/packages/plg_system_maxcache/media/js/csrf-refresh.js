// MAxCache stores pages without Joomla's csrf.token script option, because one
// static file is shared by every visitor. This script fetches the visitor's own
// token only when a request needs it. Fetching a token starts a Joomla session,
// and the session cookie bypasses the static cache, so it never runs on load.
(() => {
  'use strict';

  const script = document.currentScript;
  const endpoint = script ? script.dataset.maxcacheTokenEndpoint || '' : '';
  let pending = null;

  const currentToken = () => {
    const joomla = window.Joomla;

    return joomla && typeof joomla.getOptions === 'function' ? joomla.getOptions('csrf.token', '') : '';
  };

  const fetchToken = () => {
    if (pending === null) {
      pending = fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`MAxCache token request failed with HTTP ${response.status}`);
          }

          return response.json();
        })
        .then((data) => {
          const token = data && typeof data.token === 'string' ? data.token : '';

          if (!/^[a-f0-9]{32}$/.test(token)) {
            throw new Error('MAxCache token response is invalid');
          }

          window.Joomla.loadOptions({'csrf.token': token});

          return token;
        })
        .catch((error) => {
          pending = null;
          throw error;
        });
    }

    return pending;
  };

  window.MAxCache = window.MAxCache || {};

  // For custom scripts that send the token themselves.
  window.MAxCache.csrfToken = () => {
    const token = currentToken();

    return token !== '' || endpoint === '' ? Promise.resolve(token) : fetchToken();
  };

  const patch = () => {
    const joomla = window.Joomla;

    if (!joomla || typeof joomla.request !== 'function' || joomla.request.maxcacheTokenRefresh) {
      return;
    }

    const original = joomla.request;
    const request = (options) => {
      const method = String((options && options.method) || 'GET').toUpperCase();

      if (method === 'GET' || currentToken() !== '' || endpoint === '') {
        return original(options);
      }

      // The request is sent once a token is available. If the token cannot be
      // fetched it is still sent, and Joomla rejects it as it would have before.
      // Callers then receive a Promise (promise: true) or undefined, not an XHR.
      const send = () => original(options);
      const result = window.MAxCache.csrfToken().then(send, send);

      return options && options.promise ? result : undefined;
    };

    request.maxcacheTokenRefresh = true;
    joomla.request = request;
  };

  patch();
  document.addEventListener('DOMContentLoaded', patch, {once: true});
})();
