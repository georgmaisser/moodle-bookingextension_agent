# 16 · Support services

> **Scope.** Cross-cutting services used by many subsystems. Flowchart subgraph: `SUPPORT`.

These are the small, sharp tools the rest of the engine leans on: masking PII, choosing the
reply language, deriving server-side triggers, classifying provider errors, and letting a
domain provider plug in its own normalization and issue codes — all without putting
domain-specific logic in the engine.

**Files:** `privacy_anonymizer.php`, `services/language_policy_service.php`,
`services/localized_string_service.php`, `message_trigger_registry.php`,
`ai_error_classifier.php`, `booking_issue_code_provider.php`,
`interfaces/issue_code_provider_interface.php`,
`interfaces/skill_input_normalizer_interface.php` (+ `_provider_interface`),
`prompt_policy_builder.php`, `services/provider_routing_util.php`,
`services/shared_json_payload_extractor.php`, `services/trigger_result_util.php`.

---

## 1. Privacy anonymizer

`privacy_anonymizer` keeps PII out of model prompts. It is used at three points:

- `precheck_user_message()` — scans a draft for emails/names (backs
  [`ai_privacy_precheck`](01-entry-and-web-services.md));
- `anonymize_value_for_llm()` — masks values **before** an observation re-enters a prompt
  (in the [observation loop](13-finalization-and-observations.md));
- `deanonymize_for_display()` / `deanonymize_message_for_display()` — restores the real
  values for the user-facing reply.

The round-trip means the model reasons over placeholders while the user always sees real
data, and the `privacyapplied` flag tells the UI when masking happened.

---

## 2. Language policy & localized strings

`language_policy_service::resolve_output_language(store, threadid, result)` implements the
source priority of the `LANG` / `LG_LANG` contract:

```
latest user message  →  model-declared user_lang  →  technical fallback
```

It is the authority `agent_runtime` uses for the persisted `lang`, and it formats all
framework-generated (non-LLM) replies. `localized_string_service::get(key, component,
a, lang)` is the localized-string accessor used by the
[template finalization](13-finalization-and-observations.md) and contract fallbacks, so even
deterministic messages come out in the user's language.

---

## 3. Message trigger registry

`message_trigger_registry` now holds only the `response_type` allow-list and
`normalize_response_type()` (unknown values → `UNKNOWN_TYPE`, later coerced to a clarification
by the runtime contract).

The former `used_triggers` LLM trigger channel — and all `core.*` flow triggers with it — has
been **removed**. Routing is driven purely by `response_type` and the command/risk shape, never
by a lexical trigger→skill map (the registry's `get_message_triggers()` returns `[]`). This is
what keeps routing deterministic and language-agnostic.

---

## 4. AI error classifier

`ai_error_classifier` maps a raw provider/IO failure to a stable `error_class`:
`provider_timeout`, `transient_io`, `auth_failed`, `quota_exceeded`. These classes feed the
[retry policy](09-preflight-pipeline.md) (which are retryable, which trip the circuit
breaker) and the [finalization classifier](13-finalization-and-observations.md) (which become
template-only messages). It is wired into the three LLM call paths (selection, construction,
synchronizer).

---

## 5. Domain hooks (the framework-agnostic seam)

The engine carries no booking-specific heuristics; a provider injects domain behavior
through interfaces:

- **`issue_code_provider_interface`** — a provider defines its confirmable and
  domain-specific issue groups. `booking_issue_code_provider` is the booking implementation;
  it is what tells the [preflight domain check](09-preflight-pipeline.md) that, say,
  `DUPLICATE_TITLE_CONFIRM_REQUIRED` is a *soft* (confirmable) block rather than a hard one.
- **`skill_input_normalizer_interface`** / `…_provider_interface` — the domain normalizer
  hook (`DNORM`): provider-supplied input normalization applied during interpretation, so
  the engine never needs domain-specific parsing.

This is the `LG_AGN` / `LG_3P` contract: *framework-agnostic by contract; provider behavior
enters via interfaces/hooks; third-party onboarding is provider + skill contract only, with
no framework rewiring.* See
[developer-guides/skill-providers-and-families.md](../developer-guides/skill-providers-and-families.md).

---

## 6. Smaller helpers

- `prompt_policy_builder` — assembles policy text shared across prompts (e.g. the
  "answer in the user's language", routing-determinism, and docs-answer policies), with no
  language token lists.
- `query_english_normalizer` — the one discovery-time LLM helper: a `generate_text` call that
  translates the embedding query to English (so it matches the English skill anchors),
  protecting `<<KEEP…>>` literals. Fail-open (returns the original query on any error/missing
  provider) and not config-gated; scope is the embedding query only, never skill routing. See
  [ch. 06 §4](06-discovery-families-embeddings.md#4-the-embedding-query).
- `provider_routing_util` — provider/action routing helpers shared by the orchestrator
  routing services.
- `shared_json_payload_extractor` — robust JSON extraction shared by the interpreters.
- `trigger_result_util` — reads/normalizes trigger signals on a result.

---

## 7. Flowchart notes

> **✓ Confirmed:** language source priority; triggers are server-derived with no
> trigger→skill routing (registry returns `[]`); error classes
> provider_timeout/transient_io/auth_failed/quota_exceeded; domain behavior via the
> issue-code provider + normalizer hook.

The full issue-code catalog lives in
[reference/issue-codes.md](../reference/issue-codes.md).
