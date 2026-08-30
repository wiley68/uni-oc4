# Phase 2 — Deployment configuration, secrets and local settings

Phase 2 added the deployment primitives and admin health model. Phase 11A runtime remediation now synchronizes certificate material through the authenticated Control Panel client before SmartUCF.

Frozen contracts: `docs/CONTRACTS.md`. Phase 1 shell: `docs/PHASE1.md`.

## Deployment tree

Matches the established UniCredit (PS9) layout:

```text
mt_uni_credit/
├── config/
│   ├── environment.php     # tracked — single CP base URL
│   └── index.php           # HTTP deny stub
├── keys/
│   ├── .htaccess           # tracked — deny all
│   ├── index.php           # tracked — 403
│   ├── avalon_cert.pem     # CP-synchronized at runtime / Git-ignored
│   └── avalon_private_key.pem  # CP-synchronized at runtime / Git-ignored
└── secrets/
    ├── .htaccess           # tracked — deny all
    ├── index.php           # tracked — exit
    └── smartucf-key.php    # manual / Git-ignored
```

### Tracked vs manual

| Path                                 | Tracked | Contents                           |
| ------------------------------------ | ------- | ---------------------------------- |
| `config/environment.php`             | yes     | `control_panel_url` only           |
| `secrets/smartucf-key.php`           | **no**  | `return ['passphrase' => '...'];`  |
| `keys/avalon_cert.pem`               | **no**  | CP-synchronized client certificate |
| `keys/avalon_private_key.pem`        | **no**  | CP-synchronized private key        |
| protection `.htaccess` / `index.php` | yes     | deny direct HTTP                   |

## Single-source Control Panel endpoint

**Only** `config/environment.php` defines `control_panel_url`.

Loader: `ModuleDeploymentEnvironment` (`system/library/module_deployment_environment.php`).

- `controlPanelUrl()` — host base, no `/api/v1`
- `controlPanelApiBaseUrl()` — host + `/api/v1` (for future CP client; Phase 2 does not call the network)
- `controlPanelHost()` — safe host for admin display

Do **not** add:

- hard-coded fallback URLs in controllers/services
- admin-editable CP URL
- Twig/JS CP URL
- a second environment file with the same value

Automated guard: `tests/Phase2CpEndpointSingleSourceTest.php`.

## Secrets loader

`MtlsPrivateKeyPassphraseProvider` reads `secrets/smartucf-key.php`:

```php
<?php
return [
    'passphrase' => '…',
];
```

Same filename/key as PS9. Missing/invalid/blank → controlled `null` / health status — no warnings that echo secret values. Never exposed in Twig.

## Certificate / key paths

`CertificateLocalPaths` centralizes:

- `keys/avalon_cert.pem`
- `keys/avalon_private_key.pem`

`CertificatePairValidator` performs **local** OpenSSL checks (parse, validity window, key/cert match). Passphrase comes only from the secrets provider.

## Phase 11A runtime certificate synchronization

When `uni_sertificat` is enabled, `CertificateSynchronizer` runs before the SmartUCF submitting claim:

- `GET /ssl/certificate` compares CP SHA-256 metadata with the exact local PEM bytes.
- Missing or mismatched material triggers `GET /ssl/certificate/bundle`.
- The pair is validated locally, staged and promoted under a filesystem lock.
- SmartUCF consumes a private temporary lease and the lease is deleted after the request.
- A transient metadata failure may use an already-valid local pair; explicit CP unavailability fails closed.

The private-key passphrase is never returned by CP. It remains local-only in `secrets/smartucf-key.php`.

## Health model

`DeploymentHealthService::evaluate()` returns structured statuses:

`healthy` | `missing` | `invalid` | `unreadable` | `expired` | `not_yet_valid` | `mismatch` | `unknown`

Covers: environment file, CP URL/host, secrets, certificate file, private key file, validity, key match, `deployment_ready`.

`isDeploymentReady()` never throws (future storefront gate).

Admin shows labels + optional certificate `not_after` (ISO time). Never shows passphrase, PEM, or tokens.

## Local OpenCart settings (Phase 2)

Still only:

- `module_mt_uni_credit_status` (enable/disable)

No secrets in `oc_setting`. No geo zone / sort order until a later phase needs them.

## Permissions recommendation

| Path                          | Mode   |
| ----------------------------- | ------ |
| `keys/`                       | `0750` |
| `keys/avalon_cert.pem`        | `0640` |
| `keys/avalon_private_key.pem` | `0600` |
| `secrets/smartucf-key.php`    | `0640` |

Web user must be able to **read** these files; they must not be world-writable.

## Web access protection

1. Root `.htaccess` forbids direct HTTP to `config/`, `keys/`, `secrets/` (also `admin/`, `system/`, …).
2. Directory `.htaccess` in `keys/` and `secrets/` (`Require all denied`), matching PS9.
3. `index.php` stubs in those dirs (not under OpenCart controller discovery paths).

## Operator procedure (align with PS9)

1. Set `config/environment.php` → `control_panel_url` for the target environment.
2. Place `secrets/smartucf-key.php` with the mTLS passphrase.
3. Ensure CP has an available certificate bundle for the shop.
4. Open admin module page → confirm deployment health statuses; the first certificate-enabled Process 1 request performs synchronization.
5. Enable module when ready.

## OpenCart-specific differences vs PS9/Woo

| Topic                 | Difference                                                                                               |
| --------------------- | -------------------------------------------------------------------------------------------------------- |
| Package root          | OpenCart extension under `extension/mt_uni_credit/` instead of PrestaShop `modules/unipayment/`          |
| Autoload              | Native OC `system/library/*.php` (no Composer runtime)                                                   |
| Certificate sync      | Phase 11A parity: CP metadata/bundle sync with local-only passphrase and fail-open only for a valid pair |
| Admin UI              | OpenCart module settings + health panel (not PS back-office form)                                        |
| `config/services.yml` | Not used (PrestaShop DI only)                                                                            |

File names (`environment.php`, `smartucf-key.php`, `avalon_*.pem`) and directory names (`config/`, `keys/`, `secrets/`) are intentionally identical.

## Explicitly not in Phase 2

Database schema, CP HTTP client, login/refresh, `/shop`, calculator, Product/Cart/Checkout, financing orders, Process 1/2, SmartUCF HTTP, emails, callbacks, admin financing order panel.
