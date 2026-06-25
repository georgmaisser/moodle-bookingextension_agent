# Architecture Reduction Checklist — Radical Class Cleanup

**Referenz:** `AGENT_IMPLEMENTATION_FLOWCHART.mmd`
**Prinzip:** Behalte nur Funktionalität, die laut Flowchart in der jeweiligen Klasse vorgesehen ist.
**Regel:** Kein Ersatz. Nur Streichen.

---

## agent_decision_service.php (2116 Zeilen)
**Erlaubte Verantwortung laut Flowchart (DECIDSVC):**
- `process()` — deterministisches Routing
- Preview-Check (`core.is_preview_request`)
- Confirmation-State-Machine (has pending_intent?)
- Provider-defined confirmation override (D_DUPL, via issue_code_provider)
- Ambiguity gate
- Mutation guard (`core.is_lookup_request` + mutating block)
- Routing nach response_type → executor / preflight / retry
- planner_retry (max 2x)
- `handle_preflight()` — preflight_pipeline aufrufen
- `handle_confirm_pending()` — Bestätigungslogik
- `handle_command_routing()` — readonly → executor, mutating → preflight

**❌ VERSTÖSSE (zu streichen):**
- [x] `DUPLICATE_TITLE_ISSUE_CODES` + `PREVALIDATION_CONFIRMABLE_ISSUE_CODES` — domain-spezifische Konstanten (gehören in `domain_issue_code_provider`)
- [x] `find_missing_option_anchor_readonly_task()` — booking-spezifische optionanchor-Logik, kein Routing
- [x] `enrich_readonly_commands_with_planner()` — Enrichment während Routing = verbotenes recovery_enrichment fallback
- [x] `enrich_option_anchor_inputs()` — booking-spezifische Command-Anreicherung
- [x] `augment_missing_teacher_autocreate_confirmation()` — booking-spezifisch (Teacher-Erstellung)
- [x] `resolve_task_name_by_suffix()` — Task-Name-Heuristik, explizit verboten lt. Flowchart
- [x] `has_recent_duplicate_title_prompt()` — Domain-Heuristik (DB-Check für vergangene Buchungstitel)
- [x] `apply_duplicate_title_override()` — booking-spezifisches Domain-Override
- [x] `apply_confirmable_overrides()` für LOCATION/MISSING_LOCATION — booking-spezifisch
- [x] `inject_output_language_into_commands()` — Sprach-Injektion in Commands, kein Routing
- [x] `with_output_language()` — Wrapper für Sprach-Injektion
- [x] `extract_option_id_from_message()` — Message-Parsing (gehört in Interpreter)
- [x] `apply_execution_guard_tokens()` — Execution-Guard-Management (gehört in Executor/Preflight)
- [x] `enforce_response_contract_invariants()` — Contract-Recovery-Fallback, verboten lt. Flowchart-Legend "Deterministic routing only / no recovery_enrichment fallback"
- [x] `normalize_commands_for_contract_recovery()` — s.o.
- [x] `refresh_contract_command_fallback()` — s.o.
- [x] `enforce_task_boundary_invariants()` — s.o.
- [x] `build_fallback_message()` (public) — Narration/Presentation, kein Routing
- [x] `build_pending_intent_summary()` — Narration

**Status:** ✅ ERLEDIGT

---

## orchestrator.php (1428 Zeilen)
**Erlaubte Verantwortung laut Flowchart (ORCH):**
- `process()` — LLM aufrufen + Prompt zusammenbauen
- `prompt_policy_builder::build()` / `build_system_prompt()`
- `planner_catalog_service` → Catalog für Prompt
- Language contract
- `llm_call_service::invoke()`
- `interpreter::interpret()`
- Prompt-Hilfsmethoden (slim_catalog, compact_*, build_runtime_context_block, append_planner_traces, etc.)

**❌ VERSTÖSSE (zu streichen):**
- [x] `is_provider_available()` — Provider-Status-Check gehört in authorization_service oder external API, nicht im Orchestrator
- [x] `get_runtime_provider_status()` — wie oben, 150+ Zeilen Status-Reporting (Zeilen 172–320)

**⚠️ GRENZFÄLLE (behalten — begründet):**
- `extract_recent_task_names_from_messages()` — dient nur als Prompt-Kontext-Info für den LLM, kein Routing
- `is_first_assistant_turn()` — Prompt-Bedingung für System-Prompt-Aufbau

**Status:** ✅ ERLEDIGT

---

## interpreter.php (1191 Zeilen)
**Erlaubte Verantwortung laut Flowchart (INTERP):**
- Stage 1: `parse()` — JSON-Extraktion
- Stage 2: `classify response_type` (allow-list)
- Stage 3: `check_structure()` per task (darf RECOVERABLE_INPUT_ERROR emittieren)
- Stage 4: `normalize` (Dates, IDs, __current_user__)
- **Explizit verboten:** "No task-specific heuristics"

**❌ VERSTÖSSE (zu streichen):**
- [x] `user_facing_validation_message()` — enthält hard-coded domain-spezifischen Text ("slot booking type", "slot-buchungsart", "Sprechstunde", "office hours") — domain-Heuristik in de/en
- [x] `resolve_task_name_alias()` — Task-Name-Suffix-Heuristik, explizit verboten
- [x] `hydrate_question_field()` — task-spezifische Feld-Injektion — task-specific heuristic

**Status:** ✅ ERLEDIGT

---

## privacy_anonymizer.php (1384 Zeilen)
**Erlaubte Verantwortung laut Flowchart (ANON):**
- `anonymize_value_for_llm()`
- `deanonymize_for_display()`
- (und interne Hilfslogik für diese beiden)

**Analyse:**
Die öffentlichen Methoden über die 2 Flowchart-Methoden hinaus (precheck_user_message, deanonymize_command_input, should_anonymize_user_input etc.) sind alle aktiv von externem Code verwendet und sind semantisch Teil der Anonymisierungs-Verantwortung. Der Flowchart vereinfacht die API auf zwei Konzepte, meint aber nicht, dass nur 2 PHP-Methoden existieren dürfen.

**Befund:** Keine Verletzungen — alle Methoden sind Anonymisierungs-/Deanonymisierungs-Logik. Die Größe (1384 Zeilen) ist durch komplexe NLP-/Regex-Arbeit für Name- und E-Mail-Erkennung begründet.

**Status:** ✅ ANALYSIERT — keine Änderungen notwendig

---

## Legende Verstösse-Kategorien
| Kategorie | Beschreibung |
|-----------|-------------|
| Domain-Konstanten | Booking-spezifische Issue-Codes in Framework-Klasse |
| Task-Name-Heuristik | `resolve_task_name_by_suffix` / `resolve_task_name_alias` — lt. Flowchart verboten |
| Recovery-Enrichment | Anreicherung/Transformation während Routing — lt. Flowchart Legend verboten |
| Narration/Presentation | Fallback-Message-Building, Pending-Intent-Summary — nicht Routing |
| Domain-Heuristik | Slotbooking-Text, Duplicate-Title-Check, Teacher-Autocreate |
| Fehlplatziert | Code der in anderem Service richtig wäre (Executor, Preflight, Interpreter) |
