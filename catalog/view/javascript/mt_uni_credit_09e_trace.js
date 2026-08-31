/**
 * TEMPORARY Phase 11C Remediation 09E — browser-visible handoff build markers.
 * Loaded only when mtuc_trace session/URL is active. Remove after operator gate.
 */
(() => {
  "use strict";

  const BUILD = "09E-dd3c0d8-trace1";
  const TRACE_RE = /[?&]mtuc_trace=1(?:&|$)/;

  function traceWanted() {
    try {
      if (TRACE_RE.test(String(window.location.search || ""))) {
        return true;
      }
      if (window.__MTUC_TRACE_FORCE === true) {
        return true;
      }
    } catch (e) {
      return false;
    }
    return false;
  }

  function parseIntentFromScriptSrc() {
    try {
      const scripts = document.getElementsByTagName("script");
      for (let i = scripts.length - 1; i >= 0; i--) {
        const src = scripts[i].src || "";
        if (src.indexOf("mt_uni_credit_09e_trace.js") === -1) {
          continue;
        }
        const m = src.match(/[?&]intent=([^&]*)/);
        if (!m) {
          return null;
        }
        return JSON.parse(decodeURIComponent(m[1]));
      }
    } catch (e) {
      return null;
    }
    return null;
  }

  function discoverRoots() {
    return {
      payment_modal_selector: "#modal-payment",
      payment_modal_found: !!document.getElementById("modal-payment"),
      input_payment_code: "#input-payment-code",
      input_payment_code_value:
        (document.getElementById("input-payment-code") || {}).value || "",
      checkout_confirm: "#checkout-confirm",
      checkout_confirm_found: !!document.getElementById("checkout-confirm"),
      uni_panel_root: "#mt-uni-credit-checkout-root",
      uni_panel_found: !!document.getElementById("mt-uni-credit-checkout-root"),
      scheme_select: "[data-mtuc-schemes]",
      scheme_select_found: !!document.querySelector("[data-mtuc-schemes]"),
      scheme_select_value:
        (document.querySelector("[data-mtuc-schemes]") || {}).value || "",
    };
  }

  if (
    !traceWanted() &&
    !document.querySelector('script[src*="mt_uni_credit_09e_trace.js"]')
  ) {
    return;
  }

  window.__MTUC_HANDOFF_BUILD = BUILD;
  window.__MTUC_PRODUCT_BUILD = BUILD;
  window.__MTUC_TRACE_ON = true;
  const intent = parseIntentFromScriptSrc();
  if (intent) {
    window.__MTUC_TRACE_INTENT = intent;
  }
  window.__MTUC_ROOT_DISCOVERY = discoverRoots();

  window.__MTUC_refreshRoots = function () {
    window.__MTUC_ROOT_DISCOVERY = discoverRoots();
    return window.__MTUC_ROOT_DISCOVERY;
  };

  window.__MTUC_fetchCheckpoint = function () {
    const url =
      "index.php?route=extension/mt_uni_credit/module/mt_uni_credit_product.handoffTrace&mtuc_trace=1";
    return fetch(url, { credentials: "same-origin" })
      .then((r) => r.json())
      .then((json) => {
        window.__MTUC_LAST_CHECKPOINT = json;
        return json;
      });
  };
})();
