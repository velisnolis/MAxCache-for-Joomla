// MAxCache stores pages without Joomla's csrf.token script option, because one
// static file is shared by every visitor. This script fetches the visitor's own
// token only when a request needs it. Fetching a token starts a Joomla session,
// and the session cookie bypasses the static cache, so it never runs on load.
//
// With "Cache Pages with Forms" on, the hidden token input of each form is stored
// as <input type="hidden" data-maxcache-form-token>. A new visitor has no session
// yet, and two first requests sent at once each start their own: only the last
// cookie survives. A captcha that fetches its challenge when the form is focused and
// keeps it in the session would lose it if this script's request overlapped. So the
// token is fetched a moment after the visitor first touches the form, once such
// requests have set the cookie, and again when the form is sent.
(() => {
  'use strict';

  const script = document.currentScript;
  const endpoint = script ? script.dataset.maxcacheTokenEndpoint || '' : '';
  const placeholder = 'input[data-maxcache-form-token]';
  let pending = null;
  let fetched = '';

  const currentToken = () => {
    const joomla = window.Joomla;
    const option = joomla && typeof joomla.getOptions === 'function' ? joomla.getOptions('csrf.token', '') : '';

    return option || fetched;
  };

  // The token only goes into forms that POST back to this site, whatever control
  // submits them. A placeholder outside a form, in a GET form, or in a form whose
  // action or any button's formaction leaves the site stays empty: markup injected
  // into a page must not be able to send a visitor's token elsewhere. Attributes are
  // read directly, since form.action can be shadowed by a control named "action",
  // and URLs resolve against the document base, as the browser does on submit.
  const sameOrigin = (url) => {
    try {
      return new URL(url, document.baseURI || window.location.href).origin === window.location.origin;
    } catch (error) {
      return false;
    }
  };

  // As the browser parses it: ASCII case-insensitive, no trimming. " post" is GET.
  const isPost = (value) => String(value || '').toLowerCase() === 'post';

  // Named elements can shadow document.querySelectorAll or a form's getAttribute
  // (<img name="querySelectorAll">). Call the prototype methods, which they cannot
  // replace, and treat anything unreadable as unsafe.
  const queryAll = (selector) => {
    const native = window.Document && window.Document.prototype && window.Document.prototype.querySelectorAll;

    if (typeof native === 'function') {
      return native.call(document, selector);
    }

    return typeof document.querySelectorAll === 'function' ? document.querySelectorAll(selector) : null;
  };

  const attribute = (element, name) => {
    const native = window.Element && window.Element.prototype && window.Element.prototype.getAttribute;

    if (typeof native === 'function') {
      return native.call(element, name);
    }

    if (typeof element.getAttribute !== 'function') {
      throw new Error('MAxCache cannot read form attributes');
    }

    return element.getAttribute(name);
  };

  const postsToThisSite = (form) => {
    if (!form) {
      return false;
    }

    try {
      if (!isPost(attribute(form, 'method')) || !sameOrigin(attribute(form, 'action') || '')) {
        return false;
      }

      const overrides = queryAll('[formaction], [formmethod]');

      if (overrides === null) {
        return false;
      }

      for (const control of overrides) {
        if (control.form !== form) {
          continue;
        }

        const action = attribute(control, 'formaction');
        const method = attribute(control, 'formmethod');

        if ((action !== null && !sameOrigin(action)) || (method !== null && !isPost(method))) {
          return false;
        }
      }

      return true;
    } catch (error) {
      return false;
    }
  };

  const filled = [];

  // Take the token back out of a form that stopped being safe after it was filled,
  // for example because a button with an external formaction was added later.
  const withdrawFrom = (form) => {
    filled.forEach((input) => {
      if (input.form === form) {
        input.name = '';
        input.value = '';
      }
    });
  };

  // Turn the stored placeholders into the input Joomla expects: name=<token>, value=1.
  const fillForms = (token) => {
    const inputs = queryAll(placeholder);

    if (inputs === null) {
      return;
    }

    inputs.forEach((input) => {
      if (!postsToThisSite(input.form)) {
        return;
      }

      input.name = token;
      input.value = '1';
      input.removeAttribute('data-maxcache-form-token');
      filled.push(input);
    });

    // Inputs filled from an earlier fetch follow the current session's token.
    filled.forEach((input) => {
      if (input.name !== '') {
        input.name = token;
      }
    });
  };

  const fetchToken = () => {
    if (pending === null) {
      const request = fetch(endpoint, {
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

          fetched = token;

          if (window.Joomla && typeof window.Joomla.loadOptions === 'function') {
            window.Joomla.loadOptions({'csrf.token': token});
          }

          fillForms(token);

          return token;
        })
        .catch((error) => {
          if (pending === request) {
            pending = null;
          }

          throw error;
        });

      pending = request;
    }

    return pending;
  };

  // The token of the session the visitor has right now. Waits for a fetch that is
  // still under way, since that one may belong to a session that lost the race.
  let refreshing = null;

  const refreshToken = () => {
    if (refreshing === null) {
      const start = () => {
        pending = null;

        return fetchToken();
      };
      const done = () => {
        refreshing = null;
      };

      refreshing = (pending || Promise.resolve()).then(start, start);
      refreshing.then(done, done);
    }

    return refreshing;
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

  // Form handling is installed once, even if the script is included twice.
  if (window.MAxCache.formTokens) {
    return;
  }

  window.MAxCache.formTokens = true;

  // A form this script is responsible for: it still has a placeholder, or it holds
  // a token this script put there.
  const needsToken = (form) => !!form && typeof form.querySelector === 'function'
    && (form.querySelector(placeholder) !== null || filled.some((input) => input.form === form && input.name !== ''))
    && postsToThisSite(form);

  const confirmed = new WeakSet();

  const formOf = (event) => {
    const target = event.target;

    if (!target) {
      return null;
    }

    return target.form || (typeof target.closest === 'function' ? target.closest('form') : null);
  };

  // Fetch while the visitor is still filling the form in, so scripts that serialise
  // the form themselves find the token in place, but not in the same instant as
  // requests other scripts send on focus.
  const warmDelay = 1500;
  let warming = false;

  const warm = (event) => {
    if (warming || endpoint === '' || !needsToken(formOf(event))) {
      return;
    }

    warming = true;
    window.setTimeout(() => {
      fetchToken().catch(() => {
        warming = false;
      });
    }, warmDelay);
  };

  ['focusin', 'pointerdown', 'keydown'].forEach((type) => {
    document.addEventListener(type, warm, {capture: true, passive: true});
  });

  // Every submit of such a form is held while the token of the visitor's current
  // session is fetched, then sent again so the form's own validation and handlers
  // run with it in place. If the token cannot be fetched the form is sent anyway
  // and Joomla rejects it as before.
  document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!postsToThisSite(form)) {
      withdrawFrom(form);

      return;
    }

    if (endpoint === '' || !needsToken(form)) {
      return;
    }

    if (confirmed.has(form)) {
      confirmed.delete(form);

      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    const submitter = event.submitter || null;
    const resend = () => {
      confirmed.add(form);

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(submitter);
      } else {
        form.submit();
      }

      // requestSubmit() dispatches its submit event synchronously. If the browser's
      // own validation stopped it, the next attempt must be checked again.
      confirmed.delete(form);
    };

    refreshToken().then(resend, () => {
      form.querySelectorAll(placeholder).forEach((input) => input.removeAttribute('data-maxcache-form-token'));
      resend();
    });
  }, true);

  // form.submit() fires no submit event; scripts that validate over AJAX use it.
  if (typeof window.HTMLFormElement === 'function') {
    const nativeSubmit = window.HTMLFormElement.prototype.submit;

    window.HTMLFormElement.prototype.submit = function () {
      if (!postsToThisSite(this)) {
        withdrawFrom(this);

        return nativeSubmit.call(this);
      }

      if (endpoint === '' || !needsToken(this) || confirmed.has(this)) {
        return nativeSubmit.call(this);
      }

      const send = () => nativeSubmit.call(this);

      refreshToken().then(send, send);

      return undefined;
    };
  }
})();
