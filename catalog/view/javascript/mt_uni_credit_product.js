(() => {
  'use strict';

  const bootstrapEl = document.getElementById('mt-uni-credit-bootstrap');
  const root = document.getElementById('mt-uni-credit-product-root');
  if (!bootstrapEl || !root) {
    return;
  }

  let state;
  try {
    state = JSON.parse(bootstrapEl.textContent || '{}');
  } catch (error) {
    return;
  }

  const modal = document.getElementById('mt-uni-credit-product-modal');
  const form = document.getElementById('mt-uni-credit-product-form');
  const calculator = root.querySelector('.mt-uni-credit-calculator');
  const summary = modal ? modal.querySelector('[data-mtuc-summary]') : null;
  const errorsBox = modal ? modal.querySelector('.mt-uni-credit-form-errors') : null;
  const submitBtn = modal ? modal.querySelector('.mt-uni-credit-submit') : null;
  const panel = modal ? modal.querySelector('.mt-uni-credit-modal__panel') : null;
  const openButtons = root.querySelectorAll('.mt-uni-credit-open-modal, .mt-uni-credit-offer-btn');

  let sequence = 0;
  let abortController = null;
  let lastTrigger = null;
  let selectedOfferType = Object.keys(state.calculator?.offers || {})[0] || 'standard';
  let selectedSchemeKey = state.calculator?.offers?.[selectedOfferType]?.preferred_scheme_key || '';
  let submissionToken = '';
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

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

  function renderCalculator(data) {
    if (!data || !calculator) {
      return;
    }
    state.calculator = data;
    const scheme = selectedScheme();
    if (scheme) {
      selectedSchemeKey = scheme.key;
    }
    calculator.setAttribute('aria-busy', 'false');
    if (summary && scheme) {
      summary.textContent = `${scheme.months} x ${scheme.monthly_installment}`;
    }
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
    const focusables = [...modal.querySelectorAll(focusableSelector)].filter((el) => el.offsetParent !== null);
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
    document.querySelectorAll('body > *:not(.mt-uni-credit-modal)').forEach((element) => {
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
    if (!modal) {
      return;
    }
    lastTrigger = trigger;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    setBackgroundInert(true);
    document.addEventListener('keydown', trapFocus);
    panel?.focus();
    issueSubmissionToken();
  }

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    setBackgroundInert(false);
    document.removeEventListener('keydown', trapFocus);
    if (lastTrigger) {
      lastTrigger.focus();
    }
  }

  async function issueSubmissionToken() {
    const scheme = selectedScheme();
    if (!scheme) {
      return;
    }
    const json = await postJson(state.issue_url, buildSelectionPayload(scheme));
    if (json.success) {
      submissionToken = json.submission_token || '';
      if (json.calculation && summary) {
        summary.textContent = `${json.calculation.months} x ${json.calculation.monthly_installment}`;
      }
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
    if (!errorsBox) {
      return;
    }
    const parts = [message];
    if (errors) {
      Object.values(errors).forEach((value) => parts.push(String(value)));
    }
    errorsBox.textContent = parts.filter(Boolean).join(' ');
    errorsBox.hidden = parts.length === 0;
  }

  async function submitForm(event) {
    event.preventDefault();
    const scheme = selectedScheme();
    if (!scheme || !submitBtn) {
      return;
    }
    submitBtn.setAttribute('aria-busy', 'true');
    submitBtn.disabled = true;
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
      submitBtn.setAttribute('aria-busy', 'false');
      submitBtn.disabled = false;
    }
  }

  openButtons.forEach((button) => {
    button.addEventListener('click', (event) => {
      if (button.dataset.offerType) {
        selectedOfferType = button.dataset.offerType;
        selectedSchemeKey = button.dataset.preferredKey || selectedSchemeKey;
      }
      openModal(button);
    });
  });

  modal?.querySelectorAll('[data-mtuc-dismiss]').forEach((element) => {
    element.addEventListener('click', closeModal);
  });

  form?.addEventListener('submit', submitForm);

  document.querySelectorAll('#input-quantity, input[name="quantity"], [name^="option["]').forEach((element) => {
    element.addEventListener('change', refreshCalculator);
    element.addEventListener('input', refreshCalculator);
  });

  renderCalculator(state.calculator);
})();
