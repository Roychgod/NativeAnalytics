(function () {
  var cfg = window.PWNA_CONFIG || {};
  var pageviewTracked = false;

  function getLocal(name) {
    try { return window.localStorage.getItem(name); } catch (e) { return null; }
  }
  function setLocal(name, value) {
    try { window.localStorage.setItem(name, value); } catch (e) {}
  }
  function getSession(name) {
    try { return window.sessionStorage.getItem(name); } catch (e) { return null; }
  }
  function setSession(name, value) {
    try { window.sessionStorage.setItem(name, value); } catch (e) {}
  }
  function uid(prefix) {
    var a = Math.random().toString(36).slice(2);
    var b = Date.now().toString(36);
    return prefix + '_' + a + b;
  }
  function isCookielessMode() {
    return cfg.storageMode === 'cookieless';
  }
  function getIds() {
    if (isCookielessMode()) {
      return { visitorId: '', sessionId: '' };
    }

    var visitorId = getLocal('pwna_vid');
    if (!visitorId) {
      visitorId = uid('v');
      setLocal('pwna_vid', visitorId);
    }

    var sessionId = getSession('pwna_sid');
    if (!sessionId) {
      sessionId = uid('s');
      setSession('pwna_sid', sessionId);
    }

    window.PWNA.visitorId = visitorId;
    window.PWNA.sessionId = sessionId;
    return { visitorId: visitorId, sessionId: sessionId };
  }

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
    return match ? match[1] : null;
  }
  function setCookie(name, value, maxAge) {
    if (!name) return;
    var cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value) + '; path=/; max-age=' + Math.max(3600, parseInt(maxAge || cfg.consentCookieMaxAge || 31536000, 10)) + '; SameSite=Lax';
    if (window.location.protocol === 'https:') cookie += '; Secure';
    document.cookie = cookie;
  }
  function clearCookie(name) {
    if (!name) return;
    document.cookie = encodeURIComponent(name) + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
  }
  function hasConsent() {
    if (!cfg.consentRequired) return true;
    var name = cfg.consentCookieName || '';
    if (!name) return true;
    return getCookie(name) !== null;
  }
  function isDntActive() {
    if (!cfg.respectDnt) return false;
    return navigator.doNotTrack === '1' || window.doNotTrack === '1';
  }
  function canTrack() {
    return !!cfg.trackEndpoint && hasConsent() && !isDntActive();
  }
  function getQueryVars() {
    var out = {};
    try {
      var params = new URLSearchParams(window.location.search || '');
      params.forEach(function (value, key) { out[key] = value; });
    } catch (e) {}
    return out;
  }

  window.PWNA = window.PWNA || {};
  window.PWNA.visitorId = '';
  window.PWNA.sessionId = '';

  function sendPayload(payload, preferBeacon) {
    if (!payload || !canTrack()) return;
    var ids = getIds();
    payload.visitorId = ids.visitorId;
    payload.sessionId = ids.sessionId;
    var body = JSON.stringify(payload);

    if (preferBeacon !== false) {
      try {
        if (navigator.sendBeacon) {
          var blob = new Blob([body], { type: 'application/json' });
          if (navigator.sendBeacon(cfg.trackEndpoint, blob)) return;
        }
      } catch (e) {}
    }

    if (!window.fetch) return;
    fetch(cfg.trackEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      keepalive: true,
      body: body
    }).catch(function () {});
  }

  function textOf(el) {
    if (!el) return '';
    var text = (el.getAttribute('data-pwna-label') || el.textContent || el.value || '').replace(/\s+/g, ' ').trim();
    return text.slice(0, 180);
  }
  function hostnameOf(url) {
    try { return new URL(url, window.location.href).hostname; } catch (e) { return ''; }
  }
  function isDownloadLink(href) {
    return /\.(pdf|doc|docx|xls|xlsx|zip|rar|csv|txt)([?#].*)?$/i.test(href || '');
  }

  window.PWNA.trackPageview = function () {
    if (pageviewTracked) return;
    // Only mark as tracked if we can actually send (consent given, DNT off, endpoint set).
    // Otherwise we'd "burn" the pageview and lose it forever if consent is granted later.
    if (!canTrack()) return;
    pageviewTracked = true;
    sendPayload({
      type: 'pageview',
      pageId: cfg.pageId || 0,
      pageTitle: cfg.pageTitle || '',
      template: cfg.template || '',
      statusCode: cfg.statusCode || 200,
      path: cfg.path || window.location.pathname,
      url: window.location.href,
      referrer: document.referrer || '',
      queryVars: getQueryVars()
    });
  };

  window.PWNA.trackIfConsented = function () {
    if (!cfg.needsClientPageview) return;
    if (hasConsent()) window.PWNA.trackPageview();
  };

  window.PWNA.trackEvent = function (name, extra, options) {
    if (!cfg.trackEndpoint || !cfg.eventTracking || !name) return;
    options = options || {};
    sendPayload({
      type: 'event',
      name: String(name),
      pageId: cfg.pageId || 0,
      pageTitle: cfg.pageTitle || '',
      template: cfg.template || '',
      path: window.location.pathname + window.location.search,
      url: window.location.href,
      referrer: document.referrer || '',
      extra: extra || {}
    }, options.preferBeacon !== false);
  };

  function privacyWireAllowsAnalytics() {
    if (!cfg.privacyWireAutoConsent) return null;
    var key = cfg.privacyWireStorageKey || 'privacywire';
    var raw = getLocal(key);
    if (!raw) return null;
    var consent;
    try { consent = JSON.parse(raw); } catch (e) { return null; }
    // A consent stored under an older PrivacyWire version has been invalidated
    // (the banner re-prompts) — treat it as revoked, not merely absent, so the
    // consent cookie gets cleared until the visitor re-consents.
    var requiredVersion = parseInt(cfg.privacyWireVersion, 10) || 0;
    if (requiredVersion > 0 && parseInt(consent && consent.version, 10) !== requiredVersion) return false;
    var groups = cfg.privacyWireGroups || ['statistics'];
    var cookieGroups = consent && consent.cookieGroups ? consent.cookieGroups : {};
    for (var i = 0; i < groups.length; i++) {
      if (cookieGroups[groups[i]]) return true;
    }
    return false;
  }

  window.PWNA.setConsent = function () {
    setCookie(cfg.consentCookieName || 'pwna_consent', '1', cfg.consentCookieMaxAge || 31536000);
    window.PWNA.trackIfConsented();
  };
  window.PWNA.clearConsent = function () {
    clearCookie(cfg.consentCookieName || 'pwna_consent');
  };
  window.PWNA.syncPrivacyWireConsent = function () {
    var allowed = privacyWireAllowsAnalytics();
    if (allowed === true) {
      window.PWNA.setConsent();
    } else if (allowed === false) {
      window.PWNA.clearConsent();
    }
    return allowed;
  };

  if (cfg.eventTracking) {
    function findFormFromSubmitControl(el) {
      if (!el) return null;
      if (el.form) return el.form;
      return el.closest ? el.closest('form') : null;
    }

    function formEventPayload(form, submitter) {
      var label = '';
      if (form) {
        label = form.getAttribute('data-pwna-label') || form.getAttribute('aria-label') || form.getAttribute('name') || form.getAttribute('id') || '';
      }
      if (!label && submitter) label = submitter.getAttribute('data-pwna-label') || submitter.getAttribute('name') || textOf(submitter) || '';
      if (!label && form) label = form.getAttribute('action') || '';
      return {
        group: 'form',
        label: label || 'form submit',
        target: (form && form.getAttribute('action')) || window.location.pathname,
        method: (form && form.getAttribute('method')) || '',
        submitter: submitter ? (submitter.getAttribute('name') || textOf(submitter) || '') : ''
      };
    }

    function markFormTracked(form) {
      if (!form) return;
      try { form.__pwnaLastSubmitTrack = Date.now(); } catch (e) {}
    }

    function wasFormTrackedRecently(form) {
      if (!form) return false;
      try { return !!form.__pwnaLastSubmitTrack && (Date.now() - form.__pwnaLastSubmitTrack) < 1500; } catch (e) { return false; }
    }

    document.addEventListener('click', function (e) {
      var el = e.target && e.target.closest ? e.target.closest('[data-pwna-event], a[href], button, input[type=button], input[type=submit]') : null;
      if (!el) return;

      if (el.hasAttribute('data-pwna-event')) {
        window.PWNA.trackEvent(el.getAttribute('data-pwna-event'), {
          group: el.getAttribute('data-pwna-group') || 'custom',
          label: el.getAttribute('data-pwna-label') || textOf(el),
          target: el.getAttribute('href') || el.getAttribute('data-pwna-target') || ''
        });
        return;
      }

      var tag = (el.tagName || '').toUpperCase();
      var type = String(el.getAttribute('type') || '').toLowerCase();
      var isSubmitControl = (tag === 'BUTTON' && (!type || type === 'submit')) || (tag === 'INPUT' && type === 'submit');
      if (isSubmitControl) {
        var form = findFormFromSubmitControl(el);
        if (form && !wasFormTrackedRecently(form)) {
          markFormTracked(form);
          window.PWNA.trackEvent('form_submit', formEventPayload(form, el), { preferBeacon: true });
        }
        return;
      }

      if (tag !== 'A') return;
      var href = el.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#') return;

      if (/^mailto:/i.test(href)) {
        window.PWNA.trackEvent('click_mailto', { group: 'contact', label: textOf(el) || href.replace(/^mailto:/i, ''), target: href });
        return;
      }
      if (/^tel:/i.test(href)) {
        window.PWNA.trackEvent('click_tel', { group: 'contact', label: textOf(el) || href.replace(/^tel:/i, ''), target: href });
        return;
      }
      if (isDownloadLink(href)) {
        window.PWNA.trackEvent('download_file', { group: 'download', label: textOf(el) || href.split('/').pop(), target: href });
        return;
      }
      var host = hostnameOf(href);
      if (host && host !== window.location.hostname) {
        window.PWNA.trackEvent('click_outbound', { group: 'navigation', label: textOf(el) || host, target: href });
      }
    }, true);

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.tagName || form.tagName.toLowerCase() !== 'form') return;
      if (wasFormTrackedRecently(form)) return;
      markFormTracked(form);
      window.PWNA.trackEvent('form_submit', formEventPayload(form, e.submitter || null), { preferBeacon: true });
    }, true);
  }

  if (cfg.privacyWireAutoConsent) {
    window.PWNA.syncPrivacyWireConsent();
    window.addEventListener('storage', function (e) {
      if (!e || e.key === (cfg.privacyWireStorageKey || 'privacywire')) window.PWNA.syncPrivacyWireConsent();
    });
    // The storage event never fires in the tab that wrote the change, so also
    // react to PrivacyWire's same-tab signal when the banner/options are saved.
    document.addEventListener('PrivacyWireBannerAndOptionsClosed', function () {
      window.PWNA.syncPrivacyWireConsent();
    });
  } else {
    window.PWNA.trackIfConsented();
  }

  if (cfg.autoTrack) window.PWNA.trackPageview();

  // Time-on-page: measure active time using Page Visibility API.
  // We track "active" seconds only (tab must be visible). On tab hide or
  // page unload we send a lightweight beacon so the server can update the hit.
  (function () {
    var startVisible = document.visibilityState === 'visible' ? Date.now() : null;
    var accumulated = 0;
    var sent = false;

    function activeSeconds() {
      var extra = (startVisible !== null) ? Math.floor((Date.now() - startVisible) / 1000) : 0;
      return accumulated + extra;
    }

    function sendTime() {
      if (sent || !pageviewTracked || !canTrack()) return;
      var secs = activeSeconds();
      if (secs < 1) return;
      sent = true;
      var ids = getIds();
      var payload = JSON.stringify({
        type: 'time_on_page',
        sessionId: ids.sessionId,
        visitorId: ids.visitorId,
        seconds: secs
      });
      if (navigator.sendBeacon && cfg.trackEndpoint) {
        navigator.sendBeacon(cfg.trackEndpoint, new Blob([payload], { type: 'application/json' }));
      }
    }

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        if (startVisible !== null) {
          accumulated += Math.floor((Date.now() - startVisible) / 1000);
          startVisible = null;
        }
        sendTime();
      } else {
        // Tab became visible again — restart timer, allow re-send on next hide.
        startVisible = Date.now();
        sent = false;
      }
    });

    window.addEventListener('pagehide', sendTime);
  }());
})();
