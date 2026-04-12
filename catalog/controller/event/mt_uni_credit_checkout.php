<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

/**
 * Чекаута: ресурси за UniCredit плащане + скрипт за автоматичен избор на метода.
 */
class MtUniCreditCheckout extends \Opencart\System\Engine\Controller
{
  private string $module = 'module_mt_uni_credit';

  /**
   * CSS/JS за формата на плащане UniCredit (фрагментът се зарежда по AJAX — външните файлове са в <head>).
   *
   * @param string             $route
   * @param array<int, mixed> $args
   */
  public function registerCheckoutAssets(string &$route, array &$args): void
  {
    if ($route !== 'checkout/checkout' || !(int) $this->config->get($this->module . '_status')) {
      return;
    }

    $cssExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/stylesheet/mt_uni_credit/uni_payment_checkout.css';
    $jsExt = \DIR_EXTENSION . 'mt_uni_credit/catalog/view/javascript/mt_uni_credit/uni_payment_checkout.js';
    $cssCatalog = \DIR_APPLICATION . 'view/stylesheet/mt_uni_credit/uni_payment_checkout.css';
    $jsCatalog = \DIR_APPLICATION . 'view/javascript/mt_uni_credit/uni_payment_checkout.js';

    $cssPath = is_file($cssCatalog) ? $cssCatalog : $cssExt;
    $jsPath = is_file($jsCatalog) ? $jsCatalog : $jsExt;

    $verCss = is_file($cssPath) ? (string) filemtime($cssPath) : '0';
    $verJs = is_file($jsPath) ? (string) filemtime($jsPath) : '0';

    $this->document->addStyle('catalog/view/stylesheet/mt_uni_credit/uni_payment_checkout.css?ver=' . $verCss);
    $this->document->addScript('catalog/view/javascript/mt_uni_credit/uni_payment_checkout.js?ver=' . $verJs);
  }

  /**
   * @param string               $route
   * @param array<string, mixed> $data
   * @param string               $output
   */
  public function appendScript(string &$route, array &$data, string &$output): void
  {
    if ($route !== 'checkout/checkout' || !(int) $this->config->get($this->module . '_status')) {
      return;
    }

    $code = isset($this->session->data['mt_uni_credit_auto_payment_code']) ? (string) $this->session->data['mt_uni_credit_auto_payment_code'] : '';
    if ($code === '') {
      return;
    }

    $months = (int) ($this->session->data['mt_uni_credit_installment_months'] ?? 0);

    unset($this->session->data['mt_uni_credit_auto_payment_code']);

    $payload = json_encode([
      'payment_code' => $code,
      'months'       => $months,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    $script = <<<HTML
<script type="text/javascript">
(function () {
  var cfg = {$payload};
  if (!cfg || !cfg.payment_code) { return; }
  var openedModal = false;
  function openPaymentChooser() {
    if (openedModal || !cfg.payment_code) { return; }
    var \$btn = $('#button-payment-methods');
    if (!\$btn.length) { return; }
    openedModal = true;
    $('#input-payment-code').val(cfg.payment_code);
    \$btn.trigger('click');
  }
  $(document).ajaxSuccess(function (ev, xhr, settings) {
    var url = settings.url || '';
    var j = xhr.responseJSON;
    if (j && j.success && cfg.payment_code) {
      if (url.indexOf('checkout/shipping_method.save') !== -1) {
        setTimeout(openPaymentChooser, 450);
      }
      if (url.indexOf('checkout/payment_address.save') !== -1 && !$('#checkout-shipping-method').length) {
        setTimeout(openPaymentChooser, 450);
      }
    }
    if (url.indexOf('checkout/payment_method.getMethods') === -1) { return; }
    var json = xhr.responseJSON;
    if (!json || !json.payment_methods) { return; }
    setTimeout(function () {
      var code = cfg.payment_code;
      var \$radio = $('#modal-payment input[name="payment_method"][value="' + code.replace(/"/g, '\\"') + '"]');
      if (\$radio.length) {
        \$radio.prop('checked', true);
        $('#form-payment-method').trigger('submit');
      }
    }, 200);
  });
  $(function () {
    setTimeout(function () {
      if (cfg.payment_code) {
        openPaymentChooser();
      }
    }, 2200);
  });
})();
</script>
HTML;

    $output .= $script;
  }
}
