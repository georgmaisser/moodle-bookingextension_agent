# 06 — Test Coverage Inventory: Capability Fidelity & Cross-Context Authorization

Scope: existing PHPUnit/Behat coverage for capability checks, cross-context / cross-instance
authorization, and IDOR ownership in `bookingextension_agent` (the "wizard" agent) and `mod_booking`.
Read-only catalogue. "Covered" = a test asserts an **under-privileged or wrong-context actor is
DENIED**; merely exercising a happy path or asserting a structure does **not** count.

Test trees scanned:
- `mod/booking/bookingextension/agent/tests/` (excluding `.claude/worktrees/`)
- `mod/booking/tests/`

Grep terms: `assign_capability`, `has_capability`, `native_capability_guard`,
`skill_executability_evaluator`, `INVALID_OPTIONID`, `CONTEXT_TARGET_UNRESOLVED`,
`permission_denied`, `NO_NATIVE_CAPABILITY`, `DENY_*`, `viewuseridentity`, `user_can_view_profile`,
`setUser`, `thread_idor`, `cross_instance`, `cross_context`, `accessdenied`.

---

## 1. Test files that exercise capability / authorization / cross-context / IDOR

### Agent (`bookingextension_agent`)

| File | Property asserted (DENY-counting unless noted) | Skill / component |
|---|---|---|
| `tests/native_capability_guard_test.php` | **Central Gate-2.** User lacking cap is reported missing; **cross-context: cap in course A does NOT leak to course B**; unresolvable context fails closed; admin allowed everywhere; **invariant: every mutating non-`wizard.*` skill declares ≥1 native capability**. | `native_capability_guard` (engine, all mutating skills) |
| `tests/skill_name_capability_test.php` | **Per-skill name-capability gate.** Every skill exposes a defined `<component>:skill_<name>` capability; engine derives + enforces it even with empty declared caps (under-priv user `assertFalse`, admin `assertTrue`). | `skill_executability_evaluator`, `skill_contract_validator` (all skills) |
| `tests/thread_idor_external_test.php` | **IDOR at the WS boundary.** Authorised attacker guessing owner's `threadid` gets fail-closed empty result — no poll messages, no raw LLM debug logs. | `external\ai_poll_thread`, `ai_get_thread_debug_logs` |
| `tests/thread_ownership_gate_test.php` | **IDOR gate in isolation.** `thread_belongs_to_user` accepts owner-in-context only; rejects foreign user, wrong context, zero/non-existent id. | `conversation_store::thread_belongs_to_user` |
| `tests/conversation_store_owned_thread_test.php` | Thread ownership scoping (owner-only retrieval). | `conversation_store` |
| `tests/generate_questions_cross_context_test.php` | **Cross-context Gate-2 at TARGET course.** User with no role in target course → `NO_NATIVE_CAPABILITY` deny; teacher in target → pass; operating context resolves to target not ambient. | `question.generate_questions` |
| `tests/add_activity_skill_test.php` | **Gate-2 blocks student** → `NO_NATIVE_CAPABILITY` (`test_preflight_gate2_blocks_student`). | `course.add_activity` |
| `tests/add_quiz_skill_test.php` | **Gate blocks student** → `NO_NATIVE_CAPABILITY` (`test_gate_blocks_student`). | `course.add_quiz` |
| `tests/update_activity_skill_test.php` | **Gate blocks student** → `NO_NATIVE_CAPABILITY`. | `course.update_activity` |
| `tests/update_quiz_skill_test.php` | **Gate blocks student** → `NO_NATIVE_CAPABILITY`. | `course.update_quiz` |
| `tests/analyze_course_structure_test.php` | **Denies outsider** (`permission_denied`); student does not see hidden items / no leak in observation; R0 cross-context course resolution. | `course.analyze_course_structure` |
| `tests/diagnose_user_in_course_skill_test.php` | **Cross-user denied for student** (`role:review` absent) → `permission_denied`. | `course.diagnose_user_in_course` |
| `tests/diagnose_notifications_skill_test.php` | **Cross-user gate**: non-admin teacher reading another user → `permission_denied`; admin allowed. | `core.diagnose_notifications` |
| `tests/diagnose_permissions_skill_test.php` | **Cross-user gate**: student reviewing another user → `permission_denied`; capability-mode self allowed. | `core.diagnose_permissions` |
| `tests/authorization_readiness_test.php` | **Use-readiness gate**: user without use-cap → `permission_denied` problem (not exception); invalid context handled; messages distinct from "unavailable". | `authorization_service` / readiness (engine entry) |
| `tests/user_memory_skills_test.php` | **Ownership**: `forget` by id by a non-owner → `hard_block`; owner resolves+deletes. Plus name-capability defined gate. | `wizard.remember/forget/list_memories/recall` |
| `tests/skill_operating_context_resolver_test.php` | Opt-in skill with target resolves cross-context; non-opt-in stays ambient. (Resolution correctness, not a deny.) | `skill_operating_context_resolver` |
| `tests/context_resolver_operating_context_test.php` | Explicit/ named target course resolves cross-context (≠ ambient); ambiguous → candidates; unknown → not found. (Resolution correctness.) | `context_resolver` |
| `tests/module_target_pipeline_clarification_test.php` | Module-target resolution + clarification (no deny assert). | `module_target_resolver` |
| `tests/permission_capability_anonymizer_test.php` | Privacy: capability/permission strings anonymised in LLM payload (not an authz deny). | anonymizer |
| `tests/agent/contracts/mod_booking_option_skills_contract_test.php` | **Happy-path only** — grants caps via `assign_capability`, asserts behaviour. **No denial.** | mod_booking option skills (contract) |
| `tests/agent/real_llm_multistep/search_users_real_llm_test.php` | **Real-LLM gated.** Asserts payload contains roles/courses/profile. **No PII-denial / under-priv assertion.** | `core.search_users` |

### mod_booking

| File | Property asserted | Skill / component |
|---|---|---|
| `tests/agent_option_skill_cross_instance_test.php` | **THE cross-instance denial.** Teacher of instance A acting from A must NOT update / book into an option living in instance B (positive control: teacher B passes). Asserts `status != 'pass'` + non-empty `issue_codes`. | `mod_booking.update_option`, `mod_booking.book_users` |
| `tests/booking_option_permission_test.php` | Core booking option capability behaviour (not an agent-skill deny; `mod_booking:addeditownoption` etc.). | core `mod_booking` (not skill layer) |
| `tests/wizard_diagnose_booking_issue_visibility_test.php` | Visible/hidden activity signal (`activityuservisible`) for a student — **visibility signal, not a deny**. | `mod_booking.diagnose_booking_issue` |
| `tests/wizard_diagnose_user_certificate_test.php` | Certificate report content — **happy path, no deny**. | `mod_booking.diagnose_user_booking` |

---

## 2. Coverage matrix — "is an under-privileged / wrong-context actor DENIED?"

Legend: **COVERED** = explicit deny assertion; **PARTIAL** = deny only via the generic
`native_capability_guard` / `skill_name_capability` invariants (no skill-specific deny test, or only
cross-user but not cross-context, etc.); **NONE** = no deny assertion anywhere.

### Agent skills

| Skill | Status | Evidence |
|---|---|---|
| `core.search_users` | **NONE** | only `search_users_real_llm_test.php` (real-LLM, asserts payload presence; no `viewuseridentity` / `user_can_view_profile` / under-priv deny). |
| `core.diagnose_notifications` | **COVERED** | `diagnose_notifications_skill_test.php::test_cross_user_gate` → `permission_denied`. |
| `core.diagnose_permissions` | **COVERED** | `diagnose_permissions_skill_test.php::test_cross_user_gate` → `permission_denied`. |
| `course.add_activity` | **COVERED** | `add_activity_skill_test.php::test_preflight_gate2_blocks_student` → `NO_NATIVE_CAPABILITY`. |
| `course.add_quiz` | **COVERED** | `add_quiz_skill_test.php::test_gate_blocks_student` → `NO_NATIVE_CAPABILITY`. |
| `course.update_activity` | **COVERED** | `update_activity_skill_test.php::test_gate_blocks_student` → `NO_NATIVE_CAPABILITY`. |
| `course.update_quiz` | **COVERED** | `update_quiz_skill_test.php::test_gate_blocks_student` → `NO_NATIVE_CAPABILITY`. |
| `course.analyze_course_structure` | **COVERED** | `analyze_course_structure_test.php::test_execute_denies_user_without_course_access` → `permission_denied`. |
| `course.diagnose_user_in_course` | **COVERED** | `diagnose_user_in_course_skill_test.php::test_cross_user_access_denied_for_student` → `permission_denied`. |
| `course.search_courses` | **NONE** | only referenced in the LLM scenario provider + embeddings fixture; no deny test. |
| `question.generate_questions` | **COVERED** | `generate_questions_cross_context_test.php::test_gate2_checked_at_target_course` → `NO_NATIVE_CAPABILITY` at target. |
| `wizard.remember` | **PARTIAL** | acts on own pref store; name-capability defined-gate only (`user_memory_skills_test::test_skills_pass_real_executability_gate`). No under-priv exec deny. |
| `wizard.forget` | **COVERED** (ownership) | `user_memory_skills_test::test_forget_by_id_ownership_and_delete` — non-owner id → `hard_block`. |
| `wizard.recall` | **PARTIAL** | own store; name-capability gate only; no deny test. |
| `wizard.list_memories` | **PARTIAL** | own store; name-capability gate only; no deny test. |
| `wizard.recreate_skill_catalog` | **PARTIAL** | admin-gated by name-capability (asserted defined), but **no under-priv exec deny test**. |

### mod_booking skills

| Skill | Status | Evidence |
|---|---|---|
| `create_option` | **PARTIAL** | covered only by the `native_capability_guard` invariant (declares native caps) + `skill_name_capability`; **no per-skill / cross-instance deny test** (cross-instance test covers only update_option & book_users). |
| `update_option` | **COVERED** | `agent_option_skill_cross_instance_test.php::test_update_option_blocks_cross_instance_option`. |
| `book_users` | **COVERED** | `agent_option_skill_cross_instance_test.php::test_book_users_blocks_cross_instance_option`. |
| `bulk_update_options` | **PARTIAL** | generic guard/name-cap invariants only; no per-skill deny test. |
| `update_option_trainer` | **PARTIAL** | generic invariants only; no per-skill deny test. |
| `create_rule_from_template` | **PARTIAL** | generic invariants only; no per-skill deny test. |
| `configure_booking_instance` | **PARTIAL** | generic invariants only; no per-skill deny test. |
| `add_price_category` | **PARTIAL** | generic invariants only; no per-skill deny test. |
| `diagnose_booking_issue` | **NONE** | only a visibility-signal test (not a deny); R0 read-only diagnostics. |
| `diagnose_cancellation_issue` | **NONE** | only referenced in Behat helper; no deny test. |
| `diagnose_user_booking` | **NONE** | only a certificate-content test (happy path). |
| `get_option_details` | **NONE** | no deny test. |
| `search_options` | **NONE** | only referenced in a Behat feature; no deny test. |

> Note on PARTIAL (mutating mod_booking skills): `native_capability_guard_test::test_every_mutating_skill_declares_native_capabilities`
> and `skill_name_capability_test` give *systemic* coverage — every mutating skill is forced to
> declare native caps and a defined name-capability, and the engine enforces them generically. So
> these skills are not *un*-gated; they simply lack a **skill-specific cross-context/under-priv deny
> test** proving the wiring end-to-end (as update_option/book_users have).

---

## 3. Strongest existing tests (genuine cross-context / IDOR denials)

All are **PHPUnit (always run)** unless flagged real-LLM-gated.

**1. `native_capability_guard_test::test_cross_context_capability_does_not_leak_between_courses`** (PHPUnit)
`mod/booking/bookingextension/agent/tests/native_capability_guard_test.php:137`
```php
$this->assertSame(
    ['moodle/course:manageactivities'],
    native_capability_guard::missing_capabilities($skill, $ctxb, (int)$teacher->id),
    'A capability held in course A must not authorise acting on course B.'
);
```

**2. `agent_option_skill_cross_instance_test::test_update_option_blocks_cross_instance_option`** (PHPUnit)
`mod/booking/tests/agent_option_skill_cross_instance_test.php:58`
```php
$this->assertNotSame('pass', $deny['status'],
    'A teacher privileged only in course A must NOT update an option in course B.');
$this->assertNotEmpty($deny['issue_codes'], 'The cross-instance denial must carry a reason code.');
```
(Sibling `test_book_users_blocks_cross_instance_option` at `:86` asserts the same for `book_users`.)

**3. `generate_questions_cross_context_test::test_gate2_checked_at_target_course`** (PHPUnit)
`mod/booking/bookingextension/agent/tests/generate_questions_cross_context_test.php:93`
```php
$result = (new generate_questions_skill())->preflight($input, $targetcontextid, (int)$student->id)->to_array();
$this->assertNotSame('pass', $result['status']);
$this->assertContains('NO_NATIVE_CAPABILITY', $result['issue_codes']);
```

**4. `thread_idor_external_test::test_poll_thread_rejects_foreign_threadid`** (PHPUnit)
`mod/booking/bookingextension/agent/tests/thread_idor_external_test.php:80`
```php
$this->assertSame(0, (int)$attackerview['threadid'], 'A foreign threadid must not resolve.');
$this->assertSame([], $attackerview['messages'], 'No messages of another user may leak.');
```
(plus `test_debug_logs_reject_foreign_threadid:116` — raw LLM logs never leak.)

**5. `skill_name_capability_test::test_name_capability_enforced_even_with_empty_declared_caps`** (PHPUnit)
`mod/booking/bookingextension/agent/tests/skill_name_capability_test.php:154`
```php
$this->assertFalse(
    $method->invoke($evaluator, (int)$fresh->id, $ctxid, $skillname),
    'The engine must derive + enforce the name capability even when declared caps are empty.'
);
```

**6. `diagnose_user_in_course_skill_test::test_cross_user_access_denied_for_student`** (PHPUnit)
`mod/booking/bookingextension/agent/tests/diagnose_user_in_course_skill_test.php:183`
```php
$this->assertSame('permission_denied', $res['error_class']);
```

> Real-LLM-gated (rarely run, need `BOOKING_TEST_AI_KEY`): the
> `tests/agent/real_llm_multistep/*` suite — including `search_users_real_llm_test.php` — does **not**
> contain any cross-context/under-priv DENY assertion. It only asserts happy-path payload shape.

---

## 4. GAPS the audit must call out

Skills with **NO** under-privileged / cross-context denial test:

- **`core.search_users`** — highest-priority gap. No PHPUnit deny test at all; the only test is
  real-LLM-gated and asserts *presence* of roles/courses/profile in the payload. **No
  `viewuseridentity` / `user_can_view_profile` PII-visibility test, no under-privileged caller deny.**
- **`course.search_courses`** — no deny test (only embeddings/LLM-scenario references).
- **`mod_booking.diagnose_booking_issue`** — only a visibility-signal test.
- **`mod_booking.diagnose_cancellation_issue`** — no test beyond a Behat reference.
- **`mod_booking.diagnose_user_booking`** — only a certificate-content happy path.
- **`mod_booking.get_option_details`** — no deny test.
- **`mod_booking.search_options`** — no deny test (Behat reference only).

Skills with **PARTIAL** coverage (generic guard/name-capability invariant only; **no skill-specific
end-to-end deny test** as update_option/book_users have):

- **`mod_booking.create_option`** — mutating, no per-skill cross-instance deny (covered only generically).
- **`mod_booking.bulk_update_options`**
- **`mod_booking.update_option_trainer`**
- **`mod_booking.create_rule_from_template`**
- **`mod_booking.configure_booking_instance`**
- **`mod_booking.add_price_category`**
- **`wizard.remember` / `wizard.recall` / `wizard.list_memories`** — only the name-capability
  *defined* gate; no under-priv exec deny (acceptable since own-store, but note it).
- **`wizard.recreate_skill_catalog`** — admin-gated by name-capability but **no under-privileged
  exec deny test** proving a non-admin is blocked.

**Cross-cutting observations:**
- The strong cross-instance pattern (`agent_option_skill_cross_instance_test.php`) is applied to
  **only 2 of the mutating mod_booking skills** (update_option, book_users). The other 6 mutating
  mod_booking skills rely entirely on the generic `native_capability_guard` invariant — no skill-level
  proof that the TARGET option/instance context is the one enforced.
- **No `user_can_view_profile` / `viewuseridentity` test exists anywhere** in either tree — user-PII
  visibility for `core.search_users` is untested.
- The systemic invariants (`native_capability_guard_test::test_every_mutating_skill_declares_native_capabilities`,
  `skill_name_capability_test`) are the safety net catching new skills; they are strong but do **not**
  prove correct *context* (the target-vs-ambient question) per skill.
