# Benchmark Redesign — Specification & Plan

Status: proposal for external validation (2026-06-28).
Scope: the `bookingextension_agent` skill-selection benchmark (`cli/benchmark_runner.php`,
`classes/local/wizard/benchmark/`).

---

## 0. Context an external reviewer needs

The agent is an LLM planner for a Moodle booking module. Per user turn the pipeline is:

  discovery (semantic skill retrieval, multi-vector embeddings)
   → selection (LLM picks exactly ONE skill from a candidate catalog)
   → construction (LLM builds that skill's parameters)
   → synchronizer (final natural-language reply).

The planner emits JSON with a `response_type` ∈
`{ skill_call, confirmation_request, confirm_pending, clarification, sufficient, error }`
plus `commands[]`.

Two facts that drive this redesign:
- The selection model (MiniMax-M2.7) is a **reasoning model**: it is **non-deterministic even at
  temperature 0** (verified). Identical input can yield different output across runs.
- Skill metadata is **English-only**; user queries can be any language (the current benchmark is 100%
  German). A query→English normalisation step ("Weg B") bridges this — but it is itself an LLM call.

Measured starting point:
- **Discovery recall ≈ 100%** (the correct skill is in the top-12 candidates; mean rank ≈ 2.2).
  → The benchmark's value is in **selection + contract behaviour**, NOT discovery.
- **Run-to-run noise is large** (success-rate spread ~20–27 pp across identical configs). The single-run
  percentage is therefore not a usable metric; the **stable-fail set** is.

---

## 1. Anatomy of ONE benchmark test (a "scenario")

A scenario is a single, reproducible check:
> "Given THIS context and THIS user message, the planner must produce THIS behaviour."

### 1.1 Fields

| Field | Meaning |
|---|---|
| `key` | Unique, stable identifier. |
| `tier` | `deterministic` (contract) or `probabilistic` (LLM routing). Decides how it is run and scored. |
| `category` | routing · contract · multi-step · catalog-gap · readonly · cross-language … |
| `language` | `de` · `en` · … (explicit, for cross-language coverage). |
| **precondition** | |
| · `prior_messages` | Conversation history as text. |
| · `prior_state` | REAL thread state (queued commands, pending-confirmation, completed actions) set via **production setters** — required whenever the behaviour is state-driven. |
| · `data_fixture` | The booking/course/user data that must actually exist (e.g. an option titled "Erste Hilfe Grundkurs") so resolution is realistic. |
| **input** | `user_message` — the single turn under test. |
| **expected** | |
| · `response_type` | Exactly one of the six (or an explicit set of acceptable ones). |
| · `skill` | The skill that should be selected (or a set of acceptable skills, or N/A). |
| · `assertions` | Extra invariants (e.g. `commands` empty for `clarification`/`confirm_pending`; non-empty `message`; accepted alternative path such as find-then-book). |
| `acceptance` | The explicit, UNAMBIGUOUS pass rule. If more than one outcome is valid, ALL valid ones are listed. |
| `stub_response` | A canned valid planner output, used to test harness determinism without the LLM. |

### 1.2 Pass criterion

PASS ⇔
`response_type` matches **and** `skill` matches (if specified) **and** JSON is valid **and** the output
is contract-compliant **and** every `assertion` holds.

### 1.3 Design invariants — a valid scenario MUST satisfy all of these

1. **Unambiguous.** Exactly one `response_type` is correct, or every correct one is listed in
   `acceptance`. (Anti-example: a scenario that demands `confirm_pending` when `clarification` is
   equally correct because a required value is missing.)
2. **Reachable.** The expected behaviour must be reachable given the precondition. **State-driven**
   behaviours (e.g. `confirm_pending` = "execute the already-queued, confirmed command") require
   `prior_state`, not just narrative `prior_messages`.
3. **Single behaviour.** Tests ONE thing — routing OR a contract rule — never a conflation.
4. **Faithful.** The precondition reflects REAL production state (set via production code), not a
   hand-crafted JSON approximation or a narrative stand-in.
5. **Correct input.** Every entity the message references (option/course/user) actually exists in the
   `data_fixture`, so resolution behaviour is real.
6. **Tier-correct.** Deterministic contract rules live in the deterministic tier; only genuinely
   model-dependent routing lives in the probabilistic tier.

---

## 2. Why the current suite misleads (evidence)

- **Discovery is not the bottleneck** (recall ≈ 100%); yet failures look like routing failures.
- **Single-run % is dominated by noise** — flipping scenarios swing the headline far more than any real
  change; identical configs scored 46.7%–73.3%.
- **Input is not identical across runs.** The Weg-B normaliser (an LLM call) produced 3 distinct
  English translations for one German query, including translating a quoted option title
  ("Erste Hilfe Grundkurs" → "First Aid Basic Course"), which then fails to resolve. Self-inflicted
  variance.
- **Deterministic behaviours tested through the noisy LLM path with unrealistic preconditions.**
  `short_confirm_ja` expects `confirm_pending`, but the harness injects only prior TEXT, not the real
  pending-confirmation STATE, so `confirm_pending` is unreachable — the agent (correctly) re-plans and
  the test fails for the wrong reason. `confirm_pending` is verified to be **state-driven**.
- **Ambiguous / wrong expectations.** `confirmation_request_r1` expects `error` for a missing
  bulk-cancel skill (vs the agent choosing `search_skills`); `duplicate_prevention` assumes
  "create again" → `sufficient` (a product decision, not a fact).
- **A 2-turn fix would self-collide.** Making `short_confirm_ja` a real 2-turn run means turn 1 ("set
  Max as trainer for TestA") duplicates `update_option_trainer_by_name`, AND turn 1's own outcome
  (`confirmation_request` vs `skill_call`) is non-deterministic — so `confirm_pending` would only be
  reachable by chance. Not meaningful.

---

## 3. Redesign — three tiers

### Tier 0 — Discovery recall (diagnostic, rauschfrei)
- For each query: normalise → embed → is the expected skill in the top-12 (and at what rank)?
- No selector LLM → near-deterministic. Measures the **embedding/anchor ceiling** independently of the
  noisy selector. Already prototyped.

### Tier 1 — Deterministic contract tests (PHPUnit / integration)
- **Purpose:** verify the planner CONTRACT and decision rules deterministically:
  response_type rules; `confirm_pending` (with real seeded/queued state); clarification-on-missing-
  required; sufficient-on-completed; error-on-catalog-gap; JSON/contract validity; reply language.
- **How:** stub the selector (deterministic) OR seed real state via production setters; assert exact
  behaviour; **100% pass expected**; runs in CI on every change. **No LLM noise.**
- These do NOT belong in the live LLM benchmark.

### Tier 2 — LLM routing / selection quality (live benchmark)
- **Purpose:** for realistic queries, does the correct SKILL get selected? Includes cross-language and
  near-duplicate disambiguation (`create_option` vs `create_selflearning_option` vs
  `create_slotbooking_option`; `book_users` vs `search_options` vs `search_courses`; the 5 diagnose
  skills).
- **How:** live LLM, **N ≥ 5 runs**, aggregate per-scenario pass-rate. Primary metric = **stable-fail
  set** + per-scenario pass-rate; flip-rate reported as a noise indicator. Separate **Skill-Hit** from
  **Response-Type-Hit** from **JSON-validity**.
- **Controls:** pin sampling where the provider allows; make the discovery INPUT deterministic (fix the
  Weg-B normaliser: temp 0 + protect quoted titles, or pre-normalise scenario queries once); realistic
  data fixture; identical context per run.

---

## 4. Metrics & reporting

- **Tier 1:** binary pass/fail; must be 100% (a fail is a real contract regression).
- **Tier 2:** per-scenario pass-rate over N runs. Report separately: Skill-Hit, Response-Type-Hit,
  JSON-validity, Contract-compliance.
- **Primary signal:** the stable-fail set (0/N) = real targets. Flip-rate = noise level.
- **Tier 0:** recall@12 (and mean rank) = the embedding ceiling.
- **Harness-determinism guard:** run Tier-1 in stub mode twice → output must be byte-identical (CI
  fails otherwise). This is the consultant's "validate the harness first" turned into a standing check.

---

## 5. Harness requirements to build

1. `prior_state` seeding via production setters (queue / pending-confirmation / completed actions).
2. A **data fixture**: a known booking instance with the exact options/courses/users the scenarios
   reference (deterministic, version-controlled).
3. **Deterministic input** for Tier 2: the normaliser made deterministic for benchmarking (temp 0 +
   quoted-title protection) OR scenario queries pre-normalised once and stored.
4. **Multi-language variants** of routing scenarios.
5. **Stub mode** for Tier-1 determinism + the determinism guard.

---

## 6. Target scenario coverage

- **Routing (Tier 2):** one per major skill + each confusable cluster, each in `de` AND `en`.
- **Contract (Tier 1):** each response_type rule — missing-required → clarification; completed →
  sufficient; catalog-gap → error; mutating → confirmation_request → ("ja", seeded state) →
  confirm_pending; readonly → skill_call without confirmation.
- **Cross-language (Tier 2):** the same intent in `de`/`en`(/`fr`) to measure the bridge directly.

---

## 7. Migration of the existing 15 scenarios

| Scenario | Action |
|---|---|
| `short_confirm_ja` | → Tier 1, seed real pending-confirmation state (production setter), assert `confirm_pending`. Remove from Tier 2. |
| `clarification_missing_date` | → Tier 1 (deterministic clarification rule). |
| `confirmation_request_r1` | Resolve the product question (error vs search_skills), then place in the right tier. |
| `duplicate_prevention` | Product decision ("again" = duplicate vs no-op); then → Tier 1. |
| `book_users_single`, `create_option_*`, `update_option_trainer_by_name`, `search_*`, diagnose | → Tier 2 routing; add `de`+`en` variants; ensure the data fixture contains the referenced entities. |
| `auto_confirm_session_r1` | Split: JSON/contract part → Tier 1; routing part → Tier 2. |
| `get_current_user_readonly`, `ambiguous_*`, `retry_preflight_recovery`, `short_confirm_weiter` | Re-tier per the above rules. |

---

## 8. Open product decisions (need an owner before fixing the scenarios)

1. "Create X **again**" right after it was created → **duplicate** (act) or **no-op** (`sufficient`)?
2. A mutating action on a direct request → **always** `confirmation_request`, or `skill_call` when the
   risk is low? (We observed an inconsistency: the same `update_option_trainer` produced
   `confirmation_request` in one run and `skill_call` in another.)
3. A capability with **no matching skill** (e.g. "create a Zoom link", "bulk-cancel") → `error`, or
   route to `wizard.search_skills`?

---

## 9. One-line summary

Split the benchmark by NATURE of the check: **deterministic contract behaviour → deterministic tests
(stub/seeded, 100% pass, in CI)**; **model-dependent routing quality → live benchmark scored over N
runs by stable-fail set, with deterministic input and a realistic data fixture**. Stop reading
single-run percentages; stop testing state-machine contracts through the noisy LLM path with narrative
preconditions.
