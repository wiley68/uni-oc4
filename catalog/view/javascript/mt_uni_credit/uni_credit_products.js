let uni_old_vnoski;
function uniChangeContainer() {
    var uni_label_container = document.getElementsByClassName("uni-label-container")[0];
    if (uni_label_container.style.visibility == 'visible') {
        uni_label_container.style.visibility = 'hidden';
        uni_label_container.style.opacity = 0;
        uni_label_container.style.transition = 'visibility 0s, opacity 0.5s ease';
    } else {
        uni_label_container.style.visibility = 'visible';
        uni_label_container.style.opacity = 1;
    }
}
function uni_pogasitelni_vnoski_input_change(_uni_price) {
    const uni_vnoski = parseFloat(document.getElementById("uni_pogasitelni_vnoski_input").value);
    const uni_param_kimb_3 = parseFloat(document.getElementById("uni_param_kimb_3").value);
    const uni_param_kimb_4 = parseFloat(document.getElementById("uni_param_kimb_4").value);
    const uni_param_kimb_5 = parseFloat(document.getElementById("uni_param_kimb_5").value);
    const uni_param_kimb_6 = parseFloat(document.getElementById("uni_param_kimb_6").value);
    const uni_param_kimb_9 = parseFloat(document.getElementById("uni_param_kimb_9").value);
    const uni_param_kimb_10 = parseFloat(document.getElementById("uni_param_kimb_10").value);
    const uni_param_kimb_12 = parseFloat(document.getElementById("uni_param_kimb_12").value);
    const uni_param_kimb_18 = parseFloat(document.getElementById("uni_param_kimb_18").value);
    const uni_param_kimb_24 = parseFloat(document.getElementById("uni_param_kimb_24").value);
    const uni_param_kimb_30 = parseFloat(document.getElementById("uni_param_kimb_30").value);
    const uni_param_kimb_36 = parseFloat(document.getElementById("uni_param_kimb_36").value);

    $.ajax({
        url: calcUrl,
        type: 'post',
        dataType: 'json',
        data: {
            "uni_vnoski": uni_vnoski,
            "uni_price": _uni_price,
            "uni_param_kimb_3": uni_param_kimb_3,
            "uni_param_kimb_4": uni_param_kimb_4,
            "uni_param_kimb_5": uni_param_kimb_5,
            "uni_param_kimb_6": uni_param_kimb_6,
            "uni_param_kimb_9": uni_param_kimb_9,
            "uni_param_kimb_10": uni_param_kimb_10,
            "uni_param_kimb_12": uni_param_kimb_12,
            "uni_param_kimb_18": uni_param_kimb_18,
            "uni_param_kimb_24": uni_param_kimb_24,
            "uni_param_kimb_30": uni_param_kimb_30,
            "uni_param_kimb_36": uni_param_kimb_36
        },
        success: function (json) {
            const uni_eur = parseInt(document.getElementById("uni_eur").value);
            let uni_mesecna = 0;
            let uni_glp = 0;
            let uni_gpr = 0;
            switch (uni_vnoski) {
                case 3:
                    uni_mesecna = parseFloat(json['uni_mesecna_3']);
                    uni_gpr = parseFloat(json['uni_gpr_3']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_3").value);
                    break;
                case 4:
                    uni_mesecna = parseFloat(json['uni_mesecna_4']);
                    uni_gpr = parseFloat(json['uni_gpr_4']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_4").value);
                    break;
                case 5:
                    uni_mesecna = parseFloat(json['uni_mesecna_5']);
                    uni_gpr = parseFloat(json['uni_gpr_5']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_5").value);
                    break;
                case 6:
                    uni_mesecna = parseFloat(json['uni_mesecna_6']);
                    uni_gpr = parseFloat(json['uni_gpr_6']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_6").value);
                    break;
                case 9:
                    uni_mesecna = parseFloat(json['uni_mesecna_9']);
                    uni_gpr = parseFloat(json['uni_gpr_9']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_9").value);
                    break;
                case 10:
                    uni_mesecna = parseFloat(json['uni_mesecna_10']);
                    uni_gpr = parseFloat(json['uni_gpr_10']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_10").value);
                    break;
                case 12:
                    uni_mesecna = parseFloat(json['uni_mesecna_12']);
                    uni_gpr = parseFloat(json['uni_gpr_12']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_12").value);
                    break;
                case 18:
                    uni_mesecna = parseFloat(json['uni_mesecna_18']);
                    uni_gpr = parseFloat(json['uni_gpr_18']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_18").value);
                    break;
                case 24:
                    uni_mesecna = parseFloat(json['uni_mesecna_24']);
                    uni_gpr = parseFloat(json['uni_gpr_24']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_24").value);
                    break;
                case 30:
                    uni_mesecna = parseFloat(json['uni_mesecna_30']);
                    uni_gpr = parseFloat(json['uni_gpr_30']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_30").value);
                    break;
                case 36:
                    uni_mesecna = parseFloat(json['uni_mesecna_36']);
                    uni_gpr = parseFloat(json['uni_gpr_36']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_36").value);
                    break;
                default:
                    uni_mesecna = parseFloat(json['uni_mesecna_3']);
                    uni_gpr = parseFloat(json['uni_gpr_3']);
                    uni_glp = parseFloat(document.getElementById("uni_param_glp_3").value);
            }
            const uni_vnoska_int = document.getElementById("uni_vnoska_int");
            const uni_vnoska_dec = document.getElementById("uni_vnoska_dec");
            const uni_vnoska_second_int = document.getElementById("uni_vnoska_second_int");
            const uni_vnoska_second_dec = document.getElementById("uni_vnoska_second_dec");
            const uni_glp_int = document.getElementById("uni_glp_int");
            const uni_gpr_int = document.getElementById("uni_gpr_int");
            const uni_vnoska_arr = uni_mesecna.toFixed(2).split(".");
            uni_vnoska_int.textContent = uni_vnoska_arr[0];
            uni_vnoska_dec.textContent = uni_vnoska_arr[1];
            if (uni_vnoska_second_int !== null && uni_vnoska_second_dec !== null) {
                if (uni_eur == 1) {
                    const uni_vnoska_second_arr = (uni_mesecna / 1.95583).toFixed(2).split(".");
                    uni_vnoska_second_int.textContent = uni_vnoska_second_arr[0];
                    uni_vnoska_second_dec.textContent = uni_vnoska_second_arr[1];
                }
                if (uni_eur == 2) {
                    const uni_vnoska_second_arr = (uni_mesecna * 1.95583).toFixed(2).split(".");
                    uni_vnoska_second_int.textContent = uni_vnoska_second_arr[0];
                    uni_vnoska_second_dec.textContent = uni_vnoska_second_arr[1];
                }
            }
            uni_glp_int.textContent = uni_glp.toFixed(2);
            uni_gpr_int.textContent = uni_gpr.toFixed(2);
            uni_old_vnoski = uni_vnoski;
        }
    });
}

$(document).ready(function () {
    const uni_price = document.getElementById('uni_price');
    if (uni_price != null) {
        $("#uni-product-popup-container").prependTo("body");
        const uniProductPopupContainer = document.getElementById("uni-product-popup-container");
        let uni_price1 = uni_price.value;
        let uni_quantity = 1;
        let uni_priceall = parseFloat(uni_price1) * uni_quantity;
        const uni_buy_buttons_submit = document.querySelectorAll('#button-cart');

        $(document).on("click", "#btn_uni", function (e) {
            if (parseInt($("#uni_cart").val()) == 1) {
                if (uni_buy_buttons_submit.length) {
                    uni_buy_buttons_submit.item(0).click();
                }
            } else {
                let total_price = 0;
                uni_price1 = uni_price.value;
                if (document.getElementById("input-quantity") !== null) {
                    uni_quantity = parseFloat(document.getElementById("input-quantity").value);
                }
                if (document.querySelectorAll("[id*='input-option']").length > 0) {
                    if ($ != null) {
                        $.ajax({
                            url: (document.getElementById('uni_option_check_url') || {}).value || '',
                            type: 'post',
                            data: $('#form-product input[type=\'text\'], #form-product input[type=\'hidden\'], #form-product input[type=\'radio\']:checked, #form-product input[type=\'checkbox\']:checked, #form-product select, #form-product textarea'),
                            dataType: 'json',
                            success: function (json) {
                                $('.alert, .text-danger').remove();
                                if (json['error']) {
                                    if (json['error']['option']) {
                                        for (i in json['error']['option']) {
                                            if (!Object.prototype.hasOwnProperty.call(json['error']['option'], i)) {
                                                continue;
                                            }
                                            var element = $('#input-option-' + i);
                                            if (!element.length) {
                                                element = $('#input-option' + i.replace('_', '-'));
                                            }
                                            if (element.parent().hasClass('input-group')) {
                                                element.parent().after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
                                            } else {
                                                element.after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
                                            }
                                        }
                                    }
                                    $('.text-danger').parent().addClass('has-error');
                                    alert('Моля изберете стойност за задължителните опции за продукта!');
                                }
                                if (json['success']) {
                                    for (i = 0; i < json['optionresult'].length; i++) {
                                        var current_options = json['optionresult'][i]['product_option_id_check'];
                                        if (Object.prototype.toString.call(current_options) === '[object Array]') {
                                            for (m = 0; m < current_options.length; m++) {
                                                for (n = 0; n < json['optionresult'][i]['product_option_value'].length; n++) {
                                                    var tempid = parseInt(JSON.stringify(json['optionresult'][i]['product_option_value'][n]['product_option_value_id']).replace(/['"]+/g, ''));
                                                    var curid = parseInt(JSON.stringify(current_options[m]).replace(/['"]+/g, ''));
                                                    if (tempid == curid) {
                                                        total_price += parseFloat(JSON.stringify(json['optionresult'][i]['product_option_value'][n]['price']).replace(/['"]+/g, ''));
                                                    }
                                                }
                                            }
                                        } else {
                                            for (j = 0; j < json['optionresult'][i]['product_option_value'].length; j++) {
                                                var tempid = parseInt(JSON.stringify(json['optionresult'][i]['product_option_value'][j]['product_option_value_id']).replace(/['"]+/g, ''));
                                                var curid = parseInt(JSON.stringify(current_options).replace(/['"]+/g, ''));
                                                if (tempid == curid) {
                                                    total_price += parseFloat(JSON.stringify(json['optionresult'][i]['product_option_value'][j]['price']).replace(/['"]+/g, ''));
                                                }
                                            }
                                        }
                                    }

                                    uni_price1 = parseFloat(uni_price1) + total_price;
                                    uni_priceall = parseFloat(uni_price1) * uni_quantity;

                                    const uni_eur = parseInt(document.getElementById("uni_eur").value);
                                    const uni_currency_code = document.getElementById("uni_currency_code").value;
                                    switch (uni_eur) {
                                        case 0:
                                            break;
                                        case 1:
                                            if (uni_currency_code == "EUR") {
                                                uni_priceall = uni_priceall * 1.95583;
                                            }
                                            break;
                                        case 2:
                                        case 3:
                                            if (uni_currency_code == "BGN") {
                                                uni_priceall = uni_priceall / 1.95583;
                                            }
                                            break;
                                    }
                                    uni_priceall = Math.round(uni_priceall * 100) / 100;
                                    const uni_price_int = document.getElementById('uni_price_int');
                                    uni_price_int.innerHTML = Math.floor(uni_priceall);
                                    const uni_price_dec = document.getElementById('uni_price_dec');
                                    const decimalPartTwoDigitsStr = String(Math.ceil((uni_priceall - Math.trunc(uni_priceall)) * 100)).padStart(2, '0');
                                    uni_price_dec.innerHTML = decimalPartTwoDigitsStr;

                                    const uni_price_second_int = document.getElementById("uni_price_second_int");
                                    const uni_price_second_dec = document.getElementById("uni_price_second_dec");
                                    if (uni_price_second_int !== null && uni_price_second_dec !== null) {
                                        if (uni_eur == 1) {
                                            const uni_price_second_arr = (uni_priceall / 1.95583).toFixed(2).split(".");
                                            uni_price_second_int.textContent = uni_price_second_arr[0];
                                            uni_price_second_dec.textContent = uni_price_second_arr[1];
                                        }
                                        if (uni_eur == 2) {
                                            const uni_price_second_arr = (uni_priceall * 1.95583).toFixed(2).split(".");
                                            uni_price_second_int.textContent = uni_price_second_arr[0];
                                            uni_price_second_dec.textContent = uni_price_second_arr[1];
                                        }
                                    }

                                    uniProductPopupContainer.style.display = "block";
                                    uni_pogasitelni_vnoski_input_change(uni_priceall);
                                }
                            }
                        });
                    } else {
                        uni_price1 = parseFloat(uni_price1) + total_price;
                        uni_priceall = parseFloat(uni_price1) * uni_quantity;

                        const uni_eur = parseInt(document.getElementById("uni_eur").value);
                        const uni_currency_code = document.getElementById("uni_currency_code").value;
                        switch (uni_eur) {
                            case 0:
                                break;
                            case 1:
                                if (uni_currency_code == "EUR") {
                                    uni_priceall = uni_priceall * 1.95583;
                                }
                                break;
                            case 2:
                            case 3:
                                if (uni_currency_code == "BGN") {
                                    uni_priceall = uni_priceall / 1.95583;
                                }
                                break;
                        }

                        const uni_price_int = document.getElementById('uni_price_int');
                        uni_price_int.innerHTML = Math.floor(uni_priceall);
                        const uni_price_dec = document.getElementById('uni_price_dec');
                        const decimalPartTwoDigitsStr = String(Math.ceil((uni_priceall - Math.trunc(uni_priceall)) * 100)).padStart(2, '0');
                        uni_price_dec.innerHTML = decimalPartTwoDigitsStr;

                        const uni_price_second_int = document.getElementById("uni_price_second_int");
                        const uni_price_second_dec = document.getElementById("uni_price_second_dec");
                        if (uni_price_second_int !== null && uni_price_second_dec !== null) {
                            if (uni_eur == 1) {
                                const uni_price_second_arr = (uni_priceall / 1.95583).toFixed(2).split(".");
                                uni_price_second_int.textContent = uni_price_second_arr[0];
                                uni_price_second_dec.textContent = uni_price_second_arr[1];
                            }
                            if (uni_eur == 2) {
                                const uni_price_second_arr = (uni_priceall * 1.95583).toFixed(2).split(".");
                                uni_price_second_int.textContent = uni_price_second_arr[0];
                                uni_price_second_dec.textContent = uni_price_second_arr[1];
                            }
                        }

                        uniProductPopupContainer.style.display = "block";
                        uni_pogasitelni_vnoski_input_change(uni_priceall);
                    }
                } else {
                    uni_price1 = parseFloat(uni_price1) + total_price;
                    uni_priceall = parseFloat(uni_price1) * uni_quantity;

                    const uni_eur = parseInt(document.getElementById("uni_eur").value);
                    const uni_currency_code = document.getElementById("uni_currency_code").value;
                    switch (uni_eur) {
                        case 0:
                            break;
                        case 1:
                            if (uni_currency_code == "EUR") {
                                uni_priceall = uni_priceall * 1.95583;
                            }
                            break;
                        case 2:
                        case 3:
                            if (uni_currency_code == "BGN") {
                                uni_priceall = uni_priceall / 1.95583;
                            }
                            break;
                    }

                    const uni_price = document.getElementById('uni_price');
                    uni_price.value = uni_priceall;
                    const uni_price_int = document.getElementById('uni_price_int');
                    uni_price_int.innerHTML = Math.floor(uni_priceall);
                    const uni_price_dec = document.getElementById('uni_price_dec');
                    const decimalPartTwoDigitsStr = String(Math.ceil((uni_priceall - Math.trunc(uni_priceall)) * 100)).padStart(2, '0');
                    uni_price_dec.innerHTML = decimalPartTwoDigitsStr;

                    const uni_price_second_int = document.getElementById("uni_price_second_int");
                    const uni_price_second_dec = document.getElementById("uni_price_second_dec");
                    if (uni_price_second_int !== null && uni_price_second_dec !== null) {
                        if (uni_eur == 1) {
                            const uni_price_second_arr = (uni_priceall / 1.95583).toFixed(2).split(".");
                            uni_price_second_int.textContent = uni_price_second_arr[0];
                            uni_price_second_dec.textContent = uni_price_second_arr[1];
                        }
                        if (uni_eur == 2) {
                            const uni_price_second_arr = (uni_priceall * 1.95583).toFixed(2).split(".");
                            uni_price_second_int.textContent = uni_price_second_arr[0];
                            uni_price_second_dec.textContent = uni_price_second_arr[1];
                        }
                    }

                    uniProductPopupContainer.style.display = "block";
                    uni_pogasitelni_vnoski_input_change(uni_priceall);
                }
            }
        });

        $("#uni_pogasitelni_vnoski_input").on("change", function () {
            uni_pogasitelni_vnoski_input_change(uni_priceall);
        });

        $(document).on("focus", "#uni_pogasitelni_vnoski_input", function () {
            uni_old_vnoski = $(this).val();
        });

        $(document).on("click", "#uni_back_unicredit", function () {
            $("#uni-product-popup-container").hide("slow");
        });

        $(document).on("click", "#uni_buy_unicredit", function (e) {
            e.preventDefault();
            $.ajax({
                url: (document.getElementById('uni_cart_add_url') || {}).value || '',
                type: 'post',
                dataType: 'json',
                data: $('#form-product input[type=\'text\'], #form-product input[type=\'hidden\'], #form-product input[type=\'radio\']:checked, #form-product input[type=\'checkbox\']:checked, #form-product select, #form-product textarea'),
                success: function (json) {
                    if (json['error']) {
                        var optAlert = false;
                        for (var k in json['error']) {
                            if (!Object.prototype.hasOwnProperty.call(json['error'], k)) {
                                continue;
                            }
                            var errMsg = json['error'][k];
                            if (k.indexOf('option_') === 0) {
                                var optId = k.substring('option_'.length);
                                var el = $('#input-option-' + optId);
                                if (!el.length) {
                                    el = $('#input-option' + optId.replace(/_/g, '-'));
                                }
                                if (el.length) {
                                    if (el.parent().hasClass('input-group')) {
                                        el.parent().after('<div class="text-danger">' + errMsg + '</div>');
                                    } else {
                                        el.after('<div class="text-danger">' + errMsg + '</div>');
                                    }
                                    optAlert = true;
                                }
                            } else if (k === 'subscription') {
                                $('#input-subscription').after('<div class="text-danger">' + errMsg + '</div>');
                            }
                        }
                        if (json['error']['option']) {
                            for (var j in json['error']['option']) {
                                if (!Object.prototype.hasOwnProperty.call(json['error']['option'], j)) {
                                    continue;
                                }
                                var el2 = $('#input-option-' + j);
                                if (!el2.length) {
                                    el2 = $('#input-option' + j.replace(/_/g, '-'));
                                }
                                if (el2.length) {
                                    if (el2.parent().hasClass('input-group')) {
                                        el2.parent().after('<div class="text-danger">' + json['error']['option'][j] + '</div>');
                                    } else {
                                        el2.after('<div class="text-danger">' + json['error']['option'][j] + '</div>');
                                    }
                                    optAlert = true;
                                }
                            }
                        }
                        if (optAlert) {
                            alert("Трябва да изберете някоя от опциите за този продукт, за да можете да го добавите в количката си!");
                        }
                        $('.text-danger').parent().addClass('has-error');
                    }
                    if (json['success']) {
                        var cartPage = (document.getElementById('uni_cart_page_url') || {}).value || '';
                        window.location = cartPage || 'index.php?route=checkout/cart';
                    }
                },
                error: function (xhr, ajaxOptions, thrownError) {
                    alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                }
            });
        });
    }
});