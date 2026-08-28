(() => {
  'use strict';

  const ROOT_ID = 'mt-uni-credit-product-root';
  const MODAL_ID = 'mt-uni-credit-product-modal';
  const BOOTSTRAP_ID = 'mt-uni-credit-bootstrap';
  const TRIGGER_SELECTOR = '.mt-uni-credit-open-modal, .mt-uni-credit-offer-btn';
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
      // Safe: never log secrets/tokens/PII.
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

    const form = document.getElementById('mt-uni-credit-product-form');
    const calculator = root.querySelector('.mt-uni-credit-calculator');
    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    let sequence = 0;
    let abortController = null;
    let lastTrigger = null;
    let selectedOfferType = Object.keys(state.calculator?.offers || {})[0] || 'standard';
    let selectedSchemeKey = state.calculator?.offers?.[selectedOfferType]?.preferred_scheme_key || '';
    let submissionToken = '';
    let modalHomeParent = modal.parentElement;
    let modalHomeNext = modal.nextSibling;

    debugLog('product init');
    debugLog('calculator root found');
    debugLog('modal root found');
    debugLog('offer buttons found:', root.querySelectorAll(TRIGGER_SELECTOR).length);

    function productOptions() {
      const options = {};
      document.querySelectorAll('[name^="option["]').forEach((element) => {
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
      const qty = document.querySelector('#input-quantity, input[name="quantity"]');
      const parsed = qty ? parseInt(qty.value, 10) : 1;
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 1;
    }

    function selectedScheme() {
      const offer = state.calculator?.offers?.[selectedOfferType];
      if (!offer) {
        return null;
      }
      const schemes = offer.schemes || [];
      return schemes.find((scheme) => scheme.key === selectedSchemeKey) || schemes[0] || null;
    }

    function summaryEl() {
      return modal.querySelector('[data-mtuc-summary]');
    }

    function errorsBox() {
      return modal.querySelector('.mt-uni-credit-form-errors');
    }

    function submitBtn() {
      return modal.querySelector('.mt-uni-credit-submit');
    }

    function panel() {
      return modal.querySelector('.mt-uni-credit-modal__panel');
    }

    function renderOfferButtons(data) {
      if (!calculator || !data || !data.offers) {
        return;
      }
      const offersWrap = calculator.querySelector('.mt-uni-credit-offers');
      if (!offersWrap) {
        return;
      }
      const fragment = document.createDocumentFragment();
      Object.keys(data.offers).forEach((offerType) => {
        const offer = data.offers[offerType];
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mt-uni-credit-offer-btn' + (offerType === 'promo' ? ' is-promo' : '');
        button.dataset.offerType = offerType;
        button.dataset.preferredKey = offer.preferred_scheme_key || '';
        const label = document.createElement('span');
        label.className = 'mt-uni-credit-offer-label';
        label.textContent = offer.installment_label || '';
        button.appendChild(label);
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
      calculator.setAttribute('aria-busy', 'false');
      const summary = summaryEl();
      if (summary && scheme) {
        summary.textContent = `${scheme.months} x ${scheme.monthly_installment}`;
      }
      debugLog('calculator refreshed; offer buttons found:', root.querySelectorAll(TRIGGER_SELECTOR).length);
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

    async function refreshCalculator() {
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
        } else if (calculator) {
          calculator.setAttribute('aria-busy', 'false');
        }
      } catch (error) {
        if (error.name !== 'AbortError' && calculator) {
          calculator.setAttribute('aria-busy', 'false');
        }
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

    function openModal(trigger) {
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
      const scheme = selectedScheme();
      const summary = summaryEl();
      if (summary && scheme) {
        summary.textContent = `${scheme.months} x ${scheme.monthly_installment}`;
      }
      panel()?.focus();
      debugLog('modal opened', selectedOfferType, selectedSchemeKey);
      issueSubmissionToken();
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      setBackgroundInert(false);
      document.removeEventListener('keydown', trapFocus);
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

    async function issueSubmissionToken() {
      const scheme = selectedScheme();
      if (!scheme) {
        return;
      }
      try {
        const json = await postJson(state.issue_url, buildSelectionPayload(scheme));
        if (json.success) {
          submissionToken = json.submission_token || '';
          const summary = summaryEl();
          if (json.calculation && summary) {
            summary.textContent = `${json.calculation.months} x ${json.calculation.monthly_installment}`;
          }
        }
      } catch (error) {
        debugLog('issueSubmission failed');
      }
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
        first_installment: scheme.first_installment || 0,
        submission_token: submissionToken,
      };
    }

    function showErrors(message, errors) {
      const box = errorsBox();
      if (!box) {
        return;
      }
      const parts = [message];
      if (errors) {
        Object.values(errors).forEach((value) => parts.push(String(value)));
      }
      box.textContent = parts.filter(Boolean).join(' ');
      box.hidden = parts.length === 0;
    }

    async function submitForm(event) {
      event.preventDefault();
      const scheme = selectedScheme();
      const button = submitBtn();
      if (!scheme || !button || !form) {
        return;
      }
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
      showErrors('', null);
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
          showErrors(json.message || 'Локалната поръчка е подготвена.', null);
        } else {
          showErrors(json.message || 'Заявката не може да бъде обработена.', json.errors || null);
        }
      } catch (error) {
        showErrors('Заявката не може да бъде обработена.', null);
      } finally {
        button.setAttribute('aria-busy', 'false');
        button.disabled = false;
      }
    }

    // Delegated clicks survive AJAX offer-button replacement.
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
      }
    });

    form?.addEventListener('submit', submitForm);

    document.querySelectorAll('#input-quantity, input[name="quantity"], [name^="option["]').forEach((element) => {
      element.addEventListener('change', refreshCalculator);
      element.addEventListener('input', refreshCalculator);
    });

    renderCalculator(state.calculator);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
