(() => {
  "use strict";

  const ROOT_ID = "mt-uni-credit-product-root";
  const MODAL_ID = "mt-uni-credit-product-modal";
  const BOOTSTRAP_ID = "mt-uni-credit-bootstrap";
  const TRIGGER_SELECTOR =
    ".mt-uni-credit-product-calculator__button[data-offer-type]";
  const MTUC_TRACE_BUILD = "09E-dd3c0d8-trace1";

  function mtucTraceEnabled() {
    try {
      return /[?&]mtuc_trace=1(?:&|$)/.test(
        String(window.location.search || ""),
      );
    } catch (e) {
      return false;
    }
  }

  function init() {
    const bootstrapEl = document.getElementById(BOOTSTRAP_ID);
    const root = document.getElementById(ROOT_ID);
    if (!bootstrapEl || !root) {
      return;
    }
    if (root.dataset.mtucBound === "1") {
      return;
    }
    root.dataset.mtucBound = "1";

    if (mtucTraceEnabled()) {
      window.__MTUC_PRODUCT_BUILD = MTUC_TRACE_BUILD;
      window.__MTUC_TRACE_ON = true;
    }
    let state;
    try {
      state = JSON.parse(bootstrapEl.textContent || "{}");
    } catch (error) {
      return;
    }

    let modal = document.getElementById(MODAL_ID);
    if (!modal) {
      return;
    }

    const calculator = root.querySelector(
      ".mt-uni-credit-product-calculator__calculator",
    );
    const focusableSelector =
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    /**
     * Live financing form — never cache at init.
     * Nested inside #form-product the HTML5 parser drops the inner <form>, so
     * an init-time getElementById is permanently null even after modal→body move.
     */
    function activeProductFinancingForm() {
      return (
        modal.querySelector("#mt-uni-credit-product-form") ||
        document.getElementById("mt-uni-credit-product-form")
      );
    }

    let sequence = 0;
    let abortController = null;
    let lastTrigger = null;
    let selectedOfferType =
      Object.keys(state.calculator?.offers || {})[0] || "standard";
    let selectedSchemeKey =
      state.calculator?.offers?.[selectedOfferType]?.preferred_scheme_key || "";
    let submissionToken = "";
    let lastCalculation = null;
    let currentStep = 1;
    let calcBusy = false;
    let submitBusy = false;
    let redirectTerminal = false;
    let firstInstallmentTimer = null;
    let issueFlight = null;
    let modalHomeParent = modal.parentElement;
    let modalHomeNext = modal.nextSibling;
    let awaitingNativeCartAdd = false;

    applyRootLayoutFromData(root, state);

    function applyRootLayoutFromData(element, bootstrap) {
      const width = element.getAttribute("data-mtuc-button-width");
      const height = element.getAttribute("data-mtuc-button-height");
      const topSpacing =
        element.getAttribute("data-mtuc-top-spacing") ||
        (bootstrap.button_top_spacing > 0
          ? String(bootstrap.button_top_spacing)
          : "");
      if (width) {
        element.style.setProperty("--mtuc-button-width", `${width}px`);
      }
      if (height) {
        element.style.setProperty("--mtuc-button-height", `${height}px`);
      }
      if (topSpacing) {
        element.style.marginTop = `${topSpacing}px`;
      }
    }

    /**
     * OpenCart 4.1 product controls live under #product > #form-product.
     * Jet (mt_jet_credit) binds [name=quantity] + [id^=input-option] — proven on OC4.1.0.3.
     */
    function productRootEl() {
      return (
        document.getElementById("product") ||
        document.getElementById("form-product")
      );
    }

    function productFormEl() {
      return (
        document.getElementById("form-product") ||
        document.getElementById("product")
      );
    }

    function isQuantityControl(element) {
      if (!element || !element.getAttribute) {
        return false;
      }
      return (
        element.id === "input-quantity" ||
        element.getAttribute("name") === "quantity"
      );
    }

    function isOptionControl(element) {
      if (!element || !element.getAttribute) {
        return false;
      }
      const id = element.id || "";
      const name = element.getAttribute("name") || "";
      // Jet contract: ids start with input-option (select, radio, checkbox, wrappers).
      if (id.indexOf("input-option") === 0) {
        return true;
      }
      // Name contract from OC4 product.twig: option[product_option_id] / option[id][]
      return name.indexOf("option[") === 0;
    }

    /** Popup/root UniCredit controls must never trigger native Product calculator refresh. */
    function isInsideUniCreditUi(element) {
      if (!element || typeof element.closest !== "function") {
        return false;
      }
      return !!(
        element.closest("#mt-uni-credit-product-modal") ||
        element.closest("#mt-uni-credit-product-root") ||
        (modal && modal.contains(element)) ||
        (root && root.contains(element))
      );
    }

    function recalcTriggerReason(element) {
      return isQuantityControl(element) ? "quantity change" : "option change";
    }

    function productOptions() {
      const options = {};
      const root = productRootEl();
      if (!root) {
        return options;
      }
      // Jet-parity field set under #product / #form-product.
      const fields = root.querySelectorAll(
        "input[type='text'][name], input[type='hidden'][name], input[type='radio'][name]:checked, input[type='checkbox'][name]:checked, input[type='date'][name], input[type='time'][name], input[type='datetime-local'][name], select[name], textarea[name]",
      );
      fields.forEach((element) => {
        const name = element.getAttribute("name") || "";
        const match = name.match(/^option\[(\d+)](\[\])?$/);
        if (!match) {
          return;
        }
        const id = match[1];
        if (element.type === "checkbox" || match[2] === "[]") {
          options[id] = options[id] || [];
          if (element.type === "checkbox" && !element.checked) {
            return;
          }
          if (String(element.value) !== "") {
            options[id].push(element.value);
          }
          return;
        }
        if (element.type === "radio" && !element.checked) {
          return;
        }
        if (String(element.value) !== "") {
          options[id] = element.value;
        }
      });
      return options;
    }

    function requiredOptionsMessage() {
      return (
        (state.i18n && state.i18n.error_required_options) ||
        "Моля, изберете задължителните опции на продукта."
      );
    }

    /**
     * OpenCart product.twig marks required options with .mb-3.required (and quantity).
     * Detect from live Product DOM — never hardcode product_option_id.
     * @returns {Element|null} first incomplete required option block
     */
    function firstMissingRequiredOptionBlock() {
      const form = productFormEl();
      if (!form) {
        return null;
      }
      const blocks = form.querySelectorAll(".mb-3.required, .required");
      for (let i = 0; i < blocks.length; i += 1) {
        const block = blocks[i];
        const candidates = block.querySelectorAll("select, textarea, input");
        const optionFields = [];
        candidates.forEach((field) => {
          const name = field.getAttribute("name") || "";
          if (name.indexOf("option[") === 0) {
            optionFields.push(field);
          }
        });
        if (optionFields.length === 0) {
          continue;
        }
        const first = optionFields[0];
        const name = first.getAttribute("name") || "";
        const type = (
          first.getAttribute("type") ||
          first.tagName ||
          ""
        ).toLowerCase();
        if (
          type === "radio" ||
          name.indexOf("[]") !== -1 ||
          type === "checkbox"
        ) {
          let checked = 0;
          optionFields.forEach((field) => {
            if (
              (field.type === "radio" || field.type === "checkbox") &&
              field.checked
            ) {
              checked += 1;
            }
          });
          if (checked === 0) {
            return block;
          }
          continue;
        }
        if (type === "file") {
          let fileValue = "";
          optionFields.forEach((field) => {
            if (
              field.type === "hidden" &&
              String(field.value || "").trim() !== ""
            ) {
              fileValue = String(field.value || "").trim();
            }
          });
          if (fileValue === "") {
            return block;
          }
          continue;
        }
        let filled = false;
        optionFields.forEach((field) => {
          if (field.type === "hidden") {
            return;
          }
          if (String(field.value || "").trim() !== "") {
            filled = true;
          }
        });
        if (!filled) {
          return block;
        }
      }
      return null;
    }

    function requiredProductOptionsSatisfied() {
      return firstMissingRequiredOptionBlock() === null;
    }

    function entryErrorEl() {
      return root.querySelector("[data-mtuc-entry-error]");
    }

    function showEntryError(message) {
      const el = entryErrorEl();
      if (!el) {
        return;
      }
      el.textContent = message;
      el.hidden = false;
    }

    function clearEntryError() {
      const el = entryErrorEl();
      if (!el) {
        return;
      }
      el.textContent = "";
      el.hidden = true;
    }

    function focusFirstMissingRequiredOption() {
      const block = firstMissingRequiredOptionBlock();
      if (!block) {
        return;
      }
      if (typeof block.scrollIntoView === "function") {
        block.scrollIntoView({ behavior: "smooth", block: "center" });
      }
      const candidates = block.querySelectorAll("select, textarea, input");
      for (let i = 0; i < candidates.length; i += 1) {
        const field = candidates[i];
        const name = field.getAttribute("name") || "";
        if (name.indexOf("option[") !== 0) {
          continue;
        }
        if (field.type === "hidden") {
          continue;
        }
        if (typeof field.focus === "function") {
          field.focus();
        }
        break;
      }
    }

    function isMissingRequiredOptionError(json) {
      if (!json || json.success) {
        return false;
      }
      if (json.error_code === "missing_required_option") {
        return true;
      }
      const message = String(json.message || "");
      return (
        message.indexOf("задължителните опции") !== -1 ||
        message.indexOf("Missing required product option") !== -1 ||
        message.indexOf("Invalid product option") !== -1 ||
        message.indexOf("required product option") !== -1
      );
    }

    function handleMissingRequiredOptions() {
      submissionToken = "";
      lastCalculation = null;
      showEntryError(requiredOptionsMessage());
      if (modal && !modal.hidden) {
        closeModal();
      }
      focusFirstMissingRequiredOption();
    }

    /**
     * Woo/PS: scheme change clears first installment before server recalculation
     * so the previous scheme's value cannot leak into the new selection payload.
     */
    function resetFirstInstallmentForSchemeChange() {
      lastCalculation = null;
      submissionToken = "";
      const first = firstInput();
      if (first) {
        first.value = "0";
        first.removeAttribute("readonly");
        first.removeAttribute("disabled");
      }
      const firstRow = modal.querySelector("[data-mtuc-first-row]");
      if (firstRow) {
        firstRow.hidden = false;
      }
      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute("aria-disabled", "true");
      }
      const submit = submitBtn();
      if (submit) {
        submit.disabled = true;
        submit.setAttribute("aria-disabled", "true");
      }
      if (popupErrorEl()) {
        popupErrorEl().textContent = "";
      }
    }

    function quantityValue() {
      const qty = document.querySelector(
        '#input-quantity, input[name="quantity"]',
      );
      const parsed = qty ? parseInt(qty.value, 10) : 1;
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 1;
    }

    function bindProductRecalculationListeners() {
      // Proven Jet pattern: direct listeners on quantity + input-option* controls.
      const quantityNodes = document.querySelectorAll(
        '#input-quantity, input[name="quantity"]',
      );
      quantityNodes.forEach((node, index) => {
        if (index > 0) {
          return;
        }
        node.addEventListener("change", () => {
          if (isInsideUniCreditUi(node)) {
            return;
          }
          scheduleRefreshCalculator("quantity change");
        });
        node.addEventListener("input", () => {
          if (isInsideUniCreditUi(node)) {
            return;
          }
          scheduleRefreshCalculator("quantity change");
        });
      });

      const optionNodes = document.querySelectorAll('[id^="input-option"]');
      optionNodes.forEach((node) => {
        node.addEventListener("change", (event) => {
          const target = event.target;
          if (isInsideUniCreditUi(target) || isInsideUniCreditUi(node)) {
            return;
          }
          if (target && isOptionControl(target)) {
            scheduleRefreshCalculator("option change");
            return;
          }
          // Wrapper div (#input-option-N) receives bubbled radio/checkbox change.
          if (node !== target && isOptionControl(node)) {
            scheduleRefreshCalculator("option change");
          }
        });
      });

      // Document-level backup (survives late DOM quirks; Jet-equivalent selectors).
      document.addEventListener("change", (event) => {
        const target = event.target;
        if (!target) {
          return;
        }
        // Scheme/first-installment/customer/consent live in the UniCredit modal — never refresh Product.
        if (isInsideUniCreditUi(target)) {
          return;
        }
        if (isQuantityControl(target)) {
          scheduleRefreshCalculator("quantity change");
          return;
        }
        if (isOptionControl(target)) {
          if (requiredProductOptionsSatisfied()) {
            clearEntryError();
          }
          scheduleRefreshCalculator("option change");
        }
      });
    }

    function syncBootstrap() {
      const bootstrapEl = document.getElementById(BOOTSTRAP_ID);
      if (!bootstrapEl) {
        return;
      }
      bootstrapEl.textContent = JSON.stringify({
        product_id: state.product_id,
        calculator: state.calculator,
        modal: state.modal,
        product_button_action: state.product_button_action,
        checkout_url: state.checkout_url,
        logo_standard_url: state.logo_standard_url,
        logo_alternative_url: state.logo_alternative_url,
        badge_url: state.badge_url,
        calculate_url: state.calculate_url,
        issue_url: state.issue_url,
        submit_url: state.submit_url,
        csrf_token: state.csrf_token,
        button_top_spacing: state.button_top_spacing,
      });
    }

    function selectedOffer() {
      return state.calculator?.offers?.[selectedOfferType] || null;
    }

    /** Search all offer buckets — DOM select value is authoritative across standard/promo. */
    function findSchemeAcrossOffers(key) {
      const want = String(key || "");
      if (!want) {
        return null;
      }
      const offers = state.calculator?.offers || {};
      for (const type of Object.keys(offers)) {
        const schemes = offers[type]?.schemes || [];
        for (let i = 0; i < schemes.length; i += 1) {
          if (schemes[i] && schemes[i].key === want) {
            return schemes[i];
          }
        }
      }
      return null;
    }

    function selectedScheme() {
      const byKey = findSchemeAcrossOffers(selectedSchemeKey);
      if (byKey) {
        return byKey;
      }
      const offer = selectedOffer();
      if (!offer) {
        return null;
      }
      const schemes = offer.schemes || [];
      return schemes[0] || null;
    }

    function schemeSelect() {
      return modal.querySelector("[data-mtuc-schemes]");
    }

    function firstInput() {
      return modal.querySelector("[data-mtuc-first]");
    }

    function applyBtn() {
      return modal.querySelector("[data-mtuc-apply]");
    }

    function submitBtn() {
      return modal.querySelector("[data-mtuc-submit]");
    }

    function processingEl() {
      return modal.querySelector("[data-mtuc-processing]");
    }

    function dialogEl() {
      return modal.querySelector(".mt-uni-credit-product-calculator__dialog");
    }

    function stepEl(step) {
      return modal.querySelector(`[data-mtuc-step="${step}"]`);
    }

    function displayValue(name) {
      return modal.querySelector(`[data-mtuc-display="${name}"]`);
    }

    function popupErrorEl() {
      return modal.querySelector("[data-mtuc-popup-error]");
    }

    function fieldError(name) {
      return modal.querySelector(`[data-mtuc-field-error="${name}"]`);
    }

    function clearFieldErrors() {
      modal.querySelectorAll("[data-mtuc-field-error]").forEach((el) => {
        el.textContent = "";
      });
      const submitError = modal.querySelector("[data-mtuc-submit-error]");
      if (submitError) {
        submitError.textContent = "";
      }
      if (popupErrorEl()) {
        popupErrorEl().textContent = "";
      }
      const financingForm = activeProductFinancingForm();
      if (financingForm) {
        financingForm
          .querySelectorAll(".mt-uni-credit-product-calculator__customer-input")
          .forEach((input) => {
            input.setAttribute("aria-invalid", "false");
          });
      }
    }

    function showFieldErrors(errors) {
      if (!errors || typeof errors !== "object") {
        return;
      }
      Object.entries(errors).forEach(([key, message]) => {
        const aliases = {
          firstname: "firstname",
          first_name: "firstname",
          lastname: "lastname",
          last_name: "lastname",
          telephone: "phone",
          phone: "phone",
          address_1: "address",
          address: "address",
          email: "email",
          consents: "consent",
        };
        const field = aliases[key] || key;
        const target = fieldError(field) || fieldError(key);
        if (target) {
          target.textContent = String(message);
        }
        const input = customerField(field);
        if (input) {
          input.setAttribute("aria-invalid", message ? "true" : "false");
        }
      });
    }

    const PHONE_VALID_PATTERN = /^[-0-9+() ]+$/;
    const PHONE_ALLOWED_PATTERN = /[-0-9+() ]/;
    const EMAIL_VALID_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function step2Root() {
      return modal.querySelector('[data-mtuc-step="2"]');
    }

    function customerField(name) {
      const scope = activeProductFinancingForm() || step2Root() || modal;
      return scope ? scope.querySelector(`[name="${name}"]`) : null;
    }

    function isNonEmpty(value) {
      return String(value || "").trim() !== "";
    }

    function sanitizePhoneValue(value) {
      return String(value || "")
        .split("")
        .filter((char) => PHONE_ALLOWED_PATTERN.test(char))
        .join("");
    }

    function isValidPhone(value) {
      const phone = String(value || "").trim();
      return (
        phone !== "" && PHONE_VALID_PATTERN.test(phone) && /\d/.test(phone)
      );
    }

    function isValidEmail(value) {
      const email = String(value || "").trim();
      return email !== "" && EMAIL_VALID_PATTERN.test(email);
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

    function consentCheckboxes() {
      const scope = activeProductFinancingForm() || step2Root() || modal;
      if (!scope) {
        return [];
      }
      return scope.querySelectorAll("[data-mtuc-consent-checkbox]");
    }

    function areMandatoryConsentsChecked() {
      const boxes = consentCheckboxes();
      if (!boxes.length) {
        return true;
      }
      for (let i = 0; i < boxes.length; i += 1) {
        if (!boxes[i].checked) {
          return false;
        }
      }
      return true;
    }

    function hasAuthoritativeCalculation() {
      // Step 2 readiness ≡ submit-ready financing context (calculation + token + live scheme).
      return !!(lastCalculation && submissionToken && selectedScheme());
    }

    function syncSelectedSchemeFromDom() {
      const select = schemeSelect();
      if (select && select.value) {
        selectedSchemeKey = select.value;
      }
      // DOM value is customer intent — never overwrite with another offer's schemes[0].
      let scheme = findSchemeAcrossOffers(selectedSchemeKey);
      if (scheme) {
        if (scheme.scheme_type) {
          selectedOfferType = scheme.scheme_type;
        }
        selectedSchemeKey = scheme.key;
        if (select && select.value !== scheme.key) {
          select.value = scheme.key;
        }
        return scheme;
      }
      if (!selectedOffer()) {
        const offerTypes = Object.keys(state.calculator?.offers || {});
        if (
          offerTypes.length > 0 &&
          offerTypes.indexOf(selectedOfferType) === -1
        ) {
          selectedOfferType = offerTypes[0];
        }
      }
      scheme = selectedScheme();
      if (scheme) {
        selectedSchemeKey = scheme.key;
        if (select && select.value !== scheme.key) {
          select.value = scheme.key;
        }
      }
      return scheme;
    }

    function invalidateIssuedSelection(reason) {
      submissionToken = "";
      lastCalculation = null;
      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute("aria-disabled", "true");
      }
      const submit = submitBtn();
      if (submit) {
        submit.disabled = true;
        submit.setAttribute("aria-disabled", "true");
        submit.classList.add("is-disabled");
      }
      if (!modal.hidden && currentStep === 2) {
        updateSubmitState(false);
        const submitError = modal.querySelector("[data-mtuc-submit-error]");
        if (submitError && reason) {
          submitError.textContent = reason;
        }
      }
    }

    /**
     * Process / DOM aware readiness:
     * - Process 1: only rendered base fields (no EGN / phone2).
     * - Process 2: validate egn/phone2 only when those inputs exist in the DOM.
     */
    function getStep2FieldErrors() {
      const errors = {};
      if (!isNonEmpty(customerField("firstname")?.value)) {
        errors.firstname = "Полето е задължително.";
      }
      if (!isNonEmpty(customerField("lastname")?.value)) {
        errors.lastname = "Полето е задължително.";
      }
      if (!isNonEmpty(customerField("address")?.value)) {
        errors.address = "Полето е задължително.";
      }
      const phone = customerField("phone")?.value;
      if (!isNonEmpty(phone)) {
        errors.phone = "Полето е задължително.";
      } else if (!isValidPhone(phone)) {
        errors.phone = "Въведете валиден телефонен номер.";
      }
      const email = customerField("email")?.value;
      if (!isNonEmpty(email)) {
        errors.email = "Полето е задължително.";
      } else if (!isValidEmail(email)) {
        errors.email = "Въведете валиден e-mail адрес.";
      }

      // Process 2 only when rendered (never require hidden Process 2 fields in Process 1).
      const egnField = customerField("egn");
      if (egnField) {
        const egn = String(egnField.value || "").replace(/\D/g, "");
        if (egn === "") {
          errors.egn = "Полето е задължително.";
        } else if (!isValidEgn(egn)) {
          errors.egn =
            "ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.";
        }
      }
      const phone2Field = customerField("phone2");
      if (phone2Field) {
        const phone2 = phone2Field.value;
        if (!isNonEmpty(phone2)) {
          errors.phone2 = "Полето е задължително.";
        } else if (!isValidPhone(phone2)) {
          errors.phone2 = "Въведете валиден телефонен номер.";
        }
      }
      return errors;
    }

    function isStep2FormValid() {
      const errors = getStep2FieldErrors();
      const fieldsOk = Object.keys(errors).length === 0;
      return (
        fieldsOk &&
        areMandatoryConsentsChecked() &&
        hasAuthoritativeCalculation()
      );
    }

    function updateSubmitState(showErrors) {
      const errors = getStep2FieldErrors();
      if (showErrors) {
        showFieldErrors(errors);
      }
      const valid = isStep2FormValid();
      const submit = submitBtn();
      if (submit) {
        submit.disabled = !valid;
        submit.setAttribute("aria-disabled", valid ? "false" : "true");
        submit.classList.toggle("is-disabled", !valid);
      }
      return valid;
    }

    function bindStep2ReadinessListeners() {
      const scope = activeProductFinancingForm() || step2Root();
      if (!scope) {
        return;
      }
      // PS/Woo: any Step 2 field input/change re-evaluates readiness (incl. prefilled values on edit).
      scope.addEventListener("input", (event) => {
        const target = event.target;
        if (!target || !target.getAttribute) {
          return;
        }
        const name = target.getAttribute("name") || "";
        if (name === "phone" || name === "phone2") {
          const sanitized = sanitizePhoneValue(target.value);
          if (target.value !== sanitized) {
            target.value = sanitized;
          }
        }
        if (name || target.matches("[data-mtuc-consent-checkbox]")) {
          updateSubmitState(false);
        }
      });
      scope.addEventListener("change", (event) => {
        const target = event.target;
        if (!target || !target.getAttribute) {
          return;
        }
        if (
          target.getAttribute("name") ||
          target.matches("[data-mtuc-consent-checkbox]")
        ) {
          updateSubmitState(false);
        }
      });
      scope.addEventListener("mousedown", (event) => {
        const consentLink = event.target.closest(
          ".mt-uni-credit-product-calculator__consent-label a",
        );
        if (consentLink) {
          event.stopPropagation();
        }
      });
    }

    function setProcessing(active) {
      const panel = processingEl();
      if (panel) {
        panel.hidden = !active;
      }
      if (dialogEl()) {
        dialogEl().style.opacity = active ? "0.45" : "";
        dialogEl().style.pointerEvents = active ? "none" : "";
      }
    }

    function setStep(step) {
      currentStep = step;
      modal.querySelectorAll("[data-mtuc-step]").forEach((el) => {
        const stepNum = parseInt(el.getAttribute("data-mtuc-step"), 10);
        const active = stepNum === step;
        el.hidden = !active;
        el.classList.toggle(
          "mt-uni-credit-product-calculator__step--active",
          active,
        );
      });
    }

    function populateSchemeSelect() {
      const select = schemeSelect();
      const offer = selectedOffer();
      if (!select || !offer) {
        return;
      }
      select.replaceChildren();
      (offer.schemes || []).forEach((scheme) => {
        const option = document.createElement("option");
        option.value = scheme.key;
        // Woo/PS contract: "{months} месеца" or "{months} месеца - {promo/description}".
        let label = `${scheme.months} месеца`;
        if (scheme.description) {
          label += ` - ${scheme.description}`;
        }
        // Trailing NBSPs: native <option> right-edge offset (Woo/PS parity).
        option.textContent = `${label}\u00A0\u00A0\u00A0`;
        select.appendChild(option);
      });
      if (selectedSchemeKey) {
        select.value = selectedSchemeKey;
      }
    }

    function renderCalculation(calculation) {
      if (!calculation) {
        return;
      }
      lastCalculation = calculation;
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
        const el = displayValue(key);
        if (el && value != null) {
          el.textContent = String(value);
        }
      });

      const firstRow = modal.querySelector("[data-mtuc-first-row]");
      const first = firstInput();
      if (first) {
        first.value = String(calculation.first_installment ?? 0);
        if (calculation.first_installment_locked) {
          first.setAttribute("readonly", "readonly");
          // Keep enabled so value remains reliably readable for submit payload (readonly is enough UX lock).
          first.removeAttribute("disabled");
        } else {
          first.removeAttribute("readonly");
          first.removeAttribute("disabled");
        }
      }
      if (firstRow) {
        firstRow.hidden = calculation.show_first_installment === false;
      }

      const apply = applyBtn();
      if (apply) {
        apply.disabled = false;
        apply.setAttribute("aria-disabled", "false");
      }
      if (currentStep === 2) {
        updateSubmitState(false);
      }
    }

    function renderOfferButtons(data) {
      if (!calculator || !data || !data.offers) {
        return;
      }
      const offersWrap = calculator.querySelector(
        ".mt-uni-credit-product-calculator__buttons",
      );
      if (!offersWrap) {
        return;
      }
      const dark = !!data.dark_button;
      const logoUrl = dark
        ? state.logo_alternative_url || ""
        : state.logo_standard_url || "";
      const fragment = document.createDocumentFragment();
      Object.keys(data.offers).forEach((offerType) => {
        const offer = data.offers[offerType];
        const button = document.createElement("button");
        button.type = "button";
        button.className = `mt-uni-credit-product-calculator__button mt-uni-credit-product-calculator__button--${offerType}`;
        button.dataset.offerType = offerType;
        button.dataset.preferredKey = offer.preferred_scheme_key || "";
        button.setAttribute("aria-haspopup", "dialog");
        button.setAttribute("aria-controls", MODAL_ID);

        const content = document.createElement("span");
        content.className = "mt-uni-credit-product-calculator__button-content";
        const title = document.createElement("span");
        title.className = "mt-uni-credit-product-calculator__button-title";
        title.textContent = "Купи на изплащане";
        const price = document.createElement("span");
        price.className = "mt-uni-credit-product-calculator__button-price";
        price.textContent = offer.installment_label || "";
        content.appendChild(title);
        content.appendChild(price);
        button.appendChild(content);

        if (offerType === "promo") {
          const badge = document.createElement("span");
          badge.className = "mt-uni-credit-product-calculator__badge";
          badge.setAttribute("aria-hidden", "true");
          badge.textContent = "0%";
          button.appendChild(badge);
        } else {
          const logoWrap = document.createElement("span");
          logoWrap.className = "mt-uni-credit-product-calculator__logo";
          const img = document.createElement("img");
          img.src = logoUrl;
          img.alt = "UniCredit";
          logoWrap.appendChild(img);
          button.appendChild(logoWrap);
        }
        fragment.appendChild(button);
      });
      offersWrap.replaceChildren(fragment);
    }

    function renderCalculator(data) {
      if (!data || !calculator) {
        return;
      }
      state.calculator = data;
      if (!data.offers?.[selectedOfferType]) {
        selectedOfferType = Object.keys(data.offers || {})[0] || "standard";
      }
      const offerSchemes = data.offers?.[selectedOfferType]?.schemes || [];
      const previousKey = selectedSchemeKey;
      const previousStillValid =
        previousKey &&
        offerSchemes.some((scheme) => scheme && scheme.key === previousKey);
      const preferred =
        data.offers?.[selectedOfferType]?.preferred_scheme_key || "";
      if (previousStillValid) {
        selectedSchemeKey = previousKey;
      } else if (preferred) {
        selectedSchemeKey = preferred;
      }
      const scheme = selectedScheme();
      if (scheme) {
        selectedSchemeKey = scheme.key;
      }
      renderOfferButtons(data);
      applyRootLayoutFromData(root, state);
      // Calculator rebuild invalidates issued financing context. Step 2 must not stay submit-ready.
      invalidateIssuedSelection("");
      syncBootstrap();
      calculator.setAttribute("aria-busy", "false");
    }

    let refreshTimer = null;

    function scheduleRefreshCalculator(reason) {
      if (refreshTimer) {
        clearTimeout(refreshTimer);
      }
      refreshTimer = setTimeout(() => {
        refreshTimer = null;
        refreshCalculator(reason);
      }, 250);
    }

    async function postJson(url, payload, options) {
      const opts = options || {};
      const body = new FormData();
      Object.entries(payload).forEach(([key, value]) => {
        if (value && typeof value === "object" && !Array.isArray(value)) {
          Object.entries(value).forEach(([nestedKey, nestedValue]) => {
            if (Array.isArray(nestedValue)) {
              nestedValue.forEach((item) =>
                body.append(`${key}[${nestedKey}][]`, item),
              );
            } else {
              body.append(`${key}[${nestedKey}]`, nestedValue);
            }
          });
          return;
        }
        if (Array.isArray(value)) {
          value.forEach((item) => body.append(`${key}[]`, item));
          return;
        }
        body.append(key, value);
      });

      const fetchOptions = {
        method: "POST",
        body,
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      };
      // Submit must not share refresh abort signal — otherwise a Product AJAX refresh aborts Изпрати silently.
      if (opts.abort !== false && abortController) {
        fetchOptions.signal = abortController.signal;
      }

      const response = await fetch(url, fetchOptions);
      return response.json();
    }

    async function refreshCalculator(reason) {
      const currentSequence = ++sequence;
      if (abortController) {
        abortController.abort();
      }
      abortController = new AbortController();
      if (calculator) {
        calculator.setAttribute("aria-busy", "true");
      }
      const qty = quantityValue();
      const options = productOptions();
      try {
        const json = await postJson(state.calculate_url, {
          csrf_token: state.csrf_token,
          product_id: state.product_id,
          quantity: qty,
          option: options,
          sequence: currentSequence,
        });
        if (currentSequence !== sequence) {
          return;
        }
        if (
          !json.success &&
          (json.error_code === "missing_csrf" ||
            json.error_code === "invalid_csrf")
        ) {
        }
        if (json.success && json.calculator) {
          renderCalculator(json.calculator);
          if (!modal.hidden && (currentStep === 1 || currentStep === 2)) {
            populateSchemeSelect();
            setProcessing(currentStep === 2);
            try {
              await recalculateSelection({ force: true });
            } finally {
              if (currentStep === 2) {
                setProcessing(false);
              }
            }
            if (currentStep === 2) {
              updateSubmitState(false);
            }
          }
        } else if (calculator) {
          calculator.setAttribute("aria-busy", "false");
        }
      } catch (error) {
        if (error.name !== "AbortError" && calculator) {
          calculator.setAttribute("aria-busy", "false");
        }
      }
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
      return {
        csrf_token: state.csrf_token,
        product_id: state.product_id,
        quantity: quantityValue(),
        option: productOptions(),
        popup_offer_type: selectedOfferType,
        scheme_type: scheme.scheme_type,
        kop_code: scheme.kop_code,
        months: scheme.months,
        filter_id: scheme.filter_id,
        scheme_key: scheme.key,
        first_installment: resolveFirstInstallmentAmount(scheme),
        submission_token: submissionToken,
      };
    }

    /**
     * Issue/recalculate current scheme selection.
     * @param {{force?: boolean, abort?: boolean}} [options]
     * @returns {Promise<boolean>} true only when scheme + lastCalculation + submissionToken are present
     */
    async function recalculateSelection(options) {
      const opts = options || {};
      const allowAbort = opts.abort !== false;

      if (issueFlight) {
        const priorOk = await issueFlight;
        if (!opts.force && priorOk && hasAuthoritativeCalculation()) {
          return true;
        }
        if (!opts.force && hasAuthoritativeCalculation()) {
          return true;
        }
        // force=true (submit recovery): fall through and issue again after the in-flight request finishes.
      }

      const scheme = syncSelectedSchemeFromDom();
      if (!scheme) {
        return false;
      }

      let resolveFlight = null;
      issueFlight = new Promise((resolve) => {
        resolveFlight = resolve;
      });
      calcBusy = true;

      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute("aria-disabled", "true");
      }

      let ok = false;
      try {
        const json = await postJson(
          state.issue_url,
          buildSelectionPayload(scheme),
          { abort: allowAbort },
        );
        if (json.success) {
          const token = String(json.submission_token || "");
          if (!token) {
            ok = false;
          } else {
            submissionToken = token;
            renderCalculation(json.calculation || null);
            clearEntryError();
            ok = hasAuthoritativeCalculation();
          }
        } else if (isMissingRequiredOptionError(json)) {
          handleMissingRequiredOptions();
          ok = false;
        } else {
          if (popupErrorEl()) {
            popupErrorEl().textContent =
              json.message || "Неуспешно изчисление.";
          }
          ok = false;
        }
      } catch (error) {
        if (error && error.name !== "AbortError" && popupErrorEl()) {
          popupErrorEl().textContent =
            "Неуспешно изчисление. Моля, опитайте отново.";
        }
        ok = false;
      } finally {
        calcBusy = false;
        if (resolveFlight) {
          resolveFlight(ok);
        }
        issueFlight = null;
      }
      return ok;
    }

    function trapFocus(event) {
      if (!modal || modal.hidden) {
        return;
      }
      const focusables = [...modal.querySelectorAll(focusableSelector)].filter(
        (el) => !el.hasAttribute("disabled"),
      );
      if (focusables.length === 0) {
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.key === "Tab") {
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
      if (event.key === "Escape") {
        closeModal();
      }
    }

    function setBackgroundInert(inert) {
      document.querySelectorAll("body > *").forEach((element) => {
        if (element === modal || element.contains(modal)) {
          return;
        }
        if (inert) {
          element.setAttribute("aria-hidden", "true");
          element.setAttribute("inert", "");
        } else {
          element.removeAttribute("aria-hidden");
          element.removeAttribute("inert");
        }
      });
    }

    async function openModal(trigger) {
      lastTrigger = trigger || null;
      clearEntryError();
      resetFirstInstallmentForSchemeChange();
      if (modal.parentElement !== document.body) {
        modalHomeParent = modal.parentElement;
        modalHomeNext = modal.nextSibling;
        document.body.appendChild(modal);
      }
      modal.hidden = false;
      modal.removeAttribute("inert");
      modal.setAttribute("aria-hidden", "false");
      setBackgroundInert(true);
      document.addEventListener("keydown", trapFocus);
      setStep(1);
      clearFieldErrors();
      populateSchemeSelect();
      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute("aria-disabled", "true");
      }
      dialogEl()?.focus();
      await recalculateSelection();
    }

    function closeModal() {
      unbindNativeCartAddObserver();
      if (firstInstallmentTimer) {
        clearTimeout(firstInstallmentTimer);
        firstInstallmentTimer = null;
      }
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
      setBackgroundInert(false);
      document.removeEventListener("keydown", trapFocus);
      setProcessing(false);
      if (modalHomeParent && modal.parentElement === document.body) {
        if (modalHomeNext && modalHomeNext.parentElement === modalHomeParent) {
          modalHomeParent.insertBefore(modal, modalHomeNext);
        } else {
          modalHomeParent.appendChild(modal);
        }
      }
      if (lastTrigger && typeof lastTrigger.focus === "function") {
        lastTrigger.focus();
      }
    }

    function secondaryActionUsesNativeAddToCart() {
      return (state.product_button_action || "add_to_cart") !== "buy";
    }

    function isCheckoutCartAddUrl(url) {
      const normalized = String(url || "").replace(/&amp;/g, "&");
      return (
        normalized.indexOf("route=checkout/cart.add") !== -1 ||
        /\/checkout\/cart\.add(?:\?|$)/.test(normalized)
      );
    }

    function parseAjaxJson(xhr) {
      if (!xhr) {
        return null;
      }
      if (xhr.responseJSON && typeof xhr.responseJSON === "object") {
        return xhr.responseJSON;
      }
      try {
        return JSON.parse(xhr.responseText || "");
      } catch (error) {
        return null;
      }
    }

    function unbindNativeCartAddObserver() {
      const $ = window.jQuery;
      if ($ && typeof $.fn !== "undefined") {
        $(document).off("ajaxSuccess.mtUniCreditCart");
      }
      awaitingNativeCartAdd = false;
    }

    /**
     * Observe OpenCart 4.1 standard Product cart.add AJAX (product.twig).
     * Close UniCredit modal only when json.success is present — not on validation errors.
     */
    function bindNativeCartAddSuccessCloser() {
      const $ = window.jQuery;
      if (!$ || typeof $.fn === "undefined") {
        return false;
      }

      unbindNativeCartAddObserver();
      awaitingNativeCartAdd = true;

      const handleCartAjax = function (_event, xhr, settings) {
        if (!isCheckoutCartAddUrl(settings && settings.url)) {
          return;
        }
        unbindNativeCartAddObserver();
        const json = parseAjaxJson(xhr);
        if (json && json.success) {
          closeModal();
        }
        // json.error / missing success → keep modal open for OpenCart validation UX.
      };

      // OpenCart product.twig uses $.ajax success with json.success | json.error.
      $(document).on("ajaxSuccess.mtUniCreditCart", handleCartAjax);
      return true;
    }

    function buildBuyPreferencePayload(scheme) {
      const payload = {
        product_id: state.product_id,
        scheme_type: scheme.scheme_type || selectedOfferType || "standard",
        kop_code: scheme.kop_code,
        months: scheme.months,
        filter_id:
          scheme.filter_id == null || scheme.filter_id === ""
            ? 0
            : scheme.filter_id,
        scheme_key: scheme.key || "",
        first_installment: resolveFirstInstallmentAmount(scheme),
        csrf_token: state.csrf_token || "",
      };
      return payload;
    }

    async function stashBuyPreferenceAndGoCheckout(scheme) {
      const stashUrl = state.stash_buy_url || "";
      const fallbackCheckout =
        state.checkout_url || root.getAttribute("data-checkout-url") || "";
      if (!stashUrl) {
        if (fallbackCheckout) {
          window.location.assign(fallbackCheckout);
        }
        return;
      }
      try {
        const body = new URLSearchParams();
        const payload = buildBuyPreferencePayload(scheme);
        Object.keys(payload).forEach((key) => {
          body.append(key, String(payload[key] ?? ""));
        });
        if (mtucTraceEnabled()) {
          body.append("mtuc_trace", "1");
        }
        const response = await fetch(stashUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: body.toString(),
          credentials: "same-origin",
        });
        const json = await response.json().catch(() => null);
        if (mtucTraceEnabled() && json && json._mtuc_trace) {
          window.__MTUC_LAST_STASH_TRACE = json._mtuc_trace;
          window.__MTUC_PRODUCT_BUILD = MTUC_TRACE_BUILD;
        }
        const checkoutUrl =
          (json && json.checkout_url) || fallbackCheckout || "";
        if (json && json.success && checkoutUrl) {
          window.location.assign(checkoutUrl);
          return;
        }
        const message =
          (json && json.message) ||
          "Продуктът е в количката, но прехвърлянето към Checkout не бе завършено.";
        if (entryErrorEl()) {
          entryErrorEl().textContent = message;
        }
      } catch (error) {
        if (entryErrorEl()) {
          entryErrorEl().textContent =
            "Продуктът е в количката, но прехвърлянето към Checkout не бе завършено.";
        }
      }
    }

    /**
     * product_button_action=buy: native cart.add must succeed before Checkout redirect.
     */
    function bindBuyNativeCartAddThenCheckout(scheme) {
      const $ = window.jQuery;
      if (!$ || typeof $.fn === "undefined") {
        return false;
      }

      unbindNativeCartAddObserver();
      awaitingNativeCartAdd = true;

      const handleCartAjax = function (_event, xhr, settings) {
        if (!isCheckoutCartAddUrl(settings && settings.url)) {
          return;
        }
        unbindNativeCartAddObserver();
        const json = parseAjaxJson(xhr);
        if (json && json.success) {
          stashBuyPreferenceAndGoCheckout(scheme);
          return;
        }
        // Native option/stock validation — stay on Product; no preference / no redirect.
        awaitingNativeCartAdd = false;
      };

      $(document).on("ajaxSuccess.mtUniCreditCart", handleCartAjax);
      return true;
    }

    function triggerSecondaryAction() {
      if (secondaryActionUsesNativeAddToCart()) {
        if (awaitingNativeCartAdd) {
          return;
        }
        const cartBtn = document.querySelector("#button-cart");
        if (!cartBtn) {
          return;
        }
        bindNativeCartAddSuccessCloser();
        cartBtn.click();
        // Native #form-product submit handler owns the cart request; we only observe completion.
        return;
      }

      // Buy: native add-to-cart first, then stash preference + Checkout (never redirect on empty cart).
      if (awaitingNativeCartAdd) {
        return;
      }
      const scheme = syncSelectedSchemeFromDom();
      if (!scheme) {
        if (entryErrorEl()) {
          entryErrorEl().textContent = "Моля, изберете схема преди покупка.";
        }
        return;
      }
      const cartBtn = document.querySelector("#button-cart");
      if (!cartBtn) {
        if (entryErrorEl()) {
          entryErrorEl().textContent =
            "Бутонът за добавяне в количката не е наличен на страницата.";
        }
        return;
      }
      if (!bindBuyNativeCartAddThenCheckout(scheme)) {
        return;
      }
      cartBtn.click();
    }

    async function submitForm(event) {
      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }
      if (submitBusy) {
        return;
      }
      const activeForm = activeProductFinancingForm();
      const button = submitBtn();
      const submitError = modal.querySelector("[data-mtuc-submit-error]");
      const initialHasContext = hasAuthoritativeCalculation();
      syncSelectedSchemeFromDom();

      if (!initialHasContext) {
        setProcessing(true);
        try {
          populateSchemeSelect();
          syncSelectedSchemeFromDom();
          // force + no abort: must not silently return on calcBusy / refresh abort.
          const recovered = await recalculateSelection({
            force: true,
            abort: false,
          });
          if (!recovered) {
            updateSubmitState(false);
            if (submitError) {
              submitError.textContent =
                "Моля, изберете схема и изчакайте изчислението преди изпращане.";
            }
            return;
          }
        } finally {
          setProcessing(false);
        }
      }

      const scheme = syncSelectedSchemeFromDom();
      const schemePresent = !!scheme;
      const buttonPresent = !!(button && button.isConnected);
      const activeFormPresent = !!(activeForm && activeForm.isConnected);
      const finalHasContext = hasAuthoritativeCalculation();

      if (!schemePresent || !finalHasContext) {
        updateSubmitState(false);
        if (submitError) {
          // Internal reason: schemePresent ? 'submit_missing_context' : 'submit_missing_scheme'
          submitError.textContent =
            "Моля, изберете схема и изчакайте изчислението преди изпращане.";
        }
        return;
      }
      if (!buttonPresent || !activeFormPresent) {
        updateSubmitState(false);
        if (submitError) {
          // Internal reason: !activeFormPresent ? 'submit_missing_form' : 'submit_missing_button'
          submitError.textContent =
            "Заявката не може да бъде обработена. Моля, опитайте отново.";
        }
        return;
      }
      if (!updateSubmitState(true)) {
        return;
      }
      submitBusy = true;
      redirectTerminal = false;
      button.setAttribute("aria-busy", "true");
      button.disabled = true;
      clearFieldErrors();
      setProcessing(true);

      try {
        const payload = buildSelectionPayload(scheme);
        payload.submission_token = submissionToken;
        const formData = new FormData(activeForm);
        formData.forEach((value, key) => {
          payload[key] = value;
        });
        activeForm
          .querySelectorAll('input[name="consent[]"]:checked')
          .forEach((input, index) => {
            payload[`consent[${index}]`] = input.value;
          });

        const json = await postJson(state.submit_url, payload, {
          abort: false,
        });
        if (
          window.MtUniCreditRedirect &&
          window.MtUniCreditRedirect.navigateTerminalThankYou(json.redirect_url)
        ) {
          redirectTerminal = true;
          return;
        }
        if (json.success) {
          if (json.step === "process2_prepared" && json.redirect_url) {
            redirectTerminal = true;
            window.location.assign(json.redirect_url);
            return;
          }
          if (
            json.redirect_url &&
            window.MtUniCreditRedirect &&
            window.MtUniCreditRedirect.navigateIfTrusted(json.redirect_url)
          ) {
            // Terminal: keep loader until navigation unloads the page.
            redirectTerminal = true;
            return;
          }
          const successMessage = modal.querySelector(
            "[data-mtuc-success-message]",
          );
          if (successMessage) {
            successMessage.textContent =
              json.message ||
              "Локалната поръчка е подготвена. Следващата стъпка ще бъде финансирането.";
          }
          setStep(3);
        } else {
          showFieldErrors(json.errors || {});
          if (submitError && json.message) {
            submitError.textContent = json.message;
          } else if (submitError && !json.message) {
            submitError.textContent = "Заявката не може да бъде обработена.";
          }
          updateSubmitState(false);
        }
      } catch (error) {
        if (submitError) {
          submitError.textContent = "Заявката не може да бъде обработена.";
        }
        updateSubmitState(false);
      } finally {
        if (!redirectTerminal) {
          button.setAttribute("aria-busy", "false");
          submitBusy = false;
          setProcessing(false);
        }
      }
    }

    root.addEventListener("click", (event) => {
      const trigger = event.target.closest(TRIGGER_SELECTOR);
      if (!trigger || !root.contains(trigger)) {
        return;
      }
      event.preventDefault();
      if (!requiredProductOptionsSatisfied()) {
        handleMissingRequiredOptions();
        return;
      }
      if (trigger.dataset.offerType) {
        selectedOfferType = trigger.dataset.offerType;
        selectedSchemeKey = trigger.dataset.preferredKey || selectedSchemeKey;
      }
      openModal(trigger);
    });

    modal.addEventListener("click", (event) => {
      const dismiss = event.target.closest("[data-mtuc-dismiss]");
      if (dismiss) {
        event.preventDefault();
        closeModal();
        return;
      }
      const back = event.target.closest("[data-mtuc-back]");
      if (back) {
        event.preventDefault();
        setStep(1);
        return;
      }
      const secondary = event.target.closest("[data-mtuc-secondary]");
      if (secondary) {
        event.preventDefault();
        triggerSecondaryAction();
        return;
      }
      const apply = event.target.closest("[data-mtuc-apply]");
      if (apply) {
        event.preventDefault();
        if (!apply.disabled && hasAuthoritativeCalculation()) {
          setStep(2);
          updateSubmitState(false);
          activeProductFinancingForm()
            ?.querySelector("input, select, textarea")
            ?.focus();
        }
        return;
      }
      const submit = event.target.closest("[data-mtuc-submit]");
      if (submit) {
        event.preventDefault();
        submitForm(event);
      }
    });

    schemeSelect()?.addEventListener("change", () => {
      selectedSchemeKey = schemeSelect().value;
      const scheme = findSchemeAcrossOffers(selectedSchemeKey);
      if (scheme && scheme.scheme_type) {
        selectedOfferType = scheme.scheme_type;
      }
      resetFirstInstallmentForSchemeChange();
      recalculateSelection();
    });

    firstInput()?.addEventListener("input", () => {
      if (firstInstallmentTimer) {
        clearTimeout(firstInstallmentTimer);
      }
      firstInstallmentTimer = setTimeout(() => recalculateSelection(), 400);
    });

    activeProductFinancingForm()?.addEventListener("submit", submitForm);

    bindProductRecalculationListeners();
    bindStep2ReadinessListeners();

    renderCalculator(state.calculator);
    updateSubmitState(false);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
