let uni_old_vnoski;

function uniGetEurBgnRate() {
  const el = document.getElementById("uni_eur_bgn_rate");
  const n = el ? parseFloat(String(el.value).replace(",", ".")) : NaN;
  return Number.isFinite(n) && n > 0 ? n : 1.95583;
}

/**
 * Закръгляне до 2 знака (стотинки) за суми от float операции.
 */
function uniRoundMoney2(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n)) {
    return 0;
  }
  return Math.round(n * 100) / 100;
}

/**
 * Показване на цена като цяла част + два знака стотинки без float артефакти (напр. 1040 вместо 1039.100).
 */
function uniMoneyToDisplayParts(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n)) {
    return { intPart: 0, decTwo: "00" };
  }
  let cents = Math.round(n * 100);
  if (cents < 0) {
    cents = 0;
  }
  return {
    intPart: Math.floor(cents / 100),
    decTwo: String(cents % 100).padStart(2, "0"),
  };
}

/**
 * Парсване на сума от OC (число/низ), без междинно закръгляне — като float в количката.
 */
function uniParseMoney(v) {
  if (v === null || v === undefined) {
    return 0;
  }
  const n = parseFloat(String(v).replace(",", "."));
  return Number.isFinite(n) ? n : 0;
}

/**
 * (базова цена + сума опции) × количество, после стандартно закръгляне до 2 знака (като OpenCart).
 */
function uniLineTotalFromPartsFloat(baseStr, optsSum, quantity) {
  let q = Number(quantity);
  if (!Number.isFinite(q) || q <= 0) {
    q = 1;
  }
  const base = uniParseMoney(baseStr);
  const opts = Number(optsSum);
  const optsSafe = Number.isFinite(opts) ? opts : 0;
  return uniRoundMoney2((base + optsSafe) * q);
}

/**
 * Сума на избраните опции от отговора на uni_option (float, без закръгляне поотделно).
 */
function uniSumOptionPricesFromJson(json) {
  let total_opts = 0;
  if (!json || !json.optionresult) {
    return total_opts;
  }
  let i;
  let m;
  let n;
  let j;
  for (i = 0; i < json.optionresult.length; i++) {
    const current_options = json.optionresult[i].product_option_id_check;
    if (Object.prototype.toString.call(current_options) === "[object Array]") {
      for (m = 0; m < current_options.length; m++) {
        for (n = 0; n < json.optionresult[i].product_option_value.length; n++) {
          const tempid = parseInt(
            JSON.stringify(
              json.optionresult[i].product_option_value[n]
                .product_option_value_id,
            ).replace(/['"]+/g, ""),
            10,
          );
          const curid = parseInt(
            JSON.stringify(current_options[m]).replace(/['"]+/g, ""),
            10,
          );
          if (tempid === curid) {
            total_opts += uniParseMoney(
              json.optionresult[i].product_option_value[n].price,
            );
          }
        }
      }
    } else {
      for (j = 0; j < json.optionresult[i].product_option_value.length; j++) {
        const tempid = parseInt(
          JSON.stringify(
            json.optionresult[i].product_option_value[j]
              .product_option_value_id,
          ).replace(/['"]+/g, ""),
          10,
        );
        const curid = parseInt(
          JSON.stringify(current_options).replace(/['"]+/g, ""),
          10,
        );
        if (tempid === curid) {
          total_opts += uniParseMoney(
            json.optionresult[i].product_option_value[j].price,
          );
        }
      }
    }
  }
  return total_opts;
}

function uniApplyEurConversion(lineTotal) {
  const uni_eur = parseInt(document.getElementById("uni_eur").value, 10);
  const uni_currency_code = document.getElementById("uni_currency_code").value;
  const rate = uniGetEurBgnRate();
  let uni_priceall = lineTotal;
  switch (uni_eur) {
    case 0:
      break;
    case 1:
      if (uni_currency_code === "EUR") {
        uni_priceall = uni_priceall * rate;
      }
      break;
    case 2:
    case 3:
      if (uni_currency_code === "BGN") {
        uni_priceall = uni_priceall / rate;
      }
      break;
    default:
      break;
  }
  return uniRoundMoney2(uni_priceall);
}

/**
 * Попъп: обновява показаните суми (редовата сума за показване).
 * Скритото #uni_price не се пипа — остава единичната базова цена от Twig.
 */
function uniUpdatePopupLineTotalDisplay(uni_priceall) {
  const uniPriceParts = uniMoneyToDisplayParts(uni_priceall);
  const uni_price_int = document.getElementById("uni_price_int");
  if (uni_price_int) {
    uni_price_int.innerHTML = uniPriceParts.intPart;
  }
  const uni_price_dec = document.getElementById("uni_price_dec");
  if (uni_price_dec) {
    uni_price_dec.innerHTML = uniPriceParts.decTwo;
  }
  const uni_eur = parseInt(document.getElementById("uni_eur").value, 10);
  const uni_price_second_int = document.getElementById("uni_price_second_int");
  const uni_price_second_dec = document.getElementById("uni_price_second_dec");
  if (uni_price_second_int !== null && uni_price_second_dec !== null) {
    if (uni_eur === 1) {
      const uni_price_second_arr = (uni_priceall / uniGetEurBgnRate())
        .toFixed(2)
        .split(".");
      uni_price_second_int.textContent = uni_price_second_arr[0];
      uni_price_second_dec.textContent = uni_price_second_arr[1];
    }
    if (uni_eur === 2) {
      const uni_price_second_arr = (uni_priceall * uniGetEurBgnRate())
        .toFixed(2)
        .split(".");
      uni_price_second_int.textContent = uni_price_second_arr[0];
      uni_price_second_dec.textContent = uni_price_second_arr[1];
    }
  }
}

/**
 * Текст на бутона „N вноски“ / месечна вноска (като динамично обновяване при PS модули).
 */
function uniUpdateButtonInstallmentLabels(uni_mesecna, months) {
  const label = document.getElementById("uni_button_installments_label");
  if (label) {
    label.textContent = String(months);
  }
  const main = document.getElementById("uni_button_mesecna_main");
  const signEl = document.getElementById("uni_sign");
  const sign = signEl ? signEl.value : "";
  if (main) {
    main.textContent = uni_mesecna.toFixed(2) + " " + sign;
  }
  const second = document.getElementById("uni_button_mesecna_second");
  if (!second) {
    return;
  }
  const uni_eur = parseInt(document.getElementById("uni_eur").value, 10);
  if (uni_eur === 0 || uni_eur === 3) {
    second.style.display = "none";
    second.textContent = "";
    return;
  }
  second.style.display = "";
  second.style.fontSize = "80%";
  const signSecondEl = document.getElementById("uni_sign_second");
  const signSecond = signSecondEl ? signSecondEl.value : "";
  let secVal = 0;
  if (uni_eur === 1) {
    secVal = uni_mesecna / uniGetEurBgnRate();
  } else if (uni_eur === 2) {
    secVal = uni_mesecna * uniGetEurBgnRate();
  }
  second.textContent = "(" + secVal.toFixed(2) + " " + signSecond + ")";
}

function uniChangeContainer() {
  var uni_label_container = document.getElementsByClassName(
    "uni-label-container",
  )[0];
  if (uni_label_container.style.visibility == "visible") {
    uni_label_container.style.visibility = "hidden";
    uni_label_container.style.opacity = 0;
    uni_label_container.style.transition = "visibility 0s, opacity 0.5s ease";
  } else {
    uni_label_container.style.visibility = "visible";
    uni_label_container.style.opacity = 1;
  }
}

function uniMtCreditGetShopString(key, fallback) {
  var o = window.uniPaymentShopStrings || {};
  if (o && typeof o[key] === "string" && o[key] !== "") {
    return o[key];
  }
  return fallback || "";
}

function uniMtCreditSetInstallmentCookies(paymentCode, months) {
  var maxAge = 3600;
  var base = "; path=/; SameSite=Lax; max-age=" + maxAge;
  document.cookie =
    "mt_uni_credit_payment=" +
    encodeURIComponent(paymentCode || "uni.uni") +
    base;
  document.cookie =
    "mt_uni_credit_months=" +
    encodeURIComponent(String(months != null ? months : "")) +
    base;
}

/** @returns {boolean} true ако има грешки и не трябва да продължаваме */
function uniMtCreditCartAddShowErrors(json) {
  if (!json || !json.error) {
    return false;
  }
  var optAlert = false;
  var k;
  var j;
  for (k in json.error) {
    if (!Object.prototype.hasOwnProperty.call(json.error, k)) {
      continue;
    }
    var errMsg = json.error[k];
    if (k.indexOf("option_") === 0) {
      var optId = k.substring("option_".length);
      var el = $("#input-option-" + optId);
      if (!el.length) {
        el = $("#input-option" + optId.replace(/_/g, "-"));
      }
      if (el.length) {
        if (el.parent().hasClass("input-group")) {
          el.parent().after('<div class="text-danger">' + errMsg + "</div>");
        } else {
          el.after('<div class="text-danger">' + errMsg + "</div>");
        }
        optAlert = true;
      }
    } else if (k === "subscription") {
      $("#input-subscription").after(
        '<div class="text-danger">' + errMsg + "</div>",
      );
    }
  }
  if (json.error.option) {
    for (j in json.error.option) {
      if (!Object.prototype.hasOwnProperty.call(json.error.option, j)) {
        continue;
      }
      var el2 = $("#input-option-" + j);
      if (!el2.length) {
        el2 = $("#input-option" + j.replace(/_/g, "-"));
      }
      if (el2.length) {
        if (el2.parent().hasClass("input-group")) {
          el2
            .parent()
            .after(
              '<div class="text-danger">' + json.error.option[j] + "</div>",
            );
        } else {
          el2.after(
            '<div class="text-danger">' + json.error.option[j] + "</div>",
          );
        }
        optAlert = true;
      }
    }
  }
  if (optAlert) {
    alert(
      "Трябва да изберете някоя от опциите за този продукт, за да можете да го добавите в количката си!",
    );
  }
  $(".text-danger").parent().addClass("has-error");
  return true;
}

function uni_pogasitelni_vnoski_input_change(_uni_price) {
  const uni_vnoski = parseFloat(
    document.getElementById("uni_pogasitelni_vnoski_input").value,
  );
  const uni_param_kimb_3 = parseFloat(
    document.getElementById("uni_param_kimb_3").value,
  );
  const uni_param_kimb_4 = parseFloat(
    document.getElementById("uni_param_kimb_4").value,
  );
  const uni_param_kimb_5 = parseFloat(
    document.getElementById("uni_param_kimb_5").value,
  );
  const uni_param_kimb_6 = parseFloat(
    document.getElementById("uni_param_kimb_6").value,
  );
  const uni_param_kimb_9 = parseFloat(
    document.getElementById("uni_param_kimb_9").value,
  );
  const uni_param_kimb_10 = parseFloat(
    document.getElementById("uni_param_kimb_10").value,
  );
  const uni_param_kimb_12 = parseFloat(
    document.getElementById("uni_param_kimb_12").value,
  );
  const uni_param_kimb_18 = parseFloat(
    document.getElementById("uni_param_kimb_18").value,
  );
  const uni_param_kimb_24 = parseFloat(
    document.getElementById("uni_param_kimb_24").value,
  );
  const uni_param_kimb_30 = parseFloat(
    document.getElementById("uni_param_kimb_30").value,
  );
  const uni_param_kimb_36 = parseFloat(
    document.getElementById("uni_param_kimb_36").value,
  );

  const calcHolder = document.getElementById("uni_get_product_link");
  const calcUrl =
    calcHolder && calcHolder.value ? String(calcHolder.value).trim() : "";
  if (!calcUrl) {
    return;
  }

  $.ajax({
    url: calcUrl,
    type: "post",
    dataType: "json",
    data: {
      uni_vnoski: uni_vnoski,
      uni_price: _uni_price,
      uni_param_kimb_3: uni_param_kimb_3,
      uni_param_kimb_4: uni_param_kimb_4,
      uni_param_kimb_5: uni_param_kimb_5,
      uni_param_kimb_6: uni_param_kimb_6,
      uni_param_kimb_9: uni_param_kimb_9,
      uni_param_kimb_10: uni_param_kimb_10,
      uni_param_kimb_12: uni_param_kimb_12,
      uni_param_kimb_18: uni_param_kimb_18,
      uni_param_kimb_24: uni_param_kimb_24,
      uni_param_kimb_30: uni_param_kimb_30,
      uni_param_kimb_36: uni_param_kimb_36,
    },
    success: function (json) {
      const uni_eur = parseInt(document.getElementById("uni_eur").value);
      let uni_mesecna = 0;
      let uni_glp = 0;
      let uni_gpr = 0;
      switch (uni_vnoski) {
        case 3:
          uni_mesecna = parseFloat(json["uni_mesecna_3"]);
          uni_gpr = parseFloat(json["uni_gpr_3"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_3").value,
          );
          break;
        case 4:
          uni_mesecna = parseFloat(json["uni_mesecna_4"]);
          uni_gpr = parseFloat(json["uni_gpr_4"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_4").value,
          );
          break;
        case 5:
          uni_mesecna = parseFloat(json["uni_mesecna_5"]);
          uni_gpr = parseFloat(json["uni_gpr_5"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_5").value,
          );
          break;
        case 6:
          uni_mesecna = parseFloat(json["uni_mesecna_6"]);
          uni_gpr = parseFloat(json["uni_gpr_6"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_6").value,
          );
          break;
        case 9:
          uni_mesecna = parseFloat(json["uni_mesecna_9"]);
          uni_gpr = parseFloat(json["uni_gpr_9"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_9").value,
          );
          break;
        case 10:
          uni_mesecna = parseFloat(json["uni_mesecna_10"]);
          uni_gpr = parseFloat(json["uni_gpr_10"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_10").value,
          );
          break;
        case 12:
          uni_mesecna = parseFloat(json["uni_mesecna_12"]);
          uni_gpr = parseFloat(json["uni_gpr_12"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_12").value,
          );
          break;
        case 18:
          uni_mesecna = parseFloat(json["uni_mesecna_18"]);
          uni_gpr = parseFloat(json["uni_gpr_18"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_18").value,
          );
          break;
        case 24:
          uni_mesecna = parseFloat(json["uni_mesecna_24"]);
          uni_gpr = parseFloat(json["uni_gpr_24"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_24").value,
          );
          break;
        case 30:
          uni_mesecna = parseFloat(json["uni_mesecna_30"]);
          uni_gpr = parseFloat(json["uni_gpr_30"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_30").value,
          );
          break;
        case 36:
          uni_mesecna = parseFloat(json["uni_mesecna_36"]);
          uni_gpr = parseFloat(json["uni_gpr_36"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_36").value,
          );
          break;
        default:
          uni_mesecna = parseFloat(json["uni_mesecna_3"]);
          uni_gpr = parseFloat(json["uni_gpr_3"]);
          uni_glp = parseFloat(
            document.getElementById("uni_param_glp_3").value,
          );
      }
      const uni_vnoska_int = document.getElementById("uni_vnoska_int");
      const uni_vnoska_dec = document.getElementById("uni_vnoska_dec");
      const uni_vnoska_second_int = document.getElementById(
        "uni_vnoska_second_int",
      );
      const uni_vnoska_second_dec = document.getElementById(
        "uni_vnoska_second_dec",
      );
      const uni_glp_int = document.getElementById("uni_glp_int");
      const uni_gpr_int = document.getElementById("uni_gpr_int");
      const uni_vnoska_arr = uni_mesecna.toFixed(2).split(".");
      uni_vnoska_int.textContent = uni_vnoska_arr[0];
      uni_vnoska_dec.textContent = uni_vnoska_arr[1];
      if (uni_vnoska_second_int !== null && uni_vnoska_second_dec !== null) {
        if (uni_eur == 1) {
          const uni_vnoska_second_arr = (uni_mesecna / uniGetEurBgnRate())
            .toFixed(2)
            .split(".");
          uni_vnoska_second_int.textContent = uni_vnoska_second_arr[0];
          uni_vnoska_second_dec.textContent = uni_vnoska_second_arr[1];
        }
        if (uni_eur == 2) {
          const uni_vnoska_second_arr = (uni_mesecna * uniGetEurBgnRate())
            .toFixed(2)
            .split(".");
          uni_vnoska_second_int.textContent = uni_vnoska_second_arr[0];
          uni_vnoska_second_dec.textContent = uni_vnoska_second_arr[1];
        }
      }
      uni_glp_int.textContent = uni_glp.toFixed(2);
      uni_gpr_int.textContent = uni_gpr.toFixed(2);
      uniUpdateButtonInstallmentLabels(uni_mesecna, uni_vnoski);
      uni_old_vnoski = uni_vnoski;
    },
  });
}

$(document).ready(function () {
  const uniUnitBaseEl = document.getElementById("uni_unit_base");
  const uniPriceLineEl = document.getElementById("uni_price");
  if (uniUnitBaseEl == null && uniPriceLineEl == null) {
    return;
  }
  $("#uni-product-popup-container").prependTo("body");
  const uniProductPopupContainer = document.getElementById(
    "uni-product-popup-container",
  );
  /** Единична базова цена: #uni_unit_base (ако има); иначе съвместимост със стари шаблони (#uni_price = ед. цена). */
  const uniUnitBasePriceStr = String(
    uniUnitBaseEl && uniUnitBaseEl.value !== ""
      ? uniUnitBaseEl.value
      : uniPriceLineEl
        ? uniPriceLineEl.value
        : "",
  ).trim();
  let uni_price1 = uniUnitBasePriceStr;
  let uni_quantity = 1;
  let uni_priceall = uniLineTotalFromPartsFloat(uni_price1, 0, uni_quantity);
  const uni_buy_buttons_submit = document.querySelectorAll("#button-cart");
  let uniCreditsRecalcTimer = null;

  function uniRunFullCreditPriceFlow(showPopup, silent) {
    let total_opts_sum = 0;
    uni_price1 = uniUnitBasePriceStr;
    if (document.getElementById("input-quantity") !== null) {
      uni_quantity = parseFloat(
        document.getElementById("input-quantity").value,
      );
    } else {
      uni_quantity = 1;
    }
    function finishLineTotal(lineBeforeEur) {
      uni_priceall = uniApplyEurConversion(lineBeforeEur);
      uniUpdatePopupLineTotalDisplay(uni_priceall);
      if (showPopup && uniProductPopupContainer) {
        uniProductPopupContainer.style.display = "block";
      }
      uni_pogasitelni_vnoski_input_change(uni_priceall);
    }
    function uniAfterLineComputedThenDisplay(lineBeforeEur) {
      const refreshUrlEl = document.getElementById("uni_kimb_refresh_url");
      const refreshUrl =
        refreshUrlEl && refreshUrlEl.value
          ? String(refreshUrlEl.value).trim()
          : "";
      const productIdEl = document.getElementById("product_id");
      const productId = productIdEl
        ? String(productIdEl.value || "").trim()
        : "";
      if (!refreshUrl || !productId || typeof $ === "undefined") {
        finishLineTotal(lineBeforeEur);
        return;
      }
      $.ajax({
        url: refreshUrl,
        type: "post",
        dataType: "json",
        data: { product_id: productId, line_total: String(lineBeforeEur) },
        success: function (resp) {
          if (
            resp &&
            resp.success &&
            resp.uni_kimb_hidden_fields &&
            resp.uni_kimb_hidden_fields.length
          ) {
            let i;
            for (i = 0; i < resp.uni_kimb_hidden_fields.length; i++) {
              const row = resp.uni_kimb_hidden_fields[i];
              const m = row.m;
              const kEl = document.getElementById("uni_param_kimb_" + m);
              const gEl = document.getElementById("uni_param_glp_" + m);
              if (kEl) {
                kEl.value = row.kimb != null ? String(row.kimb) : "";
              }
              if (gEl) {
                gEl.value = row.glp != null ? String(row.glp) : "";
              }
            }
          }
          finishLineTotal(lineBeforeEur);
        },
        error: function () {
          finishLineTotal(lineBeforeEur);
        },
      });
    }
    if (document.querySelectorAll("[id*='input-option']").length > 0) {
      if ($ != null) {
        $.ajax({
          url:
            (document.getElementById("uni_option_check_url") || {}).value || "",
          type: "post",
          data: $(
            "#form-product input[type='text'], #form-product input[type='hidden'], #form-product input[type='radio']:checked, #form-product input[type='checkbox']:checked, #form-product select, #form-product textarea",
          ),
          dataType: "json",
          success: function (json) {
            $(".alert, .text-danger").remove();
            if (json["error"]) {
              if (json["error"]["option"]) {
                for (var i in json["error"]["option"]) {
                  if (
                    !Object.prototype.hasOwnProperty.call(
                      json["error"]["option"],
                      i,
                    )
                  ) {
                    continue;
                  }
                  var element = $("#input-option-" + i);
                  if (!element.length) {
                    element = $("#input-option" + i.replace("_", "-"));
                  }
                  if (!silent) {
                    if (element.parent().hasClass("input-group")) {
                      element
                        .parent()
                        .after(
                          '<div class="text-danger">' +
                            json["error"]["option"][i] +
                            "</div>",
                        );
                    } else {
                      element.after(
                        '<div class="text-danger">' +
                          json["error"]["option"][i] +
                          "</div>",
                      );
                    }
                  }
                }
              }
              if (!silent) {
                $(".text-danger").parent().addClass("has-error");
                alert(
                  "Моля изберете стойност за задължителните опции за продукта!",
                );
              }
              return;
            }
            if (json["success"]) {
              total_opts_sum = uniSumOptionPricesFromJson(json);
              const line = uniLineTotalFromPartsFloat(
                uni_price1,
                total_opts_sum,
                uni_quantity,
              );
              uniAfterLineComputedThenDisplay(line);
            }
          },
        });
      } else {
        const line = uniLineTotalFromPartsFloat(
          uni_price1,
          total_opts_sum,
          uni_quantity,
        );
        uniAfterLineComputedThenDisplay(line);
      }
    } else {
      const line = uniLineTotalFromPartsFloat(
        uni_price1,
        total_opts_sum,
        uni_quantity,
      );
      uniAfterLineComputedThenDisplay(line);
    }
  }

  function uniScheduleFullCreditPriceFlow() {
    const cartEl = document.getElementById("uni_cart");
    if (cartEl && parseInt(cartEl.value, 10) === 1) {
      return;
    }
    const btnUni = document.getElementById("btn_uni");
    if (!btnUni || !btnUni.classList.contains("uni_button")) {
      return;
    }
    if (uniCreditsRecalcTimer) {
      clearTimeout(uniCreditsRecalcTimer);
    }
    uniCreditsRecalcTimer = setTimeout(function () {
      uniCreditsRecalcTimer = null;
      uniRunFullCreditPriceFlow(false, true);
    }, 280);
  }

  $(document).on("change", "#form-product", uniScheduleFullCreditPriceFlow);
  $(document).on(
    "input change",
    "#input-quantity",
    uniScheduleFullCreditPriceFlow,
  );

  setTimeout(function () {
    uniRunFullCreditPriceFlow(false, true);
  }, 150);

  $(document).on("click", "#btn_uni", function () {
    if (parseInt($("#uni_cart").val(), 10) === 1) {
      if (uni_buy_buttons_submit.length) {
        uni_buy_buttons_submit.item(0).click();
      }
    } else {
      uniRunFullCreditPriceFlow(true, false);
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
      url: (document.getElementById("uni_cart_add_url") || {}).value || "",
      type: "post",
      dataType: "json",
      data: $(
        "#form-product input[type='text'], #form-product input[type='hidden'], #form-product input[type='radio']:checked, #form-product input[type='checkbox']:checked, #form-product select, #form-product textarea",
      ),
      success: function (json) {
        if (uniMtCreditCartAddShowErrors(json)) {
          return;
        }
        if (json["success"]) {
          var cartPage =
            (document.getElementById("uni_cart_page_url") || {}).value || "";
          window.location = cartPage || "index.php?route=checkout/cart";
        }
      },
      error: function (xhr, ajaxOptions, thrownError) {
        alert(
          thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText,
        );
      },
    });
  });

  $(document).on("click", "#uni_buy_on_installment", function (e) {
    e.preventDefault();
    var intentUrl =
      (document.getElementById("uni_prepare_installmentcheckout_url") || {})
        .value || "";
    var checkoutFallback =
      (document.getElementById("uni_checkout_url") || {}).value || "";
    var monthsEl = document.getElementById("uni_pogasitelni_vnoski_input");
    var months = monthsEl ? monthsEl.value : "12";
    $.ajax({
      url: (document.getElementById("uni_cart_add_url") || {}).value || "",
      type: "post",
      dataType: "json",
      data: $(
        "#form-product input[type='text'], #form-product input[type='hidden'], #form-product input[type='radio']:checked, #form-product input[type='checkbox']:checked, #form-product select, #form-product textarea",
      ),
      success: function (json) {
        if (uniMtCreditCartAddShowErrors(json)) {
          return;
        }
        if (!json["success"]) {
          return;
        }
        uniMtCreditSetInstallmentCookies("uni.uni", months);
        if (!intentUrl) {
          window.location =
            checkoutFallback || "index.php?route=checkout/checkout";
          return;
        }
        $.ajax({
          url: intentUrl,
          type: "post",
          data: { installment_months: months },
          dataType: "json",
          success: function (j2) {
            if (j2.error) {
              alert(j2.error);
              return;
            }
            if (j2.redirect) {
              window.location = j2.redirect;
            } else if (j2.success && checkoutFallback) {
              window.location = checkoutFallback;
            } else {
              alert(
                uniMtCreditGetShopString(
                  "installmentIntentFailed",
                  "Checkout setup failed.",
                ),
              );
            }
          },
          error: function (xhr, ajaxOptions, thrownError) {
            alert(
              uniMtCreditGetShopString(
                "installmentIntentFailed",
                thrownError || "Error",
              ),
            );
          },
        });
      },
      error: function (xhr, ajaxOptions, thrownError) {
        alert(
          thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText,
        );
      },
    });
  });
});
