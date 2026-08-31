(() => {
  "use strict";

  const ROOT_ID = "mt-uni-credit-checkout-root";
  const BOOTSTRAP_ID = "mt-uni-credit-checkout-bootstrap";
  const ASSET_MARK = "data-mtuc-checkout-asset";

  function ensureAssets(state) {
    const head = document.head || document.documentElement;
    [state.fonts_href, state.product_css_href, state.checkout_css_href].forEach(
      (href) => {
        if (
          !href ||
          document.querySelector(`link[${ASSET_MARK}="1"][href="${href}"]`)
        ) {
          return;
        }
        const link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = href;
        link.setAttribute(ASSET_MARK, "1");
        head.appendChild(link);
      },
    );

    if (
      state.script_href &&
      !document.querySelector(
        `script[${ASSET_MARK}="js"][src="${state.script_href}"]`,
      )
    ) {
      // Script is already executing; mark presence so AJAX reloads do not re-inject.
      const marker = document.createElement("script");
      marker.setAttribute(ASSET_MARK, "js");
      marker.src = state.script_href;
      marker.dataset.mtucMarker = "1";
      // Do not append a second executable copy — only mark when this file loaded via document.addScript.
    }
  }

  function initRoot(root) {
    if (!root || root.dataset.mtucBound === "1") {
      return;
    }

    const bootstrapEl =
      root.querySelector(`#${BOOTSTRAP_ID}`) ||
      document.getElementById(BOOTSTRAP_ID);
    if (!bootstrapEl) {
      return;
    }

    let state;
    try {
      state = JSON.parse(bootstrapEl.textContent || "{}");
    } catch (error) {
      return;
    }

    root.dataset.mtucBound = "1";
    ensureAssets(state);

    const form = root.querySelector("#mt-uni-credit-checkout-form");
    // Checkout has no Standard/Promo tabs — unified dropdown; preferred from standard offer when present.
    let selectedOfferType = "standard";
    let selectedSchemeKey = "";
    let submissionToken = "";
    let lastCalculation = null;
    let calcBusy = false;
    let issueFlight = null;
    let confirmBusy = false;
    let redirectTerminal = false;
    let firstInstallmentTimer = null;
    let cartFingerprint = state.calculator?.cart_fingerprint || "";

    function schemeSelect() {
      return root.querySelector("[data-mtuc-schemes]");
    }

    function firstInput() {
      return root.querySelector("[data-mtuc-first]");
    }

    function submitBtn() {
      return root.querySelector("#button-confirm, [data-mtuc-submit]");
    }

    function popupErrorEl() {
      return root.querySelector("[data-mtuc-popup-error]");
    }

    function submitErrorEl() {
      return root.querySelector("[data-mtuc-submit-error]");
    }

    function processingEl() {
      return root.querySelector("[data-mtuc-processing]");
    }

    /** Unified scheme list from presenter (standard offer already includes promo in canonical order). */
    function unifiedSchemes() {
      const offers = state.calculator?.offers || {};
      if (
        offers.standard &&
        Array.isArray(offers.standard.schemes) &&
        offers.standard.schemes.length
      ) {
        return offers.standard.schemes;
      }
      const merged = [];
      const seen = {};
      ["standard", "promo"].forEach((type) => {
        (offers[type]?.schemes || []).forEach((scheme) => {
          if (!scheme || !scheme.key || seen[scheme.key]) {
            return;
          }
          seen[scheme.key] = true;
          merged.push(scheme);
        });
      });
      return merged;
    }

    function resolvePreferredSchemeKey() {
      const offers = state.calculator?.offers || {};
      if (offers.standard?.preferred_scheme_key) {
        return offers.standard.preferred_scheme_key;
      }
      if (offers.promo?.preferred_scheme_key) {
        return offers.promo.preferred_scheme_key;
      }
      const schemes = unifiedSchemes();
      return schemes[0]?.key || "";
    }

    function selectedScheme() {
      const schemes = unifiedSchemes();
      return (
        schemes.find((scheme) => scheme.key === selectedSchemeKey) ||
        schemes[0] ||
        null
      );
    }

    function syncOfferTypeFromScheme(scheme) {
      if (scheme && scheme.scheme_type) {
        selectedOfferType = scheme.scheme_type;
      }
    }

    function hasAuthoritativeCalculation() {
      return !!(lastCalculation && submissionToken && selectedScheme());
    }

    function areMandatoryConsentsChecked() {
      const boxes = root.querySelectorAll("[data-mtuc-consent-checkbox]");
      if (!boxes.length) {
        return true;
      }
      return Array.from(boxes).every((box) => box.checked);
    }

    function fieldError(name) {
      return root.querySelector(`[data-mtuc-field-error="${name}"]`);
    }

    function clearProcess2FieldErrors() {
      ["egn", "phone2"].forEach((name) => {
        const el = fieldError(name);
        if (el) {
          el.textContent = "";
        }
        const input = root.querySelector(`[name="${name}"]`);
        if (input) {
          input.setAttribute("aria-invalid", "false");
        }
      });
    }

    function showFieldErrors(errors) {
      if (!errors || typeof errors !== "object") {
        return;
      }
      Object.entries(errors).forEach(([key, message]) => {
        const target = fieldError(key);
        if (target) {
          target.textContent = String(message);
        }
        const input = root.querySelector(`[name="${key}"]`);
        if (input) {
          input.setAttribute("aria-invalid", message ? "true" : "false");
        }
      });
    }

    function sanitizePhone2Value(value) {
      return String(value || "")
        .split("")
        .filter((char) => /[-0-9+() ]/.test(char))
        .join("");
    }

    function isValidPhone2(value) {
      const phone = String(value || "").trim();
      return phone !== "" && /^[-0-9+() ]+$/.test(phone) && /\d/.test(phone);
    }

    /** EGN: 10 digits; first 8 form a valid YYYYMMDD calendar date (PHP checkdate parity). */
    function isValidEgn(digits) {
      if (!/^\d{10}$/.test(digits)) {
        return false;
      }
      const year = parseInt(digits.slice(0, 4), 10);
      const month = parseInt(digits.slice(4, 6), 10);
      const day = parseInt(digits.slice(6, 8), 10);
      const date = new Date(year, month - 1, day);
      return (
        date.getFullYear() === year &&
        date.getMonth() === month - 1 &&
        date.getDate() === day
      );
    }

    function getProcess2FieldErrors() {
      const errors = {};
      const egnField = root.querySelector('[name="egn"]');
      if (egnField) {
        const egn = String(egnField.value || "").replace(/\D/g, "");
        if (egn === "") {
          errors.egn = "Полето „ЕГН“ е задължително.";
        } else if (!isValidEgn(egn)) {
          errors.egn =
            "Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).";
        }
      }
      const phone2Field = root.querySelector('[name="phone2"]');
      if (phone2Field) {
        const phone2 = String(phone2Field.value || "").trim();
        if (phone2 === "") {
          errors.phone2 = "Полето „Втори телефон“ е задължително.";
        } else if (!isValidPhone2(phone2)) {
          errors.phone2 = "Въведете валиден втори телефонен номер.";
        }
      }
      return errors;
    }

    function process2FieldsValid() {
      return Object.keys(getProcess2FieldErrors()).length === 0;
    }

    function clearCalculationDisplays() {
      root.querySelectorAll("[data-mtuc-display]").forEach((el) => {
        el.textContent = "";
      });
    }

    function resetOfferState() {
      lastCalculation = null;
      submissionToken = "";
      clearCalculationDisplays();
      const first = firstInput();
      if (first) {
        first.value = "0";
        first.removeAttribute("readonly");
        first.removeAttribute("disabled");
      }
      const apply = submitBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute("aria-disabled", "true");
      }
      if (popupErrorEl()) {
        popupErrorEl().textContent = "";
      }
      if (submitErrorEl()) {
        submitErrorEl().textContent = "";
      }
    }

    function resetFirstInstallmentForSchemeChange() {
      resetOfferState();
    }

    function setProcessing(active) {
      const el = processingEl();
      const panel = el
        ? el.querySelector(
            ".mt-uni-credit-product-calculator__processing-panel",
          )
        : null;
      if (el) {
        el.hidden = !active;
      }
      if (panel) {
        panel.setAttribute("aria-busy", active ? "true" : "false");
      }
      root.classList.toggle("mt-uni-credit-checkout--processing", active);
      root.setAttribute("aria-busy", active ? "true" : "false");
      document.documentElement.classList.toggle(
        "mt-uni-credit-checkout-processing-active",
        active,
      );
    }

    function updateConfirmState() {
      const button = submitBtn();
      if (!button) {
        return false;
      }
      const valid =
        hasAuthoritativeCalculation() &&
        areMandatoryConsentsChecked() &&
        process2FieldsValid() &&
        !confirmBusy;
      button.disabled = !valid;
      button.setAttribute("aria-disabled", valid ? "false" : "true");
      return valid;
    }

    function populateSchemeSelect() {
      const select = schemeSelect();
      const schemes = unifiedSchemes();
      if (!select) {
        return;
      }
      select.replaceChildren();
      schemes.forEach((scheme) => {
        const option = document.createElement("option");
        option.value = scheme.key;
        let label = `${scheme.months} месеца`;
        if (scheme.description) {
          label += ` - ${scheme.description}`;
        }
        option.textContent = `${label}\u00A0\u00A0\u00A0`;
        select.appendChild(option);
      });
      if (!selectedSchemeKey) {
        selectedSchemeKey = resolvePreferredSchemeKey();
      }
      if (selectedSchemeKey) {
        select.value = selectedSchemeKey;
      }
      if (!select.value && schemes[0]) {
        selectedSchemeKey = schemes[0].key;
        select.value = selectedSchemeKey;
      }
      syncOfferTypeFromScheme(selectedScheme());
    }

    function renderCalculation(calculation) {
      if (!calculation) {
        return;
      }
      lastCalculation = calculation;
      if (calculation.cart_fingerprint) {
        cartFingerprint = calculation.cart_fingerprint;
      }
      const map = {
        price:
          calculation.price_display?.primary ||
          calculation.price_display?.secondary ||
          calculation.price,
        financed_amount:
          calculation.financed_amount_display?.primary ||
          calculation.financed_amount,
        monthly_installment:
          calculation.monthly_installment_display?.primary ||
          calculation.monthly_installment,
        total_payable:
          calculation.total_payable_display?.primary ||
          calculation.total_payable,
        glp:
          calculation.glp_display != null
            ? `${calculation.glp_display}%`
            : calculation.glp,
        gpr:
          calculation.gpr_display != null
            ? `${calculation.gpr_display}%`
            : calculation.gpr,
      };
      Object.entries(map).forEach(([key, value]) => {
        const el = root.querySelector(`[data-mtuc-display="${key}"]`);
        if (el && value != null) {
          el.textContent = String(value);
        }
      });

      const firstRow = root.querySelector("[data-mtuc-first-row]");
      const first = firstInput();
      if (first) {
        first.value = String(calculation.first_installment ?? 0);
        if (calculation.first_installment_locked) {
          first.setAttribute("readonly", "readonly");
          first.removeAttribute("disabled");
        } else {
          first.removeAttribute("readonly");
          first.removeAttribute("disabled");
        }
      }
      if (firstRow) {
        firstRow.hidden = calculation.show_first_installment === false;
      }
      updateConfirmState();
    }

    function resolveFirstInstallmentAmount(scheme) {
      const first = firstInput();
      const locked = !!(
        lastCalculation && lastCalculation.first_installment_locked
      );
      if (
        locked &&
        lastCalculation &&
        lastCalculation.first_installment != null
      ) {
        return (
          parseFloat(
            String(lastCalculation.first_installment).replace(",", "."),
          ) || 0
        );
      }
      if (first) {
        return parseFloat(String(first.value).replace(",", ".")) || 0;
      }
      if (lastCalculation && lastCalculation.first_installment != null) {
        return (
          parseFloat(
            String(lastCalculation.first_installment).replace(",", "."),
          ) || 0
        );
      }
      return scheme.first_installment || 0;
    }

    function buildSelectionPayload(scheme) {
      syncOfferTypeFromScheme(scheme);
      return {
        csrf_token: state.csrf_token,
        cart_fingerprint:
          cartFingerprint || state.calculator?.cart_fingerprint || "",
        // 'standard' allows both scheme types server-side; scheme_type carries the concrete type.
        popup_offer_type: "standard",
        scheme_type: scheme.scheme_type,
        kop_code: scheme.kop_code,
        months: scheme.months,
        filter_id: scheme.filter_id,
        scheme_key: scheme.key,
        first_installment: resolveFirstInstallmentAmount(scheme),
        submission_token: submissionToken,
      };
    }

    async function postJson(url, payload, options) {
      const opts = options || {};
      const body = new FormData();
      Object.entries(payload).forEach(([key, value]) => {
        if (Array.isArray(value)) {
          value.forEach((item) => body.append(`${key}[]`, item));
          return;
        }
        body.append(key, value == null ? "" : String(value));
      });

      const fetchOptions = {
        method: "POST",
        body,
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      };
      if (opts.signal) {
        fetchOptions.signal = opts.signal;
      }

      const response = await fetch(url, fetchOptions);
      return response.json();
    }

    async function issueSubmission() {
      const scheme = selectedScheme();
      if (!scheme || calcBusy) {
        return;
      }
      if (issueFlight) {
        issueFlight.abort();
      }
      issueFlight = new AbortController();
      calcBusy = true;
      if (popupErrorEl()) {
        popupErrorEl().textContent = "";
      }
      try {
        const json = await postJson(
          state.issue_url,
          buildSelectionPayload(scheme),
          {
            signal: issueFlight.signal,
          },
        );
        if (json.success) {
          submissionToken = json.submission_token || submissionToken;
          renderCalculation(json.calculation || null);
        } else if (popupErrorEl()) {
          popupErrorEl().textContent =
            json.message ||
            (state.i18n && state.i18n.order_changed) ||
            "Неуспешно изчисление.";
          if (
            json.error_code === "checkout_order_changed" ||
            json.error_code === "checkout_order_missing"
          ) {
            resetOfferState();
          }
          updateConfirmState();
        }
      } catch (error) {
        if (error.name !== "AbortError" && popupErrorEl()) {
          popupErrorEl().textContent = "Неуспешно изчисление.";
        }
      } finally {
        calcBusy = false;
        issueFlight = null;
      }
    }

    async function confirmPayment(event) {
      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }
      if (confirmBusy) {
        return;
      }
      const scheme = selectedScheme();
      const button = submitBtn();
      if (!scheme || !button || !hasAuthoritativeCalculation()) {
        if (submitErrorEl()) {
          submitErrorEl().textContent =
            "Моля, изберете схема и изчакайте изчислението преди потвърждение.";
        }
        return;
      }
      if (!areMandatoryConsentsChecked()) {
        if (submitErrorEl()) {
          submitErrorEl().textContent =
            "Моля, приемете всички задължителни съгласия.";
        }
        return;
      }

      const process2Errors = getProcess2FieldErrors();
      if (Object.keys(process2Errors).length) {
        clearProcess2FieldErrors();
        showFieldErrors(process2Errors);
        if (submitErrorEl()) {
          submitErrorEl().textContent = "Моля, коригирайте данните.";
        }
        updateConfirmState();
        return;
      }
      clearProcess2FieldErrors();

      confirmBusy = true;
      redirectTerminal = false;
      button.setAttribute("aria-busy", "true");
      button.disabled = true;
      setProcessing(true);
      if (submitErrorEl()) {
        submitErrorEl().textContent = "";
      }

      try {
        const payload = buildSelectionPayload(scheme);
        payload.submission_token = submissionToken;
        if (form) {
          const formData = new FormData(form);
          formData.forEach((value, key) => {
            if (key === "consent[]") {
              return;
            }
            payload[key] = value;
          });
          form
            .querySelectorAll('input[name="consent[]"]:checked')
            .forEach((input, index) => {
              payload[`consent[${index}]`] = input.value;
            });
        }

        // Confirm must not share abort with issue/calculate.
        const json = await postJson(state.confirm_url, payload, {});
        if (
          window.MtUniCreditRedirect &&
          window.MtUniCreditRedirect.navigateTerminalThankYou(json.redirect_url)
        ) {
          redirectTerminal = true;
          return;
        }
        if (json.success) {
          const success = root.querySelector("[data-mtuc-success]");
          const successMessage = root.querySelector(
            "[data-mtuc-success-message]",
          );
          if (successMessage && json.message) {
            successMessage.textContent = json.message;
          }
          if (success) {
            success.hidden = false;
          }
          if (json.step === "process2_prepared" && json.redirect_url) {
            redirectTerminal = true;
            window.location.assign(json.redirect_url);
            return;
          }
          if (json.redirect_url) {
            if (
              window.MtUniCreditRedirect &&
              window.MtUniCreditRedirect.navigateIfTrusted(json.redirect_url)
            ) {
              redirectTerminal = true;
              return;
            }
            if (submitErrorEl()) {
              submitErrorEl().textContent =
                "Заявката не може да бъде обработена.";
            }
            updateConfirmState();
          } else if (json.redirect) {
            // Non-bank success page (e.g. Process 2 / CP-only): keep loader until unload.
            redirectTerminal = true;
            window.location.assign(json.redirect);
            return;
          }
        } else {
          if (
            json.error_code === "checkout_order_changed" ||
            json.error_code === "checkout_order_missing"
          ) {
            resetOfferState();
          }
          if (submitErrorEl()) {
            submitErrorEl().textContent =
              json.message || "Заявката не може да бъде обработена.";
          }
          if (json.errors) {
            showFieldErrors(json.errors);
          }
          updateConfirmState();
        }
      } catch (error) {
        if (submitErrorEl()) {
          submitErrorEl().textContent = "Заявката не може да бъде обработена.";
        }
        updateConfirmState();
      } finally {
        if (!redirectTerminal) {
          button.setAttribute("aria-busy", "false");
          confirmBusy = false;
          setProcessing(false);
        }
      }
    }

    root.addEventListener("click", (event) => {
      const submit = event.target.closest(
        "#button-confirm, [data-mtuc-submit]",
      );
      if (submit && root.contains(submit)) {
        event.preventDefault();
        confirmPayment(event);
      }
    });

    schemeSelect()?.addEventListener("change", () => {
      selectedSchemeKey = schemeSelect().value;
      syncOfferTypeFromScheme(selectedScheme());
      resetFirstInstallmentForSchemeChange();
      issueSubmission();
    });

    firstInput()?.addEventListener("input", () => {
      if (firstInstallmentTimer) {
        clearTimeout(firstInstallmentTimer);
      }
      firstInstallmentTimer = setTimeout(() => issueSubmission(), 400);
    });

    root.querySelectorAll("[data-mtuc-consent-checkbox]").forEach((box) => {
      box.addEventListener("change", () => updateConfirmState());
    });

    const process2Form = root.querySelector("[data-mtuc-customer-form]");
    if (process2Form) {
      process2Form.addEventListener("input", (event) => {
        const target = event.target;
        if (!target || !target.getAttribute) {
          return;
        }
        const name = target.getAttribute("name") || "";
        if (name === "phone2") {
          const sanitized = sanitizePhone2Value(target.value);
          if (target.value !== sanitized) {
            target.value = sanitized;
          }
        }
        if (name === "egn" || name === "phone2") {
          updateConfirmState();
        }
      });
    }

    selectedSchemeKey = resolvePreferredSchemeKey();
    populateSchemeSelect();
    updateConfirmState();
    issueSubmission();
  }

  function scan() {
    const root = document.getElementById(ROOT_ID);
    if (root) {
      initRoot(root);
    }
  }

  function observeCheckoutPayment() {
    // #checkout-payment is replaced wholesale by confirm AJAX; observe a stable ancestor.
    const docEl = document.documentElement;
    if (docEl.dataset.mtucCheckoutObserved === "1") {
      return;
    }
    const target = document.getElementById("checkout-confirm") || document.body;
    if (!target || typeof MutationObserver === "undefined") {
      return;
    }
    docEl.dataset.mtucCheckoutObserved = "1";
    const observer = new MutationObserver(() => {
      const root = document.getElementById(ROOT_ID);
      if (root && root.dataset.mtucBound !== "1") {
        initRoot(root);
      }
    });
    observer.observe(target, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      scan();
      observeCheckoutPayment();
    });
  } else {
    scan();
    observeCheckoutPayment();
  }
})();
