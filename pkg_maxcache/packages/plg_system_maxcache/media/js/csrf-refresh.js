// MAxCache stores pages without Joomla's csrf.token script option, because one
// static file is shared by every visitor. This script fetches the visitor's own
// token only when a request needs it. Fetching a token starts a Joomla session,
// and the session cookie bypasses the static cache, so it never runs on load.
//
// With "Cache Pages with Forms" on, the hidden token input of each form is stored
// as <input type="hidden" data-maxcache-form-token>. The token is fetched when the
// form is sent, never earlier: a new visitor has no session, and two first requests
// sent at once each start their own, of which only the last cookie survives. A
// captcha that fetches its challenge on focus and keeps it in the session has set
// the cookie by the time the form is sent, so the token request joins that session.
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

  // The inputs MAxCache stored without a token and that belong to this form,
  // including ones outside it that point to it with a form="" attribute. The marker
  // attribute stays for the life of the page.
  const tokenInputs = (form) => {
    const all = form ? queryAll(placeholder) : null;

    return all === null ? [] : Array.prototype.filter.call(all, (input) => input.form === form);
  };

  const fill = (form, token) => {
    tokenInputs(form).forEach((input) => {
      input.name = token;
      input.value = '1';
    });
  };

  // Take the token back out of a form that is not, or is no longer, safe to send.
  const clear = (form) => {
    tokenInputs(form).forEach((input) => {
      input.name = '';
      input.value = '';
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
  // still under way, since that one may belong to a session that lost a race.
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

  const formPrototype = typeof window.HTMLFormElement === 'function' ? window.HTMLFormElement.prototype : null;
  const nativeSubmit = formPrototype ? formPrototype.submit : null;
  const holding = new WeakSet();
  const confirmed = new WeakSet();

  // Prototype methods again: a control named "submit" or "requestSubmit" shadows
  // the form's own.
  const submitNow = (form) => (nativeSubmit ? nativeSubmit.call(form) : form.submit());

  const requestSubmit = (form, submitter) => {
    const native = formPrototype && formPrototype.requestSubmit;
    const call = (...parameters) => (typeof native === 'function'
      ? native.apply(form, parameters)
      : form.requestSubmit(...parameters));

    if (!submitter || submitter.form === form) {
      call(...(submitter ? [submitter] : []));

      return;
    }

    // The submitter left the form while the token was fetched: Joomla.submitform()
    // clicks a temporary button and removes it at once. Send with a hidden copy,
    // which carries its name, value and form* attributes. The formdata check below
    // still sees the copy, so an off-site formaction loses the token.
    let stand = null;

    try {
      stand = submitter.cloneNode(false);
      stand.hidden = true;
      form.appendChild(stand);
    } catch (error) {
      stand = null;
    }

    try {
      call(...(stand && stand.form === form ? [stand] : []));
    } finally {
      if (stand && stand.parentNode) {
        stand.parentNode.removeChild(stand);
      }
    }
  };

  // Put the visitor's token in, or take it out if the form no longer posts here.
  const prepare = (form, token) => {
    if (!postsToThisSite(form)) {
      clear(form);
    } else if (token !== '') {
      fill(form, token);
    }
  };

  // Every submit of such a form is held while the token of the visitor's current
  // session is fetched, then sent again so the form's own validation and handlers
  // run with it in place. If the token cannot be fetched the form is sent without
  // one and Joomla rejects it; the next attempt fetches again.
  document.addEventListener('submit', (event) => {
    const form = event.target;

    if (tokenInputs(form).length === 0) {
      return;
    }

    if (!postsToThisSite(form)) {
      clear(form);

      return;
    }

    if (endpoint === '') {
      return;
    }

    if (confirmed.has(form)) {
      confirmed.delete(form);

      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    // A second click while the token is on its way is the same submission.
    if (holding.has(form)) {
      return;
    }

    holding.add(form);

    const submitter = event.submitter || null;
    const resend = (token) => {
      holding.delete(form);
      prepare(form, token);
      confirmed.add(form);

      try {
        requestSubmit(form, submitter);
      } catch (error) {
        submitNow(form);
      } finally {
        // requestSubmit() dispatches its submit event synchronously. If the
        // browser's own validation stopped it, the next attempt is checked again.
        confirmed.delete(form);
      }
    };

    refreshToken().then(resend, () => resend(''));
  }, true);

  // The last moment before the data leaves: handlers that ran after the check above
  // may have pointed the form somewhere else or switched it to GET.
  document.addEventListener('formdata', (event) => {
    const form = event.target;

    if (postsToThisSite(form)) {
      return;
    }

    tokenInputs(form).forEach((input) => {
      if (input.name !== '' && event.formData && typeof event.formData.delete === 'function') {
        event.formData.delete(input.name);
      }
    });
    clear(form);
  }, true);

  // form.submit() fires no submit event; scripts that validate over AJAX use it.
  if (formPrototype) {
    formPrototype.submit = function () {
      if (endpoint === '' || confirmed.has(this) || tokenInputs(this).length === 0) {
        return nativeSubmit.call(this);
      }

      if (!postsToThisSite(this)) {
        clear(this);

        return nativeSubmit.call(this);
      }

      if (holding.has(this)) {
        return undefined;
      }

      holding.add(this);

      const send = (token) => {
        holding.delete(this);
        prepare(this, token);
        nativeSubmit.call(this);
      };

      refreshToken().then(send, () => send(''));

      return undefined;
    };
  }
})();
