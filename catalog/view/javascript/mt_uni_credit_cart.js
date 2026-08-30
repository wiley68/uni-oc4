(() => {
  'use strict';

  const ROOT_ID = 'mt-uni-credit-cart-root';
  const MODAL_ID = 'mt-uni-credit-cart-modal';
  const BOOTSTRAP_ID = 'mt-uni-credit-cart-bootstrap';
  const TRIGGER_SELECTOR = '.mt-uni-credit-product-calculator__button[data-offer-type]';

  function init() {
    const bootstrapEl = document.getElementById(BOOTSTRAP_ID);
    const root = document.getElementById(ROOT_ID);
    if (!bootstrapEl || !root) {
      return;
    }
    if (root.dataset.mtucBound === '1') {
      return;
    }
    root.dataset.mtucBound = '1';

    let state;
    try {
      state = JSON.parse(bootstrapEl.textContent || '{}');
    } catch (error) {
      return;
    }

    let modal = document.getElementById(MODAL_ID);
    if (!modal) {
      return;
    }

    const calculator = root.querySelector('.mt-uni-credit-product-calculator__calculator');
    const form = document.getElementById('mt-uni-credit-cart-form');
    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    let sequence = 0;
    let abortController = null;
    let lastTrigger = null;
    let selectedOfferType = Object.keys(state.calculator?.offers || {})[0] || 'standard';
    let selectedSchemeKey = state.calculator?.offers?.[selectedOfferType]?.preferred_scheme_key || '';
    let submissionToken = '';
    let lastCalculation = null;
    let currentStep = 1;
    let calcBusy = false;
    let submitBusy = false;
    let redirectTerminal = false;
    let firstInstallmentTimer = null;
    let refreshTimer = null;
    let modalHomeParent = modal.parentElement;
    let modalHomeNext = modal.nextSibling;
    let cartFingerprint = state.calculator?.cart_fingerprint || '';

    applyRootLayoutFromData(root, state);
    hideSecondaryAction();

    function applyRootLayoutFromData(element, bootstrap) {
      const width = element.getAttribute('data-mtuc-button-width');
      const height = element.getAttribute('data-mtuc-button-height');
      const topSpacing = element.getAttribute('data-mtuc-top-spacing')
        || (bootstrap.button_top_spacing > 0 ? String(bootstrap.button_top_spacing) : '');
      if (width) {
        element.style.setProperty('--mtuc-button-width', `${width}px`);
      }
      if (height) {
        element.style.setProperty('--mtuc-button-height', `${height}px`);
      }
      if (topSpacing) {
        element.style.marginTop = `${topSpacing}px`;
      }
    }

    function hideSecondaryAction() {
      modal.querySelectorAll('[data-mtuc-secondary]').forEach((el) => {
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        el.style.display = 'none';
      });
    }

    function cartChangedMessage() {
      return (state.i18n && state.i18n.cart_changed)
        || 'Съдържанието на количката е променено. Моля, презаредете калкулатора.';
    }

    function cartEmptyMessage() {
      return (state.i18n && state.i18n.cart_empty) || 'Количката е празна.';
    }

    function entryErrorEl() {
      return root.querySelector('[data-mtuc-entry-error]');
    }

    function showEntryError(message) {
      const el = entryErrorEl();
      if (!el) {
        return;
      }
      el.hidden = false;
      el.textContent = message || '';
    }

    function clearEntryError() {
      const el = entryErrorEl();
      if (!el) {
        return;
      }
      el.hidden = true;
      el.textContent = '';
    }

    function clearCalculationDisplays() {
      modal.querySelectorAll('[data-mtuc-display]').forEach((el) => {
        el.textContent = '';
      });
    }

    /**
     * Canonical offer-state reset (Product openModal parity).
     * Clears financing-specific JS + Step 1 DOM before a new offer/scheme calculation.
     * Customer Step 2 field values are intentionally preserved.
     */
    function resetCartModalOfferState() {
      lastCalculation = null;
      submissionToken = '';
      clearCalculationDisplays();
      const first = firstInput();
      if (first) {
        first.value = '0';
        first.removeAttribute('readonly');
        first.removeAttribute('disabled');
      }
      const firstRow = modal.querySelector('[data-mtuc-first-row]');
      if (firstRow) {
        firstRow.hidden = false;
      }
      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute('aria-disabled', 'true');
      }
      const submit = submitBtn();
      if (submit) {
        submit.disabled = true;
        submit.setAttribute('aria-disabled', 'true');
        submit.classList.add('is-disabled');
      }
      if (popupErrorEl()) {
        popupErrorEl().textContent = '';
      }
    }

    /** Scheme-switch alias — same contract as Product resetFirstInstallmentForSchemeChange. */
    function resetFirstInstallmentForSchemeChange() {
      resetCartModalOfferState();
    }

    function invalidateOpenPopupForCartChange() {
      resetCartModalOfferState();
      cartFingerprint = '';
      if (!modal.hidden) {
        if (popupErrorEl()) {
          popupErrorEl().textContent = cartChangedMessage();
        }
        const submitError = modal.querySelector('[data-mtuc-submit-error]');
        if (submitError && currentStep === 2) {
          submitError.textContent = cartChangedMessage();
        }
        setStep(1);
        updateSubmitState(false);
      }
    }

    function hideCalculatorShell(message) {
      if (calculator) {
        calculator.hidden = true;
      }
      root.hidden = true;
      if (!modal.hidden) {
        closeModal();
      }
      if (message) {
        showEntryError(message);
      }
    }

    function showCalculatorShell() {
      root.hidden = false;
      if (calculator) {
        calculator.hidden = false;
      }
    }

    function isCartListUrl(url) {
      const normalized = String(url || '').replace(/&amp;/g, '&');
      return normalized.indexOf('route=checkout/cart.list') !== -1
        || /\/checkout\/cart\.list(?:\?|$)/.test(normalized);
    }

    function bindCartRefreshListeners() {
      const $ = window.jQuery;
      if ($ && typeof $.fn !== 'undefined') {
        $(document).on('ajaxSuccess.mtUniCreditCartRefresh', function (_event, _xhr, settings) {
          if (isCartListUrl(settings && settings.url)) {
            scheduleRefreshCalculator('cart.list ajax');
          }
        });
        $('#shopping-cart').on('ajaxComplete.mtUniCreditCartRefresh', function () {
          scheduleRefreshCalculator('shopping-cart ajax');
        });
      }

      const shoppingCart = document.getElementById('shopping-cart');
      if (shoppingCart && typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(() => {
          scheduleRefreshCalculator('shopping-cart mutation');
        });
        observer.observe(shoppingCart, { childList: true, subtree: true });
      }
    }

    function syncBootstrap() {
      const el = document.getElementById(BOOTSTRAP_ID);
      if (!el) {
        return;
      }
      el.textContent = JSON.stringify({
        source: 'cart',
        product_id: 0,
        calculator: state.calculator,
        modal: state.modal,
        logo_standard_url: state.logo_standard_url,
        logo_alternative_url: state.logo_alternative_url,
        badge_url: state.badge_url,
        calculate_url: state.calculate_url,
        issue_url: state.issue_url,
        submit_url: state.submit_url,
        csrf_token: state.csrf_token,
        button_top_spacing: state.button_top_spacing,
        hide_secondary: true,
        i18n: state.i18n || {},
      });
    }

    function selectedOffer() {
      return state.calculator?.offers?.[selectedOfferType] || null;
    }

    function selectedScheme() {
      const offer = selectedOffer();
      if (!offer) {
        return null;
      }
      const schemes = offer.schemes || [];
      return schemes.find((scheme) => scheme.key === selectedSchemeKey) || schemes[0] || null;
    }

    function schemeSelect() {
      return modal.querySelector('[data-mtuc-schemes]');
    }

    function firstInput() {
      return modal.querySelector('[data-mtuc-first]');
    }

    function applyBtn() {
      return modal.querySelector('[data-mtuc-apply]');
    }

    function submitBtn() {
      return modal.querySelector('[data-mtuc-submit]');
    }

    function processingEl() {
      return modal.querySelector('[data-mtuc-processing]');
    }

    function dialogEl() {
      return modal.querySelector('.mt-uni-credit-product-calculator__dialog');
    }

    function popupErrorEl() {
      return modal.querySelector('[data-mtuc-popup-error]');
    }

    function displayValue(name) {
      return modal.querySelector(`[data-mtuc-display="${name}"]`);
    }

    function fieldError(name) {
      return modal.querySelector(`[data-mtuc-field-error="${name}"]`);
    }

    function clearFieldErrors() {
      modal.querySelectorAll('[data-mtuc-field-error]').forEach((el) => {
        el.textContent = '';
      });
      const submitError = modal.querySelector('[data-mtuc-submit-error]');
      if (submitError) {
        submitError.textContent = '';
      }
      if (popupErrorEl()) {
        popupErrorEl().textContent = '';
      }
      if (form) {
        form.querySelectorAll('.mt-uni-credit-product-calculator__customer-input').forEach((input) => {
          input.setAttribute('aria-invalid', 'false');
        });
      }
    }

    function showFieldErrors(errors) {
      if (!errors || typeof errors !== 'object') {
        return;
      }
      Object.entries(errors).forEach(([key, message]) => {
        const aliases = {
          firstname: 'firstname',
          first_name: 'firstname',
          lastname: 'lastname',
          last_name: 'lastname',
          telephone: 'phone',
          phone: 'phone',
          address_1: 'address',
          address: 'address',
          email: 'email',
          consents: 'consent',
        };
        const field = aliases[key] || key;
        const target = fieldError(field) || fieldError(key);
        if (target) {
          target.textContent = String(message || '');
        }
        const input = customerField(field);
        if (input) {
          input.setAttribute('aria-invalid', 'true');
        }
      });
    }

    function step2Root() {
      return modal.querySelector('[data-mtuc-step="2"]');
    }

    function customerField(name) {
      return form ? form.querySelector(`[name="${name}"]`) : null;
    }

    function isNonEmpty(value) {
      return String(value || '').trim() !== '';
    }

    function sanitizePhoneValue(value) {
      return String(value || '').replace(/[^\d+]/g, '');
    }

    function sanitizePhone2Value(value) {
      return String(value || '')
        .split('')
        .filter((char) => /[-0-9+() ]/.test(char))
        .join('');
    }

    function isValidPhone(value) {
      const cleaned = sanitizePhoneValue(value);
      return cleaned.length >= 8 && /^[+]?[\d]+$/.test(cleaned);
    }

    function isValidPhone2(value) {
      const phone = String(value || '').trim();
      return phone !== '' && /^[-0-9+() ]+$/.test(phone) && /\d/.test(phone);
    }

    function isValidEmail(value) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
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
      return date.getFullYear() === year
        && date.getMonth() === month - 1
        && date.getDate() === day;
    }

    function consentCheckboxes() {
      const scope = step2Root() || modal;
      return scope.querySelectorAll('[data-mtuc-consent-checkbox]');
    }

    function areMandatoryConsentsChecked() {
      const boxes = consentCheckboxes();
      if (!boxes.length) {
        return true;
      }
      let ok = true;
      boxes.forEach((box) => {
        if (!box.checked) {
          ok = false;
        }
      });
      return ok;
    }

    function hasAuthoritativeCalculation() {
      return !!(lastCalculation && submissionToken && cartFingerprint
        && cartFingerprint === (state.calculator?.cart_fingerprint || cartFingerprint));
    }

    function getStep2FieldErrors() {
      const errors = {};
      const firstname = customerField('firstname');
      const lastname = customerField('lastname');
      const address = customerField('address');
      const phone = customerField('phone');
      const email = customerField('email');
      if (firstname && !isNonEmpty(firstname.value)) {
        errors.firstname = 'required';
      }
      if (lastname && !isNonEmpty(lastname.value)) {
        errors.lastname = 'required';
      }
      if (address && !isNonEmpty(address.value)) {
        errors.address = 'required';
      }
      if (phone && !isValidPhone(phone.value)) {
        errors.phone = 'required';
      }
      if (email && !isValidEmail(email.value)) {
        errors.email = 'required';
      }

      // Process 2 only when rendered (never require hidden Process 2 fields in Process 1).
      const egnField = customerField('egn');
      if (egnField) {
        const egn = String(egnField.value || '').replace(/\D/g, '');
        if (egn === '') {
          errors.egn = 'Полето е задължително.';
        } else if (!isValidEgn(egn)) {
          errors.egn = 'ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.';
        }
      }
      const phone2Field = customerField('phone2');
      if (phone2Field) {
        const phone2 = phone2Field.value;
        if (!isNonEmpty(phone2)) {
          errors.phone2 = 'Полето е задължително.';
        } else if (!isValidPhone2(phone2)) {
          errors.phone2 = 'Въведете валиден телефонен номер.';
        }
      }

      if (!areMandatoryConsentsChecked()) {
        errors.consent = 'required';
      }
      return errors;
    }

    function isStep2FormValid() {
      return Object.keys(getStep2FieldErrors()).length === 0 && hasAuthoritativeCalculation();
    }

    function updateSubmitState(showErrors) {
      const button = submitBtn();
      if (!button) {
        return false;
      }
      const valid = isStep2FormValid();
      button.disabled = !valid || submitBusy;
      button.setAttribute('aria-disabled', valid && !submitBusy ? 'false' : 'true');
      button.classList.toggle('is-disabled', !valid || submitBusy);
      if (showErrors && !valid) {
        showFieldErrors(getStep2FieldErrors());
      }
      return valid;
    }

    function bindStep2ReadinessListeners() {
      const scope = step2Root();
      if (!scope) {
        return;
      }
      scope.addEventListener('input', (event) => {
        const target = event.target;
        if (!target) {
          return;
        }
        const name = target.getAttribute('name') || '';
        if (name === 'phone') {
          target.value = sanitizePhoneValue(target.value);
        }
        if (name === 'phone2') {
          const sanitized = sanitizePhone2Value(target.value);
          if (target.value !== sanitized) {
            target.value = sanitized;
          }
        }
        if (name || target.matches('[data-mtuc-consent-checkbox]')) {
          updateSubmitState(false);
        }
      });
      scope.addEventListener('change', (event) => {
        const target = event.target;
        if (!target) {
          return;
        }
        if (target.getAttribute('name') || target.matches('[data-mtuc-consent-checkbox]')) {
          updateSubmitState(false);
        }
      });
    }

    function setProcessing(active) {
      const el = processingEl();
      if (!el) {
        return;
      }
      el.hidden = !active;
    }

    function setStep(step) {
      currentStep = step;
      modal.querySelectorAll('[data-mtuc-step]').forEach((el) => {
        const stepNum = parseInt(el.getAttribute('data-mtuc-step'), 10);
        const active = stepNum === step;
        el.hidden = !active;
        el.classList.toggle('mt-uni-credit-product-calculator__step--active', active);
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
        const option = document.createElement('option');
        option.value = scheme.key;
        let label = `${scheme.months} месеца`;
        if (scheme.description) {
          label += ` - ${scheme.description}`;
        }
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
      if (calculation.cart_fingerprint) {
        cartFingerprint = calculation.cart_fingerprint;
      }
      const map = {
        price: calculation.price_display?.primary || calculation.price_display?.secondary || calculation.price,
        financed_amount: calculation.financed_amount_display?.primary || calculation.financed_amount,
        monthly_installment: calculation.monthly_installment_display?.primary || calculation.monthly_installment,
        total_payable: calculation.total_payable_display?.primary || calculation.total_payable,
        glp: calculation.glp_display != null ? `${calculation.glp_display}%` : calculation.glp,
        gpr: calculation.gpr_display != null ? `${calculation.gpr_display}%` : calculation.gpr,
      };
      Object.entries(map).forEach(([key, value]) => {
        const el = displayValue(key);
        if (el && value != null) {
          el.textContent = String(value);
        }
      });

      const firstRow = modal.querySelector('[data-mtuc-first-row]');
      const first = firstInput();
      if (first) {
        first.value = String(calculation.first_installment ?? 0);
        if (calculation.first_installment_locked) {
          first.setAttribute('readonly', 'readonly');
          first.removeAttribute('disabled');
        } else {
          first.removeAttribute('readonly');
          first.removeAttribute('disabled');
        }
      }
      if (firstRow) {
        firstRow.hidden = calculation.show_first_installment === false;
      }

      const apply = applyBtn();
      if (apply) {
        apply.disabled = false;
        apply.setAttribute('aria-disabled', 'false');
      }
      if (currentStep === 2) {
        updateSubmitState(false);
      }
    }

    function renderOfferButtons(data) {
      if (!calculator || !data || !data.offers) {
        return;
      }
      const offersWrap = calculator.querySelector('.mt-uni-credit-product-calculator__buttons');
      if (!offersWrap) {
        return;
      }
      const dark = !!data.dark_button;
      const logoUrl = dark ? (state.logo_alternative_url || '') : (state.logo_standard_url || '');
      const fragment = document.createDocumentFragment();
      Object.keys(data.offers).forEach((offerType) => {
        const offer = data.offers[offerType];
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `mt-uni-credit-product-calculator__button mt-uni-credit-product-calculator__button--${offerType}`;
        button.dataset.offerType = offerType;
        button.dataset.preferredKey = offer.preferred_scheme_key || '';
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-controls', MODAL_ID);
        const content = document.createElement('span');
        content.className = 'mt-uni-credit-product-calculator__button-content';
        const title = document.createElement('span');
        title.className = 'mt-uni-credit-product-calculator__button-title';
        title.textContent = 'Купи на изплащане';
        const price = document.createElement('span');
        price.className = 'mt-uni-credit-product-calculator__button-price';
        price.setAttribute('data-mtuc-preferred-price', '');
        price.textContent = offer.installment_label || '';
        content.appendChild(title);
        content.appendChild(price);
        button.appendChild(content);
        if (offerType === 'promo') {
          const badge = document.createElement('span');
          badge.className = 'mt-uni-credit-product-calculator__badge';
          badge.setAttribute('aria-hidden', 'true');
          badge.textContent = '0%';
          button.appendChild(badge);
        } else {
          const logoWrap = document.createElement('span');
          logoWrap.className = 'mt-uni-credit-product-calculator__logo';
          const img = document.createElement('img');
          img.src = logoUrl;
          img.alt = 'UniCredit';
          img.setAttribute('data-mtuc-logo', '');
          logoWrap.appendChild(img);
          button.appendChild(logoWrap);
        }
        fragment.appendChild(button);
      });
      offersWrap.replaceChildren(fragment);
    }

    function renderCalculator(data) {
      if (!data || !data.offers || Object.keys(data.offers).length === 0) {
        hideCalculatorShell(cartEmptyMessage());
        return;
      }
      showCalculatorShell();
      state.calculator = data;
      cartFingerprint = data.cart_fingerprint || '';
      renderOfferButtons(data);
      syncBootstrap();
      if (calculator) {
        calculator.setAttribute('aria-busy', 'false');
      }
      clearEntryError();
      if (!selectedOfferType || !data.offers[selectedOfferType]) {
        selectedOfferType = Object.keys(data.offers)[0];
      }
      selectedSchemeKey = data.offers[selectedOfferType]?.preferred_scheme_key || selectedSchemeKey;
    }

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
        if (value && typeof value === 'object' && !Array.isArray(value)) {
          Object.entries(value).forEach(([nestedKey, nestedValue]) => {
            if (Array.isArray(nestedValue)) {
              nestedValue.forEach((item) => body.append(`${key}[${nestedKey}][]`, item));
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
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      };
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
        calculator.setAttribute('aria-busy', 'true');
      }
      const previousFingerprint = cartFingerprint;
      try {
        const json = await postJson(state.calculate_url, {
          csrf_token: state.csrf_token,
          sequence: currentSequence,
        });
        if (currentSequence !== sequence) {
          return;
        }
        if (json.success && json.calculator) {
          const nextFingerprint = json.calculator.cart_fingerprint || '';
          if (previousFingerprint && nextFingerprint && previousFingerprint !== nextFingerprint) {
            invalidateOpenPopupForCartChange();
          }
          renderCalculator(json.calculator);
          if (!modal.hidden && (currentStep === 1 || currentStep === 2) && previousFingerprint === nextFingerprint) {
            if (currentStep === 1) {
              populateSchemeSelect();
            }
            await recalculateSelection();
            if (currentStep === 2) {
              updateSubmitState(false);
            }
          }
        } else {
          invalidateOpenPopupForCartChange();
          hideCalculatorShell(
            json.error_code === 'cart_empty' ? cartEmptyMessage() : (json.message || cartEmptyMessage())
          );
          if (calculator) {
            calculator.setAttribute('aria-busy', 'false');
          }
        }
      } catch (error) {
        if (error.name !== 'AbortError' && calculator) {
          calculator.setAttribute('aria-busy', 'false');
        }
      }
    }

    function resolveFirstInstallmentAmount(scheme) {
      const first = firstInput();
      const locked = !!(lastCalculation && lastCalculation.first_installment_locked);
      if (locked && lastCalculation && lastCalculation.first_installment != null) {
        return parseFloat(String(lastCalculation.first_installment).replace(',', '.')) || 0;
      }
      if (first) {
        return parseFloat(String(first.value).replace(',', '.')) || 0;
      }
      if (lastCalculation && lastCalculation.first_installment != null) {
        return parseFloat(String(lastCalculation.first_installment).replace(',', '.')) || 0;
      }
      return scheme.first_installment || 0;
    }

    function buildSelectionPayload(scheme) {
      return {
        csrf_token: state.csrf_token,
        cart_fingerprint: cartFingerprint || state.calculator?.cart_fingerprint || '',
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

    async function recalculateSelection() {
      const scheme = selectedScheme();
      if (!scheme || calcBusy) {
        return;
      }
      calcBusy = true;
      clearFieldErrors();
      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute('aria-disabled', 'true');
      }
      try {
        const json = await postJson(state.issue_url, buildSelectionPayload(scheme));
        if (json.success) {
          submissionToken = json.submission_token || submissionToken;
          renderCalculation(json.calculation || null);
          clearEntryError();
        } else if (json.error_code === 'cart_changed' || json.error_code === 'cart_empty') {
          invalidateOpenPopupForCartChange();
          showEntryError(json.message || cartChangedMessage());
          if (popupErrorEl()) {
            popupErrorEl().textContent = json.message || cartChangedMessage();
          }
        } else if (popupErrorEl()) {
          popupErrorEl().textContent = json.message || 'Неуспешно изчисление.';
        }
      } catch (error) {
        if (popupErrorEl()) {
          popupErrorEl().textContent = 'Неуспешно изчисление.';
        }
      } finally {
        calcBusy = false;
      }
    }

    function trapFocus(event) {
      if (event.key !== 'Tab' || modal.hidden) {
        return;
      }
      const dialog = dialogEl();
      if (!dialog) {
        return;
      }
      const focusable = Array.from(dialog.querySelectorAll(focusableSelector))
        .filter((el) => !el.hidden && el.offsetParent !== null);
      if (!focusable.length) {
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    function setBackgroundInert(inert) {
      Array.from(document.body.children).forEach((child) => {
        if (child === modal) {
          return;
        }
        if (inert) {
          child.setAttribute('inert', '');
          child.setAttribute('aria-hidden', 'true');
        } else {
          child.removeAttribute('inert');
          child.removeAttribute('aria-hidden');
        }
      });
    }

    async function openModal(trigger) {
      lastTrigger = trigger;
      hideSecondaryAction();
      // Product parity: reset offer-specific state before any new calculation (prevents A→B leak).
      resetCartModalOfferState();
      clearFieldErrors();
      modal.hidden = false;
      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');
      if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
      }
      setBackgroundInert(true);
      document.addEventListener('keydown', onDocumentKeydown);
      document.addEventListener('keydown', trapFocus);
      setStep(1);
      populateSchemeSelect();
      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute('aria-disabled', 'true');
      }
      dialogEl()?.focus();
      setProcessing(true);
      try {
        await recalculateSelection();
      } finally {
        setProcessing(false);
      }
    }

    function closeModal() {
      // Product resets on open (not close). Clear transient offer financing state so a later
      // open never briefly inherits A after close→open B; Step 2 customer fields stay.
      resetCartModalOfferState();
      modal.hidden = true;
      modal.setAttribute('hidden', 'hidden');
      modal.setAttribute('aria-hidden', 'true');
      setBackgroundInert(false);
      document.removeEventListener('keydown', onDocumentKeydown);
      document.removeEventListener('keydown', trapFocus);
      setProcessing(false);
      if (modalHomeParent && modal.parentElement === document.body) {
        if (modalHomeNext && modalHomeNext.parentElement === modalHomeParent) {
          modalHomeParent.insertBefore(modal, modalHomeNext);
        } else {
          modalHomeParent.appendChild(modal);
        }
      }
      if (lastTrigger && typeof lastTrigger.focus === 'function') {
        lastTrigger.focus();
      }
    }

    function onDocumentKeydown(event) {
      if (event.key === 'Escape' && !modal.hidden) {
        event.preventDefault();
        closeModal();
      }
    }

    async function submitForm(event) {
      if (event && typeof event.preventDefault === 'function') {
        event.preventDefault();
      }
      if (submitBusy) {
        return;
      }
      const activeForm = form || modal.querySelector('#mt-uni-credit-cart-form');
      const scheme = selectedScheme();
      const button = submitBtn();
      const submitError = modal.querySelector('[data-mtuc-submit-error]');
      if (!scheme || !button || !activeForm || !lastCalculation) {
        if (submitError) {
          submitError.textContent = 'Моля, изберете схема и изчакайте изчислението преди изпращане.';
        }
        return;
      }
      if (!updateSubmitState(true)) {
        return;
      }
      submitBusy = true;
      redirectTerminal = false;
      button.setAttribute('aria-busy', 'true');
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
        activeForm.querySelectorAll('input[name="consent[]"]:checked').forEach((input, index) => {
          payload[`consent[${index}]`] = input.value;
        });

        const json = await postJson(state.submit_url, payload, { abort: false });
        if (json.success) {
          if (json.step === 'process2_prepared' && json.redirect_url) {
            redirectTerminal = true;
            window.location.assign(json.redirect_url);
            return;
          }
          if (json.redirect_url
            && window.MtUniCreditRedirect
            && window.MtUniCreditRedirect.navigateIfTrusted(json.redirect_url)) {
            redirectTerminal = true;
            return;
          }
          const successMessage = modal.querySelector('[data-mtuc-success-message]');
          if (successMessage) {
            successMessage.textContent = json.message
              || 'Локалната поръчка е подготвена. Следващата стъпка ще бъде финансирането.';
          }
          setStep(3);
        } else {
          if (json.error_code === 'cart_changed') {
            invalidateOpenPopupForCartChange();
          }
          showFieldErrors(json.errors || {});
          if (submitError && json.message) {
            submitError.textContent = json.message;
          } else if (submitError && !json.message) {
            submitError.textContent = 'Заявката не може да бъде обработена.';
          }
          updateSubmitState(false);
        }
      } catch (error) {
        if (submitError) {
          submitError.textContent = 'Заявката не може да бъде обработена.';
        }
        updateSubmitState(false);
      } finally {
        if (!redirectTerminal) {
          button.setAttribute('aria-busy', 'false');
          submitBusy = false;
          setProcessing(false);
        }
      }
    }

    root.addEventListener('click', (event) => {
      const trigger = event.target.closest(TRIGGER_SELECTOR);
      if (!trigger || !root.contains(trigger)) {
        return;
      }
      event.preventDefault();
      if (trigger.dataset.offerType) {
        selectedOfferType = trigger.dataset.offerType;
        // Prefer clicked button key only — never keep offer A's preferred_scheme_key for B.
        selectedSchemeKey = trigger.dataset.preferredKey || '';
      }
      openModal(trigger);
    });

    modal.addEventListener('click', (event) => {
      const dismiss = event.target.closest('[data-mtuc-dismiss]');
      if (dismiss) {
        event.preventDefault();
        closeModal();
        return;
      }
      const back = event.target.closest('[data-mtuc-back]');
      if (back) {
        event.preventDefault();
        setStep(1);
        return;
      }
      const apply = event.target.closest('[data-mtuc-apply]');
      if (apply) {
        event.preventDefault();
        if (!apply.disabled && lastCalculation) {
          setStep(2);
          updateSubmitState(false);
          form?.querySelector('input, select, textarea')?.focus();
        }
        return;
      }
      const submit = event.target.closest('[data-mtuc-submit]');
      if (submit) {
        event.preventDefault();
        submitForm(event);
      }
    });

    schemeSelect()?.addEventListener('change', () => {
      selectedSchemeKey = schemeSelect().value;
      resetFirstInstallmentForSchemeChange();
      recalculateSelection();
    });

    firstInput()?.addEventListener('input', () => {
      if (firstInstallmentTimer) {
        clearTimeout(firstInstallmentTimer);
      }
      firstInstallmentTimer = setTimeout(() => recalculateSelection(), 400);
    });

    form?.addEventListener('submit', submitForm);

    bindCartRefreshListeners();
    bindStep2ReadinessListeners();

    renderCalculator(state.calculator);
    updateSubmitState(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
