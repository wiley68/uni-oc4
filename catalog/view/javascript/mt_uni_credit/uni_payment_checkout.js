/**
 * UniCredit — форма за плащане в чекаута (OC4).
 * Конфигурация: #uni-checkout-payment-config (application/json).
 * Зарежда се в <head> на checkout; инициализация от inline скрипт във фрагмента на плащането.
 */
(function ($, window) {
    'use strict';

    function readConfig() {
        var el = document.getElementById('uni-checkout-payment-config');
        if (!el || !el.textContent) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }

    function calculateUni(cfg, meseci) {
        if (!cfg || !cfg.calculateUrl) {
            return;
        }
        $.ajax({
            url: cfg.calculateUrl,
            type: 'post',
            dataType: 'json',
            data: {
                uni_promo: cfg.uni_promo,
                uni_promo_data: cfg.uni_promo_data,
                uni_promo_meseci_znak: cfg.uni_promo_meseci_znak,
                uni_promo_meseci: cfg.uni_promo_meseci,
                uni_promo_price: cfg.uni_promo_price,
                uni_product_cat_id: cfg.uni_product_cat_id,
                uni_meseci: meseci,
                uni_total_price: $('#uni_price').val(),
                uni_service: cfg.uni_service,
                uni_parva: $('#uni_parva').val(),
                uni_user: cfg.uni_user,
                uni_password: cfg.uni_password,
                uni_sertificat: cfg.uni_sertificat,
                uni_liveurl: cfg.uni_liveurl,
                uni_eur: cfg.uni_eur
            },
            success: function (json) {
                if (!json) {
                    return;
                }
                $('#uni_obshto').val(json.uni_obshto);
                if (json.uni_obshto_second === 0 || json.uni_obshto_second === '0') {
                    $('#uni_obshto_second').val(json.uni_obshto);
                } else {
                    $('#uni_obshto_second').val(json.uni_obshto + ' (' + json.uni_obshto_second + ')');
                }
                $('#uni_mesecna').val(json.uni_mesecna);
                if (json.uni_mesecna_second === 0 || json.uni_mesecna_second === '0') {
                    $('#uni_mesecna_second').val(json.uni_mesecna);
                } else {
                    $('#uni_mesecna_second').val(json.uni_mesecna + ' (' + json.uni_mesecna_second + ')');
                }
                $('#uni_glp').val(json.uni_glp);
                $('#uni_obshtozaplashtane').val(json.uni_obshtozaplashtane);
                if (json.uni_obshtozaplashtane_second === 0 || json.uni_obshtozaplashtane_second === '0') {
                    $('#uni_obshtozaplashtane_second').val(json.uni_obshtozaplashtane);
                } else {
                    $('#uni_obshtozaplashtane_second').val(json.uni_obshtozaplashtane + ' (' + json.uni_obshtozaplashtane_second + ')');
                }
                $('#uni_gpr').val(json.uni_gpr);
                $('#uni_kop').val(json.uni_kop);
            }
        });
    }

    function updateTermsButtonState(cfg) {
        var $btn = $('#checkout-payment #button-confirm');
        if (!$btn.length) {
            return;
        }
        if (String(cfg.uni_proces2) === '1' && cfg.btnStatus === '') {
            $btn.prop('disabled', !$('#uni_uslovia').prop('checked'));
        }
    }

    function bindOnce(cfg) {
        $(document).off('.uniMtCreditPay');

        $(document).on('click.uniMtCreditPay', '#checkout-payment #button-confirm', function (e) {
            if (!$('#uni-checkout-container').length) {
                return;
            }
            e.stopImmediatePropagation();
            var $btn = $(this);
            $btn.prop('disabled', true);
            if (typeof $btn.button === 'function') {
                $btn.button('loading');
            }
            $.ajax({
                type: 'post',
                url: cfg.confirmUrl,
                dataType: 'json',
                data: {
                    uni_mesecna: $('#uni_mesecna').val(),
                    uni_gpr: $('#uni_gpr').val(),
                    uni_glp: $('#uni_glp').val(),
                    uni_vnoski: $('#uni_pogasitelni_vnoski').val(),
                    uni_parva: $('#uni_parva').val(),
                    uni_fname: $('#uni_firstname').val(),
                    uni_lname: $('#uni_lastname').val(),
                    uni_phone: $('#uni_phone').val(),
                    uni_phone2: $('#uni_phone2').val(),
                    uni_email: $('#uni_email').val(),
                    uni_egn: $('#uni_egn').val(),
                    uni_description: $('#uni_description').val(),
                    uni_kop: $('#uni_kop').val()
                },
                success: function (json) {
                    if (json.redirect) {
                        window.location = json.redirect;
                        return;
                    }
                    if (json.error && window.console) {
                        console.log(json.error);
                    }
                    $btn.prop('disabled', false);
                    if (typeof $btn.button === 'function') {
                        $btn.button('reset');
                    }
                },
                error: function (xhr, ajaxOptions, thrownError) {
                    $btn.prop('disabled', false);
                    if (typeof $btn.button === 'function') {
                        $btn.button('reset');
                    }
                    alert(thrownError + '\r\n' + xhr.statusText + '\r\n' + xhr.responseText);
                }
            });
        });

        $(document).on('change.uniMtCreditPay', '#uni-checkout-container #uni_pogasitelni_vnoski', function () {
            calculateUni(cfg, $(this).val());
        });

        $(document).on('change.uniMtCreditPay', '#uni-checkout-container #uni_parva_chec', function () {
            if ($(this).prop('checked')) {
                $('#uni_parva').attr('readonly', false);
            } else {
                $('#uni_parva').attr('readonly', true);
            }
        });

        $(document).on('change.uniMtCreditPay', '#uni-checkout-container #uni_uslovia', function () {
            updateTermsButtonState(cfg);
        });

        $(document).on('click.uniMtCreditPay', '#uni-checkout-container #uni_parva_button', function () {
            calculateUni(cfg, $('#uni_pogasitelni_vnoski').val());
        });
    }

    function init() {
        var cfg = readConfig();
        if (!cfg) {
            return;
        }
        bindOnce(cfg);
        calculateUni(cfg, cfg.uni_shema_current);
        updateTermsButtonState(cfg);
    }

    window.uniMtCreditPaymentCheckoutInit = init;
})(jQuery, window);
