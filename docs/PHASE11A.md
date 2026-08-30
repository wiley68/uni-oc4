# Phase 11A — Process 1 SmartUCF lifecycle

After local OpenCart order creation and successful Control Panel `/orders` creation, Process 1 calls SmartUCF directly:

```text
CP created → synchronize CP certificate + private key when enabled
→ POST {trusted service}/sucfOnlineSessionStart
→ persist SmartUCF session + redirect
→ PATCH CP /orders/status with shop `order_id` (= local OpenCart order id) → `bank_sent_process1` / `Изпратен Банка - Процес 1`
→ persist local bank_sent_process1
→ browser redirect to trusted ucfin.bg application URL
```

CP `PATCH /orders/status` looks up by the shop order identifier from create — not the Control Panel internal primary key. After SmartUCF success, a failed CP status PATCH does not rewrite bank failure status; the created SmartUCF session remains durable and a later submit replays the stored redirect while reconciling CP/local bank status (no second SmartUCF call).

Storefront Product/Cart/Checkout keep the processing loader active once a trusted SmartUCF redirect navigation has started (`mt_uni_credit_redirect.js`); failure paths still clear the loader.
Only `online.ucfin.bg` and `onlinetest.ucfin.bg` with the frozen service/application paths are trusted. TLS peer and hostname verification remain enabled. When `uni_sertificat` is enabled, the coordinator synchronizes `keys/avalon_cert.pem` and `keys/avalon_private_key.pem` from authenticated CP metadata/bundle endpoints before claiming `submitting`. SHA-256 is calculated over exact PEM bytes. The passphrase remains local-only in `secrets/smartucf-key.php`.

Transient CP metadata failures fail open only when the complete local pair is valid. Explicit CP certificate unavailability, missing local material, invalid bundles, and filesystem failures produce a retryable pre-send `smartucf_credentials_sync_failed_*` result. SmartUCF is not called and `bank_send_failed_smartucf` is not written for these failures.

Lifecycle state is stored on `mt_uni_credit_financing_attempt`: `not_started`, `submitting`, `created`, `failed`, or `outcome_unknown`. A created attempt replays its stored redirect and never creates a second SmartUCF session.

Only a definitive remote SmartUCF rejection writes `bank_send_failed_smartucf` locally and to CP. Pre-send failures write no bank status. Timeout, duplicate-order evidence, or an ambiguous transport result becomes `outcome_unknown` and also writes no failure status.

Process 2 is outside Phase 11A. `uni_proces=1` skips SmartUCF in Phase 11A; Phase 11B owns `bank_sent_process2` handoff (see `docs/PHASE11B.md`).
