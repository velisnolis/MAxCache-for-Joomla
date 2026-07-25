(() => {
  'use strict';

  const selector = '.js-maxcache-fragment[data-maxcache-fragment-endpoint]';

  const activateScripts = (fragment) => {
    fragment.querySelectorAll('script').forEach((script) => {
      const replacement = document.createElement('script');

      for (const attribute of script.attributes) {
        replacement.setAttribute(attribute.name, attribute.value);
      }

      replacement.textContent = script.textContent;
      script.replaceWith(replacement);
    });
  };

  const normalizeFragmentForms = (fragment) => {
    fragment.querySelectorAll('form[action]').forEach((form) => {
      const action = new URL(form.getAttribute('action'), window.location.href);
      const isOwnFragmentEndpoint = action.origin === window.location.origin
        && (
          action.searchParams.get('plugin') === 'maxcachefragment'
          || /^\/component\/ajax\/?$/.test(action.pathname)
        );

      if (!isOwnFragmentEndpoint) {
        return;
      }

      const pageUrl = new URL(window.location.href);
      pageUrl.hash = '';
      form.action = pageUrl.toString();
    });
  };

  const load = async (fragment) => {
    if (fragment.dataset.maxcacheFragmentState === 'loading' || fragment.dataset.maxcacheFragmentState === 'loaded') {
      return;
    }

    fragment.dataset.maxcacheFragmentState = 'loading';
    fragment.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(fragment.dataset.maxcacheFragmentEndpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      if (!response.ok) {
        throw new Error(`MAxCache fragment request failed with HTTP ${response.status}`);
      }

      fragment.innerHTML = await response.text();
      normalizeFragmentForms(fragment);
      activateScripts(fragment);
      fragment.dataset.maxcacheFragmentState = 'loaded';
      fragment.dispatchEvent(new CustomEvent('maxcache:fragment-loaded', {
        bubbles: true,
        detail: {
          id: fragment.dataset.maxcacheFragment
        }
      }));
    } catch (error) {
      fragment.dataset.maxcacheFragmentState = 'error';
      fragment.dispatchEvent(new CustomEvent('maxcache:fragment-error', {
        bubbles: true,
        detail: {
          id: fragment.dataset.maxcacheFragment,
          error
        }
      }));
    } finally {
      fragment.removeAttribute('aria-busy');
    }
  };

  const observeVisible = (fragment) => {
    if (!('IntersectionObserver' in window)) {
      load(fragment);
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) {
        return;
      }

      observer.disconnect();
      load(fragment);
    });

    observer.observe(fragment);
  };

  const boot = () => {
    document.querySelectorAll(selector).forEach((fragment) => {
      const trigger = fragment.dataset.maxcacheFragmentTrigger || 'visible';

      if (trigger === 'immediate') {
        load(fragment);
      } else if (trigger === 'visible') {
        observeVisible(fragment);
      }
    });
  };

  document.addEventListener('maxcache:fragment-load', (event) => {
    const id = String(event.detail?.id || '');

    document.querySelectorAll(selector).forEach((fragment) => {
      if (id === '' || fragment.dataset.maxcacheFragment === id) {
        load(fragment);
      }
    });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, {once: true});
  } else {
    boot();
  }
})();
