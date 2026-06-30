# Operations · Configuration

> **Scope.** Every admin setting defined in `settings.php` and every runtime feature flag,
> what it controls, and safe defaults.

Settings live in [`settings.php`](../../settings.php) under the booking admin tree (the
main page `bookingextension_agent_aisettings`, capability `moodle/site:config`). Per-skill
toggles and feature flags supplement them.

## Core & provider

| Config | Type | Default | Effect |
|--------|------|---------|--------|
| `aiexecutionmode` | select | `direct` | execution mode: `direct` (run inline) or `adhoc` (queue via the [worker](tasks-and-async.md)) |
| `aidebugmode` | checkbox | 0 | enable LLM debug logging ([observability](observability.md)) |
| `aiprivacymode` | select | `strict` | PII anonymization before the LLM: `off` / `soft` / `strict` |
| `aiprivacyprotectedwords` | textarea | `user, users, admin, edit, test` | comma-/newline-separated words never treated as a person name (and so never anonymized), even when a real account uses them as a first/last name (e.g. "admin user"). Case-insensitive; added on top of the built-in stop words. Read by `privacy_anonymizer::get_protected_words()` |
| `aifollowupsuggestionscount` | int | 0 | number of follow-up suggestions (0 = off) |

The LLM provider itself is configured in `aiprovider_wunderbyte` / core AI; the agent's
[readiness gate](../architecture/01-entry-and-web-services.md#3-the-readiness-gate) checks it.

## Documentation corpus

| Config | Type | Default | Effect |
|--------|------|---------|--------|
| `aidocsroot` | text | '' | absolute root of an extra docs corpus to index |
| `aidocsentry` / `aidocs_corpusid` | text | `README.md` / — | entry file / corpus id for that root |

Provider plugins register corpora via a `docs_provider` instead (this agent self-registers
its own docs — see [skill-providers-and-families.md](../developer-guides/skill-providers-and-families.md)).

## Prompt overrides

| Config | Type | Effect |
|--------|------|--------|
| `aiinitialprompt_discovery` | textarea | override the discovery-phase prompt |
| `aiinitialprompt_selection` | textarea | override the selector prompt |
| `aiinitialprompt_parameter_construction` | textarea | override the constructor prompt |
| `aiinitialprompt_summarise_text` | textarea | prefix for the summarise/synchronizer action |

Each falls back to the built-in template when empty (see [ch. 05](../architecture/05-planner-orchestrator.md)).

## Safety / queue flags

| Config | Type | Default | Effect |
|--------|------|---------|--------|
| `aigovernancestrictmode` | checkbox | 0 | fail registry init if contract diagnostics exist (CI enforcement) |
| `queue_dag_validation_enabled` | checkbox | 1 | validate queue dependency DAGs ([ch. 10](../architecture/10-shadow-queue.md)) |
| `queue_blocked_ttl_enabled` | checkbox | 1 | enable TTL expiry of `blocked_confirmation` items |
| `queue_blocked_ttl_seconds` | int | (default 900) | fallback blocked TTL when risk-specific value not used |

## Skill activation

| Config | Type | Default | Effect |
|--------|------|---------|--------|
| `aiskillenabled_<name>` | implicit bool | on per skill | enable/disable one skill |
| `aiskillenableall` | one-shot | 0 | enable all discovered skills, then resets ([governance](governance.md)) |

Newly discovered skills are default-off until enabled.

## Benchmarking

| Config | Type | Default | Effect |
|--------|------|---------|--------|
| `benchmark_retention_days` | int | 365 | auto-delete runs older than N days (baselines kept) |
| `benchmark_threshold_skill_hit_rate` | float | 90 | CI-gate min skill-selection accuracy % |
| `benchmark_threshold_json_validity` | float | 99 | CI-gate min JSON validity % |
| `benchmark_threshold_e2e_success` | float | 85 | CI-gate min end-to-end success % |

See [benchmarking](benchmarking.md).

## Runtime feature flags

Defined in `config/runtime_feature_flags.php` (not all surfaced as admin settings); read via
`runtime_feature_flags::is_enabled()` / `enforcement_mode()`:

| Flag | Default | Gates |
|------|---------|-------|
| `FAMILY_DISCOVERY_ENABLED` | off | the deterministic family-discovery path |
| `STAGED_DISCOVERY_ENABLED` | off | Stage A→B→C escalation |
| `FAMILY_EMBEDDINGS_ENABLED` | off | semantic family-score boosting |
| `SYNCHRONIZER_STRICT_CONTRACT` | off | stricter synchronizer output validation |
| `CONSISTENCY_GATE_MODE` | `enforce` | consistency gate: `observe` / `warn` / `enforce` |
| `POSTCONDITION_ENFORCEMENT_MODE` | `enforce` | post-mutation verification: `observe` / `warn` / `enforce` |

The three enforcement modes mean: `observe` (log only), `warn` (log + soft message),
`enforce` (block + issue code).

---

## Server requirements (optional)

## Server requirements (optional)

The plugin runs on a stock Moodle server with no extra system packages. One feature has an
**optional** dependency that only improves performance:

| Feature | Hard requirement | Optional accelerator |
|---------|------------------|----------------------|
| PDF attachment → text | none — a pure-PHP extractor is bundled | `pdftotext` (poppler-utils) + PHP `exec()` enabled |

**PDF text extraction.** When a user uploads a PDF, the agent extracts its text
(`pdf_text_extractor`). It prefers the `pdftotext` binary (poppler-utils) when present and
when PHP `exec()` is allowed, because it is faster and more robust on large or complex PDFs.
If the binary is missing or `exec()` is disabled, it transparently falls back to the bundled
pure-PHP library **smalot/pdfparser** ([`thirdparty/pdfparser/`](../../thirdparty/pdfparser/),
LGPL-3.0) — so the feature always works, with no admin action required.

- **To enable the fast path** (recommended for sites that handle large PDFs):
  install poppler-utils on the web/CLI host, e.g. `apt-get install -y poppler-utils`, and
  make sure `exec` is not listed in PHP's `disable_functions`. Verify as the web user:
  `sudo -u www-data pdftotext -v`.
- **Limitation (both paths):** no OCR. Scanned / image-only PDFs contain no extractable
  text and will yield an empty result. Extracted text is capped at 15 000 characters.

See [architecture · entry & web services §7](../architecture/01-entry-and-web-services.md#7-attachments-docs--previews)
for the technical pipeline.
