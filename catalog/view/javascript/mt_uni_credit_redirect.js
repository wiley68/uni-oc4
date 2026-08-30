/**
 * Shared SmartUCF redirect helper (Product / Cart / Checkout).
 * Keeps trusted ucfin.bg validation and terminal navigation UX aligned.
 */
(function (global) {
  'use strict';

  var TRUSTED_HOSTS = ['online.ucfin.bg', 'onlinetest.ucfin.bg'];
  var APPLICATION_PREFIX = '/sucf-online/Request/Start/';

  function isTrustedApplicationRedirect(url) {
    try {
      var parsed = new URL(String(url || ''));
      if (parsed.protocol !== 'https:') {
        return false;
      }
      if (parsed.port && parsed.port !== '' && parsed.port !== '443') {
        return false;
      }
      if (TRUSTED_HOSTS.indexOf(parsed.hostname.toLowerCase()) === -1) {
        return false;
      }
      if (parsed.username || parsed.password || parsed.search || parsed.hash) {
        return false;
      }
      var path = parsed.pathname || '';
      if (path.indexOf(APPLICATION_PREFIX) !== 0) {
        return false;
      }
      var sessionId = path.slice(APPLICATION_PREFIX.length);
      return /^[A-Za-z0-9._~-]{1,128}$/.test(sessionId);
    } catch (error) {
      return false;
    }
  }

  /**
   * @returns {boolean} true when navigation was started (terminal UI state)
   */
  function navigateIfTrusted(url) {
    if (!isTrustedApplicationRedirect(url)) {
      return false;
    }
    global.location.assign(url);
    return true;
  }

  global.MtUniCreditRedirect = {
    isTrustedApplicationRedirect: isTrustedApplicationRedirect,
    navigateIfTrusted: navigateIfTrusted
  };
})(typeof window !== 'undefined' ? window : this);
