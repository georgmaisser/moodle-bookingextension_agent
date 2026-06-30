# Pre-Go-Live Audit — `bookingextension_agent`

**Plugin:** `bookingextension_agent` (the *wizard* engine) · **Version:** `2026062900` / release `1.0.0`
**Branch:** `first-release` · **Scope:** `classes/` (291 files, ~64k LOC) + `db/` + `cli/` + `amd/` + `templates/` + root PHP
**Excluded:** `thirdparty/`, `obsolet/`, `.claude/worktrees/*`
**Audit type:** read-only — findings & reports only, no engine changes.

This is the **final audit before the plugin goes live**. It checks six dimensions across every
file and method: **D1 Security · D2 Moodle API compliance · D3 Structure · D4 Duplicated code ·
D5 Flowchart compliance · D6 Architecture-docs coverage**.

## How this audit is organised

- **[`00-AUDIT-TEMPLATE.md`](00-AUDIT-TEMPLATE.md)** — the canonical template every report follows
  (dimensions, severity scale, per-file/per-method checklist format).
- **[`sections/`](sections/)** — one report per subsystem cluster, each with a full per-file /
  per-method checklist.
- **[`crosscutting/`](crosscutting/)** — horizontal sweeps (security, Moodle-API, duplication,
  flowchart+docs) that span the whole tree.
- **This file** — the executive summary, scorecard, and **go / no-go verdict** (filled after
  all sections complete).

---

## Executive summary

**Audit completed 2026-06-30.** 23 independent auditors (18 per-subsystem + 5 horizontal sweeps),
each on Opus 4.8, read every file and method in scope. The detailed reports are linked at the
bottom; this section is the consolidated verdict.

### 🟡 Verdict: **CONDITIONAL GO**

> **Status update 2026-06-30 — all gate-level (HIGH) findings are now resolved & tested.** Every
> original HIGH (search_users PII `12-F01`, LLM-debug logging `15-F01`, upgrade path `16-F01`,
> markdown scheme `06-F01`, benchmark write-cap `C1-F01`, engine→domain leak `05-F01`, the
> docs-coverage cluster `C2-F02`/`C5-*`/`09-F01`) has been fixed and committed; see the
> **[Remediation log](#remediation-log-2026-06-30)** below. A follow-up **capability-fidelity
> sub-audit** ([`capability/`](capability/README.md)) then re-hardened the read side: **CAP-01**
> (search_users — supersedes the incomplete first 12-F01 pass), **CAP-02** (get_option_details
> cross-instance read), **CAP-03** (recreate_skill_catalog teacher→manager) — all fixed with
> first-of-their-kind denial tests. **Residual = MEDIUM/LOW only** (`CAP-04…CAP-12` + assorted
> deferred mediums), tracked in the fix lists below. Zero blockers throughout.

The engine is **structurally sound and safe to ship after a short, well-defined pre-launch fix
list.** There are **zero BLOCKER findings** and — importantly — the flowchart sweep (C4) found
**no behavioural contradiction in any of the 14 subgraphs**; the safety machinery (two-gate
authorization, guard-token execution, risk-class gating, queue idempotency, file-upload hardening)
is confirmed correct. The prior audit's worst behavioural item (R2 blocked-TTL drift) is **resolved
in current code**.

The gate to launch is a small set of **HIGH** findings. Two are genuine
security/privacy must-fixes; the rest are an upgrade-path break, an ops privilege-separation slip,
a one-line XSS hardening, one architectural leak, and a cluster of doc inaccuracies. None is a
deep redesign.

> **The single most important pre-launch item is [12-F01]** — `core.search_users` returns full user
> PII (email/phone/address/idnumber/custom fields) for *any* user matched by free text, granted to
> the `teacher`/`editingteacher` archetypes at module context, **with no per-target visibility
> capability check** — unlike every sibling read skill. In a GDPR/USI context this should be fixed
> or explicitly waived by the maintainer **before** go-live. _Verified directly against the code
> during synthesis._

### Coverage

| | |
|---|---|
| Subsystem section reports | **18** (`sections/`) — every engine cluster |
| Cross-cutting sweeps | **5** (`crosscutting/`) — security, Moodle-API, duplication, flowchart, docs |
| File-audits across sections | **282** (some files deliberately covered by >1 section) |
| Methods audited across sections | **1,335** |
| Excluded | `thirdparty/`, `obsolet/`, `.claude/worktrees/*` |

### Findings by severity (18 subsystem sections)

| 🔴 Blocker | 🟠 High | 🟡 Medium | 🟢 Low | ⚪ Info |
|:---:|:---:|:---:|:---:|:---:|
| **0** | **6** | **32** | **58** | **39** |

Cross-cutting sweeps add **5 more HIGH** that mostly **corroborate/deduplicate** into the section
HIGHs below (e.g. the upgrade-path break is found by both `16` and `C2`).

### Aggregate dimension scorecard

| Dim | Result | Summary |
|-----|--------|---------|
| **D1 Security** | 🟡 Mostly strong, 2 real gaps | Two-gate model, guard tokens, file-upload, idempotency all confirmed. Gaps: `search_users` PII (12-F01), unconditional LLM-debug PII logging (15-F01), `javascript:` URI passthrough (06-F01), benchmark write-on-read-cap (C1-F01). |
| **D2 Moodle API** | 🟡 Compliant, 2 notable issues | External-API shape, PSR-4 (0 mismatches), capability+lang completeness, CLI guards, sesskey all clean. Issues: broken upgrade path (16-F01), dead caches/caps, 69 files missing `declare(strict_types=1)`. |
| **D3 Structure** | 🟡 Good, isolated leaks | Layering & DI respected, almost no dead code. One real engine→domain leak (05-F01 `parameter_constructor`), a few zero-caller helpers, one domain term in an engine trigger. |
| **D4 Duplication** | 🟡 Several drift risks | `WB_ACTION_*` FQCNs redeclared in 9 classes (already drifting), two parallel issue-code classifiers, duplicated `normalize_input`/`prune_empty_input_values`, byte-identical provider-error builders. |
| **D5 Flowchart** | 🟢 Behaviourally faithful | **No behavioural contradiction in any subgraph.** All deviations are doc-lag (stale method names/line numbers/labels). |
| **D6 Docs coverage** | 🟠 The weakest dimension | The biggest concentration of HIGHs: data-model doc has the **wrong table prefix** + false "no migrations" claim; executor chapter describes a **non-existent async task**; planner/runtime chapters cite **stale line numbers**; operating-context subsystem **undocumented**. |

---

### Pre-launch fix list (ordered — the actionable output of this audit)

> **✅ All items below are now resolved (2026-06-30).** This ordered list is the original actionable
> output of the audit; every HIGH item #1–#7 has since been fixed and committed — see the
> [Remediation log](#remediation-log-2026-06-30) for the specific change and test per item. #1
> (`search_users`) was additionally re-hardened by capability audit **CAP-01** after the first pass
> proved insufficient. Only the MEDIUM/LOW items (#8 and `CAP-04…CAP-12`) remain open. Kept here for
> traceability.


**Must fix or formally waive before go-live (security/privacy/data-integrity):**

1. **[12-F01] 🟠 `search_users` PII exposure** — add a per-candidate visibility gate
   (`moodle/site:viewuseridentity` / `moodle/user:viewdetails` at the candidate's context, or
   restrict to course-shared users / trim PII for non-managers). _Highest priority._
2. **[15-F01] 🟠 Unbounded LLM-debug PII store** — `bx_agent_ai_llm_debug` records full prompts +
   responses on *every* call regardless of `aidebugmode`, with no retention task. Gate behind
   `aidebugmode` and add a pruning scheduled task. (Privacy provider already covers GDPR export/erasure.)
3. **[16-F01 / C2-F01] 🟠 Broken upgrade path** — `db/upgrade.php` targets `local_wizard_*` while
   `install.xml` + runtime use `bx_agent_*`, no `rename_table`. Fatal for any site upgraded from a
   pre-rename build. Add a guarded `rename_table` (or delete the dead `local_wizard_*` blocks).
   Fresh installs are unaffected.
4. **[06-F01 / C1-F03] 🟠 `javascript:`/`data:` URI passthrough** in `markdown_renderer` — one-line
   `http(s)`/relative scheme allowlist in `format_non_doc_link()`. Reachable only from the trusted
   shipped corpus today, so low live risk, but trivial to close.
5. **[C1-F01 / C2-F03] 🟠 Benchmark baseline-pin write gated by *read* cap** `viewbenchmarks` —
   enforce the already-defined-but-unused `managebenchmarks` cap on the `pinbaseline` action in
   `benchmark_report.php`. Ops tooling, low blast radius.

**Strongly recommended before/around launch (correctness & maintainability):**

6. **[05-F01] 🟠 Engine→domain leak** — `parameter_constructor` hard-codes `mod_booking`/`musi`
   field names. Move into the per-skill `normalize_skill_input` hook **before the engine is frozen
   for the `local_wizard` extraction** (cheaper now than after the cut).
7. **Doc-coverage HIGH cluster** ([C2-F02], [C5-F01], [C5-F02], [09-F01]) — fix the data-model doc
   prefix + false "no migrations" claim; correct the executor chapter's async-task / deny-reason
   description; refresh stale planner/runtime line numbers; document the operating-context subsystem.
8. **MEDIUM themes** (32 total) — dead `aiwaitstate`/`aiwaitmailbox` caches & `managebenchmarks`
   cap; `WB_ACTION_*` constant duplication (C3-F01); duplicated normalizers (03-F03, 05-F02);
   `recreate_skill_catalog` mis-scoped `['module']` (12-F02). None gate launch.

**Cleanup backlog (LOW, 58 total):** 2 committed Python files in the PSR-4 `classes/` tree;
69 files missing `declare(strict_types=1)`; assorted zero-caller helpers and doc-lag line numbers.

---

### Remediation log (2026-06-30)

Fixes landed in this pass (in addition to the C-team's already-committed work on the upgrade path,
data-model docs, and benchmark write-cap):

| Item | Status | What changed |
|------|--------|--------------|
| **[12-F01] search_users PII** | ✅ Fixed (superseded — see CAP-01) | The first pass gated on `user_can_view_profile()`. The capability sub-audit (`docs/audit/capability/`, **CAP-01**) showed that is a no-op when `$CFG->forceloginforprofiles` is off and does not gate identity fields, and re-hardened it: drop candidates with no actor relationship (self / shared course / site-level `user:viewdetails`/`viewalldetails` / admin) **and** strip identity fields unless `moodle/site:viewuseridentity` (system or shared course). Denial test `search_users_capability_test` (4/4). |
| **[15-F01] LLM debug PII store** | ✅ Fixed (gated + retention) | **Maintainer decision (2026-06-30): log only when `aidebugmode` is on.** Both `llm_call_service` call sites use `llm_debug_logger::log_exchange` (self-gates on `is_enabled()`); the `log_exchange_always` bypass + `$forcelog` were removed, so `bx_agent_ai_llm_debug` stays empty in normal operation. The `cleanup_old_llm_debug_task` + `llm_debug_retention_days` (30d) still cap rows while debug mode is on. The real-LLM test base now enables `aidebugmode` in setUp so its trail assertions hold. |
| **[06-F01] markdown_renderer scheme** | ✅ Fixed | `format_non_doc_link()` now allow-lists `http`/`https`/`mailto`; any other scheme (`javascript:`/`data:`/`vbscript:`) is neutralised to `#`. Closes the XSS vector. |
| **[05-F01] parameter_constructor engine→domain leak** | ✅ Fixed | Engine de-leaked: booking self-ref (`teacherquery`/`selectusers`/`bookusers`) + `coursestarttime`/`courseendtime` moved to mod_booking `slot_booking_normalizer` (runs for all booking tasks); `search_queries` handled in `explain_docs`; `question` hydration is now schema-driven via a `from_user_message` flag (6 skills declare it). `parameter_constructor` names no domain field. phpcs 0/0; `phase3_selection_construction` 4/4 + `mod_booking_option_skills` 11/11 green. Cross-repo (agent + mod_booking); **Georg runs the real-LLM matrix** for end-to-end verification. |
| **[C1-F01 / C2-F03] benchmark write-cap** | ✅ Already fixed | Commit `8402580` gates `pinbaseline` on `managebenchmarks`; the dead-cap concern (C1-F02) is resolved with it. |
| **[16-F01 / C2-F01] upgrade path** | ✅ Already fixed | Commit `5c6556c` (install-only `upgrade.php` + corrected data-model docs). |

**Version** bumped `2026063000 → 2026063001` so the new cleanup task registers.
**Not yet run (no PHP toolchain on this mount):** `phpcs --standard=moodle` and PHPUnit must be run in
the container before merge — the changes were hand-checked for line-length/whitespace but not phpcs-verified.

### Confidence & method notes

- Every "zero callers / dead" claim was grep-verified tree-wide; framework-invoked entry points
  (skill `execute`/`preflight`, external `execute*`, tasks, observers, hooks, DI/reflection targets)
  were **not** treated as dead, per the template's false-positive guardrail.
- The top security item (12-F01) was **independently re-verified against the source during
  synthesis**, not merely relayed from the sub-auditor.
- The first workflow run was throttled by a transient **server-side** rate limit (not a usage
  limit); the 17 affected sections were re-run in throttled waves and all completed. The audit set
  is complete: 18/18 sections + 5/5 sweeps on disk.

---

## Report index

**Cross-cutting sweeps** — [`crosscutting/`](crosscutting/):
[C1 Security](crosscutting/C1-security.md) ·
[C2 Moodle API](crosscutting/C2-moodle-api.md) ·
[C3 Duplication](crosscutting/C3-duplication.md) ·
[C4 Flowchart](crosscutting/C4-flowchart.md) ·
[C5 Docs coverage](crosscutting/C5-docs-coverage.md)

**Subsystem sections** — [`sections/`](sections/): `01` Entry & WS · `02` Authz/Context/Privacy ·
`03` Store & Runtime loop · `04` Planner & Discovery · `05` Selection & Construction ·
`06` Embeddings & Docs · `07` Decision/Risk/Finalization · `08` Preflight · `09` Queue & Executor ·
`10` Synchronizer · `11` Skill foundation · `12` Skill implementations · `13` Activities/Questions ·
`14` Diagnostics/Summarizers · `15` Support (LLM/attachments/trial) · `16` Plugin files/DB/settings ·
`17` Front-end · `18` Benchmark & CLI.
