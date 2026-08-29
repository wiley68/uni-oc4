(() => {
  'use strict';

  const ROOT_ID = 'mt-uni-credit-product-root';
  const MODAL_ID = 'mt-uni-credit-product-modal';
  const BOOTSTRAP_ID = 'mt-uni-credit-bootstrap';
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
    let awaitingNativeCartAdd = false;

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

    /**
     * OpenCart 4.1 product controls live under #product > #form-product.
     * Jet (mt_jet_credit) binds [name=quantity] + [id^=input-option] — proven on OC4.1.0.3.
     */
    function productRootEl() {
      return document.getElementById('product') || document.getElementById('form-product');
    }

    function productFormEl() {
      return document.getElementById('form-product') || document.getElementById('product');
    }

    function isQuantityControl(element) {
      if (!element || !element.getAttribute) {
        return false;
      }
      return element.id === 'input-quantity' || element.getAttribute('name') === 'quantity';
    }

    function isOptionControl(element) {
      if (!element || !element.getAttribute) {
        return false;
      }
      const id = element.id || '';
      const name = element.getAttribute('name') || '';
      // Jet contract: ids start with input-option (select, radio, checkbox, wrappers).
      if (id.indexOf('input-option') === 0) {
        return true;
      }
      // Name contract from OC4 product.twig: option[product_option_id] / option[id][]
      return name.indexOf('option[') === 0;
    }

    function recalcTriggerReason(element) {
      return isQuantityControl(element) ? 'quantity change' : 'option change';
    }

    function productOptions() {
      const options = {};
      const root = productRootEl();
      if (!root) {
        return options;
      }
      // Jet-parity field set under #product / #form-product.
      const fields = root.querySelectorAll(
        "input[type='text'][name], input[type='hidden'][name], input[type='radio'][name]:checked, input[type='checkbox'][name]:checked, input[type='date'][name], input[type='time'][name], input[type='datetime-local'][name], select[name], textarea[name]"
      );
      fields.forEach((element) => {
        const name = element.getAttribute('name') || '';
        const match = name.match(/^option\[(\d+)](\[\])?$/);
        if (!match) {
          return;
        }
        const id = match[1];
        if (element.type === 'checkbox' || match[2] === '[]') {
          options[id] = options[id] || [];
          if (element.type === 'checkbox' && !element.checked) {
            return;
          }
          if (String(element.value) !== '') {
            options[id].push(element.value);
          }
          return;
        }
        if (element.type === 'radio' && !element.checked) {
          return;
        }
        if (String(element.value) !== '') {
          options[id] = element.value;
        }
      });
      return options;
    }

    function requiredOptionsMessage() {
      return (state.i18n && state.i18n.error_required_options)
        || 'Моля, изберете задължителните опции на продукта.';
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
      const blocks = form.querySelectorAll('.mb-3.required, .required');
      for (let i = 0; i < blocks.length; i += 1) {
        const block = blocks[i];
        const candidates = block.querySelectorAll('select, textarea, input');
        const optionFields = [];
        candidates.forEach((field) => {
          const name = field.getAttribute('name') || '';
          if (name.indexOf('option[') === 0) {
            optionFields.push(field);
          }
        });
        if (optionFields.length === 0) {
          continue;
        }
        const first = optionFields[0];
        const name = first.getAttribute('name') || '';
        const type = (first.getAttribute('type') || first.tagName || '').toLowerCase();
        if (type === 'radio' || name.indexOf('[]') !== -1 || type === 'checkbox') {
          let checked = 0;
          optionFields.forEach((field) => {
            if ((field.type === 'radio' || field.type === 'checkbox') && field.checked) {
              checked += 1;
            }
          });
          if (checked === 0) {
            return block;
          }
          continue;
        }
        if (type === 'file') {
          let fileValue = '';
          optionFields.forEach((field) => {
            if (field.type === 'hidden' && String(field.value || '').trim() !== '') {
              fileValue = String(field.value || '').trim();
            }
          });
          if (fileValue === '') {
            return block;
          }
          continue;
        }
        let filled = false;
        optionFields.forEach((field) => {
          if (field.type === 'hidden') {
            return;
          }
          if (String(field.value || '').trim() !== '') {
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
      return root.querySelector('[data-mtuc-entry-error]');
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
      el.textContent = '';
      el.hidden = true;
    }

    function focusFirstMissingRequiredOption() {
      const block = firstMissingRequiredOptionBlock();
      if (!block) {
        return;
      }
      if (typeof block.scrollIntoView === 'function') {
        block.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      const candidates = block.querySelectorAll('select, textarea, input');
      for (let i = 0; i < candidates.length; i += 1) {
        const field = candidates[i];
        const name = field.getAttribute('name') || '';
        if (name.indexOf('option[') !== 0) {
          continue;
        }
        if (field.type === 'hidden') {
          continue;
        }
        if (typeof field.focus === 'function') {
          field.focus();
        }
        break;
      }
    }

    function isMissingRequiredOptionError(json) {
      if (!json || json.success) {
        return false;
      }
      if (json.error_code === 'missing_required_option') {
        return true;
      }
      const message = String(json.message || '');
      return message.indexOf('задължителните опции') !== -1
        || message.indexOf('Missing required product option') !== -1
        || message.indexOf('Invalid product option') !== -1
        || message.indexOf('required product option') !== -1;
    }

    function handleMissingRequiredOptions() {
      submissionToken = '';
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
      submissionToken = '';
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
      }
      if (popupErrorEl()) {
        popupErrorEl().textContent = '';
      }
    }

    function quantityValue() {
      const qty = document.querySelector('#input-quantity, input[name="quantity"]');
      const parsed = qty ? parseInt(qty.value, 10) : 1;
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 1;
    }

    function bindProductRecalculationListeners() {
      // Proven Jet pattern: direct listeners on quantity + input-option* controls.
      const quantityNodes = document.querySelectorAll('#input-quantity, input[name="quantity"]');
      quantityNodes.forEach((node, index) => {
        if (index > 0) {
          return;
        }
        node.addEventListener('change', () => {
          scheduleRefreshCalculator('quantity change');
        });
        node.addEventListener('input', () => {
          scheduleRefreshCalculator('quantity change');
        });
      });

      const optionNodes = document.querySelectorAll('[id^="input-option"]');
      optionNodes.forEach((node) => {
        node.addEventListener('change', (event) => {
          const target = event.target;
          if (target && isOptionControl(target)) {
            scheduleRefreshCalculator('option change');
            return;
          }
          // Wrapper div (#input-option-N) receives bubbled radio/checkbox change.
          if (node !== target && isOptionControl(node)) {
            scheduleRefreshCalculator('option change');
          }
        });
      });

      // Document-level backup (survives late DOM quirks; Jet-equivalent selectors).
      document.addEventListener('change', (event) => {
        const target = event.target;
        if (!target) {
          return;
        }
        if (isQuantityControl(target)) {
          scheduleRefreshCalculator('quantity change');
          return;
        }
        if (isOptionControl(target)) {
          if (requiredProductOptionsSatisfied()) {
            clearEntryError();
          }
          scheduleRefreshCalculator('option change');
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
        if (!json.success && (json.error_code === 'missing_csrf' || json.error_code === 'invalid_csrf')) {
        }
        if (json.success && json.calculator) {
          renderCalculator(json.calculator);
          if (!modal.hidden && currentStep === 1) {
            populateSchemeSelect();
            await recalculateSelection();
          }
        } else if (calculator) {
          calculator.setAttribute('aria-busy', 'false');
        }
      } catch (error) {
        if (error.name !== 'AbortError' && calculator) {
          calculator.setAttribute('aria-busy', 'false');
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
          clearEntryError();
        } else if (isMissingRequiredOptionError(json)) {
          handleMissingRequiredOptions();
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
      clearEntryError();
      resetFirstInstallmentForSchemeChange();
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
      await recalculateSelection();
    }

    function closeModal() {
      unbindNativeCartAddObserver();
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
    }

    function secondaryActionUsesNativeAddToCart() {
      return (state.product_button_action || 'add_to_cart') !== 'buy';
    }

    function isCheckoutCartAddUrl(url) {
      const normalized = String(url || '').replace(/&amp;/g, '&');
      return normalized.indexOf('route=checkout/cart.add') !== -1
        || /\/checkout\/cart\.add(?:\?|$)/.test(normalized);
    }

    function parseAjaxJson(xhr) {
      if (!xhr) {
        return null;
      }
      if (xhr.responseJSON && typeof xhr.responseJSON === 'object') {
        return xhr.responseJSON;
      }
      try {
        return JSON.parse(xhr.responseText || '');
      } catch (error) {
        return null;
      }
    }

    function unbindNativeCartAddObserver() {
      const $ = window.jQuery;
      if ($ && typeof $.fn !== 'undefined') {
        $(document).off('ajaxSuccess.mtUniCreditCart');
      }
      awaitingNativeCartAdd = false;
    }

    /**
     * Observe OpenCart 4.1 standard Product cart.add AJAX (product.twig).
     * Close UniCredit modal only when json.success is present — not on validation errors.
     */
    function bindNativeCartAddSuccessCloser() {
      const $ = window.jQuery;
      if (!$ || typeof $.fn === 'undefined') {
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
      $(document).on('ajaxSuccess.mtUniCreditCart', handleCartAjax);
      return true;
    }

    function triggerSecondaryAction() {
      if (secondaryActionUsesNativeAddToCart()) {
        if (awaitingNativeCartAdd) {
          return;
        }
        const cartBtn = document.querySelector('#button-cart');
        if (!cartBtn) {
          return;
        }
        bindNativeCartAddSuccessCloser();
        cartBtn.click();
        // Native #form-product submit handler owns the cart request; we only observe completion.
        return;
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

    bindProductRecalculationListeners();

    renderCalculator(state.calculator);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
