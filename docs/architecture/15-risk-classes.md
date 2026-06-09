# 15 · Risk classes (R0–R3)

> **Scope.** The single cross-cutting contract that drives confirmation, retry, preflight
> depth, and reply requirements. Flowchart legend: `LG_RISK*`. This chapter is the canonical
> home of the risk-class matrix that the other chapters link to.

Risk classes are the spine of the agent's safety model. A skill declares **one** class, and
that single declaration is enforced — consistently and deterministically — at five different
points in the engine. Nothing about confirmation, retry, or irreversibility is decided
ad-hoc; it all follows from the class.

**Files:** `dto/skill_risk_class.php`, `skill_contract_validator.php`, and the enforcement
points in `agent_decision_service`, `preflight_pipeline`, `queue_manager` /
`queue_transition_service`, `executor`, and `finalization_classifier`.

---

## 1. The four classes

`skill_risk_class` defines them as string constants:

| Const | Value | Meaning |
|-------|-------|---------|
| `R0` | `read_only` | reads only; no state change |
| `R1` | `scoped_write` | a write with limited, local impact |
| `R2` | `broad_write` | a write with broad impact (many records / instance config) |
| `R3` | `irreversible_or_external` | irreversible or reaching an external system |

`skill_risk_class::is_valid()` is the only helper. The class is declared via
`skill_interface::get_risk_class()` and validated at construction (`base_skill` throws on an
invalid value) and at activation (`skill_contract_validator`).

---

## 2. Declaration & validation

`skill_contract_validator::verify_risk_class_declaration()` enforces consistency:

- the class must be valid;
- **R0 ⇔ `is_read_only()` is true**; R1/R2/R3 must be **not** read-only;
- **R2 and R3 must declare explicit `context_scopes`**;
- any mismatch makes the skill **not activatable** (it cannot be enabled in governance).

A command whose skill is unknown is treated as **R3** by the decision service — the
fail-safe default ([ch. 08 §3](08-decision-service.md)).

---

## 3. The consolidated matrix

This one table is what the rest of the corpus references. Every value here is verified
against code.

| Concern | R0 | R1 | R2 | R3 |
|---------|----|----|----|----|
| **Confirmation** | never | session-allow ok | always explicit | always **manual** (no session-allow) |
| **Auto-confirm** | n/a | yes, if session allowance | no | no |
| **Queue `blocked_expires_at` TTL** | n/a | **900 s** | **300 s** | **900 s** (manual-only) |
| **Preflight layers** | none | L1 + L2 | L1 + L2 + L3 | L1 + L2 + L3 + external dep. |
| **Execution retry** | allowed | allowed | allowed | **none** (`R3_NO_RETRY`) |
| **Reply requirement (`sufficient`)** | — | — | `affected_scope_summary` | `irreversibility_notice` |
| **Loop planner-retry** | allowed | allowed | allowed | **blocked** (R3 blocker) |

Notes:
- The **session allowance** itself (the user pref that enables auto-confirm) defaults to a
  12-hour TTL and is keyed by `(userid, contextid)` — distinct from the per-item queue
  `blocked_expires_at` TTLs above (see the [open question](../reference/flowchart-guide.md)).
- "manual-only" for R3 is enforced in `queue_transition_service` (R3 can never become
  `retry_waiting`), not by the TTL value.

---

## 4. Where each rule is enforced

| Rule | Enforcement point |
|------|-------------------|
| Confirmation gating (R0 inline / R1 session / R2 forced / R3 manual) | `agent_decision_service::handle_command_routing()` + `queue_transition_service::apply_preflight_decision()` ([ch. 08](08-decision-service.md)) |
| Preflight depth | `preflight_pipeline::run()` risk gate ([ch. 09](09-preflight-pipeline.md)) |
| Queue TTLs + R3 no-retry | `queue_manager::resolve_blocked_ttl_seconds()` + `queue_transition_service` ([ch. 10](10-shadow-queue.md)) |
| Execution-retry suppression for R3 | `queue_transition_service` (`R3_NO_RETRY`) ([ch. 11](11-executor.md)) |
| Loop planner-retry suppression for R3 | `agent_runtime::has_r3_retry_blocker()` ([ch. 04 §4](04-agent-runtime-and-loop.md)) |
| Reply requirements | `finalization_classifier::requires_irreversibility_notice()` / `requires_affected_scope_summary()` → synchronizer ([ch. 12](12-synchronizer.md), [ch. 13](13-finalization-and-observations.md)) |

---

## 5. Flowchart notes

> **✓ Confirmed:** all `LG_RISK*` legends hold — confirmation gating, preflight depth,
> R3-no-retry, and the R3 irreversibility / R2 affected-scope reply requirements. Queue TTLs
> R1=900/R2=300/R3=900 verified.

> **❓ Open:** the R1 *session-allow* TTL shown as 900 s in `LG_AUTO`/`LG_RISK_CONF` is the
> queue-blocked TTL; the session-allowance default is 12 h. Confirm intended value (see the
> discrepancy log).
