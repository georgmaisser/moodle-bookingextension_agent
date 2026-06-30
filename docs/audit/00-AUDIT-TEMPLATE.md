# Pre-Go-Live Audit Template — `bookingextension_agent`

> **Status:** canonical template. Every subsystem section report under `docs/audit/sections/`
> and every cross-cutting report under `docs/audit/crosscutting/` MUST follow this structure
> so the executive summary can aggregate them mechanically.
>
> **Audit type:** read-only. The audit produces *findings and reports only* — no engine code
> is changed during the audit. Per `feedback_flowchart_policy`, flowchart deviations are
> **reported, not silently reconciled**.

---

## 1. The six audit dimensions

Every file and every method is judged against these six dimensions. A finding always names
exactly one primary dimension.

| # | Dimension | What it checks |
|---|-----------|----------------|
| **D1** | **Security** | Capability checks (Gate 1 use/skill, Gate 2 native), `require_login`/`require_capability`, context validation, IDOR (threadid/runid/userid ownership), SQL injection (parameterised `$DB`, no string interpolation), CSRF/`sesskey` on state-changing entry points, privacy (PII handling, anonymizer, privacy provider completeness), secrets handling (API keys, no logging of keys), unvalidated external input, file-upload safety, capability *risk* (XSS via unescaped output, `format_text`/`clean_param` usage). |
| **D2** | **Moodle API compliance** | Uses framework APIs rather than reinventing: `$DB` data-manipulation API, external API (`external_function_parameters`/`external_value`/`execute`), capability API (`db/access.php` defines every checked cap), string API (`get_string`, no hard-coded user strings), file API (`file_storage`/draft areas), caching API (`db/caches.php` + `cache::make`), task API (`\core\task`), events API, hook API, forms API (`moodleform`/dynamic forms), `clean_param`/`PARAM_*`, `context_*::instance`, output/renderer + Mustache, coding-style (`phpcs --standard=moodle`), correct file headers/`MOODLE_INTERNAL`/`defined('MOODLE_INTERNAL')`. |
| **D3** | **Structure** | Single responsibility, file size sanity, dead code (zero-caller methods/classes/constants — excluding framework-invoked entry points), unused `use` imports, namespace correctness vs PSR-4 path, DI/interface boundaries respected, no engine→domain leak (engine carries no `mod_booking.*` heuristics), god-objects, layering (entry → authz → runtime → planner → decision → safety → reply → skills). |
| **D4** | **Duplicated code** | Same logic copy-pasted across files/methods; near-duplicate helpers that should share a base/trait/service; repeated literals/constants that should be centralised; parallel switch/maps that drift. Report the canonical home + the duplicates. |
| **D5** | **Flowchart compliance** | Behaviour matches `docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd` for the nodes this subsystem owns. Each finding cites the node id (e.g. `EXC_GUARD`, `PF_L2P`). Distinguish **behavioural** deviation (code does something the diagram contradicts) from **doc-lag** (diagram names a stale method/label). |
| **D6** | **Architecture-docs coverage** | The matching `docs/architecture/NN-*.md` chapter accurately describes the code, AND the code does not contain material behaviour the chapter omits. Cite chapter + section. Flag claims in the chapter that are false against current code, and significant code paths the chapter never mentions. |

---

## 2. Severity scale

| Severity | Definition | Go-live effect |
|----------|------------|----------------|
| 🔴 **BLOCKER** | Exploitable security hole, data-loss/corruption risk, or contract violation that can mis-execute a mutation. Ships a real user-facing failure. | **Blocks go-live.** Must be fixed or explicitly waived by maintainer. |
| 🟠 **HIGH** | Real bug or compliance violation with limited blast radius, OR a security weakness mitigated by another layer. Should fix before or immediately after launch. | Fix strongly recommended pre-launch. |
| 🟡 **MEDIUM** | Correctness/maintainability issue, notable duplication, doc/flowchart behavioural drift, missing-but-defended capability check. | Schedule soon; not a launch gate. |
| 🟢 **LOW** | Minor cruft, dead code, style, unused import, naming, doc-lag. | Cleanup backlog. |
| ⚪ **INFO** | Observation / confirmed-correct note / suggestion with no defect. | None. |

A finding's severity is the **residual** risk after accounting for compensating controls
(e.g. a missing `require_capability` that is unreachable because an earlier gate already
enforced it is MEDIUM, not BLOCKER — but say so explicitly).

---

## 3. Section report structure (one file per subsystem)

Each subsystem auditor writes `docs/audit/sections/NN-<cluster>.md` with exactly these parts:

```markdown
# Audit Section NN — <Cluster name>

**Scope:** <dirs/files>  ·  **Files audited:** N  ·  **Methods audited:** M
**Arch chapter(s):** docs/architecture/NN-*.md  ·  **Flowchart nodes:** <ids>
**Auditor verdict:** ✅ clean / ⚠️ issues / 🛑 blocker present

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass / issues / n/a | … |
| D2 Moodle API      | pass / issues / n/a | … |
| D3 Structure       | pass / issues / n/a | … |
| D4 Duplication     | pass / issues / n/a | … |
| D5 Flowchart       | pass / issues / n/a | … |
| D6 Docs coverage   | pass / issues / n/a | … |

## B. Findings
(one block per finding — see finding format below; empty if none)

## C. Per-file / per-method checklist
(every file in scope, every public/protected method — checkbox table; see below)

## D. Go-live blockers from this section
(bulleted; "none" if clean)
```

### Finding format (used in part B)

```
### [NN-F01] 🟠 HIGH · D1 Security · <file>:<line>
**What:** one-sentence statement of the defect.
**Evidence:** the code / call path that proves it (quote the line or method).
**Impact:** what an attacker / user / maintainer experiences.
**Compensating control:** any layer that reduces residual risk (or "none").
**Recommendation:** the concrete fix.
```

### Per-file / per-method checklist format (part C)

A checked box `[x]` means **audited and clean on that dimension**; an unchecked box `[ ]`
means **a finding was raised** (cross-reference its id). `n/a` where a dimension does not
apply (e.g. D5 for a file with no flowchart node).

```markdown
#### `relative/path/file.php`  (class `foo`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct()`            — D1✓ D2✓ D3✓
  - [ ] `execute(array $input)`    — see NN-F01 (D1)
  - [x] `private helper_x()`       — clean
```

Audit **every** PHP file in scope and **every** named method (public, protected, private,
static). For a file that is a pure DTO/interface, a single file-level line is enough; for a
service with logic, enumerate the methods.

---

## 4. Cross-cutting report structure

The horizontal auditors (security, moodle-api, duplication, flowchart+docs) write
`docs/audit/crosscutting/<name>.md` using parts A (scorecard), B (findings, same finding
format) and a closing **"Top blockers"** list. They cite findings across the whole tree,
not one cluster.

---

## 5. What is NOT a defect (false-positive guardrail)

To keep signal high, the following are **not** to be reported as dead code or violations:

- Framework-invoked entry points with zero in-repo callers: skill `execute()`/`preflight()`,
  external API `execute*`/`execute_parameters`/`execute_returns`, task `execute()`, event
  observers, privacy provider methods, hook callbacks, `db/*.php` callbacks, DI-factory
  targets, `usort`/`array_map`/reflection callbacks, Mustache-referenced renderer methods.
- Interface methods that are part of a declared contract even if only one implementer exists.
- `config/command_schema.json`-style data files loaded at runtime by path.
- The `.claude/worktrees/*` trees — **excluded from audit** (stale shadow copies).
- `thirdparty/` — **excluded** (vendored; only check it is declared in `thirdpartylibs.xml`).
- `obsolet/` — note its existence (D3 cruft, LOW) but do not audit its contents.

When in doubt whether something is dead, **grep the whole `classes/` + `tests/` tree** before
claiming zero callers, and state that you did.

---

## 6. Coverage ledger

The audit is "complete" only when every file in the inventory below is owned by exactly one
subsystem section (cross-cutting reports overlap deliberately). The executive summary
reconciles the union of section scopes against `find classes db cli amd templates -name '*.php'`.
