# UniCredit OpenCart 4.x — замразени договори (Phase 0)

Този документ записва **проверени** договори от текущите реализации. Не е спецификация на желано бъдещо поведение.

Целева среда: OpenCart **4.1.0.3**, производствен PHP **8.2**, тестове PHP **8.4**.

Референции:

| Роля                       | Път / хранилище                                                              |
| -------------------------- | ---------------------------------------------------------------------------- |
| Този модул                 | `/var/www/open40.avalonbg.com/extension/mt_uni_credit` (`wiley68/uni-oc4`)   |
| UniCredit бизнес/сигурност | `/var/www/presta9.avalonbg.com/modules/unipayment` (`wiley68/uni-ps9`) 2.0.2 |
| OpenCart 4 платформа       | `/var/www/open40.avalonbg.com/extension/mt_jet_credit` (`wiley68/jet-oc4`)   |
| Control Panel              | `/var/www/uni.avalonbg.com` (`wiley68/uni.avalonbg.com`)                     |
| OpenCart core              | `/var/www/open40.avalonbg.com` (`VERSION = '4.1.0.3'`)                       |

Автоматичните фикстури са в `tests/fixtures/`. Тестовете **не** викат жив CP или SmartUCF.

---

## 1. Control Panel API

Базов префикс: `/api/v1` (`routes/api.php`, PS9 `ModuleDeploymentEnvironment::API_PATH_PREFIX`).

Клиентът в uni-ps9: `src/Api/ControlPanelClient.php`.

| Метод | Път                     | Auth   | Throttle        | Бележки                                                                  |
| ----- | ----------------------- | ------ | --------------- | ------------------------------------------------------------------------ |
| POST  | `/api/v1/auth/login`    | не     | 10 / IP / min   | body: `unicid`, `name`, `secret` (required string, без max)              |
| POST  | `/api/v1/auth/refresh`  | Bearer | 30 / shop / min | ротира токена; стар се забравя                                           |
| POST  | `/api/v1/auth/logout`   | Bearer | 30 / shop / min | повторно logout → 401                                                    |
| GET   | `/api/v1/shop`          | Bearer | 30 / shop / min | `data` = ShopModuleResource + `coeff_list`                               |
| POST  | `/api/v1/orders`        | Bearer | 60 / shop / min | виж §2                                                                   |
| PATCH | `/api/v1/orders/status` | Bearer | 60 / shop / min | `order_id` max 13, `status` required, `status_id` optional; **без** enum |

Токен: 64 символа, TTL 24h, cache ключ `shop_token_{token}`. Login **не** е идемпотентен.

Допълнителни CP endpoint-и (SSL bundle) съществуват, но **не** са част от Phase 0 freeze за storefront.

---

## 2. CP order payload и лимити

Валидация: `app/Http/Requests/StoreOrderRequest.php`.  
PS9 builder: `src/Order/ControlPanelOrderPayloadBuilder.php` (`substr` към същите лимити, плюс `products_name` max 255 в builder-а).

| Поле                       | Правило                                                                            |
| -------------------------- | ---------------------------------------------------------------------------------- |
| `order_id`                 | required, string, **max 13**; PS9: `substr(order_reference, 0, 13)`                |
| `name`                     | required, max **65**                                                               |
| `phone`                    | required, max **45**, `^[\d\s\-\+\(\)]+$`                                          |
| `email`                    | required, email, max **128**                                                       |
| `address`                  | required, max **256**                                                              |
| `address2`                 | optional, max **256**; PS9: delivery, иначе invoice, иначе `'-'`                   |
| `price` / `vnoska` / `gpr` | required, numeric, min 0; PS9 `round(..., 2)`                                      |
| `vnoski`                   | optional integer **1–255**, default **12** (месеци; shop UI е 3–36)                |
| `parva`                    | optional numeric min 0, default **0**                                              |
| `products_*`               | optional string; CP **без** max; DB `text`                                         |
| `type_client`              | 0–255, default **0**; PS9: `0` ако `_is_mobile`, иначе `1`                         |
| `currency`                 | max 3, **`in:BGN,EUR`**, API default **BGN**; DB default **EUR**                   |
| `version`                  | max 11, `^\d{1,3}\.\d{1,3}\.\d{1,3}$`; текущ модул **2.0.2**                       |
| `status` / `status_id`     | optional string max 255, **не** са enum; default `Създаден в КП Банка` / `cp_sent` |

Process 2 (`uni_proces === 1`) добавя при create:

- `status` = `Изпратен Банка - Процес 2`
- `status_id` = `bank_sent_process2`

Идемпотентност (`app/Support/IdempotentOrderCreator.php`): ключ `(shop_id, order_id)`. Същият semantic hash → HTTP **200**; различен hash → **409**. Semantic полетата са замразени в `tests/fixtures/cp_order_payload.json`.

**Разминаване:** съобщението за `currency.in` споменава USD, правилото приема само BGN и EUR.

---

## 3. HMAC / replay

Един и същ вектор в:

- uni-ps9 `src/Security/ModuleRequestSignatureProtocol.php`
- CP `app/Support/ModuleRequestSigner.php`

Канонична форма:

```text
timestamp + "\n" + nonce + "\n" + exact_raw_body
```

`hash_hmac('sha256', canonical, secret)` → lowercase hex. Сравнение: `hash_equals`.

Headers: `X-UniPayment-Timestamp`, `X-UniPayment-Nonce`, `X-UniPayment-Signature`.

| Правило   | Стойност                                                 | Къде се прилага                                     |
| --------- | -------------------------------------------------------- | --------------------------------------------------- |
| Timestamp | `ctype_digit`, прозорец **±300 s**                       | модул (verifier)                                    |
| Nonce     | **64** hex                                               | модул; CP само генерира `bin2hex(random_bytes(32))` |
| Retention | **900 s**, `sha256(nonce)` UNIQUE `(unicid, nonce_hash)` | модул                                               |
| Raw body  | точните байтове; **без** re-encode                       | и двете при sign/verify                             |

Известен тестови вектор (не е производствен секрет): виж `tests/fixtures/hmac_callback_vector.json`.

**Разминаване:** CP дефинира `TIMESTAMP_TOLERANCE_SECONDS = 300`, но **не** верифицира входящ HMAC (само подписва изходящи push-ове).

Невалиден HMAC **не** консумира nonce (uni-ps9 authenticator).

---

## 4. Речник на статусите

### Изходящи bank status (модул → CP)

Точни низове от `src/Order/BankStatus.php` — **без** преименуване:

| `status_id`                 | `status_label`                        | Кога                                          |
| --------------------------- | ------------------------------------- | --------------------------------------------- |
| `bank_sent_process1`        | `Изпратен Банка - Процес 1`           | CP създадена **и** SmartUCF Process 1 успешен |
| `bank_sent_process2`        | `Изпратен Банка - Процес 2`           | CP създадена за Process 2 (без SmartUCF)      |
| `bank_send_failed`          | `Неуспешно изпратен Банка`            | CP create fail при Process 2                  |
| `bank_send_failed_cp`       | `Неуспешно изпратен Банка - КП`       | CP create fail при Process 1                  |
| `bank_send_failed_smartucf` | `Неуспешно изпратен Банка - SmartUCF` | CP създадена **и** SmartUCF Process 1 fail    |

Флаг: `ShopConfigurationFlags::isProcess2` → `(int) uni_proces === 1`. Името на процеса е **обърнато** спрямо числото.

Process 1 (`uni_proces !== 1`): след CP → SmartUCF; create payload **без** status полета; без ЕГН.  
Process 2 (`uni_proces === 1`): native confirmation; **без** SmartUCF; create payload **със** status полета; ЕГН + `phone2`.

Inbound `orderbankstatus` **не** сменя storefront order state (`ps_order_state_changed: false`).

### CP enum (`app/Enums/OrderStatus.php`)

API **не** форсира тези стойности. Default при create: `Създаден в КП Банка` / `cp_sent`. Пълният списък е в `tests/fixtures/status_vocabulary.json`.

---

## 5. Калкулатор — parity source

Оракул: uni-ps9 `tests/Calculator/*` и `src/Calculator/*`, дата на филтър **2026-08-17**.

Golden вектори: `tests/fixtures/calculator_golden.json`.

Ключови класове:

- `Calculator`, `OfferFactory`, `FinancialCalculator`
- `SchemePresentationCategory`, `PreferredOfferSelector`
- `FirstInstallmentResolver`, `SchemaFilterMatcher`, `MonthResolver` (3–36)
- `CurrencyGate`, `AmountDisplayFormatter`
- `CartSchemeResolver` (`type|kopCode|months`; цена = **cart total**)

Формули:

- `monthly = round(financed * coeff, 2)`
- `totalPayable = round(monthly * months, 2)`
- `glp = round(abs(interestPercent), 2)`
- `Calculator::calculateScheme` GPR: `raw <= 0.1 ? 0.0 : round(raw, 2)`
- `OfferFactory` GPR: `round(raw, 2)` **без** 0.1 floor

Това е вътрешно разминаване в uni-ps9: 0% promo preferred offer има `gpr_offerFactory = 0.01`, а `calculateScheme` дава `0.0`. OpenCart имплементацията трябва да запази същото разделение, докато uni-ps9 не го промени.

`uni_typekop`: `0` = by_default, `1` = by_schema.filters. Promo `type=promo` изисква zero-interest. Presentation rank: standard → nonzero_promo → zero_promo, след това months ASC.

`uni_promo_meseci_znak`: буквално `eq` или `greateq`.

Валута: `uni_eur ∈ {2,3}` очаква EUR, иначе BGN. Display курс **1.95583**.

---

## 6. OpenCart 4.1.0.3 checkout / order lifecycle

Проверено в инсталирания core (не модифициран).

1. `catalog/controller/checkout/confirm.php` `index()` вика `addOrder()` само при `$status === true` и липсващ `order_id`.
2. `session.order_id` се задава **веднага** след `addOrder()` (ред ~279).
3. Payment controller се зарежда **след** create/edit: `extension/{extension}/payment/{code}`.
4. Ако има `order_id` и `order_status_id == 0` → `editOrder()`. `editOrder()` **първо** вика `addHistory(..., config_void_status_id)`, затова след първия refresh статусът вече **не** е 0 и следващи refresh **не** редактират отново.
5. `addOrder()` INSERT **не** пише `order_status_id`; DB default е **0** (`system/helper/db_schema.php`).
6. Payment identifier: `{code}.{option}` (`explode('.')` в `checkout/payment_method.php`). В DB колоната `payment_method` е JSON. Jet: `jet.jet`.
7. `addHistory()` обновява `order_status_id` и INSERT в `order_history`.
8. `checkout/success` чисти cart + session (`order_id`, payment/shipping, comment, agree, coupon, reward). **Не** пипа поръчката в DB.

Jet checkout разчита native confirm да е създал `session.order_id` преди `payment/jet::index()`.

---

## 7. Routing / event съвместимост `|` срещу `.`

В OpenCart **4.1.0.3** `Action` разделя метод **само** по последната `.` (`system/engine/action.php`). `|` **не** е separator в Action.

- HTTP/request: `framework.php` и `Loader::controller()` правят `str_replace('|', '.', $route)` преди parse. `|save` в URL работи.
- Events: `catalog/controller/startup/event.php` подава DB `action` директно на `new Action(...)` **без** конверсия. `|init` → method=`index`, счупен път (factory стрипва `|` и `.`).

Jet (`admin/controller/module/mt_jet_credit.php`):

```php
$jet_separator = (VERSION >= '4.0.2') ? '.' : '|';
```

На 4.1.0.3 → `.`. Event action пример: `extension/mt_jet_credit/event/mt_jet_credit_product_controller.init`.

Marketplace installer (`admin4/controller/marketplace/installer.php`) чете от `install.json` само metadata (`name`, `code`, `version`, `author`, `link`, …). Полетата `type`, `status`, `install`, `uninstall` **не** се изпълняват. Реалният install е Extensions → Module/Payment → `.install`.

Namespace: `str_replace(['_', '/'], ['', '\\'], ucwords($extension, '_/'))`.  
`mt_uni_credit` → `MtUniCredit`.

Планирани пътища (без skeleton файлове в Phase 0):

|                | Стойност                                               |
| -------------- | ------------------------------------------------------ |
| Код            | `mt_uni_credit`                                        |
| Admin NS       | `Opencart\Admin\Controller\Extension\MtUniCredit\`     |
| Catalog NS     | `Opencart\Catalog\Controller\Extension\MtUniCredit\`   |
| Route prefix   | `extension/mt_uni_credit/`                             |
| Language load  | `extension/mt_uni_credit/{type}/{name}`                |
| Twig load      | `extension/mt_uni_credit/{type}/{name}`                |
| Language files | `{admin\|catalog}/language/{locale}/{type}/{name}.php` |
| Twig files     | `{admin\|catalog}/view/template/{type}/{name}.twig`    |

`install.json` следва jet: `type=module`, hooks с `::` (legacy, игнорирани от OC 4.1 marketplace). Runtime: `extension/mt_uni_credit/module/mt_uni_credit.install`.

---

## 8. PHP

- Производство: PHP **8.2** (`composer.json` `>=8.2 <8.5`, `scripts/lint-php82.sh` → `/usr/bin/php8.2`).
- Тестове: PHPUnit 11 под PHP **8.4** (`composer test` / `composer test:php84`).
- Няма runtime Composer зависимости. PHPUnit е `require-dev`.
- Системният PHP не се пипа; CLI остава 8.4, lint вика `php8.2` директно.

---

## 9. Какво Phase 0 нарочно не съдържа

Няма schema, token persistence, CP login клиент, калкулаторни продукционни класове, Product/Cart/Checkout UI, Process 1/2, SmartUCF, email, callback controller, admin order panel.

---

## 10. Phase 1 skeleton (admin shell)

Phase 1 добавя admin module shell и install wiring. Подробности: `docs/PHASE1.md`.

| Елемент         | Стойност                                               |
| --------------- | ------------------------------------------------------ |
| Admin route     | `extension/mt_uni_credit/module/mt_uni_credit`         |
| Runtime install | `extension/mt_uni_credit/module/mt_uni_credit.install` |
| Setting code    | `module_mt_uni_credit`                                 |
| Library NS      | `Opencart\System\Library\Extension\MtUniCredit\`       |
| Compatibility   | `system/library/open_cart_compatibility.php`           |
| Events Phase 1  | **0** (само `EventRegistry` инфраструктура)            |
| Autoload        | native OpenCart; **без** runtime Composer              |

**Remediation:** OpenCart glob-сканира `admin/controller/{type}/*.php` за discovery. Generic `index.php` в `admin/controller/module/` се интерпретира като модул с code `index` и може да счупи Extensions → Modules. Не слагай exit stubs в scanned component namespaces; виж `docs/PHASE1.md` §Remediation.

---

## 11. Phase 2 deployment configuration

Подробности: `docs/PHASE2.md`.

| Елемент                             | Стойност                                                 |
| ----------------------------------- | -------------------------------------------------------- |
| CP URL (единствен tracked източник) | `config/environment.php` → `control_panel_url`           |
| Loader                              | `ModuleDeploymentEnvironment`                            |
| API prefix                          | `/api/v1` (host + prefix; **без** HTTP в Phase 2)        |
| Secrets file                        | `secrets/smartucf-key.php` → `passphrase` (Git-ignored)  |
| Certificate                         | `keys/avalon_cert.pem` (Git-ignored, ръчно)              |
| Private key                         | `keys/avalon_private_key.pem` (Git-ignored, ръчно)       |
| Health                              | `DeploymentHealthService` (локално; без CP connectivity) |
| Auto cert sync                      | **забранено** за OC4 `2.0.2`                             |

---

## 12. Phase 3 persistence foundation

Подробности: `docs/PHASE3.md`.

| Таблица                           | Назначение                                                         |
| --------------------------------- | ------------------------------------------------------------------ |
| `mt_uni_credit_shop_cache`        | CP shop snapshot cache (validated replace; без live fetch)         |
| `mt_uni_credit_api_nonce`         | Nonce claim `(store_id, unicid, sha256(nonce))`, retention 900 s   |
| `mt_uni_credit_operation_lock`    | Mutex `(store_id, entry_point, operation_key_hash)`, TTL 45 s      |
| `mt_uni_credit_financing_attempt` | Generalized attempt identity + CAS transitions + attach-once order |

Install: idempotent `CREATE TABLE IF NOT EXISTS`. Uninstall: **не** DROP-ва persistence таблици.

`store_id` е explicit non-negative OpenCart store id (`OpenCartStoreScope`): `0` = default store; negative ids са невалидни; missing scope ≠ `0`.

---

## 13. Phase 4 CP auth and shop cache

Подробности: `docs/PHASE4.md`.

| Елемент            | Стойност                                                                               |
| ------------------ | -------------------------------------------------------------------------------------- |
| HTTP transport     | `CurlCpHttpTransport`, TLS verify, connect 5s / total 15s                              |
| Login body         | `unicid`, `name` (canonical shop URL), `secret`                                        |
| CP secret          | admin setting `module_mt_uni_credit_secret` (encrypted)                                |
| UNICID             | admin setting `module_mt_uni_credit_unicid`                                            |
| Token storage      | encrypted `oc_setting`, store-scoped, prefix `enc:v1:`                                 |
| Encryption key     | `ModuleEncryptionKeyProvider` — HKDF from `DB_PASSWORD` (`config.php`)                 |
| 401 retry          | exactly once after re-auth; second 401 invalidates                                     |
| Shop fetch         | `GET /api/v1/shop` → validate → `mt_uni_credit_shop_cache`                             |
| Cache TTL          | 86400 s                                                                                |
| Invalid snapshot   | does **not** overwrite known-good cache                                                |
| Admin operator     | Native OC Save → Refresh bank data (transparent auth; no Login/Logout UI)              |
| Local settings     | status, advertising, debug, product_button_action, button_top_spacing (PS9/Woo parity) |
| Catalog URL for CP | Prefer store `config_ssl`/`config_url`; fallback `HTTPS_CATALOG`/`HTTP_CATALOG`        |
| Operations journal | UI placeholder disabled until bank-request diagnostics (later phase)                   |
| SmartUCF mTLS      | Not required for CP login or `GET /shop`                                               |

## Cross-phase storefront contracts (permanent)

These rules apply to Product, Cart, Checkout and future admin/storefront work.

### Browser diagnostics

Storefront production JS emits no intentional developer/debug console output.
Debug mode uses server-side logging/journal only.

Temporary remediation diagnostics may be used locally during active work; they must be removed before STOP GATE PASS (no console dumps, test-only HTML markers, or dead debug branches).

### Resource locality

All module-owned static assets are packaged locally.
No external font/CSS/JS/icon dependencies.
Only CP-provided advertising images from the approved operator CDN may be remote.

### Font

Roboto Condensed is bundled locally with its license and scoped only to UniCredit UI.

### Asset cache identity

Module-owned JS/CSS URLs use per-file `filemtime` via `ModuleAssetVersion` (not module release version alone).

## 15. Phase 6 order materialization foundation

Подробности: `docs/PHASE6.md`.

| Елемент                 | Стойност                                                                  |
| ----------------------- | ------------------------------------------------------------------------- |
| Submission DTO          | `ValidatedFinancingSubmission`                                            |
| Gateways                | `ProductOrderGateway`, `CartOrderGateway`, `CheckoutExistingOrderGateway` |
| Materializer            | `OpenCartOrderMaterializer` → `CheckoutOrderModelPort.addOrder()`         |
| Payment identity        | `mt_uni_credit.mt_uni_credit` (`PaymentIdentity`)                         |
| Crash recovery          | `mt_uni_credit_order_correlation` (`store_id`, `attempt_id`, `order_id`)  |
| Awaiting status setting | `module_mt_uni_credit_awaiting_financing_order_status_id`                 |

### Store scope (OpenCart)

`store_id` is an explicit non-negative OpenCart store id (`OpenCartStoreScope`). **`0` is the default store** and a real scope. Positive ids are additional stores. Negative ids are rejected. Missing scope is not equivalent to `0`.

## 14. Phase 5 shared financing domain

Подробности: `docs/PHASE5.md`.

| Елемент           | Стойност                                                          |
| ----------------- | ----------------------------------------------------------------- |
| Orchestrator      | `Calculator` (single shared domain)                               |
| Context DTOs      | `ProductContext`, `CartContext`, `CartLine`                       |
| Cart intersection | `CartSchemeResolver`, key `type\|kopCode\|months`                 |
| GPR split         | OfferFactory vs calculateScheme (0% promo: 0.01 vs 0.0)           |
| Currency          | BGN/EUR via `CurrencyGate`; display rate 1.95583                  |
| Parity            | `tests/fixtures/calculator_golden.json`, `Phase5GoldenParityTest` |
