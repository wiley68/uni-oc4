# Control Panel destination and origin binding

Change only `config/environment.php` → `control_panel_url` to select the Control Panel.
The file is copied into the installer byte-for-byte. No client API-base or configuration-file
override exists. The normalized HTTPS root derives `/api/v1`; the persisted origin includes
the effective `:443` port. Case, an explicit default port and a root slash normalize identically.

The URL must contain a public DNS hostname, HTTPS, port 443 and only a root path. Userinfo,
queries, fragments, IP literals, malformed names and special-use namespaces are rejected.
This is a general destination policy, without a list of permitted CP hostnames.

The cURL transport resolves A/AAAA with at most eight CNAME edges and 64 records across
the traversal. Every advertised address must be public; mixed public/private answers fail.
Special-purpose IP space is conservatively excluded using the IANA IPv4/IPv6 registries.
The first validated address is pinned with `CURLOPT_RESOLVE`; HTTPS, TLS peer/hostname
verification, disabled redirects and the existing connect/transfer timeouts remain enforced.
Proxy environment behavior is disabled explicitly. No alternate destination is tried.

DNS calls use the operating system resolver's timeouts; the query count is bounded, but PHP's
synchronous DNS API has no per-call deadline. IPv6 pinning requires libcurl 7.57 or newer.
Only the selected address is attempted, so an unreachable first address can fail even when
another advertised address is reachable. DNS validation does not attest ownership of a
hostname or guarantee routing availability; TLS still validates the configured hostname.

Tokens and SmartUCF credentials carry the origin inside their encrypted values. Shop-cache
JSON uses an origin envelope. Foreign or legacy unbound values are unusable; a normal login
and shop refresh reacquire ephemeral state. Same-origin cache TTL and presentation LKG
behavior remain intact. Certificate sync state includes the origin and pair hashes; transient
fail-open requires both to match. Runtime PEMs, secrets and the SmartUCF bank-host policy
remain separate from CP destination configuration.

New durable financing attempts record `cp_origin`. Existing installations acquire a nullable
column lazily and idempotently. Historical rows are never automatically assigned today's
origin. Foreign or unbound attempts, frozen payloads and CP IDs cannot create/replay/resume;
they return `cp_origin_reconciliation_required` (HTTP 409). Foreign or unbound status records
return `reconciliation_required` without PATCH or rewriting historical state. Reconciliation
requires an operator to establish the original CP identity; changing the URL never migrates
remote orders. Existing unknown-outcome and duplicate-create protections remain in place.

Inbound callback authentication remains the separate merchant-secret/HMAC protocol. This
change does not introduce hostname authentication for inbound senders or alter bank endpoints.

Packaging preserves the existing `dist/` directory, published file inodes, ownership, modes
and ACLs. New publication files use 0664 and inherit the directory's default ACL; a newly
created `dist/` uses 2775. Existing output files must already permit shared access. Build
staging remains private. Verified output is written under an exclusive file lock with rollback
on a reported write failure. Readers should take a shared lock: an unlocked reader or process
termination during in-place publication can observe incomplete bytes. Preserving the inode
avoids replacing shared files with CLI-owned files or losing named ACL entries.

The package manifest is explicit and permits reviewed unstaged new runtime files, so a
release can be built for manual review without staging or committing. Files outside that
manifest, including runtime certificates, private keys, leases and certificate state, are excluded.
