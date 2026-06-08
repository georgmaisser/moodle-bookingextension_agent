# Vollständige Inventur: bookingextension_agent

> **Datum:** 2026-06-06
> **Blueprint-Referenz:** `docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd`
> **Verzeichnisse:** `bookingextension/agent/classes/`, `tests/`, `lang/`, Root, `mod_booking/classes/local/wbagent/`

---

## 1. Executive Summary / Qualitätsbewertung

**Overall Score: 8.5 / 10**

Das `bookingextension_agent`-Plugin ist strukturell hervorragend aufgebaut und zeigt einen sehr hohen Blueprint-Compliance-Grad. Die Schichtentrennung (Entry → Runtime → Orchestrator → Decision → Preflight → Queue → Executor → Tasks) ist sauber durchgehalten. Es gibt keine groben Architekturverstöße.

### Stärken
- Strikte Schichtenarchitektur: Entry Layer, Runtime Loop, Orchestrator (3-Phasen), Decision Service, Preflight Pipeline (L1/L2/L3), Queue Manager, Executor — alle als separate PHP-Klassen implementiert
- Hervorragendes Test-Ökosystem (36 Contract-Tests + 7 Real-LLM-Tests + 15 Scenario-Tests)
- Vollständige Blueprint-Implementierung inkl. Risk-Class-Gating (R0–R3), Preflight-v2, Idempotenz-Guards, DAG-Validierung
- Family-Discovery mit Stages A/B/C + semantischem Embedding-Pfad komplett implementiert
- Saubere Interface-Schicht mit 17 PHP-Interfaces

### Schwächen
- `wunderbyte_trial_endpoint.py` (Python) liegt im PHP-Classes-Verzeichnis — falsche Platzierung
- Das Verzeichnis `services/planning/` existiert leer — im Blueprint referenziert aber leer
- `booking_task_support.php` (102 KB!) ist eine Gottklasse — sehr große Datei mit gemischten Verantwortlichkeiten
- Mehrere `external/` APIs (`booking_create_option`, `booking_update_option`, `booking_bulk_update_options`, `booking_validate_option`) wirken wie Legacy-Reste
- `ai_privacy_precheck.php` gehört nicht in den Blueprint-Hauptfluss

### Blueprint-Compliance
**~90% konform.** Die zentralen Systeme (Runtime Loop, Orchestrator, Decision Service, Preflight Pipeline v2, Queue Manager, Executor, Task Registry, Synchronizer, Finalization Classifier) sind vollständig und korrekt implementiert.

---

## 2. Architecture Compliance (vs. Blueprint)

| Blueprint-Komponente | Datei/Klasse implementiert | Status |
|---|---|---|
| `ai_send_message::execute()` | `external/ai_send_message.php` | ✅ |
| `ai_confirm_run::execute()` | `external/ai_confirm_run.php` | ✅ |
| `ai_discard_pending::execute()` | `external/ai_discard_pending.php` | ✅ |
| `ai_poll_thread::execute()` | `external/ai_poll_thread.php` | ✅ |
| `ai_render_command_preview::execute()` | `external/ai_render_command_preview.php` | ✅ |
| `execute_ai_run_adhoc` (Worker) | `task/execute_ai_run_adhoc.php` | ✅ |
| `authorization_service` | `services/security/authorization_service.php` | ✅ |
| `conversation_store` (CS1–CS15) | `conversation_store.php` | ✅ |
| `agent_runtime` / `run_loop()` | `agent_runtime.php` | ✅ |
| `agent_state::make()` | `agent_state.php` | ✅ |
| `orchestrator::process()` (3-Phasen) | `orchestrator.php` | ✅ |
| `phase_prompt_bundle_builder` | `services/phase_prompt_bundle_builder.php` | ✅ |
| `context_prior_builder` | `services/discovery/context_prior_builder.php` | ✅ |
| `family_registry_service` | `services/discovery/family_registry_service.php` | ✅ |
| `core_family_set` | `services/discovery/core_family_set.php` | ✅ |
| `embedding_query_builder` (EMB_QUERY) | Im `orchestrator.php` inline | ⚠️ kein eigenes Objekt |
| `family_embeddings_retrieval_service` | `services/embeddings/family_embeddings_retrieval_service.php` | ✅ |
| `family_signal_ranker` | `services/discovery/family_signal_ranker.php` | ✅ |
| Discovery Stages A/B/C | `services/discovery/discovery_stage_controller.php` | ✅ |
| `family_ranker` | `services/discovery/family_ranker.php` | ✅ |
| `lazy_skill_loader` | `services/selection/lazy_skill_loader.php` | ✅ |
| `skill_selector` | `services/selection/skill_selector.php` | ✅ |
| `parameter_constructor` | `services/construction/parameter_constructor.php` | ✅ |
| `parameter_contract_validator` | `services/construction/parameter_contract_validator.php` | ✅ |
| `orchestrator_routing_service` | `services/orchestrator_routing_service.php` | ✅ |
| `interpreter::interpret_phase_output()` | `interpreter.php` | ✅ |
| `planner_result_composer` | `services/planner_result_composer.php` | ✅ |
| `agent_decision_service::process()` | `services/decision/agent_decision_service.php` | ✅ |
| `preflight_pipeline::run()` (L1/L2/L3) | `services/preflight_pipeline.php` | ✅ |
| `preflight_result_v2` (DTO) | `services/preflight_result_v2.php` | ✅ |
| `preflight_audit_logger` | `services/preflight_audit_logger.php` | ✅ |
| `queue_manager` | `queue/queue_manager.php` | ✅ |
| `executor::execute_commands()` | `executor.php` | ✅ |
| `skill_executability_evaluator` | `skill_executability_evaluator.php` | ✅ |
| `skill_registry` | `skill_registry.php` | ✅ |
| `skill_registry_factory` | `skill_registry_factory.php` | ✅ |
| `task_interface` | `interfaces/task_interface.php` | ✅ |
| `skill_prompt_contract` (DTO) | `services/skill_prompt_contract.php` | ✅ |
| `task_risk_class` (enum/DTO) | `dto/task_risk_class.php` | ✅ |
| `base_task` | `base_task.php` | ✅ |
| `skill_contract_validator` | `skill_contract_validator.php` | ✅ |
| `finalization_classifier` | `services/finalization_classifier.php` | ✅ |
| `synchronizer_input_builder` | `services/synchronizer_input_builder.php` | ✅ |
| `synchronizer_output_contract` | `services/synchronizer_output_contract.php` | ✅ |
| `synchronizer_prompt_builder` | `services/synchronizer_prompt_builder.php` | ✅ |
| `synchronizer_routing_service` | `services/synchronizer_routing_service.php` | ✅ |
| `llm_call_service` | `services/llm/llm_call_service.php` | ✅ |
| `privacy_anonymizer` | `privacy_anonymizer.php` | ✅ |
| `language_policy_service` | `services/language_policy_service.php` | ✅ |
| `message_trigger_registry` | `message_trigger_registry.php` | ✅ |
| `ai_error_classifier` | `ai_error_classifier.php` | ✅ |
| `observation_builder` | `queue/observation_builder.php` | ✅ |
| `result_payload_summarizer` | `result_payload_summarizer.php` | ✅ |
| `attempt_budget_dto` | `services/attempt_budget_dto.php` | ✅ |
| `spawn_contract_service` | `services/spawn_contract_service.php` | ✅ |
| `message_persistence_service` | `services/messaging/message_persistence_service.php` | ✅ |
| `planning/` (leer) | `services/planning/` — **leer** | 🚧 |
| `embedding_query_builder` eigenes Objekt | fehlt (inline im Orchestrator) | ⚠️ |


---

## 3. File-by-File Inventur

### ROOT-FILES: `bookingextension/agent/`

### `version.php` ✅
**Zweck:** Plugin-Metadaten und Versions-Declaration fuer Moodle
**Klasse:** *(kein OOP, reine Config)*
**Methoden:** keine
**Anmerkung:** Version `2026060403`, requires Moodle `2024100700`, Dependency `mod_booking >= 2026020300`. Korrekt.

### `settings.php` ✅
**Zweck:** Admin-Einstellungsseiten fuer das Plugin (AI-Settings, Task-Governance, Benchmark-Thresholds, Debug-Pages)
**Klasse:** *(Procedural, Moodle settings)*
**Methoden:** keine
**Anmerkung:** Registriert Einstellungen fuer `aiexecutionmode`, `aidebugmode`, `aiprivacymode`, Per-Task-Toggle-Checkboxen, Benchmark-Schwellwerte.

### `benchmark_report.php` ⚠️
**Zweck:** Benchmark-Report-Seite (standalone PHP admin page)
**Anmerkung:** Nicht im Blueprint. Dev-Tool. Korrekt per admin_externalpage registriert.

### `benchmark_compare.php` ⚠️
**Zweck:** Vergleichs-Ansicht von Benchmark-Runs

### `benchmark_run_detail.php` ⚠️
**Zweck:** Detail-Ansicht eines einzelnen Benchmark-Runs

### `skill_selection_debug.php` ⚠️
**Zweck:** Debug-Seite fuer Task-Selektion (zeigt Scoring/Ranking-Details)
**Anmerkung:** Dev-Tool, nicht im Blueprint. Via settings.php als admin_externalpage registriert.

### `trial_challenge.php` ⚠️
**Zweck:** CAPTCHA/Challenge-Mechanismus fuer Trial-Schlussel-Anfrage
**Anmerkung:** 1.5 KB, nicht im Blueprint. Ergaenzung zum Trial-System.

### `styles.css` ✅
**Zweck:** Plugin-CSS (Chat-UI-Styles, Benchmark-Report-Styles)

### `classes/agent.php` ✅
**Zweck:** Plugin-Entrypoint, implementiert `bookingextension_interface`
**Klasse:** `bookingextension_agent\agent`
**Methoden:**
- `get_plugin_name()` ✅
- `contains_option_fields()` ✅
- `get_option_fields_info_array()` ✅
- `load_settings()` ✅
- `load_data_for_settings_singleton()` ✅ (Stub)
- `set_template_data_for_optionview()` ✅ (Stub)
- `add_options_to_col_actions()` ✅ (Stub)
- `get_allowedruleeventkeys()` ✅ (Stub)
- `get_booking_history_description()` ✅ (Stub)

---

## ENTRY LAYER: `classes/external/`

### `classes/external/ai_send_message.php` ✅
**Zweck:** Haupteintrittspunkt: User-Message empfangen, Thread erzeugen/holen, `run_loop()` starten
**Klasse:** `bookingextension_agent\external\ai_send_message`
**Methoden:**
- `execute_parameters()` ✅ — WS-Parameterdeklaration (contextid, message, threadid)
- `execute()` ✅ — Core: Privacy-Precheck, Thread, User-Message speichern, run_loop(), Response normalisieren
- `execute_returns()` ✅
- `normalize_string_list()` ✅ (private)
- `resolve_response_queue_item_id()` ✅ (private)
- `resolve_response_commands()` ✅ (private)
- `resolve_preview_option_ids_json_for_response()` ✅ (private)
- `resolve_preview_option_id_for_response()` ✅ (private)
- `encode_phase_trace_for_response()` ✅ (private)
**Anmerkung:** Implementiert `ASM` aus Blueprint vollstaendig.

### `classes/external/ai_confirm_run.php` ✅
**Zweck:** User bestaetigt eine pending Aktion (Queue-Item freigeben oder adhoc-Task starten)
**Klasse:** `bookingextension_agent\external\ai_confirm_run`
**Methoden:**
- `execute_parameters()` ✅ — contextid, threadid, queue_item_id, allow_session
- `execute()` ✅ — allow_session CS11, consume_pending_intent CS13, adhoc/direct execute
- `execute_returns()` ✅
**Anmerkung:** `ACR` aus Blueprint. Execution-Mode (direct/adhoc) via Config-Flag.

### `classes/external/ai_discard_pending.php` ✅
**Zweck:** User verwirft eine pending Aktion
**Methoden:** `execute_parameters()` ✅, `execute()` ✅ (ACD), `execute_returns()` ✅

### `classes/external/ai_poll_thread.php` ✅
**Zweck:** Frontend pollt Thread auf neue Schritt-Nachrichten
**Methoden:** `execute_parameters()` ✅, `execute()` ✅ (APO: step-Messages seit letzter poll-ID), `execute_returns()` ✅

### `classes/external/ai_render_command_preview.php` ✅
**Zweck:** Vorschau einer geplanten Aktion rendern (Option-Preview vor Bestaetigung)
**Methoden:** `execute_parameters()` ✅, `execute()` ✅ (APREVIEW), `execute_returns()` ✅, `render_preview_table()` ✅ (private)

### `classes/external/ai_privacy_precheck.php` ⚠️
**Zweck:** Prueft User-Nachricht auf Privacy-Verletzungen vor dem Senden
**Methoden:** `execute_parameters()` ✅, `execute()` ✅, `execute_returns()` ✅
**Anmerkung:** Nicht im Flowchart als externer WS-Endpoint. Wird als Pre-step vor ai_send_message genutzt. Diskutierbar ob Eigenstaendigkeit sinnvoll.

### `classes/external/ai_get_doc_content.php` ⚠️
**Zweck:** Laedt und rendert Markdown-Dokumentations-Inhalte fuer Chat-UI
**Methoden:** `execute_parameters()` ✅, `execute()` ✅, `execute_returns()` ✅
**Anmerkung:** Nicht im Flowchart. Support-Feature fuer eingebettete Doku.

### `classes/external/ai_get_thread_debug_logs.php` ⚠️
**Zweck:** Liefert LLM-Debug-Logs fuer einen Thread (nur im Debug-Mode)
**Methoden:** `execute_parameters()` ✅, `execute()` ✅, `execute_returns()` ✅
**Anmerkung:** Dev-Tool, nicht im Blueprint.

### `classes/external/ai_list_candidate_options.php` ⚠️
**Zweck:** Listet Kandidaten-Options fuer Fuzzy-Matching (Autocomplete)
**Methoden:** `execute_parameters()` ✅, `execute()` ✅, `execute_returns()` ✅

### `classes/external/booking_bulk_update_options.php` ⚠️
**Zweck:** Legacy-WS fuer Bulk-Updates von Booking-Options (direkt, nicht ueber Agent-Flow)
**Klasse:** `bookingextension_agent\external\booking_bulk_update_options`
**Methoden:** `execute_parameters()` ✅, `execute()` ✅, `execute_returns()` ✅
**Anmerkung:** ⚠️ DISKUSSION: Ist das ein echter Agent-Flow oder Direct-CRUD? Nicht im Flowchart. Moeglicherweise Legacy.

### `classes/external/booking_create_option.php` ⚠️
**Zweck:** Legacy-WS fuer direktes Erstellen einer Booking-Option (nicht ueber Agent-Flow)
**Anmerkung:** ⚠️ Moeglicherweise Legacy — evaluieren ob noch benoetigt.

### `classes/external/booking_update_option.php` ⚠️
**Zweck:** Legacy-WS fuer direktes Update einer Booking-Option
**Anmerkung:** ⚠️ Wie booking_create_option.

### `classes/external/booking_validate_option.php` ⚠️
**Zweck:** Legacy-WS fuer Validierung einer Booking-Option
**Anmerkung:** ⚠️ Wie oben.

### `classes/external/activate_trial_context.php` ⚠️
**Zweck:** Trial-Kontext aktivieren (trial key fuer bestimmten Context setzen)
**Anmerkung:** Trial-System, nicht im Flowchart.

### `classes/external/request_trial_key.php` ⚠️
**Zweck:** Trial-Key bei Wunderbyte-Backend anfordern
**Anmerkung:** Trial-System. Ruft wunderbyte_trial_endpoint auf.

### `classes/external/ws_message_formatter.php` ⚠️
**Zweck:** Helper-Klasse zum Normalisieren von WS-Response-Arrays
**Klasse:** `bookingextension_agent\external\ws_message_formatter`
**Methoden:** `format()` ✅ (static)
**Anmerkung:** Sehr klein (1.5 KB), koennte inline sein.

---

## ASYNC WORKER: `classes/task/`

### `classes/task/execute_ai_run_adhoc.php` ✅
**Zweck:** Adhoc-Task: Verarbeitet bestaetigt Runs asynchron
**Klasse:** `bookingextension_agent\task\execute_ai_run_adhoc`
**Methoden:** `get_name()` ✅, `execute()` ✅ (Holt Queue-Item, ruft executor::execute_commands() auf)
**Anmerkung:** `ADHOC` aus Blueprint. Korrekt.

### `classes/task/rebuild_skill_catalog_embeddings_adhoc.php` ✅
**Zweck:** Adhoc-Task: Rebuildet Task-Catalog-Embeddings
**Klasse:** `bookingextension_agent\task\rebuild_skill_catalog_embeddings_adhoc`
**Methoden:** `execute()` ✅ (family_embeddings_index_service::rebuild_catalog())
**Anmerkung:** Abhaengig von aiprovider_wunderbyte. Korrekt.

### `classes/task/cleanup_old_benchmark_runs_task.php` ⚠️
**Zweck:** Scheduled Task: Bereinigt alte Benchmark-Runs
**Klasse:** `bookingextension_agent\task\cleanup_old_benchmark_runs_task`
**Methoden:** `get_name()` ✅, `execute()` ✅
**Anmerkung:** Nicht im Flowchart. Maintenance-Task fuer Benchmark-Subsystem.

---

## AGENT RUNTIME: `classes/local/wbagent/`

### `agent_runtime.php` ✅
**Zweck:** Zentraler Loop-Koordinator — run() und run_loop() steuern den gesamten Agenten-Durchlauf
**Klasse:** `bookingextension_agent\local\wbagent\agent_runtime`
**Methoden:**
- `get_runtime_feature_flags_snapshot()` ✅ (static)
- `run()` ✅ — Einzel-Schritt-Entrypoint
- `run_loop()` ✅ — Multi-Step Loop (MAX_LOOP_STEPS=6)
- `finalize_terminal_result()` ✅
- `resolve_cmid_from_contextid()` ✅ (private)
- `finalize_and_persist_result()` ✅ (private)
- `apply_finalization_strategy()` ✅ (private) — direct/template/llm_polish
- `apply_template_only_finalization()` ✅ (private)
- `apply_synchronizer_message_polish()` ✅ (private)
- `finalize_and_persist_budget_exceeded()` ✅ (private)
- `budget_guard_allows_next_llm_call()` ✅ (private)
- `resolve_framework_retry_issue_code()` ✅ (private)
- `resolve_exhausted_framework_retry_issue_code()` ✅ (private)
- `has_r3_retry_blocker()` ✅ (private)
- `has_active_non_planner_retry_signal()` ✅ (private)
- `build_framework_retry_observation()` ✅ (private)
- `build_budget_exceeded_result()` ✅ (private)
- `enforce_final_response_contract()` ✅ (private)
- `strip_markdown_fences_from_message()` ✅ (private)
- `build_contract_fallback_message()` ✅ (private)
- `attach_loop_results()` ✅ (private)
**Anmerkung:** 981 Zeilen, implementiert `RUNTIME` aus Blueprint vollstaendig inkl. Retry-Collision-Guard.

### `agent_state.php` ✅
**Zweck:** Immutable value object fuer den Loop-State (Observations, Steps, Caches)
**Klasse:** `bookingextension_agent\local\wbagent\agent_state` (final)
**Methoden:**
- `make()` ✅ (static) — Neuen State erzeugen
- `make_resumed()` ✅ (static) — State mit existing Observations (fuer Resume)
- `record_step()` ✅
- `get_observations()` ✅
- `append_observation()` ✅
- `get_steps()` ✅
- `step_count()` ✅
- `has_observations()` ✅
- `get_discovery_family_cache()` / `set_discovery_family_cache()` ✅
- `get_selection_task_cache()` / `set_selection_task_cache()` ✅
- `get_construction_params_cache()` / `set_construction_params_cache()` ✅
- `extract_observed_command_signatures()` ✅
- `normalize_command_input()` ✅ (private static)
**Anmerkung:** `LOOP_INIT / agent_state::make(limit)` aus Blueprint. Korrekt.

### `conversation_store.php` ✅
**Zweck:** DB-backed Persistence fuer Threads, Messages, Runs, Pending Intents, Session-Allowlist
**Klasse:** `bookingextension_agent\local\wbagent\conversation_store`
**Methoden:**
- `get_active_thread()` ✅
- `get_or_create_thread()` ✅ — CS1
- `create_fresh_thread()` ✅
- `add_message()` ✅ — CS2
- `add_step_message()` ✅
- `clear_step_messages()` ✅ — CS9
- `get_step_messages_since()` ✅
- `get_messages()` ✅
- `get_thread()` ✅
- `get_recent_messages()` ✅ — CS3
- `get_last_thread_for_user()` ✅
- `get_user_threads_by_date_window()` ✅
- `get_user_messages_for_thread()` ✅
- `create_run()` ✅ — CS4
- `update_run_status()` ✅
- `get_run()` ✅
- `get_latest_run()` ✅
- `run_exists()` ✅
- `run_exists_other_than()` ✅ — CS10
- `get_thread_metadata_value()` ✅ — CS7
- `set_thread_metadata_value()` ✅ — CS8
- `set_planner_trace_history()` ✅
- `set_phase_trace()` ✅
- `set_pending_intent()` ✅ — CS6
- `get_pending_intent()` ✅ — CS5
- `consume_pending_intent()` ✅ — CS13
- `clear_pending_intent()` ✅
- `allow_confirmation_for_session()` ✅ — CS11
- `allow_confirmation_for_thread()` ✅
- `is_confirmation_allowed_for_session()` ✅ — CS12
- `is_confirmation_allowed_for_thread()` ✅
- `clear_confirmation_allowance()` ✅
- `add_llm_debug_entry()` ✅
- `get_llm_debug_entries()` ✅
- `get_runtime_feature_flags_snapshot()` ✅ (static)
- private helpers (allowlist, normalize) ✅
**Anmerkung:** 1008 Zeilen. Implementiert CS1-CS15 vollstaendig.

### `orchestrator.php` ✅
**Zweck:** 3-Phasen Planner: Discovery -> Selection -> Parameter-Construction. LLM-Calls orchestrieren
**Klasse:** `bookingextension_agent\local\wbagent\orchestrator`
**Methoden (public):**
- `get_runtime_feature_flags_snapshot()` ✅ (static)
- `get_runtime_provider_status()` ✅
- `process()` ✅ — Haupt-Entry: 3-Phasen Pipeline
- `process_synchronizer()` ✅
- `get_default_initial_prompt_template_for_action()` ✅ (static)
- `get_default_summary_prompt_prefix()` ✅ (static)
**Methoden (private, Auswahl):**
- `run_discovery_phase()` ✅ — Stage A/B/C + Signal-Ranker + Embedding-Pfad
- `run_selection_phase()` ✅ — LLM-Call 1
- `run_construction_phase()` ✅ — LLM-Call 2
- `build_system_prompt()` ✅
- `build_prompt()` ✅
- `build_runtime_context_block()` ✅
- `slim_prompt_catalog_for_planner()` ✅
- `filter_catalog_by_selected_families()` ✅
- `extract_recent_task_names_from_messages()` ✅
- und viele weitere ✅ (alle Catalog/Prompt-Hilfsmethoden)
**Anmerkung:** GROESSTE Datei (96 KB, ca. 2400 Zeilen). Enthaelt inline die embedding_query_builder-Logik -- Blueprint sieht eigenes Objekt vor.

### `interpreter.php` ✅
**Zweck:** LLM-Output parsen, normalisieren und auf Contract pruefen
**Klasse:** `bookingextension_agent\local\wbagent\interpreter`
**Methoden:**
- `interpret()` ✅ — Legacy-Entrypoint
- `interpret_phase_output()` ✅ — Phase-spezifisches Output parsen
- `interpret_selection_phase_output()` ✅ (private)
- `enforce_phase_contract()` ✅ (private)
- `normalize_commands_payload()` ✅ (private)
- `prune_empty_input_values()` ✅ (private)
- `normalize_task_like_response()` ✅ (private)
- `parse()` ✅ (private) — JSON-Parsing
- `sanitize_json_payload()` ✅ (private)
- `validate_commands()` ✅ (private)
- `clarification_message()` ✅ (private)
- und weitere private Helfer ✅
**Anmerkung:** 47 KB. SINT/CINT aus Blueprint. Phase-Contract-Enforcement klar.

### `executor.php` ✅
**Zweck:** Command-Dispatcher: Guard-Verify, Task-Executability, execute(), Result sammeln
**Klasse:** `bookingextension_agent\local\wbagent\executor`
**Methoden:**
- `execute_commands()` ✅ — Idempotenz-Guard, Guard-Token-Verify, task::execute()
- `build_safe_executed_input()` ✅ (private) — Privacy-Safe Input-Echo
**Anmerkung:** EXC aus Blueprint. Kein second full preflight, nur Guard-Verification.

### `base_task.php` ✅
**Zweck:** Abstrakte Basis-Klasse fuer alle AI-Tasks
**Klasse:** `bookingextension_agent\local\wbagent\base_task` (abstract)
**Methoden:** `is_read_only()` ✅, `get_risk_class()` ✅, `check_structure()` ✅, `preflight()` ✅, `get_example_input()` ✅, `execute()` ✅ (abstract)

### `skill_registry.php` ✅
**Zweck:** Zentrales Registry fuer alle Tasks, Schema-Aggregation, Provider-Wiring
**Klasse:** `bookingextension_agent\local\wbagent\skill_registry`
**Methoden:**
- `register()` ✅, `get_task()` ✅, `get_provider_for_task()` ✅
- `normalize_task_input()` ✅, `get_preview_option_memory_for_task()` ✅
- `get_task_names()` ✅, `get_task_names_for_context()` ✅
- `get_task_contract()` ✅, `get_task_contracts()` ✅, `get_contract_diagnostics()` ✅
- `get_result_summary_contributors()` ✅
- `is_read_only_task()` ✅, `is_task_active()` ✅
- `get_skill_toggle_setting_name()` ✅ (static)
- `get_task_capabilities()` ✅
- `get_all_schemas()` ✅, `get_all_schemas_for_context()` ✅
- `explain_task_schema_for_context()` ✅
- `get_all_prompt_contracts()` ✅, `get_prompt_contracts_for_context()` ✅
- `build_prompt_contract()` ✅ (private)
- `get_contextual_prompt_packs()` ✅
- `get_message_triggers()` ✅ (intentionally disabled)
- `make_default()` ✅ (static) — Auto-Discovery aller Provider
- `register_discovered_tasks_without_provider()` ✅ (private static) — Fallback
**Anmerkung:** TR aus Blueprint. 927 Zeilen. Provider-First mit Fallback-Discovery korrekt.

### `skill_registry_factory.php` ✅
**Zweck:** Factory: Erstellt skill_registry Instanz (Singleton-Pattern)
**Methoden:** `get_default()` ✅ (static)

### `skill_contract_validator.php` ✅
**Zweck:** Validiert Task-Metadata, Risk-Class-Deklaration, Namespace-Regeln
**Methoden:**
- `build_task_metadata()` ✅, `validate_task_metadata()` ✅, `validate_registry_contracts()` ✅
- `extract_task_namespace()` ✅, `component_may_register_namespace()` ✅
- `verify_risk_class_declaration()` ✅ + private Helfer ✅

### `skill_discovery.php` ✅
**Zweck:** Auto-Discovers Task-Klassen in local/wbagent ohne Provider
**Methoden:** `get_task_instances()` ✅ (static), `get_last_diagnostics()` ✅ (static)

### `skill_executability_evaluator.php` ✅
**Zweck:** Prueft Runtime-Executability eines Tasks (active + registered + context + capability)
**Methoden:** `evaluate_task()` ✅, `get_executable_task_names()` ✅

### `skill_provider.php` ✅
**Zweck:** Default-Provider fuer core-Tasks des bookingextension_agent
**Methoden:** `get_component()` ✅, `get_tasks()` ✅, `get_discovery_diagnostics()` ✅, `get_contextual_prompt_packs()` ✅, `get_issue_code_provider()` ✅, `get_prompt_guidance()` ✅, `get_task_input_normalizer()` ✅, `get_preview_option_memory()` ✅

### `privacy_anonymizer.php` ✅
**Zweck:** Anonymisiert PII fuer LLM-Backend, de-anonymisiert fuer Display
**Methoden:**
- `get_mode()` ✅, `looks_like_anon_token()` ✅ (static)
- `should_anonymize_user_input()` ✅, `should_anonymize_llm_backend_data()` ✅
- `precheck_user_message()` ✅ — ANON precheck aus Blueprint
- `deanonymize_command_input()` ✅, `deanonymize_command_input_for_active_user()` ✅
- `deanonymize_message_for_display()` ✅
- `anonymize_value_for_llm()` ✅ — ANON anonymize aus Blueprint
- diverse private Methoden (email, name matching, recursive) ✅
**Anmerkung:** 46 KB. Sehr ausgereifte Implementierung.

### `message_trigger_registry.php` ✅
**Zweck:** Core-Trigger Allow-List, Trigger-Normalisierung
**Methoden:** `normalize_used_triggers()` ✅ — MTRIG aus Blueprint

### `ai_error_classifier.php` ✅
**Zweck:** Klassifiziert AI-Provider-Fehler (timeout, transient_io, auth_failed, quota_exceeded)
**Methoden:** `classify()` ✅ — AIER aus Blueprint

### `result_payload_summarizer.php` ✅
**Zweck:** Erstellt kompakte Observation-Strings aus Executor-Results
**Methoden:** `for_observation()` ✅ (static) — OB_OUT aus Blueprint + private Helfer ✅

### `loop_finalizer.php` ✅
**Zweck:** Hilfsdienst fuer finale Loop-Abschluss-Logik
**Methoden:** `finalize()` ✅

### `aiready.php` ✅
**Zweck:** Prueft ob AI-Provider/Extension bereit ist (fuer Frontend-Checks)

### `llm_debug_logger.php` ✅
**Zweck:** Debug-Logger fuer LLM-Requests/Responses

### `booking_issue_code_provider.php` ✅
**Zweck:** Provider fuer domain-spezifische Issue-Codes — ISCP aus Blueprint

### `embeddings_action_config_resolver.php` ✅
**Zweck:** Konfiguriert Embedding-Actions (Modell, Dimensionen)

### `embeddings_csv_repository.php` ✅
**Zweck:** Liest/Schreibt Embedding-Daten aus CSV (fuer Test-Fixtures)

### `preview_policy.php` ✅
**Zweck:** Policy-Klasse fuer Preview-Verhalten

### `prompt_policy_builder.php` ✅
**Zweck:** Baut Policy-Teile fuer System-Prompts

### `wunderbyte_trial_endpoint.py` 🗑️
**Zweck:** FastAPI-Router fuer Wunderbyte Trial-Key-Endpoint (Python-Referenzimplementierung)
**Klasse:** *(Python, kein PHP)*
**Anmerkung:** 🗑️ LOESCHEN ODER VERSCHIEBEN! Eine Python-Datei hat in einem PHP/Moodle-Plugin-Classes-Verzeichnis nichts zu suchen. Gehoert nach docs/ oder eigenes Repository.


---

## INTERFACES: `classes/local/wbagent/interfaces/`

### `task_interface.php` ✅
**Zweck:** Core-Interface fuer alle Tasks
**Methoden (Interface):** `get_name()`, `get_schema()`, `get_prompt_contract()`, `get_risk_class()`, `check_structure()`, `preflight()`, `execute()`, `is_read_only()` — alle ✅

### `skill_provider_interface.php` ✅
**Zweck:** Interface fuer Task-Provider (3rd-party extensibility)
**Methoden:** `get_component()`, `get_tasks()`, `get_contextual_prompt_packs()` ✅

### `agent_conversation_store.php` ✅
**Zweck:** Abstraktion-Interface fuer conversation_store (Testbarkeit)

### `agent_authorization_service.php` ✅
**Zweck:** Abstraktion-Interface fuer authorization_service

### `agent_executor.php` ✅
**Zweck:** Abstraktion-Interface fuer executor

### `agent_interpreter.php` ✅
**Zweck:** Abstraktion-Interface fuer interpreter

### `external_dependency_checker_interface.php` ✅
**Zweck:** Interface fuer R3-External-Dependency-Check (PF_L3_EXT aus Blueprint)
**Methoden:** `check()` ✅

### `issue_code_provider_interface.php` ✅
**Zweck:** Interface fuer domain-spezifische Issue-Code-Provider (ISCP aus Blueprint)

### `preview_option_memory_interface.php` ✅
**Zweck:** Interface fuer Preview-Option-Speicher

### `preview_option_memory_provider_interface.php` ✅
**Zweck:** Provider-Interface fuer Preview-Option-Memory

### `queue_identity_provider_interface.php` ✅
**Zweck:** Interface fuer Tasks die eine custom Queue-Business-Identity liefern

### `result_summary_provider_interface.php` ✅
**Zweck:** Interface fuer Result-Summary-Contributor-Provider

### `task_result_summary_provider_interface.php` ✅
**Zweck:** Interface fuer Task-seitige Result-Summary

### `task_input_normalizer_interface.php` ✅
**Zweck:** Interface fuer Input-Normalisierung (DNORM aus Blueprint)

### `task_input_normalizer_provider_interface.php` ✅
**Zweck:** Provider-Interface fuer Input-Normalizer

### `task_trigger_provider_interface.php` ✅
**Zweck:** Interface fuer Tasks die Message-Triggers deklarieren

### `interfaces/summarizer/result_summary_contributor_interface.php` ✅
**Zweck:** Interface fuer Result-Summarizer-Contributors

---

## DTOs: `classes/local/wbagent/dto/`

### `task_risk_class.php` ✅
**Zweck:** DTO/Enum fuer Risk-Classes R0-R3
**Methoden:** `is_valid()` ✅ (static)

### `discovery_result.php` ✅
**Zweck:** DTO fuer Discovery-Phase-Output

### `skill_selection_result.php` ✅
**Zweck:** DTO fuer Task-Selektion-Ergebnis

### `parameter_construction_result.php` ✅
**Zweck:** DTO fuer Parameter-Construction-Ergebnis

### `mutation_result_dto.php` ✅
**Zweck:** DTO fuer Mutation-Execution-Ergebnis

### `bulk_update_options_input_dto.php` ⚠️
**Zweck:** DTO fuer Bulk-Update-Input
**Anmerkung:** Haengt an Legacy-WS-Endpoints — beim Cleanup pruefen.

### `create_entity_input_dto.php` ⚠️
**Zweck:** DTO fuer direktes Entity-Create (Legacy-WS-spezifisch)

### `create_option_input_dto.php` ⚠️
**Zweck:** DTO fuer Option-Create (Legacy-WS)
**Anmerkung:** ⚠️ Werden diese DTOs noch gebraucht wenn Legacy-WS-Endpoints bereinigt werden?

### `update_option_input_dto.php` ⚠️
**Zweck:** DTO fuer Option-Update (Legacy-WS)

---

## CONTRACTS: `classes/local/wbagent/contracts/`

### `task_family_contract.php` ✅
**Zweck:** Deklariert und normalisiert Task-Family-Namen fuer Discovery
**Methoden:** `resolve_from_prompt_contract()` ✅ (static), `normalize_family()` ✅ (static)

---

## CONFIG: `classes/local/wbagent/config/`

### `command_schema.json` ✅
**Zweck:** JSON-Schema fuer Command-Validation in Preflight-L1 (PF_L1 aus Blueprint)
**Anmerkung:** 1.5 KB, kompaktes Schema. Korrekt.

### `runtime_feature_flags.php` ✅
**Zweck:** Zentrale Feature-Flag-Konfiguration fuer Runtime
**Methoden:** `snapshot()` ✅ (static)

---

## CORE TASKS: `classes/local/wbagent/core/tasks/`

### `core_task_base.php` ✅
**Zweck:** Basis fuer Core-Tasks (non-booking domain, abstract)

### `get_current_user_task.php` ✅
**Zweck:** R0 readonly — Gibt aktuellen User-Context zurueck

### `list_actions_task.php` ✅
**Zweck:** R0 readonly — Listet verfuegbare Agent-Actions auf

### `recall_memory_task.php` ✅
**Zweck:** R0 readonly — Abrufen von Thread-Memory/History

### `recreate_skill_catalog_task.php` ✅
**Zweck:** R1 — Rebuildet den Task-Catalog (Embedding-Rebuild)

### `search_courses_task.php` ✅
**Zweck:** R0 readonly — Sucht Moodle-Kurse

### `search_users_task.php` ✅
**Zweck:** R0 readonly — Sucht Moodle-User

---

## QUEUE: `classes/local/wbagent/queue/`

### `queue_manager.php` ✅
**Zweck:** Shadow-Queue-Management: Enqueue, Status-Updates, DAG-Validierung, Placeholder, Idempotenz
**Klasse:** `bookingextension_agent\local\wbagent\queue\queue_manager`
**Methoden:**
- `enqueue_command()` ✅ — Q_ENQUEUE: Idempotenz + DAG-Check
- `update_status()` ✅ — Q_UPDST
- `get_queue_items()` ✅, `get_queue_item()` ✅, `save_queue_items()` ✅
- `set_prepared_input()` ✅
- `has_running_item()` ✅
- `try_mark_running()` ✅ — Q_RUNNING: Atomic SELECT analog
- `can_pickup_now()` ✅ — Q_CANPICK
- `dependencies_succeeded()` ✅ — Q_DEPCHECK
- `validate_depends_on_is_dag()` ✅ — Q_DAG
- `fail_expired_blocked_items()` ✅ — Q_FAIL_TTL
- `enqueue_placeholder()` ✅ — Q_PLANNED
- `has_planned_placeholders()` ✅, `consume_next_placeholder()` ✅
- `get_planned_placeholder_intents()` ✅
- `build_input_signature()` ✅ — Idempotenz-Signature
- private Helfer (next_sequence, resolve_blocked_expires_at, dfs_cycle_detect) ✅
**Anmerkung:** 31 KB. Vollstaendig implementiert inkl. TTL-Blocking, Risk-Class-TTL-Differenzierung.

### `queue/observation_builder.php` ✅
**Zweck:** Baut kompakte Observation-Strings fuer Queue-Events (OB_OUT aus Blueprint)

---

## SERVICES: `classes/local/wbagent/services/`

### `services/decision/agent_decision_service.php` ✅
**Zweck:** Deterministische Routing-Logik nach Planner-Output (DECIDSVC aus Blueprint)
**Klasse:** `bookingextension_agent\local\wbagent\services\decision\agent_decision_service`
**Methoden:**
- `process()` ✅ — D_PREVIEW → D_PENDING → D_LOOKUP_GUARD → D_PROMOTE → D_ROUTE
- `handle_confirm_pending()` ✅ (private) — D_CONFIRM_PENDING
- `handle_command_routing()` ✅ (private) — D_CMD_ROUTE
- `handle_preflight()` ✅ (private) — D_PREFLIGHT
- `apply_execution_guard_tokens()` ✅ (private)
- `persist_pending_intent_pointer()` ✅ (private) — D_STORE_PENDING
- `execute_readonly_commands()` ✅ (private) — R0 direct execute
- `split_commands_by_risk_class()` ✅ (private)
- `split_commands_by_mutability()` ✅ (private)
- `inject_risk_class_into_commands()` ✅ (private)
- diverse weitere private Helfer ✅
**Anmerkung:** 62 KB — grosse Klasse. Implementiert DECIDSVC vollstaendig.

### `services/discovery/context_prior_builder.php` ✅
**Zweck:** Baut Context-Prior-Signal (cm/course/page type) fuer Discovery-Ranking (DISC_CTX)
**Methoden:** `build()` ✅

### `services/discovery/core_family_set.php` ✅
**Zweck:** Definiert immer-verfuegbare Core-Families (CORESET aus Blueprint)
**Methoden:** `get_families()` ✅

### `services/discovery/discovery_budget_policy.php` ✅
**Zweck:** Budget-Policy pro Discovery-Stage A/B/C
**Methoden:** `get_stage_budget()` ✅, `apply_budget()` ✅

### `services/discovery/discovery_confidence_policy.php` ✅
**Zweck:** Confidence-Threshold pro Stage
**Methoden:** `normalize_score()` ✅, `is_sufficient()` ✅

### `services/discovery/discovery_stage_controller.php` ✅
**Zweck:** Steuert Discovery-Stages A→B→C (DISC_A_OK → DISC_B → etc.)
**Methoden:** `resolve()` ✅, private `append_low_score_tail()` ✅

### `services/discovery/family_ranker.php` ✅
**Zweck:** Rankt Families nach Score (FRANK aus Blueprint)
**Methoden:** `rank()` ✅, `select_low_score_tail()` ✅

### `services/discovery/family_registry_service.php` ✅
**Zweck:** Mapped Domain/Context auf Task-Family-Set (FREG aus Blueprint)
**Methoden:** `discover()` ✅

### `services/discovery/family_signal_ranker.php` ✅
**Zweck:** Language-agnostic Signal-Scoring (FSIG aus Blueprint)
**Methoden:** `score_families()` ✅

### `services/embeddings/embeddings_catalog_builder_service.php` ✅
**Zweck:** Baut Embedding-Catalog-Rows aus Task-Contracts
**Methoden:** `build_full_catalog_rows()` ✅, `compute_content_hash()` ✅, `to_embedding_input()` ✅

### `services/embeddings/embeddings_readiness_service.php` ✅
**Zweck:** Prueft ob Wunderbyte + Embeddings verfuegbar (EMB_AVAIL aus Blueprint)
**Methoden:** `is_wunderbyte_embeddings_available()` ✅, `get_catalog_status()` ✅, `ensure_rebuild_scheduled_if_needed()` ✅

### `services/embeddings/embeddings_retrieval_service.php` ✅
**Zweck:** Semantisches Top-K Retrieval (Cosine-Similarity) auf Catalog
**Methoden:** `search_top_k()` ✅, `build_planner_catalog_subset()` ✅

### `services/embeddings/family_embeddings_index_service.php` ✅
**Zweck:** Rebuildet und persistiert Family-Level-Embeddings
**Methoden:** `rebuild_catalog()` ✅

### `services/embeddings/family_embeddings_retrieval_service.php` ✅
**Zweck:** Semantic Family-Level Retrieval (FEMB aus Blueprint)
**Methoden:** `score_families()` ✅, `boost_task_rows()` ✅

### `services/selection/lazy_skill_loader.php` ✅
**Zweck:** Laedt Tasks on-demand nach Family-Selektion (TASKLOAD aus Blueprint)
**Methoden:** `load_task()` ✅

### `services/selection/skill_selector.php` ✅
**Zweck:** Selektiert konkreten Task aus Candidate-Set (TSEL aus Blueprint)
**Methoden:** `select()` ✅

### `services/selection/skill_selection_overlap_policy.php` ✅
**Zweck:** Loest Overlap-Situationen bei semantisch aehnlichen Tasks
**Methoden:** `resolve()` ✅

### `services/construction/parameter_constructor.php` ✅
**Zweck:** Baut Parameter nach Task-Selektion (PCON aus Blueprint)
**Methoden:** `build()` ✅ + private Normalisierungsmethoden ✅

### `services/construction/parameter_contract_validator.php` ✅
**Zweck:** Validiert Parameter-Construction-Output (PVAL aus Blueprint)
**Methoden:** `validate()` ✅

### `services/security/authorization_service.php` ✅
**Zweck:** Capability-Checks, Context-Validierung (AUTHZ aus Blueprint)
**Methoden:** `is_agent_extension_installed()` ✅ (static/AZ3), `require_use_capability()` ✅ (AZ1), `can_use()` ✅, `require_valid_context()` ✅ (AZ2)

### `services/messaging/message_persistence_service.php` ✅
**Zweck:** Persistiert Assistenten-Nachrichten nach jedem Turn (MPS aus Blueprint)
**Methoden:** `persist_assistant_message()` ✅

### `services/llm/llm_call_service.php` ✅
**Zweck:** Kapselt alle LLM-API-Aufrufe (SPLLM/CPLLM/SLLM aus Blueprint)
**Methoden:** `invoke()` ✅

### `services/execution/execution_feedback_service.php` ✅
**Zweck:** Baut Completion-Feedback nach Executor-Ergebnis
**Methoden:** `build_completion_feedback()` ✅

### `services/mutation/entity_mutation_service.php` ✅
**Zweck:** Wrapper fuer Entity-Mutations

### `services/mutation/option_mutation_service.php` ✅
**Zweck:** Wrapper fuer Option-Mutations

### `services/lookup/option_lookup_service.php` ✅
**Zweck:** Option-Suche und Resolving fuer Agent-Tasks
**Methoden:** `search_options()` ✅, `resolve_single_option()` ✅

### `services/governance/skill_governance_service.php` ✅
**Zweck:** Sync alle Task-Enable-All-Toggle-Einstellungen
**Methoden:** `sync_enableall_toggles()` ✅ (static)

### `services/telemetry/routing_decision_log_service.php` ✅
**Zweck:** Persistiert Routing-Entscheidungen fuer Telemetrie
**Methoden:** `persist_thread_routing_decision()` ✅

### `services/debug/skill_selection_debug_service.php` ✅
**Zweck:** Debug-Service fuer Task-Selektion (fuer skill_selection_debug.php)

### `services/catalog/adaptive_task_catalog_service.php` ✅
**Zweck:** Adaptiver Task-Catalog mit Always-Include-Logik (EMB_QUERY Downstream)
**Anmerkung:** Enthaelt ALWAYS_INCLUDE_TASK_NAMES-Logik fuer update_option_trainer + book_users.

### `services/preflight_pipeline.php` ✅
**Zweck:** Unified Preflight L1→L2→L3 Pipeline
**Methoden:** `run()` ✅, private Helfer ✅

### `services/preflight_result_v2.php` ✅
**Zweck:** DTO fuer Preflight-Ergebnis (PRV2 aus Blueprint)
**Felder:** status, blockinglayer, retryafterms, retrycount, durationms, issues, execution_guard_token ✅

### `services/preflight_schema_validator.php` ✅
**Zweck:** L1: Schema-Validierung (PF_L1 aus Blueprint)

### `services/preflight_version_validator.php` ✅
**Zweck:** L1: Version-Validierung

### `services/preflight_domain_check_runner.php` ✅
**Zweck:** L2: Domain-Status auf Issue-Codes mappen (PF_L2D aus Blueprint)

### `services/preflight_execution_gate.php` ✅
**Zweck:** L3: Execution-Gate mit Backoff-Policy (PF_L3 aus Blueprint)
**Methoden:** `evaluate()` ✅, `verify_guard_token()` ✅ (static)

### `services/preflight_audit_logger.php` ✅
**Zweck:** Audit-Log fuer Preflight-Events (PAL aus Blueprint)
**Methoden:** `append()` ✅

### `services/preflight_error_classifier.php` ✅
**Zweck:** Klassifiziert Error-Class aus Issue-Codes

### `services/preflight_contract_validator.php` ✅
**Zweck:** Validiert Command-Payload gegen Schema

### `services/retry_policy_service.php` ✅
**Zweck:** Retry-Policy (TECHNICAL/DOMAIN/EXTERNAL_DEPENDENCY) (PF_L3P aus Blueprint)
**Methoden:** `resolve_retry_hint_category()` ✅

### `services/noop_external_dependency_checker.php` ✅
**Zweck:** No-op Implementierung von external_dependency_checker_interface

### `services/confirm_run_service.php` ✅
**Zweck:** Verarbeitet bestaetigt Runs (Queue-Item freigeben, execute, feedback)
**Methoden:** `confirm()` ✅ (Haupt-Methode, sehr komplex, 43 KB) + private Helfer ✅

### `services/finalization_classifier.php` ✅
**Zweck:** Deterministische Finalisierungs-Strategie-Klassifikation (FCLASS aus Blueprint)
**Methoden:** `classify()` ✅ (direct_final/template_only/llm_polish), `requires_irreversibility_notice()` ✅, `requires_affected_scope_summary()` ✅

### `services/finalization_template_service.php` ✅
**Zweck:** Template-Only Nachrichten fuer budget_exceeded etc. (SYNC_TEMPLATE aus Blueprint)
**Methoden:** `resolve_message()` ✅

### `services/synchronizer_input_builder.php` ✅
**Zweck:** Baut Synchronizer-Input aus Planner-Result + State (SYNC_CTX aus Blueprint)
**Methoden:** `build_observations()` ✅

### `services/synchronizer_output_contract.php` ✅
**Zweck:** Merged Synchronizer-Output, validiert Semantic-Drift (SCONTRACT aus Blueprint)
**Methoden:** `merge()` ✅ (Rollback bei Semantic-Drift) + private Helfer ✅

### `services/synchronizer_prompt_builder.php` ✅
**Zweck:** Baut Synchronizer-Prompt (SYNC_PPB aus Blueprint)
**Methoden:** `build()` ✅

### `services/synchronizer_routing_service.php` ✅
**Zweck:** Routing fuer Synchronizer-LLM-Call (SYNC_ROUTE aus Blueprint)
**Methoden:** `call_synchronizer_step()` ✅

### `services/language_policy_service.php` ✅
**Zweck:** Output-Sprache bestimmen (User-Language Priority) (LANG aus Blueprint)
**Methoden:** `normalize_iso_language()` ✅, `resolve_output_language()` ✅, `fallback_string_id_for_response_type()` ✅, `preflight_retry_hint_string_id()` ✅

### `services/attempt_budget_dto.php` ✅
**Zweck:** DTO fuer globalen Attempt-Budget-View (ATTB aus Blueprint)
**Methoden:** `from_loop()` ✅ (static), `to_array()` ✅

### `services/spawn_contract_service.php` ✅
**Zweck:** Spawn-Contract-Normalisierung fuer Child-Commands (EXC_SPAWN aus Blueprint)

### `services/queue_transition_service.php` ✅
**Zweck:** Steuert Queue-Status-Uebergaenge (ready/blocked/retry) mit Risk-Class-Awareness
**Methoden:** `apply_preflight_decision()` ✅, `to_ready()` ✅, `to_blocked_confirmation()` ✅, `to_retry_waiting()` ✅

### `services/queue_status_policy.php` ✅
**Zweck:** Policy fuer Queue-Status-Uebergaenge

### `services/execution_observation_ledger.php` ✅
**Zweck:** Persistiert Execution-Observations fuer Confirm-Run-Service
**Methoden:** `append_from_results()` ✅, `get_recent_for_runtime()` ✅

### `services/planner_result_composer.php` ✅
**Zweck:** Komponiert unified Planner-Result aus Phase-Outputs (PCOMP aus Blueprint)
**Methoden:** `compose()` ✅

### `services/orchestrator_routing_service.php` ✅
**Zweck:** Routing fuer Orchestrator-LLM-Calls (ORCSVC aus Blueprint)
**Methoden:** `resolve()` ✅

### `services/orchestrator_prompt_profile_service.php` ✅
**Zweck:** Orchestrator-Prompt-Profile-Management
**Methoden:** `observations_are_framework_retry_hints()` ✅

### `services/phase_prompt_bundle_builder.php` ✅
**Zweck:** Baut Prompt-Bundle pro Phase (selector.md / constructor.md) (PPB aus Blueprint)
**Methoden:** `build()` ✅

### `services/skill_prompt_contract.php` ✅
**Zweck:** DTO fuer Task-Prompt-Contract (TPC aus Blueprint)
**Methoden:** `to_array()` ✅

### `services/skill_version_policy.php` ✅
**Zweck:** Policy fuer Task-Versioning

### `services/pending_intent_service.php` ✅
**Zweck:** Helper fuer Pending-Intent-Management

### `services/pending_queue_command_service.php` ✅
**Zweck:** Helper fuer pending Queue-Commands

### `services/runtime_step_analysis_service.php` ✅
**Zweck:** Analysiert Runtime-Step fuer Loop-State

### `services/provider_routing_util.php` ✅
**Zweck:** Utility fuer Provider-Routing-Entscheidungen

### `services/queue_command_mapper.php` ✅
**Zweck:** Mapped Commands zu Queue-Items

### `services/confirm_preview_option_service.php` ✅
**Zweck:** Verwaltet Preview-Option-IDs waehrend Confirmation-Flow

### `services/localized_string_service.php` ✅
**Zweck:** Lokalisierte Strings via Language-Code
**Methoden:** `get()` ✅ (static)

### `services/shared_json_payload_extractor.php` ✅
**Zweck:** Shared Utility zum JSON-Payload-Extrahieren

### `services/trigger_result_util.php` ✅
**Zweck:** Utility fuer Trigger-Result-Normalisierung

### `services/assistant_state_guidance_service.php` ✅
**Zweck:** Baut Guidance-Context fuer Orchestrator

### `services/completed_command_history_service.php` ✅
**Zweck:** Verwaltet History abgeschlossener Commands im Thread

### `services/planning/` 🚧
**Zweck:** *(LEER)* — Verzeichnis existiert, ist aber leer
**Anmerkung:** 🚧 Sollte entweder mit Inhalt gefullt oder geloescht werden.

---

## SUMMARIZER: `classes/local/wbagent/summarizer/`

### `basic_collection_result_summary_contributor.php` ✅
### `diagnosis_result_summary_contributor.php` ✅
### `docs_result_summary_contributor.php` ✅
### `single_object_result_summary_contributor.php` ✅
**Zweck:** Implementierungen von result_summary_contributor_interface fuer verschiedene Result-Typen

---

## BENCHMARK: `classes/local/wbagent/benchmark/`

### `abstract_benchmark_scenario.php` ✅
### `benchmark_db_writer.php` ✅
### `benchmark_envkey_manager.php` ✅
### `benchmark_metrics_calculator.php` ✅
### `benchmark_result_collector.php` ✅
### `benchmark_scenario_interface.php` ✅
### `benchmark_scenario_registry.php` ✅
### `benchmark_seed_data.php` ✅
### `benchmark/scenarios/` (15 Szenario-Dateien) ✅
**Anmerkung:** ⚠️ Nicht im Blueprint aber wertvolles Dev-Tool. Gut strukturiert.

---

## PROMPTS: `classes/local/wbagent/prompts/`

### `initial_system_prompt.md` ✅
**Zweck:** Standard-System-Prompt Template (klein, 5 KB — Prompt wird dynamisch gebaut)

---

## LANG-FILES: `lang/`

### `lang/en/bookingextension_agent.php` ✅
**Zweck:** Englische Uebersetzungen (73 KB)

### `lang/de/bookingextension_agent.php` ✅
**Zweck:** Deutsche Uebersetzungen (77 KB)

---

## TESTS: `tests/agent/`

### `tests/agent/abstract_agent_testcase.php` ✅
**Zweck:** Basis-Testklasse mit Mock-Setup fuer Agent-Tests (32 KB)

### `tests/agent/abstract_llm_skill_matrix_testcase.php` ✅
**Zweck:** Abstrakte Basis fuer LLM-Matrix-Tests (42 KB)

### `tests/agent/llm_skill_matrix_scenario_provider.php` ✅
**Zweck:** Scenario-Provider fuer Matrix-Tests (28 KB)

### `tests/agent/r3_skill_e2e_test.php` ✅
**Zweck:** E2E-Test fuer R3-Tasks (irreversible)

### `tests/agent/contracts/` (36 Test-Files) ✅
**Zweck:** Contract-Tests fuer alle Kernkomponenten
**Abdeckt:** finalization_classifier, decision_service, preflight_pipeline, queue, synchronizer, orchestrator, skill_contract_validator, integration, etc.

### `tests/agent/real_llm_multistep/` (7 Test-Files) ✅
**Zweck:** Real-LLM-Tests (require live provider)
- `all_skills_real_llm_test.php` ✅
- `confirmation_flow_real_llm_test.php` ✅
- `get_current_user_real_llm_test.php` ✅
- `lecture_autoconfirm_real_llm_test.php` ✅
- `list_actions_real_llm_test.php` ✅
- `normal_option_datetime_real_llm_test.php` ✅
- `search_users_real_llm_test.php` ✅

### `tests/agent/fixtures/skill_catalog_embeddings.csv` ✅
**Zweck:** Embedding-Daten fuer Tests (789 KB)

---

## mod_booking wbagent Tasks: `mod_booking/classes/local/wbagent/`

### `skill_provider.php` ✅
**Zweck:** Provider fuer mod_booking — registriert alle booking Tasks
**Klasse:** `mod_booking\local\wbagent\skill_provider`
**Methoden:** `get_component()` ✅, `get_tasks()` ✅, `get_discovery_diagnostics()` ✅, `get_contextual_prompt_packs()` ✅, `get_issue_code_provider()` ✅, `get_prompt_guidance()` ✅, `get_task_input_normalizer()` ✅, `get_preview_option_memory()` ✅

### `booking/booking_skill_provider.php` ✅
**Zweck:** Extends booking_task_support — eigentlicher Provider-Einstiegspunkt
**Klasse:** `mod_booking\local\wbagent\booking\booking_skill_provider`
**Anmerkung:** Duenne Klasse die auf booking_task_support aufbaut.

### `booking/booking_task_support.php` ⚠️
**Zweck:** Zentrale Support-Klasse: Schema-Access, Execute, User/Option/Course-Resolution, Datetime-Normalisierung
**Klasse:** `mod_booking\local\wbagent\booking\booking_task_support`
**Methoden:**
- `get_task_names()` ✅, `get_contextual_prompt_packs()` ✅
- `get_task_schema()` ✅, `check_structure()` ✅
- `get_task_instances()` ✅ (private), `has_task_name()` ✅ (private)
- `execute()` ✅ — delegiert an booking_task_mutation_execute_service
- `validate_update_field_permissions()` ✅ (static)
- `parse_datetime()` ✅ (static), `normalize_identity_datetime()` ✅ (static)
- `normalize_temporal_input()` ✅ (static), `extract_optiondates()` ✅ (static)
- `search_option_candidates_for_preview()` ✅ (static)
- `search_user_candidates_for_preview()` ✅ (static)
- `search_course_candidates_for_preview()` ✅ (static)
- `resolve_single_option()` ✅ (static)
- `find_existing_options_by_exact_title()` ✅ (static)
- `resolve_single_user()` ✅ (static), `resolve_single_course()` ✅ (static)
- `resolve_courses_for_restriction()` ✅ (static)
- private static Helfer (search_user_candidates, search_course_candidates, etc.) ✅
**Anmerkung:** ⚠️ GOTTKLASSE (102 KB)! Enthaelt zu viel: Task-Registry, Execute-Delegation, Resolution-Logik, Datetime-Normalisierung. Sollte in separate Service-Klassen aufgeteilt werden.

### `booking/booking_task_mutation_execute_service.php` ✅
**Zweck:** Fuehrt tatsaechliche Mutations aus (create/update option, book users etc.)
**Klasse:** `mod_booking\local\wbagent\booking\booking_task_mutation_execute_service`
**Methoden:**
- `execute()` ✅ — Haupt-Dispatcher fuer Mutations (52 KB)
- `preflight_validate()` ✅ — L2-Preflight fuer booking tasks
- private Helfer (resolve_option_type, is_update_style_task, postcondition_mapping) ✅

### `booking/provider_preview_option_memory.php` ✅
**Zweck:** Implementierung von preview_option_memory_interface

### `booking/provider_task_input_normalizer.php` ✅
**Zweck:** Implementierung von task_input_normalizer_interface
**Methoden:** `normalize()` ✅

### `booking/support/booking_mutation_validation.php` ✅
**Zweck:** Gemeinsame Validierungslogik fuer Mutations
**Methoden:** `validate_common()` ✅ (static)

### `booking/support/booking_rules_agent_service.php` ✅
**Zweck:** Service fuer Booking-Rules (Templates, Create/Update Rules)
**Methoden:** `get_module_contextid()` ✅, `build_rules_link()` ✅, `list_templates()` ✅, `resolve_template()` ✅, `list_rules_for_context()` ✅, `resolve_rule()` ✅, `create_rule_from_template()` ✅, `update_rule_from_template()` ✅, `list_active_rules_for_context()` ✅

### `booking/support/slot_booking_normalizer.php` ✅
**Zweck:** Normalisiert Slot-Booking und Self-Learning Inputs

---

## OPTION TASKS: `options/tasks/`

### `options/tasks/booking_task_base.php` ✅
**Zweck:** Abstrakte Basis fuer alle mod_booking-spezifischen Tasks (BTASK aus Blueprint)
**Klasse:** `mod_booking\local\wbagent\options\tasks\booking_task_base` (abstract, extends base_task)
**Methoden:** `get_schema()` ✅, `get_example_input()` ✅, `enrich_schema_with_prompt_meta()` ✅ (protected), `validate_common_mutation_structure()` ✅ (protected), `execute()` ✅, `get_contextual_prompt_packs()` ✅, `verify_persisted_option_state()` ✅, `apply_service_preflight()` ✅ (protected), `get_output_language()` ✅ (protected), `localized_string()` ✅ (protected), `enforce_max_chars()` ✅ (protected)

### `options/tasks/create_option_task.php` ✅
**Zweck:** R2 Task — Erstellt eine neue Booking-Option
**Methoden:** `get_name()`, `build_queue_business_identity()`, `get_schema()`, `get_message_triggers()`, `check_structure()`, `preflight()`, `get_contextual_prompt_packs()`, `verify_persisted_option_state()` — alle ✅

### `options/tasks/update_option_task.php` ✅
**Zweck:** R2 Task — Updated eine bestehende Booking-Option

### `options/tasks/update_option_trainer_task.php` ✅
**Zweck:** R1 Task — Updated Trainer-Infos auf einer Option

### `options/tasks/bulk_update_options_task.php` ✅
**Zweck:** R2 Task — Bulk-Update mehrerer Optionen

### `options/tasks/configure_booking_instance_task.php` ✅
**Zweck:** R2 Task — Konfiguriert Booking-Instance

### `options/tasks/book_users_task.php` ✅
**Zweck:** R3 Task — Bucht User auf Optionen (irreversible!)
**Anmerkung:** Implements queue_identity_provider_interface + task_trigger_provider_interface

### `options/tasks/search_options_task.php` ✅
**Zweck:** R0 Task — Sucht Booking-Optionen

### `options/tasks/get_option_details_task.php` ✅
**Zweck:** R0 Task — Gibt Option-Details zurueck

### `options/tasks/list_option_properties_task.php` ✅
**Zweck:** R0 Task — Listet Option-Properties

### `options/tasks/diagnose_booking_issue_task.php` ✅
**Zweck:** R0 Task — Diagnose von Booking-Problemen

### `options/tasks/diagnose_cancellation_issue_task.php` ✅
**Zweck:** R0 Task — Diagnose von Stornierungsproblemen

### `options/tasks/explain_docs_topic_task.php` ✅
**Zweck:** R0 Task — Erklaert Docs-Topics aus der Dokumentation

### `options/tasks/add_price_category_task.php` ✅
**Zweck:** R2 Task — Fuegt Preiskategorie zu einer Option hinzu

### `options/tasks/analyze_rules_task.php` ✅
**Zweck:** R0 Task — Analysiert Booking-Rules

### `options/tasks/create_rule_from_template_task.php` ✅
**Zweck:** R2 Task — Erstellt Rule aus Template

### `options/tasks/update_rule_from_template_task.php` ✅
**Zweck:** R2 Task — Updated Rule aus Template

### `options/tasks/create_selflearning_option_task.php` ✅
**Zweck:** R2 Task — Erstellt Self-Learning Option

### `options/tasks/create_slotbooking_option_task.php` ✅
**Zweck:** R2 Task — Erstellt Slot-Booking Option

### `options/tasks/option_input_verification.php` ✅
**Zweck:** Helper fuer Input-Verification in Option-Tasks

### `options/tasks/option_schema_definition.php` ✅
**Zweck:** Zentrale Schema-Definition fuer Option-Felder (24 KB)
**Anmerkung:** Gutes Muster — zentralisiert Schema-Definitionen.

---

## 4. Verdaechtige Duplikate

| Datei A | Datei B | Duplikat-Verdacht |
|---|---|---|
| `booking_task_support.php::execute()` | `booking_task_mutation_execute_service.php::execute()` | 🔀 support delegiert an service, aber support enthaelt auch Resolution-Logik |
| `booking_task_support.php::search_user_candidates()` | `booking_task_support.php::search_user_candidates_for_preview()` | 🔀 Aehnliche Methoden leicht unterschiedlicher Kontext |
| `booking/support/booking_mutation_validation.php` | `booking_task_mutation_execute_service.php::preflight_validate()` | 🔀 Teilweise ueberlappende Validierungslogik |
| `dto/create_option_input_dto.php` | `options/tasks/create_option_task.php` (Input-Array) | ⚠️ DTO scheint nicht konsistent genutzt — Task verwendet direkt array |

---

## 5. Empfehlungen zum Loeschen / Aufraeumen

| Datei | Begründung |
|---|---|
| `classes/local/wbagent/wunderbyte_trial_endpoint.py` | 🗑️ Python-Datei im PHP-Verzeichnis — muss raus |
| `services/planning/` (leer) | 🗑️ Leeres Verzeichnis — loeschen oder fuellen |
| `external/booking_create_option.php` | ⚠️ Evaluieren: Ist diese API noch aktiv genutzt? |
| `external/booking_update_option.php` | ⚠️ Evaluieren |
| `external/booking_bulk_update_options.php` | ⚠️ Evaluieren |
| `external/booking_validate_option.php` | ⚠️ Evaluieren |
| `dto/bulk_update_options_input_dto.php` | ⚠️ Abhaengig von Legacy-WS |
| `dto/create_entity_input_dto.php` | ⚠️ Abhaengig von Legacy-WS |
| `dto/update_option_input_dto.php` | ⚠️ Abhaengig von Legacy-WS |

---

## 6. Diskussionspunkte

1. **`orchestrator.php` (96 KB, ca. 2400 Zeilen)**: Der `embedding_query_builder` ist inline — der Blueprint sieht ein eigenes Objekt vor. Refactoring wuerde Testbarkeit verbessern.

2. **`booking_task_support.php` (102 KB)**: GOTTKLASSE! Sollte aufgeteilt werden in:
   - `booking_resolution_service` (User/Option/Course-Resolving)
   - `booking_datetime_service` (Datetime-Normalisierung)
   - `booking_schema_service` (Schema-Access)

3. **`agent_decision_service.php` (62 KB)**: Confirm-Pending-Logik + Command-Routing-Logik koennten in eigenstaendige Services ausgelagert werden.

4. **`external/ai_privacy_precheck.php`**: Eigenstaendiger WS-Endpoint vs. Integration in `ai_send_message` — Diskussion sinnvoll.

5. **Trial-System** (`activate_trial_context`, `request_trial_key`, `trial_challenge.php`, `wunderbyte_trial_endpoint.py`): Diese 4 Dateien bilden ein eigenstaendiges Sub-System das nicht im Blueprint vorkommt. Organisation in eigenes `trial/`-Verzeichnis?

6. **`services/planning/` leer**: Falls diese Ebene nicht gebraucht wird, Verzeichnis loeschen. Falls geplant: Was soll hier hin?

7. **Legacy-DTOs**: `dto/create_option_input_dto.php` und Verwandte — werden diese noch genutzt?

8. **`embeddings_csv_repository.php`** im Haupt-Namespace: Koennte in `services/embeddings/` verschoben werden.

---

## 7. Qualitaetsbewertung (Zusammenfassung)

| Kategorie | Bewertung |
|---|---|
| **Architektur-Compliance** | 9/10 — fast vollstaendig |
| **Code-Qualitaet** | 8/10 — einige Gottklassen |
| **Testabdeckung** | 9/10 — exzellentes Test-Oekosystem |
| **Blueprint-Treue** | 9/10 — alle Kernkomponenten implementiert |
| **Wartbarkeit** | 7/10 — grosse Dateien, Python-File misplaced |
| **Vollstaendigkeit** | 9/10 — alle geforderten Features vorhanden |
| **Overall** | **8.5 / 10** |

### Konkrete Empfehlungen (Prioritaet):

1. 🗑️ `wunderbyte_trial_endpoint.py` sofort verschieben
2. 🗑️ `services/planning/` loeschen wenn nicht geplant
3. ⚠️ Legacy-WS-Endpoints evaluieren und ggf. entfernen
4. 🔧 `booking_task_support.php` in kleinere Services aufteilen (mittelfristig)
5. 🔧 `embedding_query_builder` als eigenes Objekt extrahieren (niedrige Prioritaet)
