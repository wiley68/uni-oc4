/**
 * UniCredit popup for cart page.
 */
(function ($, window, document) {
    'use strict';

    function parseConfig() {
        var el = document.getElementById('uni-cart-config');
        if (!el || !el.textContent) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }

    function toParts(v) {
        var n = parseFloat(String(v).replace(',', '.'));
        if (!isFinite(n)) {
            n = 0;
        }
        var p = n.toFixed(2).split('.');
        return { i: p[0], d: p[1] };
    }

    function setMoney(idInt, idDec, value) {
        var p = toParts(value);
        var intNodes = document.querySelectorAll('[id="' + idInt + '"]');
        var decNodes = document.querySelectorAll('[id="' + idDec + '"]');
        intNodes.forEach(function (node) {
            node.textContent = p.i;
        });
        decNodes.forEach(function (node) {
            node.textContent = p.d;
        });
    }

    function toNumberSafe(v) {
        var n = parseFloat(String(v).replace(',', '.'));
        return isFinite(n) ? n : 0;
    }

    function resolveSecondary(primary, secondaryFromApi, eurMode) {
        if (secondaryFromApi !== undefined && secondaryFromApi !== null && String(secondaryFromApi).trim() !== '') {
            return toNumberSafe(secondaryFromApi);
        }
        var p = toNumberSafe(primary);
        if (eurMode === 1) {
            return p / 1.95583;
        }
        if (eurMode === 2) {
            return p * 1.95583;
        }
        return 0;
    }

    function updateButton(months, monthly, monthlySecond, eurMode) {
        var m = document.getElementById('uni_button_installments_label');
        var t = document.getElementById('uni_button_mesecna_main');
        var t2 = document.getElementById('uni_button_mesecna_second');
        var signEl = document.getElementById('uni_sign');
        var signSecondEl = document.getElementById('uni_sign_second');
        if (m) {
            m.textContent = String(months);
        }
        if (t) {
            t.textContent = Number(monthly || 0).toFixed(2) + ' ' + (signEl ? signEl.value : '');
        }
        if (t2) {
            if (eurMode === 0 || eurMode === 3 || Number(monthlySecond || 0) === 0) {
                t2.style.display = 'none';
                t2.textContent = '';
            } else {
                t2.style.display = '';
                t2.style.fontSize = '80%';
                t2.textContent = ' (' + Number(monthlySecond || 0).toFixed(2) + ' ' + (signSecondEl ? signSecondEl.value : '') + ')';
            }
        }
    }

    function calculate(cfg, months) {
        return $.ajax({
            url: document.getElementById('uni_get_product_link').value,
            type: 'post',
            dataType: 'json',
            data: {
                uni_promo: cfg.uni_promo,
                uni_promo_data: cfg.uni_promo_data,
                uni_promo_meseci_znak: cfg.uni_promo_meseci_znak,
                uni_promo_meseci: cfg.uni_promo_meseci,
                uni_promo_price: cfg.uni_promo_price,
                uni_product_cat_id: cfg.uni_product_cat_id,
                uni_meseci: months,
                uni_total_price: cfg.price,
                uni_service: cfg.uni_service,
                uni_parva: 0,
                uni_sertificat: cfg.uni_sertificat,
                uni_liveurl: cfg.uni_liveurl,
                uni_eur: cfg.uni_eur
            }
        });
    }

    $(function () {
        function getCfg() {
            return parseConfig();
        }

        function runCalc() {
            var cfg = getCfg();
            if (!cfg || !$('#btn_uni').length) {
                return $.Deferred().resolve().promise();
            }
            var months = $('#uni_pogasitelni_vnoski_input').val() || cfg.months || '12';
            return calculate(cfg, months).done(function (json) {
                if (!json || json.success !== 'success') {
                    return;
                }
                var eurMode = parseInt(cfg.uni_eur, 10) || 0;
                var obshtoSecond = resolveSecondary(json.uni_obshto, json.uni_obshto_second, eurMode);
                var mesecnaSecond = resolveSecondary(json.uni_mesecna, json.uni_mesecna_second, eurMode);
                cfg.price = json.uni_obshto;
                $('#uni_price').val(json.uni_obshto);
                setMoney('uni_price_int', 'uni_price_dec', json.uni_obshto);
                setMoney('uni_vnoska_int', 'uni_vnoska_dec', json.uni_mesecna);
                setMoney('uni_price_second_int', 'uni_price_second_dec', obshtoSecond);
                setMoney('uni_vnoska_second_int', 'uni_vnoska_second_dec', mesecnaSecond);
                $('#uni_glp_int').text(Number(json.uni_glp || 0).toFixed(2));
                $('#uni_gpr_int').text(Number(json.uni_gpr || 0).toFixed(2));
                updateButton(months, json.uni_mesecna, mesecnaSecond, eurMode);
            });
        }

        $(document).off('.uniCartMtCredit');

        $(document).on('click.uniCartMtCredit', '#btn_uni', function () {
            $('#uni-product-popup-container').show();
            runCalc();
        });

        $(document).on('change.uniCartMtCredit', '#uni_pogasitelni_vnoski_input', function () {
            runCalc();
        });

        $(document).on('click.uniCartMtCredit', '#uni_back_unicredit', function () {
            $('#uni-product-popup-container').hide();
        });

        $(document).on('click.uniCartMtCredit', '#uni_buy_on_installment', function (e) {
            e.preventDefault();
            var cfg = getCfg();
            if (!cfg) {
                return;
            }
            var months = $('#uni_pogasitelni_vnoski_input').val() || cfg.months || '12';
            $.ajax({
                url: $('#uni_prepare_installmentcheckout_url').val(),
                type: 'post',
                dataType: 'json',
                data: { installment_months: months },
                success: function (json) {
                    if (json && json.redirect) {
                        window.location = json.redirect;
                    } else if ($('#uni_checkout_url').val()) {
                        window.location = $('#uni_checkout_url').val();
                    }
                }
            });
        });

        $(document).ajaxComplete(function (event, xhr, settings) {
            var url = (settings && settings.url) ? settings.url : '';
            if (url.indexOf('checkout/cart.remove') !== -1 || url.indexOf('checkout/cart.list') !== -1) {
                runCalc();
            }
        });

        runCalc();
    });
})(jQuery, window, document);
