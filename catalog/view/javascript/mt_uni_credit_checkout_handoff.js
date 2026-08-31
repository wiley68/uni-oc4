/**
 * Product Buy Checkout handoff — sync native payment UI after OC4 shipping/payment rebuilds.
 *
 * Native shipping_method.save unsets session.payment_method. Payment modal radios use
 * #input-payment-code (or first method). This script applies server-annotated preferred
 * payment onto those fields before the native getMethods success handler builds the modal.
 *
 * Deterministic: jQuery dataFilter on the specific OC4 routes only — no polling.
 */
(() => {
  "use strict";

  const PREFERRED_KEY = "mt_uni_credit_preferred_payment";
  const GET_METHODS_RE = /route=checkout\/payment_method\.getMethods/;
  const SHIPPING_SAVE_RE = /route=checkout\/shipping_method\.save/;

  function applyPreferredToDom(preferred) {
    if (!preferred || typeof preferred !== "object") {
      return;
    }
    const codeInput = document.getElementById("input-payment-code");
    const nameInput = document.getElementById("input-payment-method");
    if (preferred.pending) {
      // After shipping reset: clear display so getMethods can re-apply authoritatively.
      if (nameInput) {
        nameInput.value = "";
      }
      if (codeInput && preferred.code) {
        codeInput.value = preferred.code;
      }
      return;
    }
    if (preferred.code && codeInput) {
      codeInput.value = String(preferred.code);
    }
    if (preferred.name && nameInput) {
      nameInput.value = String(preferred.name);
    }
  }

  function extractPreferred(raw) {
    if (!raw || typeof raw !== "string") {
      return null;
    }
    try {
      const json = JSON.parse(raw);
      return json && json[PREFERRED_KEY] ? json[PREFERRED_KEY] : null;
    } catch (e) {
      return null;
    }
  }

  function isHandoffUrl(url) {
    const target = String(url || "");
    return GET_METHODS_RE.test(target) || SHIPPING_SAVE_RE.test(target);
  }

  if (typeof window.jQuery === "undefined" || !window.jQuery.ajaxPrefilter) {
    return;
  }

  window.jQuery.ajaxPrefilter(function (options) {
    if (!isHandoffUrl(options.url)) {
      return;
    }
    const prior = options.dataFilter;
    options.dataFilter = function (data, type) {
      let payload = data;
      if (typeof prior === "function") {
        payload = prior(data, type);
      }
      applyPreferredToDom(extractPreferred(payload));
      return payload;
    };
  });
})();
