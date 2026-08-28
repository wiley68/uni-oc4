(() => {
  'use strict';

  const ROOT_ID = 'mt-uni-credit-product-root';
  const MODAL_ID = 'mt-uni-credit-product-modal';
  const BOOTSTRAP_ID = 'mt-uni-credit-bootstrap';
  const TRIGGER_SELECTOR = '.mt-uni-credit-product-calculator__button[data-offer-type]';
  const DEBUG_FLAG = 'mtUniCreditDebug';

  function debugEnabled() {
    if (window[DEBUG_FLAG] === true) {
      return true;
    }
    const root = document.getElementById(ROOT_ID);
    return !!(root && root.getAttribute('data-mtuc-debug') === '1');
  }

  function debugLog(...args) {
    if (debugEnabled()) {
      console.info('[mt_uni_credit]', ...args);
    }
  }

  function init() {
    const bootstrapEl = document.getElementById(BOOTSTRAP_ID);
    const root = document.getElementById(ROOT_ID);
    if (!bootstrapEl || !root) {
      debugLog('init skipped: calculator root or bootstrap missing');
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
      debugLog('init skipped: bootstrap JSON invalid');
      return;
    }

    let modal = document.getElementById(MODAL_ID);
    if (!modal) {
      debugLog('init skipped: modal root missing');
      return;
    }

    const calculator = root.querySelector('.mt-uni-credit-product-calculator__calculator');
    const form = document.getElementById('mt-uni-credit-product-form');
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
    let firstInstallmentTimer = null;
    let modalHomeParent = modal.parentElement;
    let modalHomeNext = modal.nextSibling;

    debugLog('product init');
    applyRootLayoutFromData(root, state);

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

    function productFormEl() {
      return document.getElementById('form-product');
    }

    function isRecalcControl(element) {
      if (!element || !(element instanceof Element)) {
        return false;
      }
      const form = productFormEl();
      if (!form || !form.contains(element)) {
        return false;
      }
      if (element.matches('#input-quantity, input[name="quantity"]')) {
        return true;
      }
      return element.matches('[name^="option["]');
    }

    function recalcTriggerReason(element) {
      if (element.matches('#input-quantity, input[name="quantity"]')) {
        return 'quantity change';
      }
      return 'option change';
    }

    function productOptions() {
      const form = productFormEl();
      const options = {};
      if (!form) {
        return options;
      }
      form.querySelectorAll('[name^="option["]').forEach((element) => {
        const match = element.name.match(/^option\[(\d+)\]/);
        if (!match) {
          return;
        }
        const id = match[1];
        if (element.type === 'checkbox') {
          if (!element.checked) {
            return;
          }
          options[id] = options[id] || [];
          options[id].push(element.value);
          return;
        }
        if (element.type === 'radio') {
          if (element.checked) {
            options[id] = element.value;
          }
          return;
        }
        if (element.value !== '') {
          options[id] = element.value;
        }
      });
      return options;
    }

    function quantityValue() {
      const form = productFormEl();
      const qty = form
        ? form.querySelector('#input-quantity, input[name="quantity"]')
        : document.querySelector('#input-quantity, input[name="quantity"]');
      const parsed = qty ? parseInt(qty.value, 10) : 1;
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 1;
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

    function stepEl(step) {
      return modal.querySelector(`[data-mtuc-step="${step}"]`);
    }

    function displayValue(name) {
      return modal.querySelector(`[data-mtuc-display="${name}"]`);
    }

    function popupErrorEl() {
      return modal.querySelector('[data-mtuc-popup-error]');
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
          consents: 'consent',
        };
        const field = aliases[key] || key;
        const target = fieldError(field) || fieldError(key);
        if (target) {
          target.textContent = String(message);
        }
      });
    }

    function setProcessing(active) {
      const panel = processingEl();
      if (panel) {
        panel.hidden = !active;
      }
      if (dialogEl()) {
        dialogEl().style.opacity = active ? '0.45' : '';
        dialogEl().style.pointerEvents = active ? 'none' : '';
      }
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
        option.textContent = `${scheme.months} месеца`;
        if (scheme.description) {
          option.textContent = scheme.description;
        }
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
          first.setAttribute('disabled', 'disabled');
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
      const submit = submitBtn();
      if (submit && currentStep === 2) {
        submit.disabled = false;
        submit.setAttribute('aria-disabled', 'false');
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
        selectedOfferType = Object.keys(data.offers || {})[0] || 'standard';
      }
      selectedSchemeKey = data.offers?.[selectedOfferType]?.preferred_scheme_key || selectedSchemeKey;
      const scheme = selectedScheme();
      if (scheme) {
        selectedSchemeKey = scheme.key;
      }
      renderOfferButtons(data);
      applyRootLayoutFromData(root, state);
      submissionToken = '';
      lastCalculation = null;
      syncBootstrap();
      calculator.setAttribute('aria-busy', 'false');
      debugLog('product recalculation completed');
      debugLog('calculator refreshed');
    }

    let refreshTimer = null;

    function scheduleRefreshCalculator(reason) {
      if (refreshTimer) {
        clearTimeout(refreshTimer);
      }
      refreshTimer = setTimeout(() => {
        refreshTimer = null;
        debugLog('product recalculation triggered:', reason);
        refreshCalculator(reason);
      }, 250);
    }

    async function postJson(url, payload) {
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

      const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        signal: abortController ? abortController.signal : undefined,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
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
      try {
        const json = await postJson(state.calculate_url, {
          csrf_token: state.csrf_token,
          product_id: state.product_id,
          quantity: quantityValue(),
          option: productOptions(),
          sequence: currentSequence,
        });
        if (currentSequence !== sequence) {
          return;
        }
        if (json.success && json.calculator) {
          renderCalculator(json.calculator);
          if (!modal.hidden && currentStep === 1) {
            populateSchemeSelect();
            await recalculateSelection();
          }
        } else if (calculator) {
          calculator.setAttribute('aria-busy', 'false');
          debugLog('product recalculation failed', reason || '', json.message || '');
        }
      } catch (error) {
        if (error.name !== 'AbortError' && calculator) {
          calculator.setAttribute('aria-busy', 'false');
          debugLog('product recalculation failed', reason || '');
        }
      }
    }

    function buildSelectionPayload(scheme) {
      const firstInstallment = firstInput() ? parseFloat(firstInput().value.replace(',', '.')) || 0 : (scheme.first_installment || 0);
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
        first_installment: firstInstallment,
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
        } else {
          if (popupErrorEl()) {
            popupErrorEl().textContent = json.message || 'Неуспешно изчисление.';
          }
        }
      } catch (error) {
        if (popupErrorEl()) {
          popupErrorEl().textContent = 'Неуспешно изчисление. Моля, опитайте отново.';
        }
      } finally {
        calcBusy = false;
      }
    }

    function trapFocus(event) {
      if (!modal || modal.hidden) {
        return;
      }
      const focusables = [...modal.querySelectorAll(focusableSelector)].filter((el) => !el.hasAttribute('disabled'));
      if (focusables.length === 0) {
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.key === 'Tab') {
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
      if (event.key === 'Escape') {
        closeModal();
      }
    }

    function setBackgroundInert(inert) {
      document.querySelectorAll('body > *').forEach((element) => {
        if (element === modal || element.contains(modal)) {
          return;
        }
        if (inert) {
          element.setAttribute('aria-hidden', 'true');
          element.setAttribute('inert', '');
        } else {
          element.removeAttribute('aria-hidden');
          element.removeAttribute('inert');
        }
      });
    }

    async function openModal(trigger) {
      lastTrigger = trigger || null;
      if (modal.parentElement !== document.body) {
        modalHomeParent = modal.parentElement;
        modalHomeNext = modal.nextSibling;
        document.body.appendChild(modal);
      }
      modal.hidden = false;
      modal.removeAttribute('inert');
      modal.setAttribute('aria-hidden', 'false');
      setBackgroundInert(true);
      document.addEventListener('keydown', trapFocus);
      setStep(1);
      clearFieldErrors();
      populateSchemeSelect();
      const apply = applyBtn();
      if (apply) {
        apply.disabled = true;
        apply.setAttribute('aria-disabled', 'true');
      }
      dialogEl()?.focus();
      debugLog('modal opened', selectedOfferType, selectedSchemeKey);
      await recalculateSelection();
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      setBackgroundInert(false);
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
      debugLog('modal closed');
    }

    function secondaryActionUsesNativeAddToCart() {
      return (state.product_button_action || 'add_to_cart') !== 'buy';
    }

    function triggerSecondaryAction() {
      if (secondaryActionUsesNativeAddToCart()) {
        const cartBtn = document.querySelector('#button-cart');
        if (cartBtn) {
          cartBtn.click();
          return;
        }
      }
      const checkoutUrl = state.checkout_url || root.getAttribute('data-checkout-url') || '';
      if (checkoutUrl) {
        window.location.href = checkoutUrl;
      }
    }

    async function submitForm(event) {
      event.preventDefault();
      if (submitBusy) {
        return;
      }
      const scheme = selectedScheme();
      const button = submitBtn();
      if (!scheme || !button || !form) {
        return;
      }
      submitBusy = true;
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
      clearFieldErrors();
      setProcessing(true);

      const payload = buildSelectionPayload(scheme);
      payload.submission_token = submissionToken;
      const formData = new FormData(form);
      formData.forEach((value, key) => {
        payload[key] = value;
      });
      form.querySelectorAll('input[name="consent[]"]:checked').forEach((input, index) => {
        payload[`consent[${index}]`] = input.value;
      });

      try {
        const json = await postJson(state.submit_url, payload);
        if (json.success) {
          const successMessage = modal.querySelector('[data-mtuc-success-message]');
          if (successMessage) {
            successMessage.textContent = json.message || 'Локалната поръчка е подготвена. Следващата стъпка ще бъде финансирането.';
          }
          setStep(3);
        } else {
          showFieldErrors(json.errors || {});
          const submitError = modal.querySelector('[data-mtuc-submit-error]');
          if (submitError && json.message) {
            submitError.textContent = json.message;
          }
          button.disabled = false;
          button.setAttribute('aria-disabled', 'false');
        }
      } catch (error) {
        const submitError = modal.querySelector('[data-mtuc-submit-error]');
        if (submitError) {
          submitError.textContent = 'Заявката не може да бъде обработена.';
        }
        button.disabled = false;
        button.setAttribute('aria-disabled', 'false');
      } finally {
        button.setAttribute('aria-busy', 'false');
        submitBusy = false;
        setProcessing(false);
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
        selectedSchemeKey = trigger.dataset.preferredKey || selectedSchemeKey;
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
      const secondary = event.target.closest('[data-mtuc-secondary]');
      if (secondary) {
        event.preventDefault();
        triggerSecondaryAction();
        return;
      }
      const apply = event.target.closest('[data-mtuc-apply]');
      if (apply) {
        event.preventDefault();
        if (!apply.disabled) {
          setStep(2);
          const submit = submitBtn();
          if (submit && lastCalculation) {
            submit.disabled = false;
            submit.setAttribute('aria-disabled', 'false');
          }
          form?.querySelector('input, select, textarea')?.focus();
        }
      }
    });

    schemeSelect()?.addEventListener('change', () => {
      selectedSchemeKey = schemeSelect().value;
      recalculateSelection();
    });

    firstInput()?.addEventListener('input', () => {
      if (firstInstallmentTimer) {
        clearTimeout(firstInstallmentTimer);
      }
      firstInstallmentTimer = setTimeout(() => recalculateSelection(), 400);
    });

    form?.addEventListener('submit', submitForm);

    const productForm = productFormEl();
    if (productForm) {
      productForm.addEventListener('change', (event) => {
        if (isRecalcControl(event.target)) {
          scheduleRefreshCalculator(recalcTriggerReason(event.target));
        }
      });
      productForm.addEventListener('input', (event) => {
        if (isRecalcControl(event.target)) {
          scheduleRefreshCalculator(recalcTriggerReason(event.target));
        }
      });
    } else {
      debugLog('init warning: #form-product missing; dynamic recalculation disabled');
    }

    renderCalculator(state.calculator);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
