# Phase 11A — Process 1 SmartUCF lifecycle

After local OpenCart order creation and successful Control Panel `/orders` creation, Process 1 calls SmartUCF directly:

```text
CP created → POST {trusted service}/sucfOnlineSessionStart
→ persist SmartUCF session + redirect
→ PATCH CP /orders/status (bank_sent_process1)
→ persist local bank_sent_process1
→ browser redirect to trusted ucfin.bg application URL
```

Only `online.ucfin.bg` and `onlinetest.ucfin.bg` with the frozen service/application paths are trusted. TLS peer and hostname verification remain enabled. When `uni_sertificat` is enabled, the manually deployed `keys/avalon_cert.pem` and `keys/avalon_private_key.pem` plus the ZIP secret passphrase are used; certificates are not synchronized from CP.

Lifecycle state is stored on `mt_uni_credit_financing_attempt`: `not_started`, `submitting`, `created`, `failed`, or `outcome_unknown`. A created attempt replays its stored redirect and never creates a second SmartUCF session.

Definitive SmartUCF rejection writes `bank_send_failed_smartucf` locally and to CP. Timeout, duplicate-order evidence, or an ambiguous transport result becomes `outcome_unknown` and does not write a failure status.

Process 2 is intentionally outside Phase 11A. `uni_proces=1` skips SmartUCF and preserves the Phase 10B CP-created result without writing `bank_sent_process2`.
