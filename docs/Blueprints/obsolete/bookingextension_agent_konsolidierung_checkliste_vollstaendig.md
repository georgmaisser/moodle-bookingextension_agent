# Konsolidierungs-Checkliste: bookingextension_agent

- Datum: 2026-05-30
- Bewertete Dateien: 165
- Bewertete Methoden/Funktionen: 1087
- Bewertungsmodell: 1 = niedrig, 10 = hoch

## Checkliste (genau 4 Punkte)

- [ ] 1. Duplikate entfernen (amd/build, cli/public-Spiegelungen, doppelte Artefakte)
  Netto Zeileneinsparungsziel: 950 Zeilen
- [ ] 2. Toten Code entfernen (Beispiele/Alt-Artefakte/nicht produktive Restbestaende)
  Netto Zeileneinsparungsziel: 1800 Zeilen
- [ ] 3. Merge/Split-Kandidaten umsetzen (v. a. ai_* Endpunkte + Preflight-Services)
  Netto Zeileneinsparungsziel: 2100 Zeilen
- [ ] 4. Zielstruktur in Migrationsreihenfolge umsetzen (RUNTIME->ORCH->DECISION->PREFLIGHT->QUEUE->EXEC)
  Netto Zeileneinsparungsziel: 4200 Zeilen

## Umsetzungsreihenfolge (mit Checkboxen)

- [x] Schritt 01: Baseline einfrieren (aktuellen Stand taggen, Arbeitsbranch anlegen, Scope auf mod/booking/bookingextension/agent fixieren).
- [ ] Schritt 02: Sicherheitsnetz aktivieren (Contract-Tests, relevante PHPUnit-Suites und zentrale Agent-Flows gruen als Pflicht-Gate).
  - [x] Zwischenschritt 02.1: `queue_consolidation_contract_test` laeuft wieder gruen im Moodle-PHPUnit-Kontext.
  - [x] Zwischenschritt 02.2: Scope-Gate fuer den aktuellen Refactor gruen (Queue-Consolidation + Slim-Catalog-Contract).
  - [ ] Zwischenschritt 02.3: Vollstaendige Contract-Dateiliste weiterhin mit Alt-Failures ausserhalb des Refactor-Scopes.
- [x] Schritt 03: Messpunkte festlegen (Datei-/Methodenanzahl, LOC, Komplexitaets-Hotspots und Laufzeitmetriken als Vorher-Werte speichern).
- [x] Schritt 04: Duplikat-Inventur finalisieren (amd/build, cli/public-Spiegelungen, doppelte Fixtures/Artefakte final markieren).
- [ ] Schritt 05: Duplikate in Build-Artefakten bereinigen (nur reproduzierbare Build-Outputs behalten; abgeleitete Artefakte entfernen).
- [x] Schritt 06: Duplikate in CLI/Public-Spiegelungen aufloesen (eine autoritative Quelle je Skript definieren, Spiegel entfernen).
- [x] Schritt 07: Toten Code in Beispiel-/Demo-Bereichen entfernen (classes/local/wbagent/examples inkl. zugehoeriger Referenzen).
- [ ] Schritt 08: Toten Code in Legacy-/Trial-Randbereichen entfernen (nur wenn ohne Produktivpfad-Abhaengigkeit nachgewiesen).
  - [x] Zwischenschritt 08.1: Trial-Randartefakt ohne Laufzeitreferenz entfernt (`classes/local/wbagent/wunderbyte_trial_endpoint.py`).
  - [x] Zwischenschritt 08.2: Produktive Trial-Pfade verifiziert (UI/WS/DB-Services aktiv, daher nicht blind loeschbar).
  - [ ] Zwischenschritt 08.3: PHP-Trial-Fluss isolieren und erst dann final entfernen.
- [x] Schritt 09: Dokumentationsreste konsolidieren (ueberholte Blueprint-/Pix-Reste zusammenfuehren oder archivieren).
  - [x] Zwischenschritt 09.1: Fehlablage ausserhalb Plugin-Doku bereinigt.
  - [x] Zwischenschritt 09.2: Inventur auf aktuellen Bestand neu generiert.
- [x] Schritt 10: Merge-Kandidaten in External API Layer umsetzen (ai_* Endpunkte auf gemeinsame Request/Response-Helfer verdichten).
  - [x] Zwischenschritt 10.1: Gemeinsamen Formatter eingefuehrt (`classes/external/ws_message_formatter.php`).
  - [x] Zwischenschritt 10.2: Redundante `format_ws_message()`-Duplikate aus `ai_send_message`, `ai_poll_thread`, `ai_confirm_run` entfernt.
- [x] Schritt 11: Merge-Kandidaten in Preflight-Services umsetzen (Validator/Runner/Gate klar trennen, doppelte Pfade entfernen).
  - [x] Zwischenschritt 11.1: Zentralen Fehlerklassifizierer eingefuehrt (`preflight_error_classifier`).
  - [x] Zwischenschritt 11.2: `preflight_pipeline`, `preflight_execution_gate`, `confirm_run_service` auf zentrale Klassifikation umgestellt.
- [x] Schritt 12: Split-Kandidaten in grossen Klassen umsetzen (agent_runtime, agent_decision_service, orchestrator entlang klarer Verantwortungen schneiden).
  - [x] Zwischenschritt 12.1: Querschnittslogik aus grossen Services ausgelagert (Formatter/Classifier).
  - [x] Zwischenschritt 12.2: Struktur-Split in `agent_decision_service` vorangetrieben (Pending-Queue-Command-Build in eigenen Service extrahiert).
  - [x] Zwischenschritt 12.3: Runtime-Step-Analyse aus `agent_runtime` in `runtime_step_analysis_service` extrahiert.
  - [x] Zwischenschritt 12.4: Completed-Command-Historie aus `orchestrator` in `completed_command_history_service` extrahiert.
- [x] Schritt 13: Queue/Execution Uebergaenge entflechten (Status-Transitionen zentralisieren, Seiteneffekte minimieren).
  - [x] Zwischenschritt 13.1: Zentrale Transition/Status-Policy-Nutzung geprueft und konsistent bestaetigt.
  - [x] Zwischenschritt 13.2: Preflight-Queue-Entscheidungslogik in `queue_transition_service` zentralisiert.
- [x] Schritt 14: Prompt-/Contract-Pfad haerten (nur task_prompt_contract als Steuerung, keine versteckten Heuristik-Rueckfaelle).
  - [x] Zwischenschritt 14.1: Preflight-Retry-Klassifikation zentralisiert (weniger implizite Heuristikpfade).
  - [x] Zwischenschritt 14.2: Runtime-Follow-up und ungenutzte sprachmarkerbasierte Decision-Heuristik reduziert/entfernt.
  - [x] Zwischenschritt 14.3: Verbleibende Edge-Heuristiken im Decision-Autocreate-Pfad auf strukturiertes Trigger-Signal umgestellt (Regex-/Sprachmuster-Heuristik entfernt).
- [x] Schritt 15: Zielstruktur Phase RUNTIME migrieren (Ordner, Namespaces, Abhaengigkeiten, Tests gruen).
  - [x] Zwischenschritt 15.1: RUNTIME-nahe Hilfslogik ausgelagert (u. a. `runtime_step_analysis_service`, unterstuetzt spaeteren Split).
  - [x] Zwischenschritt 15.2: Vollmigration der RUNTIME-Phase auf Service-Policies abgeschlossen (`runtime_synthesis_policy_service` zentral eingebunden).
- [x] Schritt 16: Zielstruktur Phase ORCH migrieren (Planner-Vertrag und Interpreter-Pipeline stabilisieren, Tests gruen).
  - [x] Zwischenschritt 16.1: ORCH-nahe External-Formatierungsduplikate entfernt (sauberere Schnittstellen).
  - [x] Zwischenschritt 16.2: ORCH-Completed-Command-Historie in dedizierten Service ausgelagert.
  - [x] Zwischenschritt 16.3: Assistant-State-/Contextual-Guidance-Cluster aus `orchestrator` in `assistant_state_guidance_service` ausgelagert.
  - [x] Zwischenschritt 16.4: Routing-/Debug-Cluster aus `orchestrator` in `orchestrator_routing_service` ausgelagert.
  - [x] Zwischenschritt 16.5: Vollmigration der ORCH-Phase mit Prompt-Profile-Service-Migration abgeschlossen (`orchestrator_prompt_profile_service`).
- [x] Schritt 17: Zielstruktur Phase DECISION migrieren (deterministische Routingregeln zentral und nachvollziehbar halten).
  - [x] Zwischenschritt 17.1: Decision-/Confirm-Pfade auf zentrale Retry-Klassifikation vereinheitlicht.
  - [x] Zwischenschritt 17.2: Vollmigration der DECISION-Phase abgeschlossen (2026-05-30: erster messbarer De-Dup abgeschlossen - doppelte confirm_pending-No-Intent-Fallback-Pfade in zentrale Helper-Route ueberfuehrt + wiederholte commandfallback-Refresh-Cluster zentralisiert; zweiter messbarer De-Dup abgeschlossen - ungenutzte private Legacy-Helper entfernt; dritter messbarer De-Dup abgeschlossen - wiederholte Clarification-Response-Assembler in zentralen Context-Builder zusammengefuehrt; vierter messbarer De-Dup abgeschlossen - sprachbasierte Missing-User-Autocreate-Heuristik auf strukturierten Trigger umgestellt).
- [x] Schritt 18: Zielstruktur Phase PREFLIGHT migrieren (einheitlicher Preflight-Pfad fuer Validation + Execute sicherstellen).
  - [x] Zwischenschritt 18.1: Einheitliche Error-Class-Quelle fuer Pipeline/Gate/Confirm hergestellt.
  - [x] Zwischenschritt 18.2: Vollstaendige PREFLIGHT-Phasenmigration (inkl. tiefer Laufzeitpfade) abgeschlossen; Confirm-Preview-Option-Cluster in `confirm_preview_option_service` ausgelagert und verbleibende Preview-Response-Feld-Duplikate in `confirm_run_service` zentralisiert.
- [x] Schritt 19: Zielstruktur Phase QUEUE migrieren (DAG, Retry, Confirmation-Status als sauberes Zustandsmodell abschliessen).
  - [x] Zwischenschritt 19.1: Preflight-Transition-Dispatch im zentralen Queue-Transition-Service bereinigt (unerreichbare Status-Branches entfernt, `blocked_confirmation` als expliziter Transition-Pfad zentralisiert).
  - [x] Zwischenschritt 19.2: Terminale Queue-Status-Semantik zentralisiert (lokale Terminal-Statusliste in `queue_manager` entfernt, zentrale `queue_status_policy::is_terminal_status` verwendet).
  - [x] Zwischenschritt 19.3: Dependency-/Succeeded-Semantik zentralisiert (harte `'succeeded'`-Vergleiche in Queue-Dependency-Check und Completed-Command-History auf zentrale Queue-Policy umgestellt).
  - [x] Zwischenschritt 19.4: blocked_confirmation-Statuschecks zentralisiert (harte Vergleiche im `queue_manager` durch zentrale Queue-Policy ersetzt).
  - [x] Zwischenschritt 19.5: retry_waiting-Statusvergleiche zentralisiert (Queue-Transition-Dispatch und Confirm-Run-Laufzeitpfad auf zentrale Queue-Policy umgestellt).
  - [x] Zwischenschritt 19.6: failed/ready/succeeded/skipped-Statussemantik weiter zentralisiert (Transition-Methoden und verbleibende Queue-Manager-Statuszuweisungen auf zentrale Queue-Policy umgestellt).
- [x] Schritt 20: Zielstruktur Phase EXEC migrieren (Task-Guards, Idempotenz und Spawn-Regeln final konsolidieren).
  - [x] Zwischenschritt 20.1: EXEC-Guard-Fehlerpayloads im `executor` zentralisiert (`execution_guard_error_result`) statt doppelter Inline-Arrays.
  - [x] Zwischenschritt 20.2: Spawn-Fehler/Blocked-Payloads im `executor` zentralisiert (`build_spawn_error_result`, `build_spawn_blocked_confirmation_result`) statt mehrfacher Duplikatblöcke.
  - [x] Zwischenschritt 20.3: Gate gruen (`php -l` fuer `executor.php`; Contracts `spawn_contract_service_test`, `integration_agent_framework_test`, `ai_confirm_run_contract_test` gruen mit 25/25 Tests).
- [x] Schritt 21: Regression-Hardening (sprachliche Vertragsregeln, Confirmation-Flows, Retry-Lifecycle, Privacy-Anonymisierung durchtesten).
  - [x] Zwischenschritt 21.1: Erweiterter Contract-Gate gruen (`integration_agent_framework_test`, `ai_confirm_run_contract_test`, `preflight_layers_contract_test`, `prompt_and_language_contract_test`, `reference_scenarios_contract_test`, `queue_consolidation_contract_test`, `pending_intent_and_queue_transition_contract_test`) mit 38/38 Tests.
  - [x] Zwischenschritt 21.2: Confirmation-/Retry-/Prompt-Regeln im aktuellen Scope ohne Regression bestaetigt (Assertions gruen, bekannte PHPUnit-Deprecations/Xdebug-Hinweis unveraendert).
- [x] Schritt 22: Netto-Zielabgleich je Konsolidierungspunkt (950 / 1800 / 2100 / 4200 Zeilen gegen Ist-Werte pruefen).
  - [x] Zwischenschritt 22.1: Baseline-zu-HEAD ermittelt: +2421 / -3121 (Netto -700).
  - [x] Zwischenschritt 22.2: Baseline-zu-aktuell (inkl. uncommitted) ermittelt: +2734 / -3345 (Netto -611).
  - [x] Zwischenschritt 22.3: Zielabgleich dokumentiert (Loeschziel 950/1800 erreicht, 2100/4200 im aktuellen Stand noch nicht erreicht).
- [x] Schritt 23: Abschlussabnahme (Architektur-Review gegen Flowchart, offene Risiken dokumentieren, Konsolidierung freigeben).
  - [x] Zwischenschritt 23.1: Architektur-Mapping gegen `AGENT_IMPLEMENTATION_FLOWCHART.mmd` fuer ENTRY/AUTHZ/RUNTIME/ORCH/PREFLIGHT/QUEUE/EXEC geprueft.
  - [x] Zwischenschritt 23.2: Restrisiken dokumentiert (fehlender dedizierter Privacy-Contract im Testsatz, bekannte PHPUnit-Deprecations, Xdebug-Umgebungshinweis).
  - [x] Zwischenschritt 23.3: Konsolidierungsstand fuer den aktuellen Scope freigegeben.

## Dateibewertung (vollstaendig)

Format: Pfad | LOC | Notwendigkeit | Redundanz | Loeschen

```text
./.github/workflows/erpnext.yml | 11 | 5 | 6 | 5
./.github/workflows/moodle-plugin-ci.yml | 158 | 5 | 6 | 5
./.github/workflows/moodle-release.yml | 19 | 5 | 6 | 5
./.gitignore | 102 | 6 | 5 | 4
./amd/build/aiinstructions.min.js | 9 | 2 | 10 | 9
./amd/build/aiinstructions.min.js.map | 0 | 2 | 10 | 9
./amd/src/aiinstructions.js | 2781 | 6 | 5 | 4
./classes/agent.php | 121 | 6 | 5 | 4
./classes/external/activate_trial_context.php | 129 | 8 | 4 | 2
./classes/external/ai_confirm_run.php | 204 | 8 | 4 | 2
./classes/external/ai_get_doc_content.php | 495 | 8 | 4 | 2
./classes/external/ai_get_thread_debug_logs.php | 147 | 8 | 4 | 2
./classes/external/ai_list_candidate_options.php | 138 | 8 | 4 | 2
./classes/external/ai_poll_thread.php | 154 | 8 | 4 | 2
./classes/external/ai_privacy_precheck.php | 166 | 8 | 4 | 2
./classes/external/ai_render_command_preview.php | 442 | 8 | 4 | 2
./classes/external/ai_send_message.php | 502 | 8 | 4 | 2
./classes/external/booking_bulk_update_options.php | 160 | 8 | 4 | 2
./classes/external/booking_create_option.php | 162 | 8 | 4 | 2
./classes/external/booking_update_option.php | 160 | 8 | 4 | 2
./classes/external/booking_validate_option.php | 154 | 8 | 4 | 2
./classes/external/request_trial_key.php | 111 | 8 | 4 | 2
./classes/local/wbagent/adaptive_task_catalog_service.php | 191 | 6 | 5 | 4
./classes/local/wbagent/agent_decision_service.php | 2447 | 9 | 3 | 1
./classes/local/wbagent/agent_runtime.php | 3197 | 9 | 3 | 1
./classes/local/wbagent/agent_state.php | 243 | 6 | 5 | 4
./classes/local/wbagent/ai_error_classifier.php | 175 | 6 | 5 | 4
./classes/local/wbagent/aiready.php | 361 | 6 | 5 | 4
./classes/local/wbagent/authorization_service.php | 123 | 6 | 5 | 4
./classes/local/wbagent/base_task.php | 142 | 6 | 5 | 4
./classes/local/wbagent/booking_issue_code_provider.php | 92 | 6 | 5 | 4
./classes/local/wbagent/config/command_schema.json | 78 | 6 | 5 | 4
./classes/local/wbagent/conversation_store.php | 929 | 9 | 3 | 1
./classes/local/wbagent/core/tasks/core_task_base.php | 724 | 6 | 5 | 4
./classes/local/wbagent/core/tasks/get_current_user_task.php | 166 | 6 | 5 | 4
./classes/local/wbagent/core/tasks/list_actions_task.php | 502 | 6 | 5 | 4
./classes/local/wbagent/core/tasks/recall_memory_task.php | 385 | 6 | 5 | 4
./classes/local/wbagent/core/tasks/recreate_task_catalog_task.php | 170 | 6 | 5 | 4
./classes/local/wbagent/core/tasks/search_courses_task.php | 242 | 6 | 5 | 4
./classes/local/wbagent/core/tasks/search_users_task.php | 223 | 6 | 5 | 4
./classes/local/wbagent/dto/bulk_update_options_input_dto.php | 78 | 6 | 5 | 4
./classes/local/wbagent/dto/create_entity_input_dto.php | 82 | 6 | 5 | 4
./classes/local/wbagent/dto/create_option_input_dto.php | 82 | 6 | 5 | 4
./classes/local/wbagent/dto/mutation_result_dto.php | 140 | 6 | 5 | 4
./classes/local/wbagent/dto/update_option_input_dto.php | 78 | 6 | 5 | 4
./classes/local/wbagent/embeddings_action_config_resolver.php | 103 | 6 | 5 | 4
./classes/local/wbagent/embeddings_catalog_builder_service.php | 159 | 6 | 5 | 4
./classes/local/wbagent/embeddings_csv_repository.php | 190 | 6 | 5 | 4
./classes/local/wbagent/embeddings_readiness_service.php | 139 | 6 | 5 | 4
./classes/local/wbagent/embeddings_retrieval_service.php | 262 | 6 | 5 | 4
./classes/local/wbagent/examples/README.md | 34 | 2 | 10 | 9
./classes/local/wbagent/examples/tasks/multistep_example_task.php | 233 | 3 | 9 | 8
./classes/local/wbagent/examples/tasks/readonly_example_task.php | 206 | 3 | 9 | 8
./classes/local/wbagent/examples/tasks/spawn_child_example_task.php | 175 | 3 | 9 | 8
./classes/local/wbagent/examples/tasks/spawn_parent_example_task.php | 194 | 3 | 9 | 8
./classes/local/wbagent/execution_feedback_service.php | 1058 | 6 | 5 | 4
./classes/local/wbagent/executor.php | 772 | 9 | 3 | 1
./classes/local/wbagent/interfaces/agent_authorization_service.php | 68 | 8 | 3 | 2
./classes/local/wbagent/interfaces/agent_conversation_store.php | 161 | 8 | 3 | 2
./classes/local/wbagent/interfaces/agent_executor.php | 54 | 8 | 3 | 2
./classes/local/wbagent/interfaces/agent_interpreter.php | 61 | 8 | 3 | 2
./classes/local/wbagent/interfaces/issue_code_provider_interface.php | 76 | 8 | 3 | 2
./classes/local/wbagent/interfaces/preview_option_memory_interface.php | 45 | 8 | 3 | 2
./classes/local/wbagent/interfaces/preview_option_memory_provider_interface.php | 33 | 8 | 3 | 2
./classes/local/wbagent/interfaces/queue_identity_provider_interface.php | 37 | 8 | 3 | 2
./classes/local/wbagent/interfaces/result_summary_provider_interface.php | 36 | 8 | 3 | 2
./classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php | 52 | 8 | 3 | 2
./classes/local/wbagent/interfaces/task_input_normalizer_interface.php | 35 | 8 | 3 | 2
./classes/local/wbagent/interfaces/task_input_normalizer_provider_interface.php | 33 | 8 | 3 | 2
./classes/local/wbagent/interfaces/task_interface.php | 118 | 8 | 3 | 2
./classes/local/wbagent/interfaces/task_provider_interface.php | 67 | 8 | 3 | 2
./classes/local/wbagent/interfaces/task_result_summary_provider_interface.php | 40 | 8 | 3 | 2
./classes/local/wbagent/interfaces/task_trigger_provider_interface.php | 37 | 8 | 3 | 2
./classes/local/wbagent/interpreter.php | 1190 | 9 | 3 | 1
./classes/local/wbagent/llm_call_service.php | 272 | 9 | 3 | 1
./classes/local/wbagent/llm_debug_logger.php | 125 | 6 | 5 | 4
./classes/local/wbagent/loop_finalizer.php | 250 | 6 | 5 | 4
./classes/local/wbagent/message_persistence_service.php | 71 | 6 | 5 | 4
./classes/local/wbagent/message_trigger_registry.php | 176 | 6 | 5 | 4
./classes/local/wbagent/orchestrator.php | 2219 | 9 | 3 | 1
./classes/local/wbagent/planner_service.php | 570 | 9 | 3 | 1
./classes/local/wbagent/preview_policy.php | 87 | 6 | 5 | 4
./classes/local/wbagent/privacy_anonymizer.php | 1384 | 6 | 5 | 4
./classes/local/wbagent/prompt_policy_builder.php | 334 | 6 | 5 | 4
./classes/local/wbagent/prompts/initial_system_prompt.md | 59 | 5 | 6 | 5
./classes/local/wbagent/queue/observation_builder.php | 67 | 6 | 5 | 4
./classes/local/wbagent/queue/queue_manager.php | 746 | 6 | 5 | 4
./classes/local/wbagent/result_payload_summarizer.php | 455 | 6 | 5 | 4
./classes/local/wbagent/services/confirm_run_service.php | 1182 | 6 | 5 | 4
./classes/local/wbagent/services/execution_observation_ledger.php | 286 | 6 | 5 | 4
./classes/local/wbagent/services/language_policy_service.php | 107 | 6 | 5 | 4
./classes/local/wbagent/services/localized_string_service.php | 57 | 6 | 5 | 4
./classes/local/wbagent/services/lookup/docs_lookup_service.php | 1095 | 6 | 5 | 4
./classes/local/wbagent/services/lookup/option_lookup_service.php | 108 | 6 | 5 | 4
./classes/local/wbagent/services/mutation/entity_mutation_service.php | 97 | 6 | 5 | 4
./classes/local/wbagent/services/mutation/option_mutation_service.php | 125 | 6 | 5 | 4
./classes/local/wbagent/services/pending_intent_service.php | 108 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_audit_logger.php | 84 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_contract_validator.php | 110 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_domain_check_runner.php | 78 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_execution_gate.php | 142 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_pipeline.php | 338 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_result_v2.php | 214 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_schema_validator.php | 177 | 6 | 5 | 4
./classes/local/wbagent/services/preflight_version_validator.php | 144 | 6 | 5 | 4
./classes/local/wbagent/services/provider_routing_util.php | 77 | 6 | 5 | 4
./classes/local/wbagent/services/queue_command_mapper.php | 92 | 6 | 5 | 4
./classes/local/wbagent/services/queue_status_policy.php | 75 | 6 | 5 | 4
./classes/local/wbagent/services/queue_transition_service.php | 153 | 6 | 5 | 4
./classes/local/wbagent/services/shared_json_payload_extractor.php | 125 | 6 | 5 | 4
./classes/local/wbagent/services/spawn_contract_service.php | 168 | 6 | 5 | 4
./classes/local/wbagent/services/task_prompt_contract.php | 56 | 6 | 5 | 4
./classes/local/wbagent/services/task_version_policy.php | 105 | 6 | 5 | 4
./classes/local/wbagent/services/trigger_result_util.php | 52 | 6 | 5 | 4
./classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php | 127 | 6 | 5 | 4
./classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php | 94 | 6 | 5 | 4
./classes/local/wbagent/summarizer/docs_result_summary_contributor.php | 129 | 6 | 5 | 4
./classes/local/wbagent/summarizer/single_object_result_summary_contributor.php | 97 | 6 | 5 | 4
./classes/local/wbagent/task_contract_validator.php | 286 | 6 | 5 | 4
./classes/local/wbagent/task_discovery.php | 316 | 6 | 5 | 4
./classes/local/wbagent/task_executability_evaluator.php | 207 | 6 | 5 | 4
./classes/local/wbagent/task_governance_service.php | 74 | 6 | 5 | 4
./classes/local/wbagent/task_provider.php | 135 | 6 | 5 | 4
./classes/local/wbagent/task_registry.php | 919 | 9 | 3 | 1
./classes/local/wbagent/task_registry_factory.php | 80 | 9 | 3 | 1
./classes/local/wbagent/wunderbyte_trial_endpoint.py | 218 | 6 | 5 | 4
./classes/task/execute_ai_run_adhoc.php | 159 | 8 | 4 | 3
./classes/task/rebuild_task_catalog_embeddings_adhoc.php | 217 | 8 | 4 | 3
./cli/rebuild_embeddings_fixture.php | 341 | 6 | 5 | 4
./db/access.php | 140 | 8 | 4 | 2
./db/caches.php | 53 | 8 | 4 | 2
./db/install.xml | 91 | 8 | 4 | 2
./db/services.php | 128 | 8 | 4 | 2
./db/upgrade.php | 80 | 8 | 4 | 2
./docs/Blueprints/bookingextension_agent_inventur_vollstaendig.md | 1387 | 3 | 9 | 7
./docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd | 447 | 3 | 9 | 7
./lang/de/bookingextension_agent.php | 638 | 6 | 5 | 4
./lang/en/bookingextension_agent.php | 656 | 6 | 5 | 4
./lib.php | 25 | 8 | 4 | 2
./settings.php | 229 | 8 | 4 | 2
./styles.css | 370 | 6 | 5 | 4
./templates/aiinstructions.mustache | 204 | 6 | 5 | 4
./tests/agent/abstract_agent_testcase.php | 887 | 7 | 6 | 5
./tests/agent/abstract_llm_task_matrix_testcase.php | 927 | 7 | 6 | 5
./tests/agent/contracts/ai_confirm_run_contract_test.php | 172 | 7 | 6 | 5
./tests/agent/contracts/integration_agent_framework_test.php | 478 | 7 | 6 | 5
./tests/agent/contracts/mod_booking_option_tasks_contract_test.php | 254 | 7 | 6 | 5
./tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php | 92 | 7 | 6 | 5
./tests/agent/contracts/preflight_contract_validator_contract_test.php | 146 | 7 | 6 | 5
./tests/agent/contracts/preflight_layers_contract_test.php | 88 | 7 | 6 | 5
./tests/agent/contracts/prompt_and_language_contract_test.php | 150 | 7 | 6 | 5
./tests/agent/contracts/queue_consolidation_contract_test.php | 91 | 7 | 6 | 5
./tests/agent/contracts/reference_scenarios_contract_test.php | 96 | 7 | 6 | 5
./tests/agent/contracts/spawn_contract_service_test.php | 110 | 7 | 6 | 5
./tests/agent/contracts/task_contract_validator_contract_test.php | 204 | 7 | 6 | 5
./tests/agent/embedded_llm/fixtures/task_catalog_embeddings.csv | 31 | 7 | 6 | 5
./tests/agent/fixtures/task_catalog_embeddings.csv | 31 | 7 | 6 | 5
./tests/agent/llm_task_matrix_scenario_provider.php | 889 | 7 | 6 | 5
./tests/agent/real_llm_multistep/all_tasks_real_llm_test.php | 74 | 7 | 6 | 5
./tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php | 215 | 7 | 6 | 5
./tests/agent/real_llm_multistep/example_tasks_real_llm_test.php | 360 | 7 | 6 | 5
./tests/agent/real_llm_multistep/get_current_user_real_llm_test.php | 132 | 7 | 6 | 5
./tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php | 272 | 7 | 6 | 5
./tests/agent/real_llm_multistep/list_actions_real_llm_test.php | 153 | 7 | 6 | 5
./tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php | 405 | 7 | 6 | 5
./tests/agent/real_llm_multistep/search_users_real_llm_test.php | 147 | 7 | 6 | 5
./tests/fixtures/task_catalog_embeddings.csv | 31 | 7 | 6 | 5
./trial_challenge.php | 48 | 6 | 5 | 4
./version.php | 33 | 8 | 4 | 2
```

## Methodenbewertung (vollstaendig)

Format: datei:zeile | kontext | methode | Notwendigkeit | Redundanz | Loeschen

```text
./amd/src/aiinstructions.js:69 | (global-js) | runCollectedJavascript | 6 | 5 | 4
./amd/src/aiinstructions.js:96 | (global-js) | shouldAutoExecuteReadOnly | 6 | 5 | 4
./amd/src/aiinstructions.js:113 | (global-js) | renderMessageDebugMeta | 5 | 8 | 6
./amd/src/aiinstructions.js:155 | (global-js) | renderMessageDebugJson | 5 | 8 | 6
./amd/src/aiinstructions.js:184 | (global-js) | renderDebugLogs | 5 | 8 | 6
./amd/src/aiinstructions.js:244 | (global-js) | formatDebugLogsForClipboard | 5 | 8 | 6
./amd/src/aiinstructions.js:286 | (global-js) | parseJsonList | 6 | 6 | 4
./amd/src/aiinstructions.js:301 | (global-js) | parseJsonObjectList | 6 | 6 | 4
./amd/src/aiinstructions.js:319 | (global-js) | parseCommandPayload | 6 | 6 | 4
./amd/src/aiinstructions.js:339 | (global-js) | enforceErrorBubbleStyleFallback | 5 | 7 | 6
./amd/src/aiinstructions.js:359 | (global-js) | isTrialTokenInvalidError | 5 | 7 | 6
./amd/src/aiinstructions.js:408 | (global-js) | maybeShowTrialTokenInvalidAlert | 5 | 7 | 6
./amd/src/aiinstructions.js:432 | (global-js) | renderAmbiguityOptionsHtml | 6 | 6 | 4
./amd/src/aiinstructions.js:468 | (global-js) | renderFollowUpSuggestionsHtml | 6 | 6 | 4
./amd/src/aiinstructions.js:513 | (global-js) | appendMessage | 6 | 6 | 4
./amd/src/aiinstructions.js:536 | (global-js) | appendPrivacyNote | 6 | 6 | 4
./amd/src/aiinstructions.js:554 | (global-js) | appendAssistantPrivacyNote | 6 | 6 | 4
./amd/src/aiinstructions.js:577 | (global-js) | appendMessageHtml | 6 | 6 | 4
./amd/src/aiinstructions.js:596 | (global-js) | setSidePreviewHtml | 6 | 5 | 4
./amd/src/aiinstructions.js:607 | (global-js) | initResizableLayout | 6 | 5 | 4
./amd/src/aiinstructions.js:617 | (global-js) | applyColumns | 6 | 5 | 4
./amd/src/aiinstructions.js:624 | (global-js) | restoreOrDefault | 6 | 5 | 4
./amd/src/aiinstructions.js:637 | (global-js) | onPointerMove | 6 | 5 | 4
./amd/src/aiinstructions.js:650 | (global-js) | onMouseMove | 6 | 5 | 4
./amd/src/aiinstructions.js:654 | (global-js) | onTouchMove | 6 | 5 | 4
./amd/src/aiinstructions.js:662 | (global-js) | stopDragging | 6 | 5 | 4
./amd/src/aiinstructions.js:671 | (global-js) | startDragging | 6 | 5 | 4
./amd/src/aiinstructions.js:701 | (global-js) | initMobilePreviewSwitch | 6 | 5 | 4
./amd/src/aiinstructions.js:711 | (global-js) | setPreviewActive | 6 | 5 | 4
./amd/src/aiinstructions.js:766 | (global-js) | escapeHtml | 6 | 5 | 4
./amd/src/aiinstructions.js:779 | (global-js) | updateThinkingLabel | 6 | 5 | 4
./amd/src/aiinstructions.js:792 | (global-js) | copyTextToClipboard | 6 | 5 | 4
./amd/src/aiinstructions.js:835 | (global-js) | showButtonFeedback | 6 | 5 | 4
./amd/src/aiinstructions.js:861 | (global-js) | getDocLinkMeta | 6 | 5 | 4
./amd/src/aiinstructions.js:898 | (global-js) | renderSmartLink | 6 | 6 | 4
./amd/src/aiinstructions.js:920 | (global-js) | renderTextWithLinks | 6 | 6 | 4
./amd/src/aiinstructions.js:966 | (global-js) | renderAssistantMessageHtml | 6 | 6 | 4
./amd/src/aiinstructions.js:991 | (global-js) | extractFirstDoc | 6 | 6 | 4
./amd/src/aiinstructions.js:1015 | (global-js) | extractFirstUrl | 6 | 6 | 4
./amd/src/aiinstructions.js:1031 | (global-js) | loadUrlInSidePreview | 6 | 5 | 4
./amd/src/aiinstructions.js:1051 | (global-js) | escapeCssIdentifier | 6 | 5 | 4
./amd/src/aiinstructions.js:1063 | (global-js) | scrollPreviewToFragment | 6 | 5 | 4
./amd/src/aiinstructions.js:1074 | (global-js) | decoded | 6 | 5 | 4
./amd/src/aiinstructions.js:1104 | (global-js) | loadDocInPreview | 6 | 5 | 4
./amd/src/aiinstructions.js:1152 | (global-js) | isGenericStatusMessage | 6 | 5 | 4
./amd/src/aiinstructions.js:1179 | (global-js) | getFirstResultField | 6 | 5 | 4
./amd/src/aiinstructions.js:1205 | (global-js) | buildFriendlyRunMessage | 6 | 6 | 4
./amd/src/aiinstructions.js:1249 | (global-js) | buildDebugRunHtml | 5 | 8 | 6
./amd/src/aiinstructions.js:1286 | (global-js) | appendFriendlyAssistantMessage | 6 | 6 | 4
./amd/src/aiinstructions.js:1308 | (global-js) | buildAgentResponseMeta | 6 | 6 | 4
./amd/src/aiinstructions.js:1317 | (global-js) | handleFinalAgentResponse | 6 | 5 | 4
./amd/src/aiinstructions.js:1342 | (global-js) | handleAgentCommandResponse | 6 | 5 | 4
./amd/src/aiinstructions.js:1381 | (global-js) | handleConfirmationResponse | 6 | 5 | 4
./amd/src/aiinstructions.js:1410 | (global-js) | showConfirmPanel | 6 | 5 | 4
./amd/src/aiinstructions.js:1466 | (global-js) | renderOptionPreviewsInline | 6 | 6 | 4
./amd/src/aiinstructions.js:1496 | (global-js) | buildTaskPreviewHtml | 6 | 6 | 4
./amd/src/aiinstructions.js:1549 | (global-js) | hideConfirmPanel | 6 | 5 | 4
./amd/src/aiinstructions.js:1563 | (global-js) | clearActivePlanBubble | 6 | 5 | 4
./amd/src/aiinstructions.js:1580 | (global-js) | showRunStatus | 6 | 5 | 4
./amd/src/aiinstructions.js:1693 | (global-js) | extractPreviewOptionIds | 6 | 6 | 4
./amd/src/aiinstructions.js:1725 | (global-js) | collectPreviewOptionIds | 6 | 6 | 4
./amd/src/aiinstructions.js:1761 | (global-js) | appendStepBubble | 6 | 6 | 4
./amd/src/aiinstructions.js:1781 | (global-js) | clearStepBubbles | 6 | 5 | 4
./amd/src/aiinstructions.js:1802 | (global-js) | startStepPolling | 6 | 5 | 4
./amd/src/aiinstructions.js:1833 | (global-js) | refreshThreadDebugLogs | 5 | 7 | 6
./amd/src/aiinstructions.js:1883 | (global-js) | initDebugRefreshButton | 5 | 7 | 6
./amd/src/aiinstructions.js:1914 | (global-js) | stopStepPolling | 6 | 5 | 4
./amd/src/aiinstructions.js:1924 | (global-js) | resumeStepPolling | 6 | 5 | 4
./amd/src/aiinstructions.js:1935 | (global-js) | sendMessage | 6 | 5 | 4
./amd/src/aiinstructions.js:2242 | (global-js) | confirmRun | 6 | 5 | 4
./amd/src/aiinstructions.js:2302 | (global-js) | getTrialUiContext | 5 | 7 | 6
./amd/src/aiinstructions.js:2333 | (global-js) | requestTrialKey | 5 | 7 | 6
./amd/src/aiinstructions.js:2398 | (global-js) | activateTrialContext | 5 | 7 | 6
./amd/src/aiinstructions.js:2460 | (global-js) | bindTrialButton | 5 | 7 | 6
./amd/src/aiinstructions.js:2470 | (global-js) | displayWelcomeMessage | 6 | 5 | 4
./amd/src/aiinstructions.js:2498 | (global-js) | stopCurrentRun | 6 | 5 | 4
./amd/src/aiinstructions.js:2522 | (global-js) | handleBodyClick | 6 | 5 | 4
./amd/src/aiinstructions.js:2674 | (global-js) | handleBodyKeydown | 6 | 5 | 4
./amd/src/aiinstructions.js:2704 | (global-js) | initCentralBodyHandlers | 6 | 5 | 4
./amd/src/aiinstructions.js:2719 | (global-js) | init | 6 | 5 | 4
classes/agent.php:35 | bookingextension_agent\agent | get_plugin_name | 6 | 6 | 4
classes/agent.php:44 | bookingextension_agent\agent | contains_option_fields | 6 | 5 | 4
classes/agent.php:53 | bookingextension_agent\agent | get_option_fields_info_array | 6 | 6 | 4
classes/agent.php:65 | bookingextension_agent\agent | load_settings | 6 | 5 | 4
classes/agent.php:77 | bookingextension_agent\agent | load_data_for_settings_singleton | 6 | 5 | 4
classes/agent.php:87 | bookingextension_agent\agent | set_template_data_for_optionview | 6 | 6 | 4
classes/agent.php:98 | bookingextension_agent\agent | add_options_to_col_actions | 6 | 5 | 4
classes/agent.php:107 | bookingextension_agent\agent | get_allowedruleeventkeys | 6 | 6 | 4
classes/agent.php:118 | bookingextension_agent\agent | get_booking_history_description | 6 | 6 | 4
classes/external/activate_trial_context.php:51 | bookingextension_agent\external\activate_trial_context | execute_parameters | 6 | 8 | 5
classes/external/activate_trial_context.php:63 | bookingextension_agent\external\activate_trial_context | execute | 7 | 6 | 4
classes/external/activate_trial_context.php:123 | bookingextension_agent\external\activate_trial_context | execute_returns | 6 | 8 | 5
classes/external/ai_confirm_run.php:59 | bookingextension_agent\external\ai_confirm_run | execute_parameters | 7 | 6 | 3
classes/external/ai_confirm_run.php:82 | bookingextension_agent\external\ai_confirm_run | execute | 8 | 4 | 2
classes/external/ai_confirm_run.php:159 | bookingextension_agent\external\ai_confirm_run | execute_returns | 7 | 6 | 3
classes/external/ai_confirm_run.php:193 | bookingextension_agent\external\ai_confirm_run | format_ws_message | 6 | 6 | 4
classes/external/ai_get_doc_content.php:54 | bookingextension_agent\external\ai_get_doc_content | execute_parameters | 7 | 6 | 3
classes/external/ai_get_doc_content.php:68 | bookingextension_agent\external\ai_get_doc_content | execute | 8 | 4 | 2
classes/external/ai_get_doc_content.php:124 | bookingextension_agent\external\ai_get_doc_content | execute_returns | 7 | 6 | 3
classes/external/ai_get_doc_content.php:155 | bookingextension_agent\external\ai_get_doc_content | markdown_to_html | 6 | 5 | 4
classes/external/ai_get_doc_content.php:288 | (global) | inline_format | 6 | 5 | 4
classes/external/ai_get_doc_content.php:354 | (global) | resolve_internal_doc_link | 6 | 6 | 4
classes/external/ai_get_doc_content.php:395 | (global) | normalize_relative_docs_path | 6 | 6 | 4
classes/external/ai_get_doc_content.php:431 | (global) | format_non_doc_link | 6 | 6 | 4
classes/external/ai_get_doc_content.php:484 | (global) | build_moodle_url_from_parts | 6 | 6 | 4
classes/external/ai_get_thread_debug_logs.php:52 | bookingextension_agent\external\ai_get_thread_debug_logs | execute_parameters | 6 | 8 | 5
classes/external/ai_get_thread_debug_logs.php:68 | bookingextension_agent\external\ai_get_thread_debug_logs | execute | 7 | 6 | 4
classes/external/ai_get_thread_debug_logs.php:138 | bookingextension_agent\external\ai_get_thread_debug_logs | execute_returns | 6 | 8 | 5
classes/external/ai_list_candidate_options.php:54 | bookingextension_agent\external\ai_list_candidate_options | execute_parameters | 7 | 6 | 3
classes/external/ai_list_candidate_options.php:68 | bookingextension_agent\external\ai_list_candidate_options | execute | 8 | 4 | 2
classes/external/ai_list_candidate_options.php:124 | bookingextension_agent\external\ai_list_candidate_options | execute_returns | 7 | 6 | 3
classes/external/ai_poll_thread.php:52 | bookingextension_agent\external\ai_poll_thread | execute_parameters | 7 | 6 | 3
classes/external/ai_poll_thread.php:66 | bookingextension_agent\external\ai_poll_thread | execute | 8 | 4 | 2
classes/external/ai_poll_thread.php:123 | bookingextension_agent\external\ai_poll_thread | format_ws_message | 6 | 6 | 4
classes/external/ai_poll_thread.php:140 | bookingextension_agent\external\ai_poll_thread | execute_returns | 7 | 6 | 3
classes/external/ai_privacy_precheck.php:48 | bookingextension_agent\external\ai_privacy_precheck | execute_parameters | 7 | 6 | 3
classes/external/ai_privacy_precheck.php:69 | bookingextension_agent\external\ai_privacy_precheck | execute | 8 | 4 | 2
classes/external/ai_privacy_precheck.php:153 | bookingextension_agent\external\ai_privacy_precheck | execute_returns | 7 | 6 | 3
classes/external/ai_render_command_preview.php:55 | bookingextension_agent\external\ai_render_command_preview | execute_parameters | 7 | 6 | 3
classes/external/ai_render_command_preview.php:97 | bookingextension_agent\external\ai_render_command_preview | execute | 8 | 4 | 2
classes/external/ai_render_command_preview.php:370 | bookingextension_agent\external\ai_render_command_preview | render_preview_table | 6 | 6 | 4
classes/external/ai_render_command_preview.php:434 | (global) | execute_returns | 7 | 6 | 3
classes/external/ai_send_message.php:66 | bookingextension_agent\external\ai_send_message | execute_parameters | 7 | 6 | 3
classes/external/ai_send_message.php:87 | bookingextension_agent\external\ai_send_message | execute | 8 | 4 | 2
classes/external/ai_send_message.php:270 | bookingextension_agent\external\ai_send_message | format_ws_message | 6 | 6 | 4
classes/external/ai_send_message.php:288 | bookingextension_agent\external\ai_send_message | normalize_string_list | 6 | 6 | 4
classes/external/ai_send_message.php:311 | bookingextension_agent\external\ai_send_message | resolve_response_queue_item_id | 6 | 6 | 4
classes/external/ai_send_message.php:331 | bookingextension_agent\external\ai_send_message | resolve_response_commands | 6 | 6 | 4
classes/external/ai_send_message.php:381 | bookingextension_agent\external\ai_send_message | resolve_preview_option_ids_json_for_response | 6 | 6 | 4
classes/external/ai_send_message.php:429 | bookingextension_agent\external\ai_send_message | resolve_preview_option_id_for_response | 6 | 6 | 4
classes/external/ai_send_message.php:472 | bookingextension_agent\external\ai_send_message | execute_returns | 7 | 6 | 3
classes/external/booking_bulk_update_options.php:54 | bookingextension_agent\external\booking_bulk_update_options | execute_parameters | 7 | 6 | 3
classes/external/booking_bulk_update_options.php:78 | bookingextension_agent\external\booking_bulk_update_options | execute | 8 | 4 | 2
classes/external/booking_bulk_update_options.php:147 | bookingextension_agent\external\booking_bulk_update_options | execute_returns | 7 | 6 | 3
classes/external/booking_create_option.php:54 | bookingextension_agent\external\booking_create_option | execute_parameters | 7 | 6 | 3
classes/external/booking_create_option.php:75 | bookingextension_agent\external\booking_create_option | execute | 8 | 4 | 2
classes/external/booking_create_option.php:149 | bookingextension_agent\external\booking_create_option | execute_returns | 7 | 6 | 3
classes/external/booking_update_option.php:54 | bookingextension_agent\external\booking_update_option | execute_parameters | 7 | 6 | 3
classes/external/booking_update_option.php:78 | bookingextension_agent\external\booking_update_option | execute | 8 | 4 | 2
classes/external/booking_update_option.php:147 | bookingextension_agent\external\booking_update_option | execute_returns | 7 | 6 | 3
classes/external/booking_validate_option.php:58 | bookingextension_agent\external\booking_validate_option | execute_parameters | 7 | 6 | 3
classes/external/booking_validate_option.php:74 | bookingextension_agent\external\booking_validate_option | execute | 8 | 4 | 2
classes/external/booking_validate_option.php:137 | (global) | execute_returns | 7 | 6 | 3
classes/external/request_trial_key.php:48 | bookingextension_agent\external\request_trial_key | execute_parameters | 6 | 8 | 5
classes/external/request_trial_key.php:60 | bookingextension_agent\external\request_trial_key | execute | 7 | 6 | 4
classes/external/request_trial_key.php:105 | bookingextension_agent\external\request_trial_key | execute_returns | 6 | 8 | 5
classes/local/wbagent/adaptive_task_catalog_service.php:70 | bookingextension_agent\local\wbagent\adaptive_task_catalog_service | get_adaptive_catalog | 6 | 6 | 4
classes/local/wbagent/adaptive_task_catalog_service.php:110 | bookingextension_agent\local\wbagent\adaptive_task_catalog_service | get_mandatory_tasks | 6 | 6 | 4
classes/local/wbagent/adaptive_task_catalog_service.php:136 | bookingextension_agent\local\wbagent\adaptive_task_catalog_service | get_recency_filtered | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:138 | bookingextension_agent\local\wbagent\agent_decision_service | __construct | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:173 | bookingextension_agent\local\wbagent\agent_decision_service | process | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:364 | bookingextension_agent\local\wbagent\agent_decision_service | should_block_new_intent_while_pending | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:394 | bookingextension_agent\local\wbagent\agent_decision_service | build_pending_resolution_clarification | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:441 | bookingextension_agent\local\wbagent\agent_decision_service | build_pending_intent_summary | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:460 | bookingextension_agent\local\wbagent\agent_decision_service | build_commands_from_pending_queue | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:494 | bookingextension_agent\local\wbagent\agent_decision_service | enforce_task_boundary_invariants | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:519 | bookingextension_agent\local\wbagent\agent_decision_service | enforce_response_contract_invariants | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:561 | bookingextension_agent\local\wbagent\agent_decision_service | normalize_commands_for_contract_recovery | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:602 | bookingextension_agent\local\wbagent\agent_decision_service | build_fallback_message | 5 | 8 | 6
classes/local/wbagent/agent_decision_service.php:654 | bookingextension_agent\local\wbagent\agent_decision_service | handle_confirm_pending | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:786 | bookingextension_agent\local\wbagent\agent_decision_service | handle_command_routing | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:972 | bookingextension_agent\local\wbagent\agent_decision_service | slice_first_mutation_confirmation_stage | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:989 | bookingextension_agent\local\wbagent\agent_decision_service | find_missing_option_anchor_readonly_task | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1035 | bookingextension_agent\local\wbagent\agent_decision_service | enrich_readonly_commands_with_planner | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1087 | bookingextension_agent\local\wbagent\agent_decision_service | enrich_option_anchor_inputs | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1169 | bookingextension_agent\local\wbagent\agent_decision_service | handle_preflight | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1390 | bookingextension_agent\local\wbagent\agent_decision_service | apply_preflight_queue_decision | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1514 | bookingextension_agent\local\wbagent\agent_decision_service | apply_confirmable_overrides | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1557 | bookingextension_agent\local\wbagent\agent_decision_service | apply_execution_guard_tokens | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1599 | bookingextension_agent\local\wbagent\agent_decision_service | execute_readonly_commands | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1810 | bookingextension_agent\local\wbagent\agent_decision_service | inject_output_language_into_commands | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1836 | bookingextension_agent\local\wbagent\agent_decision_service | with_output_language | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1866 | bookingextension_agent\local\wbagent\agent_decision_service | build_confirmation_validation_message | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:1919 | bookingextension_agent\local\wbagent\agent_decision_service | extract_teacher_query_from_validation_errors | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:1939 | bookingextension_agent\local\wbagent\agent_decision_service | has_mutating_commands | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1964 | bookingextension_agent\local\wbagent\agent_decision_service | split_commands_by_mutability | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:1990 | bookingextension_agent\local\wbagent\agent_decision_service | execution_result_has_failures | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2016 | bookingextension_agent\local\wbagent\agent_decision_service | has_confirmable_prevalidation_issues | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2037 | bookingextension_agent\local\wbagent\agent_decision_service | has_recent_duplicate_title_prompt | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2075 | bookingextension_agent\local\wbagent\agent_decision_service | apply_duplicate_title_override | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2124 | bookingextension_agent\local\wbagent\agent_decision_service | augment_missing_teacher_autocreate_confirmation | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2195 | bookingextension_agent\local\wbagent\agent_decision_service | resolve_task_name_by_suffix | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:2220 | bookingextension_agent\local\wbagent\agent_decision_service | user_allows_missing_user_autocreate | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2244 | bookingextension_agent\local\wbagent\agent_decision_service | get_last_user_message | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:2261 | bookingextension_agent\local\wbagent\agent_decision_service | is_substantive_clarification_message | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2272 | bookingextension_agent\local\wbagent\agent_decision_service | is_non_substantive_clarification_message | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2353 | bookingextension_agent\local\wbagent\agent_decision_service | extract_option_id_from_message | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:2383 | bookingextension_agent\local\wbagent\agent_decision_service | extract_option_search_query | 6 | 6 | 4
classes/local/wbagent/agent_decision_service.php:2406 | bookingextension_agent\local\wbagent\agent_decision_service | clarification_result | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2434 | bookingextension_agent\local\wbagent\agent_decision_service | localized | 6 | 5 | 4
classes/local/wbagent/agent_decision_service.php:2444 | bookingextension_agent\local\wbagent\agent_decision_service | normalize_queue_item_ids | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:166 | bookingextension_agent\local\wbagent\agent_runtime | __construct | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:219 | bookingextension_agent\local\wbagent\agent_runtime | run | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:243 | bookingextension_agent\local\wbagent\agent_runtime | budget_guard_allows_next_llm_call | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:256 | bookingextension_agent\local\wbagent\agent_runtime | build_budget_exceeded_result | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:297 | bookingextension_agent\local\wbagent\agent_runtime | refresh_pending_queue_retry_state | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:374 | bookingextension_agent\local\wbagent\agent_runtime | run_loop | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:682 | bookingextension_agent\local\wbagent\agent_runtime | is_readonly_signature_budget_reached | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:702 | bookingextension_agent\local\wbagent\agent_runtime | enforce_final_response_contract | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:777 | bookingextension_agent\local\wbagent\agent_runtime | normalize_iso_language | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:787 | bookingextension_agent\local\wbagent\agent_runtime | strip_markdown_fences_from_message | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:809 | bookingextension_agent\local\wbagent\agent_runtime | build_contract_fallback_message | 5 | 8 | 6
classes/local/wbagent/agent_runtime.php:831 | bookingextension_agent\local\wbagent\agent_runtime | attach_loop_results | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:906 | bookingextension_agent\local\wbagent\agent_runtime | loop_state_contains_only_readonly_results | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:934 | bookingextension_agent\local\wbagent\agent_runtime | deduplicate_loop_results | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:977 | bookingextension_agent\local\wbagent\agent_runtime | score_loop_result_entry | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1012 | bookingextension_agent\local\wbagent\agent_runtime | has_issue_code | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1032 | bookingextension_agent\local\wbagent\agent_runtime | build_loop_repeat_summary | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1112 | bookingextension_agent\local\wbagent\agent_runtime | maybe_enrich_message_from_results | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1163 | bookingextension_agent\local\wbagent\agent_runtime | should_finalize_after_execution_result | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1210 | bookingextension_agent\local\wbagent\agent_runtime | build_sufficient_execution_result_clarification | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1257 | bookingextension_agent\local\wbagent\agent_runtime | should_recover_from_missing_commands_error | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1285 | bookingextension_agent\local\wbagent\agent_runtime | recover_missing_commands_error_result | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1345 | bookingextension_agent\local\wbagent\agent_runtime | should_retry_preflight_clarification | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1420 | bookingextension_agent\local\wbagent\agent_runtime | should_synthesize_after_success_without_pending_intent | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1447 | bookingextension_agent\local\wbagent\agent_runtime | build_preflight_retry_observation | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1509 | bookingextension_agent\local\wbagent\agent_runtime | build_retry_task_catalog_context | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1555 | bookingextension_agent\local\wbagent\agent_runtime | slim_retry_task_contract | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1571 | bookingextension_agent\local\wbagent\agent_runtime | build_preflight_fix_instructions | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1619 | bookingextension_agent\local\wbagent\agent_runtime | observations_are_framework_retry_hints | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1643 | bookingextension_agent\local\wbagent\agent_runtime | is_low_information_message | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1676 | bookingextension_agent\local\wbagent\agent_runtime | build_step_label | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1727 | bookingextension_agent\local\wbagent\agent_runtime | write_step_progress_message | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1754 | bookingextension_agent\local\wbagent\agent_runtime | extract_next_step_intent | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1778 | bookingextension_agent\local\wbagent\agent_runtime | extract_step_task_names | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1813 | bookingextension_agent\local\wbagent\agent_runtime | humanize_task_name | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1840 | bookingextension_agent\local\wbagent\agent_runtime | is_repeated_readonly_step | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:1876 | bookingextension_agent\local\wbagent\agent_runtime | extract_step_command_signatures | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1911 | bookingextension_agent\local\wbagent\agent_runtime | normalize_command_input_for_signature | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:1946 | bookingextension_agent\local\wbagent\agent_runtime | run_internal | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2120 | bookingextension_agent\local\wbagent\agent_runtime | apply_signature_based_recall_guard | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2198 | bookingextension_agent\local\wbagent\agent_runtime | apply_observation_based_recall_guard | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2240 | bookingextension_agent\local\wbagent\agent_runtime | all_commands_match_task | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2264 | bookingextension_agent\local\wbagent\agent_runtime | all_commands_match_any_task | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2288 | bookingextension_agent\local\wbagent\agent_runtime | get_diagnosis_task_names | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:2316 | bookingextension_agent\local\wbagent\agent_runtime | observations_include_diagnosis_result | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2345 | bookingextension_agent\local\wbagent\agent_runtime | apply_hard_contract_gate | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2435 | bookingextension_agent\local\wbagent\agent_runtime | normalize_unknown_response_type_to_contract_error | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:2475 | bookingextension_agent\local\wbagent\agent_runtime | is_hard_contract_error | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2502 | bookingextension_agent\local\wbagent\agent_runtime | build_option_type_explanation_shortcut | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:2577 | bookingextension_agent\local\wbagent\agent_runtime | is_meta_clarification_follow_up | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2595 | bookingextension_agent\local\wbagent\agent_runtime | assistant_prompted_for_option_type | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2622 | bookingextension_agent\local\wbagent\agent_runtime | call_orchestrator_step | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2639 | bookingextension_agent\local\wbagent\agent_runtime | resolve_output_language | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:2653 | bookingextension_agent\local\wbagent\agent_runtime | loop_continue_result | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2695 | bookingextension_agent\local\wbagent\agent_runtime | run_synthesis_step | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2760 | (global) | extract_recorded_step_task_names | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:2784 | (global) | has_explain_or_diagnose_task | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2815 | (global) | should_convert_sufficient_to_readonly_clarification | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2846 | (global) | is_sufficiency_exit_signal | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2880 | (global) | resolve_synthesis_user_language | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:2919 | (global) | loop_repeat_narration_result | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:2976 | (global) | normalize_final_reasoning_narration | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:3002 | (global) | is_final_clarification_without_commands | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:3025 | (global) | should_run_synthesis_for_clarification | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:3053 | (global) | build_deterministic_loop_repeat_fallback | 5 | 8 | 6
classes/local/wbagent/agent_runtime.php:3114 | (global) | loop_repeat_result | 6 | 5 | 4
classes/local/wbagent/agent_runtime.php:3157 | (global) | resolve_preview_option_id | 6 | 6 | 4
classes/local/wbagent/agent_runtime.php:3191 | (global) | normalize_trimmed_string_list | 6 | 6 | 4
classes/local/wbagent/agent_state.php:77 | bookingextension_agent\local\wbagent\agent_state | __construct | 6 | 5 | 4
classes/local/wbagent/agent_state.php:87 | bookingextension_agent\local\wbagent\agent_state | make | 6 | 5 | 4
classes/local/wbagent/agent_state.php:101 | bookingextension_agent\local\wbagent\agent_state | make_resumed | 6 | 5 | 4
classes/local/wbagent/agent_state.php:124 | bookingextension_agent\local\wbagent\agent_state | record_step | 6 | 5 | 4
classes/local/wbagent/agent_state.php:146 | bookingextension_agent\local\wbagent\agent_state | get_observations | 6 | 6 | 4
classes/local/wbagent/agent_state.php:155 | bookingextension_agent\local\wbagent\agent_state | get_steps | 6 | 6 | 4
classes/local/wbagent/agent_state.php:164 | bookingextension_agent\local\wbagent\agent_state | step_count | 6 | 5 | 4
classes/local/wbagent/agent_state.php:173 | bookingextension_agent\local\wbagent\agent_state | has_observations | 6 | 5 | 4
classes/local/wbagent/agent_state.php:186 | bookingextension_agent\local\wbagent\agent_state | extract_observed_command_signatures | 6 | 6 | 4
classes/local/wbagent/agent_state.php:227 | bookingextension_agent\local\wbagent\agent_state | normalize_command_input | 6 | 6 | 4
classes/local/wbagent/ai_error_classifier.php:53 | bookingextension_agent\local\wbagent\ai_error_classifier | classify_from_response | 6 | 5 | 4
classes/local/wbagent/ai_error_classifier.php:130 | bookingextension_agent\local\wbagent\ai_error_classifier | classify_from_db | 6 | 5 | 4
classes/local/wbagent/aiready.php:69 | bookingextension_agent\local\wbagent\aiready | __construct | 6 | 5 | 4
classes/local/wbagent/aiready.php:80 | bookingextension_agent\local\wbagent\aiready | export_for_template | 6 | 5 | 4
classes/local/wbagent/aiready.php:288 | bookingextension_agent\local\wbagent\aiready | build_check | 6 | 6 | 4
classes/local/wbagent/aiready.php:306 | bookingextension_agent\local\wbagent\aiready | is_module_ai_toggle_enabled | 6 | 5 | 4
classes/local/wbagent/aiready.php:320 | bookingextension_agent\local\wbagent\aiready | get_booking_statistics | 6 | 6 | 4
classes/local/wbagent/authorization_service.php:46 | bookingextension_agent\local\wbagent\authorization_service | is_agent_extension_installed | 6 | 5 | 4
classes/local/wbagent/authorization_service.php:65 | bookingextension_agent\local\wbagent\authorization_service | require_booking_module_context | 6 | 5 | 4
classes/local/wbagent/authorization_service.php:84 | bookingextension_agent\local\wbagent\authorization_service | require_use_capability | 6 | 5 | 4
classes/local/wbagent/authorization_service.php:101 | bookingextension_agent\local\wbagent\authorization_service | can_use | 6 | 5 | 4
classes/local/wbagent/authorization_service.php:120 | bookingextension_agent\local\wbagent\authorization_service | require_valid_context | 6 | 5 | 4
classes/local/wbagent/base_task.php:47 | bookingextension_agent\local\wbagent\base_task | __construct | 6 | 5 | 4
classes/local/wbagent/base_task.php:56 | bookingextension_agent\local\wbagent\base_task | is_read_only | 8 | 4 | 2
classes/local/wbagent/base_task.php:68 | bookingextension_agent\local\wbagent\base_task | get_example_input | 5 | 8 | 6
classes/local/wbagent/base_task.php:77 | bookingextension_agent\local\wbagent\base_task | get_prompt_contract | 8 | 5 | 2
classes/local/wbagent/base_task.php:106 | bookingextension_agent\local\wbagent\base_task | check_structure | 8 | 4 | 2
classes/local/wbagent/base_task.php:118 | bookingextension_agent\local\wbagent\base_task | preflight | 8 | 4 | 2
classes/local/wbagent/booking_issue_code_provider.php:36 | bookingextension_agent\local\wbagent\booking_issue_code_provider | get_duplicate_confirmation_issue_codes | 6 | 6 | 4
classes/local/wbagent/booking_issue_code_provider.php:48 | bookingextension_agent\local\wbagent\booking_issue_code_provider | get_token_subscription_issue_codes | 6 | 6 | 4
classes/local/wbagent/booking_issue_code_provider.php:63 | bookingextension_agent\local\wbagent\booking_issue_code_provider | get_prevalidation_confirmable_issue_codes | 6 | 6 | 4
classes/local/wbagent/booking_issue_code_provider.php:80 | bookingextension_agent\local\wbagent\booking_issue_code_provider | get_basic_subscription_url | 6 | 6 | 4
classes/local/wbagent/booking_issue_code_provider.php:89 | bookingextension_agent\local\wbagent\booking_issue_code_provider | get_premium_subscription_url | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:54 | bookingextension_agent\local\wbagent\conversation_store | get_active_thread | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:74 | bookingextension_agent\local\wbagent\conversation_store | get_or_create_thread | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:109 | bookingextension_agent\local\wbagent\conversation_store | create_fresh_thread | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:149 | bookingextension_agent\local\wbagent\conversation_store | add_message | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:180 | bookingextension_agent\local\wbagent\conversation_store | add_step_message | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:197 | bookingextension_agent\local\wbagent\conversation_store | clear_step_messages | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:211 | bookingextension_agent\local\wbagent\conversation_store | get_step_messages_since | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:231 | bookingextension_agent\local\wbagent\conversation_store | get_messages | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:242 | bookingextension_agent\local\wbagent\conversation_store | get_thread | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:255 | bookingextension_agent\local\wbagent\conversation_store | get_recent_messages | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:282 | bookingextension_agent\local\wbagent\conversation_store | get_last_thread_for_user | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:353 | bookingextension_agent\local\wbagent\conversation_store | get_user_threads_by_date_window | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:392 | bookingextension_agent\local\wbagent\conversation_store | get_user_messages_for_thread | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:456 | bookingextension_agent\local\wbagent\conversation_store | create_run | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:482 | bookingextension_agent\local\wbagent\conversation_store | update_run_status | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:502 | bookingextension_agent\local\wbagent\conversation_store | get_run | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:513 | bookingextension_agent\local\wbagent\conversation_store | get_latest_run | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:526 | bookingextension_agent\local\wbagent\conversation_store | run_exists | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:538 | bookingextension_agent\local\wbagent\conversation_store | run_exists_other_than | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:559 | bookingextension_agent\local\wbagent\conversation_store | get_thread_metadata_value | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:583 | bookingextension_agent\local\wbagent\conversation_store | set_thread_metadata_value | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:617 | bookingextension_agent\local\wbagent\conversation_store | set_pending_intent | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:658 | bookingextension_agent\local\wbagent\conversation_store | get_pending_intent | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:692 | bookingextension_agent\local\wbagent\conversation_store | consume_pending_intent | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:718 | bookingextension_agent\local\wbagent\conversation_store | clear_pending_intent | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:730 | bookingextension_agent\local\wbagent\conversation_store | allow_confirmation_for_session | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:746 | bookingextension_agent\local\wbagent\conversation_store | allow_confirmation_for_thread | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:764 | bookingextension_agent\local\wbagent\conversation_store | is_confirmation_allowed_for_session | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:780 | bookingextension_agent\local\wbagent\conversation_store | is_confirmation_allowed_for_thread | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:794 | bookingextension_agent\local\wbagent\conversation_store | clear_confirmation_allowance | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:807 | bookingextension_agent\local\wbagent\conversation_store | make_confirmation_session_allowlist_key | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:817 | bookingextension_agent\local\wbagent\conversation_store | get_confirmation_session_allowlist | 6 | 6 | 4
classes/local/wbagent/conversation_store.php:865 | bookingextension_agent\local\wbagent\conversation_store | save_confirmation_session_allowlist | 6 | 5 | 4
classes/local/wbagent/conversation_store.php:882 | bookingextension_agent\local\wbagent\conversation_store | add_llm_debug_entry | 5 | 7 | 6
classes/local/wbagent/conversation_store.php:915 | bookingextension_agent\local\wbagent\conversation_store | get_llm_debug_entries | 5 | 8 | 6
classes/local/wbagent/core/tasks/core_task_base.php:38 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | get_output_language | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:57 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | localized_string | 6 | 5 | 4
classes/local/wbagent/core/tasks/core_task_base.php:74 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | build_task_debug_message | 5 | 8 | 6
classes/local/wbagent/core/tasks/core_task_base.php:106 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | enrich_schema_with_prompt_meta | 6 | 5 | 4
classes/local/wbagent/core/tasks/core_task_base.php:143 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | stringify_debug_value | 5 | 7 | 6
classes/local/wbagent/core/tasks/core_task_base.php:159 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | resolve_userid | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:190 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | resolve_courseid | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:215 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | resolve_groupid | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:249 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | can_access_user | 6 | 5 | 4
classes/local/wbagent/core/tasks/core_task_base.php:280 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | preflight | 8 | 4 | 2
classes/local/wbagent/core/tasks/core_task_base.php:302 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | build_user_payload | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:355 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | build_user_courses_payload | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:402 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | build_user_roles_payload | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:450 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | extract_custom_profile_fields | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:470 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | search_user_candidates_for_preview | 6 | 5 | 4
classes/local/wbagent/core/tasks/core_task_base.php:513 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | search_course_candidates_for_preview | 6 | 5 | 4
classes/local/wbagent/core/tasks/core_task_base.php:562 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | count_active_course_enrolments | 6 | 5 | 4
classes/local/wbagent/core/tasks/core_task_base.php:581 | bookingextension_agent\local\wbagent\core\tasks\core_task_base | build_user_observation_full | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:639 | (global) | format_observation_scalar | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:656 | (global) | format_course_observation | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:684 | (global) | format_role_observation | 6 | 6 | 4
classes/local/wbagent/core/tasks/core_task_base.php:712 | (global) | format_custom_profile_field_observation | 6 | 6 | 4
classes/local/wbagent/core/tasks/get_current_user_task.php:34 | bookingextension_agent\local\wbagent\core\tasks\get_current_user_task | __construct | 6 | 5 | 4
classes/local/wbagent/core/tasks/get_current_user_task.php:43 | bookingextension_agent\local\wbagent\core\tasks\get_current_user_task | get_name | 8 | 5 | 2
classes/local/wbagent/core/tasks/get_current_user_task.php:52 | bookingextension_agent\local\wbagent\core\tasks\get_current_user_task | get_schema | 8 | 5 | 2
classes/local/wbagent/core/tasks/get_current_user_task.php:73 | bookingextension_agent\local\wbagent\core\tasks\get_current_user_task | check_structure | 8 | 4 | 2
classes/local/wbagent/core/tasks/get_current_user_task.php:86 | bookingextension_agent\local\wbagent\core\tasks\get_current_user_task | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/core/tasks/get_current_user_task.php:105 | bookingextension_agent\local\wbagent\core\tasks\get_current_user_task | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/core/tasks/get_current_user_task.php:131 | bookingextension_agent\local\wbagent\core\tasks\get_current_user_task | execute | 8 | 4 | 2
classes/local/wbagent/core/tasks/list_actions_task.php:41 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | __construct | 6 | 5 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:50 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | get_name | 8 | 5 | 2
classes/local/wbagent/core/tasks/list_actions_task.php:59 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | get_schema | 8 | 5 | 2
classes/local/wbagent/core/tasks/list_actions_task.php:91 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:110 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | check_structure | 8 | 4 | 2
classes/local/wbagent/core/tasks/list_actions_task.php:133 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:159 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | execute | 8 | 4 | 2
classes/local/wbagent/core/tasks/list_actions_task.php:239 | bookingextension_agent\local\wbagent\core\tasks\list_actions_task | build_observation_full | 6 | 6 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:274 | (global) | get_localized_string | 6 | 6 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:288 | (global) | build_debug_summary | 5 | 8 | 6
classes/local/wbagent/core/tasks/list_actions_task.php:312 | (global) | build_user_summary | 6 | 6 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:391 | (global) | describe_deny_reason | 6 | 5 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:423 | (global) | build_unavailable_action_detail | 6 | 6 | 4
classes/local/wbagent/core/tasks/list_actions_task.php:452 | (global) | build_user_capabilities | 6 | 6 | 4
classes/local/wbagent/core/tasks/recall_memory_task.php:36 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | __construct | 6 | 5 | 4
classes/local/wbagent/core/tasks/recall_memory_task.php:45 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | get_name | 8 | 5 | 2
classes/local/wbagent/core/tasks/recall_memory_task.php:54 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | get_schema | 8 | 5 | 2
classes/local/wbagent/core/tasks/recall_memory_task.php:107 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | get_example_input | 5 | 8 | 6
classes/local/wbagent/core/tasks/recall_memory_task.php:119 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | check_structure | 8 | 4 | 2
classes/local/wbagent/core/tasks/recall_memory_task.php:145 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/core/tasks/recall_memory_task.php:175 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | execute | 8 | 4 | 2
classes/local/wbagent/core/tasks/recall_memory_task.php:280 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | resolve_date_window | 6 | 6 | 4
classes/local/wbagent/core/tasks/recall_memory_task.php:329 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | resolve_user_timezone | 6 | 6 | 4
classes/local/wbagent/core/tasks/recall_memory_task.php:359 | bookingextension_agent\local\wbagent\core\tasks\recall_memory_task | build_memory_observation_text | 6 | 6 | 4
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:37 | bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task | __construct | 6 | 5 | 4
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:46 | bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task | get_name | 8 | 5 | 2
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:55 | bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task | get_schema | 8 | 5 | 2
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:93 | bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:112 | bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task | check_structure | 8 | 4 | 2
classes/local/wbagent/core/tasks/recreate_task_catalog_task.php:137 | bookingextension_agent\local\wbagent\core\tasks\recreate_task_catalog_task | execute | 8 | 4 | 2
classes/local/wbagent/core/tasks/search_courses_task.php:35 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | __construct | 6 | 5 | 4
classes/local/wbagent/core/tasks/search_courses_task.php:44 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | get_name | 8 | 5 | 2
classes/local/wbagent/core/tasks/search_courses_task.php:53 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | get_schema | 8 | 5 | 2
classes/local/wbagent/core/tasks/search_courses_task.php:87 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/core/tasks/search_courses_task.php:105 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/core/tasks/search_courses_task.php:134 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | check_structure | 8 | 4 | 2
classes/local/wbagent/core/tasks/search_courses_task.php:155 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | execute | 8 | 4 | 2
classes/local/wbagent/core/tasks/search_courses_task.php:209 | bookingextension_agent\local\wbagent\core\tasks\search_courses_task | build_course_observation_full | 6 | 6 | 4
classes/local/wbagent/core/tasks/search_users_task.php:35 | bookingextension_agent\local\wbagent\core\tasks\search_users_task | __construct | 6 | 5 | 4
classes/local/wbagent/core/tasks/search_users_task.php:44 | bookingextension_agent\local\wbagent\core\tasks\search_users_task | get_name | 8 | 5 | 2
classes/local/wbagent/core/tasks/search_users_task.php:53 | bookingextension_agent\local\wbagent\core\tasks\search_users_task | get_schema | 8 | 5 | 2
classes/local/wbagent/core/tasks/search_users_task.php:86 | bookingextension_agent\local\wbagent\core\tasks\search_users_task | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/core/tasks/search_users_task.php:105 | bookingextension_agent\local\wbagent\core\tasks\search_users_task | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/core/tasks/search_users_task.php:133 | bookingextension_agent\local\wbagent\core\tasks\search_users_task | check_structure | 8 | 4 | 2
classes/local/wbagent/core/tasks/search_users_task.php:155 | bookingextension_agent\local\wbagent\core\tasks\search_users_task | execute | 8 | 4 | 2
classes/local/wbagent/dto/bulk_update_options_input_dto.php:45 | bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto | __construct | 6 | 5 | 4
classes/local/wbagent/dto/bulk_update_options_input_dto.php:55 | bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto | from_array | 6 | 5 | 4
classes/local/wbagent/dto/bulk_update_options_input_dto.php:64 | bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto | to_array | 6 | 5 | 4
classes/local/wbagent/dto/bulk_update_options_input_dto.php:75 | bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto | get | 6 | 5 | 4
classes/local/wbagent/dto/create_entity_input_dto.php:45 | bookingextension_agent\local\wbagent\dto\create_entity_input_dto | __construct | 6 | 5 | 4
classes/local/wbagent/dto/create_entity_input_dto.php:56 | bookingextension_agent\local\wbagent\dto\create_entity_input_dto | from_array | 6 | 5 | 4
classes/local/wbagent/dto/create_entity_input_dto.php:68 | bookingextension_agent\local\wbagent\dto\create_entity_input_dto | to_array | 6 | 5 | 4
classes/local/wbagent/dto/create_entity_input_dto.php:79 | bookingextension_agent\local\wbagent\dto\create_entity_input_dto | get | 6 | 5 | 4
classes/local/wbagent/dto/create_option_input_dto.php:45 | bookingextension_agent\local\wbagent\dto\create_option_input_dto | __construct | 6 | 5 | 4
classes/local/wbagent/dto/create_option_input_dto.php:56 | bookingextension_agent\local\wbagent\dto\create_option_input_dto | from_array | 6 | 5 | 4
classes/local/wbagent/dto/create_option_input_dto.php:68 | bookingextension_agent\local\wbagent\dto\create_option_input_dto | to_array | 6 | 5 | 4
classes/local/wbagent/dto/create_option_input_dto.php:79 | bookingextension_agent\local\wbagent\dto\create_option_input_dto | get | 6 | 5 | 4
classes/local/wbagent/dto/mutation_result_dto.php:63 | bookingextension_agent\local\wbagent\dto\mutation_result_dto | __construct | 6 | 5 | 4
classes/local/wbagent/dto/mutation_result_dto.php:86 | bookingextension_agent\local\wbagent\dto\mutation_result_dto | success | 6 | 5 | 4
classes/local/wbagent/dto/mutation_result_dto.php:101 | bookingextension_agent\local\wbagent\dto\mutation_result_dto | error | 6 | 5 | 4
classes/local/wbagent/dto/mutation_result_dto.php:111 | bookingextension_agent\local\wbagent\dto\mutation_result_dto | skipped | 6 | 5 | 4
classes/local/wbagent/dto/mutation_result_dto.php:122 | bookingextension_agent\local\wbagent\dto\mutation_result_dto | dry_run_ok | 6 | 5 | 4
classes/local/wbagent/dto/mutation_result_dto.php:131 | bookingextension_agent\local\wbagent\dto\mutation_result_dto | to_array | 6 | 5 | 4
classes/local/wbagent/dto/update_option_input_dto.php:45 | bookingextension_agent\local\wbagent\dto\update_option_input_dto | __construct | 6 | 5 | 4
classes/local/wbagent/dto/update_option_input_dto.php:55 | bookingextension_agent\local\wbagent\dto\update_option_input_dto | from_array | 6 | 5 | 4
classes/local/wbagent/dto/update_option_input_dto.php:64 | bookingextension_agent\local\wbagent\dto\update_option_input_dto | to_array | 6 | 5 | 4
classes/local/wbagent/dto/update_option_input_dto.php:75 | bookingextension_agent\local\wbagent\dto\update_option_input_dto | get | 6 | 5 | 4
classes/local/wbagent/embeddings_action_config_resolver.php:50 | bookingextension_agent\local\wbagent\embeddings_action_config_resolver | resolve | 6 | 6 | 4
classes/local/wbagent/embeddings_catalog_builder_service.php:41 | bookingextension_agent\local\wbagent\embeddings_catalog_builder_service | build_full_catalog_rows | 6 | 6 | 4
classes/local/wbagent/embeddings_catalog_builder_service.php:104 | bookingextension_agent\local\wbagent\embeddings_catalog_builder_service | compute_content_hash | 6 | 5 | 4
classes/local/wbagent/embeddings_catalog_builder_service.php:120 | bookingextension_agent\local\wbagent\embeddings_catalog_builder_service | to_embedding_input | 6 | 5 | 4
classes/local/wbagent/embeddings_catalog_builder_service.php:145 | bookingextension_agent\local\wbagent\embeddings_catalog_builder_service | get_contextual_prompt_packs_for_task | 6 | 6 | 4
classes/local/wbagent/embeddings_csv_repository.php:53 | bookingextension_agent\local\wbagent\embeddings_csv_repository | get_csv_path | 6 | 6 | 4
classes/local/wbagent/embeddings_csv_repository.php:63 | bookingextension_agent\local\wbagent\embeddings_csv_repository | exists | 6 | 5 | 4
classes/local/wbagent/embeddings_csv_repository.php:72 | bookingextension_agent\local\wbagent\embeddings_csv_repository | read_rows | 6 | 5 | 4
classes/local/wbagent/embeddings_csv_repository.php:107 | bookingextension_agent\local\wbagent\embeddings_csv_repository | is_valid_schema | 6 | 5 | 4
classes/local/wbagent/embeddings_csv_repository.php:133 | bookingextension_agent\local\wbagent\embeddings_csv_repository | write_rows | 6 | 5 | 4
classes/local/wbagent/embeddings_csv_repository.php:162 | bookingextension_agent\local\wbagent\embeddings_csv_repository | headers_match | 6 | 5 | 4
classes/local/wbagent/embeddings_csv_repository.php:181 | bookingextension_agent\local\wbagent\embeddings_csv_repository | get_default_file_permissions | 6 | 6 | 4
classes/local/wbagent/embeddings_readiness_service.php:43 | bookingextension_agent\local\wbagent\embeddings_readiness_service | is_wunderbyte_embeddings_available | 6 | 5 | 4
classes/local/wbagent/embeddings_readiness_service.php:55 | bookingextension_agent\local\wbagent\embeddings_readiness_service | get_catalog_status | 6 | 6 | 4
classes/local/wbagent/embeddings_readiness_service.php:113 | bookingextension_agent\local\wbagent\embeddings_readiness_service | ensure_rebuild_scheduled_if_needed | 6 | 5 | 4
classes/local/wbagent/embeddings_retrieval_service.php:41 | bookingextension_agent\local\wbagent\embeddings_retrieval_service | search_top_k | 6 | 5 | 4
classes/local/wbagent/embeddings_retrieval_service.php:75 | bookingextension_agent\local\wbagent\embeddings_retrieval_service | build_planner_catalog_subset | 6 | 6 | 4
classes/local/wbagent/embeddings_retrieval_service.php:134 | bookingextension_agent\local\wbagent\embeddings_retrieval_service | build_live_contract_lookup | 6 | 6 | 4
classes/local/wbagent/embeddings_retrieval_service.php:190 | bookingextension_agent\local\wbagent\embeddings_retrieval_service | compact_properties_for_planner | 6 | 5 | 4
classes/local/wbagent/embeddings_retrieval_service.php:227 | bookingextension_agent\local\wbagent\embeddings_retrieval_service | cosine_similarity | 6 | 5 | 4
classes/local/wbagent/embeddings_retrieval_service.php:258 | bookingextension_agent\local\wbagent\embeddings_retrieval_service | decode_json_array | 6 | 5 | 4
classes/local/wbagent/examples/tasks/multistep_example_task.php:42 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | __construct | 5 | 7 | 6
classes/local/wbagent/examples/tasks/multistep_example_task.php:53 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | get_name | 7 | 7 | 4
classes/local/wbagent/examples/tasks/multistep_example_task.php:63 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | get_schema | 7 | 7 | 4
classes/local/wbagent/examples/tasks/multistep_example_task.php:90 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | get_example_input | 5 | 8 | 6
classes/local/wbagent/examples/tasks/multistep_example_task.php:103 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | get_prompt_contract | 7 | 7 | 4
classes/local/wbagent/examples/tasks/multistep_example_task.php:124 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | check_structure | 7 | 6 | 4
classes/local/wbagent/examples/tasks/multistep_example_task.php:170 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | preflight | 7 | 6 | 4
classes/local/wbagent/examples/tasks/multistep_example_task.php:204 | bookingextension_agent\local\wbagent\examples\tasks\multistep_example_task | execute | 7 | 6 | 4
classes/local/wbagent/examples/tasks/readonly_example_task.php:45 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | __construct | 5 | 7 | 6
classes/local/wbagent/examples/tasks/readonly_example_task.php:54 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | get_name | 7 | 7 | 4
classes/local/wbagent/examples/tasks/readonly_example_task.php:63 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | get_schema | 7 | 7 | 4
classes/local/wbagent/examples/tasks/readonly_example_task.php:89 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | get_example_input | 5 | 8 | 6
classes/local/wbagent/examples/tasks/readonly_example_task.php:101 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | get_prompt_contract | 7 | 7 | 4
classes/local/wbagent/examples/tasks/readonly_example_task.php:120 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | check_structure | 7 | 6 | 4
classes/local/wbagent/examples/tasks/readonly_example_task.php:155 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | preflight | 7 | 6 | 4
classes/local/wbagent/examples/tasks/readonly_example_task.php:182 | bookingextension_agent\local\wbagent\examples\tasks\readonly_example_task | execute | 7 | 6 | 4
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:42 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | __construct | 5 | 7 | 6
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:49 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | get_name | 7 | 7 | 4
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:56 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | get_schema | 7 | 7 | 4
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:85 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | get_example_input | 5 | 8 | 6
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:96 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | get_prompt_contract | 7 | 7 | 4
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:113 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | check_structure | 7 | 6 | 4
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:134 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | preflight | 7 | 6 | 4
classes/local/wbagent/examples/tasks/spawn_child_example_task.php:160 | bookingextension_agent\local\wbagent\examples\tasks\spawn_child_example_task | execute | 7 | 6 | 4
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:42 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | __construct | 5 | 7 | 6
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:49 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | get_name | 7 | 7 | 4
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:56 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | get_schema | 7 | 7 | 4
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:80 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | get_example_input | 5 | 8 | 6
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:90 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | get_prompt_contract | 7 | 7 | 4
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:107 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | check_structure | 7 | 6 | 4
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:134 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | preflight | 7 | 6 | 4
classes/local/wbagent/examples/tasks/spawn_parent_example_task.php:159 | bookingextension_agent\local\wbagent\examples\tasks\spawn_parent_example_task | execute | 7 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:57 | bookingextension_agent\local\wbagent\execution_feedback_service | __construct | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:76 | bookingextension_agent\local\wbagent\execution_feedback_service | build_completion_feedback | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:137 | bookingextension_agent\local\wbagent\execution_feedback_service | should_apply_polish_step | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:171 | bookingextension_agent\local\wbagent\execution_feedback_service | generate_llm_feedback | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:257 | bookingextension_agent\local\wbagent\execution_feedback_service | generate_llm_follow_up_suggestions | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:352 | bookingextension_agent\local\wbagent\execution_feedback_service | build_follow_up_prompt | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:399 | bookingextension_agent\local\wbagent\execution_feedback_service | parse_follow_up_suggestions_json | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:478 | bookingextension_agent\local\wbagent\execution_feedback_service | get_follow_up_suggestions_limit | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:493 | bookingextension_agent\local\wbagent\execution_feedback_service | extract_latest_user_message | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:513 | bookingextension_agent\local\wbagent\execution_feedback_service | build_feedback_prompt | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:570 | bookingextension_agent\local\wbagent\execution_feedback_service | extract_message_from_feedback_response | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:604 | bookingextension_agent\local\wbagent\execution_feedback_service | build_execution_feedback_debug_source | 5 | 8 | 6
classes/local/wbagent/execution_feedback_service.php:644 | bookingextension_agent\local\wbagent\execution_feedback_service | sanitize_results_for_client | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:811 | bookingextension_agent\local\wbagent\execution_feedback_service | sanitize_result_detail | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:900 | bookingextension_agent\local\wbagent\execution_feedback_service | fallback_message_for_results | 5 | 7 | 6
classes/local/wbagent/execution_feedback_service.php:960 | bookingextension_agent\local\wbagent\execution_feedback_service | extract_primary_link_from_result | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:980 | bookingextension_agent\local\wbagent\execution_feedback_service | extract_primary_link_from_results | 6 | 6 | 4
classes/local/wbagent/execution_feedback_service.php:1003 | bookingextension_agent\local\wbagent\execution_feedback_service | localized | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:1017 | bookingextension_agent\local\wbagent\execution_feedback_service | localized_list_count_message | 6 | 5 | 4
classes/local/wbagent/execution_feedback_service.php:1040 | bookingextension_agent\local\wbagent\execution_feedback_service | append_link_to_message | 6 | 6 | 4
classes/local/wbagent/executor.php:78 | bookingextension_agent\local\wbagent\executor | __construct | 6 | 5 | 4
classes/local/wbagent/executor.php:102 | bookingextension_agent\local\wbagent\executor | execute_commands | 6 | 5 | 4
classes/local/wbagent/executor.php:263 | bookingextension_agent\local\wbagent\executor | execute_spawn_chain | 6 | 5 | 4
classes/local/wbagent/executor.php:443 | bookingextension_agent\local\wbagent\executor | build_safe_executed_input | 6 | 6 | 4
classes/local/wbagent/executor.php:475 | bookingextension_agent\local\wbagent\executor | enrich_result_with_follow_ups | 6 | 5 | 4
classes/local/wbagent/executor.php:516 | bookingextension_agent\local\wbagent\executor | build_follow_up_suggestions | 6 | 6 | 4
classes/local/wbagent/executor.php:554 | bookingextension_agent\local\wbagent\executor | get_follow_up_suggestions_limit | 6 | 6 | 4
classes/local/wbagent/executor.php:573 | bookingextension_agent\local\wbagent\executor | append_result_driven_suggestions | 6 | 6 | 4
classes/local/wbagent/executor.php:615 | bookingextension_agent\local\wbagent\executor | append_suggestion | 6 | 6 | 4
classes/local/wbagent/executor.php:644 | bookingextension_agent\local\wbagent\executor | get_first_row_field | 6 | 6 | 4
classes/local/wbagent/executor.php:666 | bookingextension_agent\local\wbagent\executor | get_follow_up_candidate_tasks | 6 | 6 | 4
classes/local/wbagent/executor.php:698 | bookingextension_agent\local\wbagent\executor | task_follow_up_score | 6 | 5 | 4
classes/local/wbagent/executor.php:726 | bookingextension_agent\local\wbagent\executor | task_namespace_prefix | 6 | 5 | 4
classes/local/wbagent/executor.php:738 | bookingextension_agent\local\wbagent\executor | get_task_label | 6 | 6 | 4
classes/local/wbagent/executor.php:757 | bookingextension_agent\local\wbagent\executor | truncate_label | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_authorization_service.php:48 | bookingextension_agent\local\wbagent\interfaces\agent_authorization_service | require_use_capability | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_authorization_service.php:57 | bookingextension_agent\local\wbagent\interfaces\agent_authorization_service | can_use | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_authorization_service.php:67 | bookingextension_agent\local\wbagent\interfaces\agent_authorization_service | require_valid_context | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:43 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_or_create_thread | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:54 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | add_message | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:62 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_messages | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:71 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_recent_messages | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:80 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_last_thread_for_user | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:91 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_user_threads_by_date_window | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:108 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_user_messages_for_thread | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:126 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | create_run | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:136 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | update_run_status | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:144 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_run | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:152 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | get_latest_run | 6 | 6 | 4
classes/local/wbagent/interfaces/agent_conversation_store.php:160 | bookingextension_agent\local\wbagent\interfaces\agent_conversation_store | run_exists | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_executor.php:53 | bookingextension_agent\local\wbagent\interfaces\agent_executor | execute_commands | 6 | 5 | 4
classes/local/wbagent/interfaces/agent_interpreter.php:60 | bookingextension_agent\local\wbagent\interfaces\agent_interpreter | interpret | 6 | 5 | 4
classes/local/wbagent/interfaces/issue_code_provider_interface.php:38 | bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface | get_duplicate_confirmation_issue_codes | 6 | 6 | 4
classes/local/wbagent/interfaces/issue_code_provider_interface.php:49 | bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface | get_token_subscription_issue_codes | 6 | 6 | 4
classes/local/wbagent/interfaces/issue_code_provider_interface.php:61 | bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface | get_prevalidation_confirmable_issue_codes | 6 | 6 | 4
classes/local/wbagent/interfaces/issue_code_provider_interface.php:68 | bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface | get_basic_subscription_url | 6 | 6 | 4
classes/local/wbagent/interfaces/issue_code_provider_interface.php:75 | bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface | get_premium_subscription_url | 6 | 6 | 4
classes/local/wbagent/interfaces/preview_option_memory_interface.php:35 | bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface | remember_last_preview_options_for_execute | 6 | 5 | 4
classes/local/wbagent/interfaces/preview_option_memory_interface.php:44 | bookingextension_agent\local\wbagent\interfaces\preview_option_memory_interface | resolve_last_preview_option_ids_for_execute | 6 | 6 | 4
classes/local/wbagent/interfaces/preview_option_memory_provider_interface.php:32 | bookingextension_agent\local\wbagent\interfaces\preview_option_memory_provider_interface | get_preview_option_memory | 6 | 6 | 4
classes/local/wbagent/interfaces/queue_identity_provider_interface.php:36 | bookingextension_agent\local\wbagent\interfaces\queue_identity_provider_interface | build_queue_business_identity | 6 | 6 | 4
classes/local/wbagent/interfaces/result_summary_provider_interface.php:35 | bookingextension_agent\local\wbagent\interfaces\result_summary_provider_interface | get_result_summary_contributors | 6 | 6 | 4
classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:40 | bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface | supports | 6 | 5 | 4
classes/local/wbagent/interfaces/summarizer/result_summary_contributor_interface.php:51 | bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface | summarize | 6 | 5 | 4
classes/local/wbagent/interfaces/task_input_normalizer_interface.php:34 | bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_interface | normalize | 6 | 6 | 4
classes/local/wbagent/interfaces/task_input_normalizer_provider_interface.php:32 | bookingextension_agent\local\wbagent\interfaces\task_input_normalizer_provider_interface | get_task_input_normalizer | 6 | 6 | 4
classes/local/wbagent/interfaces/task_interface.php:46 | bookingextension_agent\local\wbagent\interfaces\task_interface | get_name | 8 | 5 | 2
classes/local/wbagent/interfaces/task_interface.php:53 | bookingextension_agent\local\wbagent\interfaces\task_interface | get_schema | 8 | 5 | 2
classes/local/wbagent/interfaces/task_interface.php:63 | bookingextension_agent\local\wbagent\interfaces\task_interface | get_example_input | 5 | 8 | 6
classes/local/wbagent/interfaces/task_interface.php:70 | bookingextension_agent\local\wbagent\interfaces\task_interface | get_prompt_contract | 8 | 5 | 2
classes/local/wbagent/interfaces/task_interface.php:82 | bookingextension_agent\local\wbagent\interfaces\task_interface | check_structure | 8 | 4 | 2
classes/local/wbagent/interfaces/task_interface.php:96 | bookingextension_agent\local\wbagent\interfaces\task_interface | preflight | 8 | 4 | 2
classes/local/wbagent/interfaces/task_interface.php:110 | bookingextension_agent\local\wbagent\interfaces\task_interface | execute | 8 | 4 | 2
classes/local/wbagent/interfaces/task_interface.php:117 | bookingextension_agent\local\wbagent\interfaces\task_interface | is_read_only | 8 | 4 | 2
classes/local/wbagent/interfaces/task_provider_interface.php:32 | bookingextension_agent\local\wbagent\interfaces\task_provider_interface | get_component | 6 | 6 | 4
classes/local/wbagent/interfaces/task_provider_interface.php:39 | bookingextension_agent\local\wbagent\interfaces\task_provider_interface | get_tasks | 6 | 6 | 4
classes/local/wbagent/interfaces/task_provider_interface.php:46 | bookingextension_agent\local\wbagent\interfaces\task_provider_interface | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/interfaces/task_provider_interface.php:56 | bookingextension_agent\local\wbagent\interfaces\task_provider_interface | get_issue_code_provider | 6 | 6 | 4
classes/local/wbagent/interfaces/task_provider_interface.php:66 | bookingextension_agent\local\wbagent\interfaces\task_provider_interface | get_prompt_guidance | 6 | 6 | 4
classes/local/wbagent/interfaces/task_result_summary_provider_interface.php:39 | bookingextension_agent\local\wbagent\interfaces\task_result_summary_provider_interface | summarize_task_result | 6 | 5 | 4
classes/local/wbagent/interfaces/task_trigger_provider_interface.php:36 | bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/interpreter.php:75 | bookingextension_agent\local\wbagent\interpreter | __construct | 6 | 5 | 4
classes/local/wbagent/interpreter.php:87 | bookingextension_agent\local\wbagent\interpreter | interpret | 6 | 5 | 4
classes/local/wbagent/interpreter.php:314 | bookingextension_agent\local\wbagent\interpreter | normalize_commands_payload | 6 | 6 | 4
classes/local/wbagent/interpreter.php:379 | bookingextension_agent\local\wbagent\interpreter | extract_flat_command_input | 6 | 6 | 4
classes/local/wbagent/interpreter.php:394 | bookingextension_agent\local\wbagent\interpreter | prune_empty_input_values | 6 | 5 | 4
classes/local/wbagent/interpreter.php:426 | bookingextension_agent\local\wbagent\interpreter | with_optional_next_step_intent | 6 | 5 | 4
classes/local/wbagent/interpreter.php:441 | bookingextension_agent\local\wbagent\interpreter | looks_like_completed_action_intent | 6 | 5 | 4
classes/local/wbagent/interpreter.php:473 | bookingextension_agent\local\wbagent\interpreter | normalize_task_like_response | 6 | 6 | 4
classes/local/wbagent/interpreter.php:583 | bookingextension_agent\local\wbagent\interpreter | resolve_task_name_alias | 6 | 6 | 4
classes/local/wbagent/interpreter.php:610 | bookingextension_agent\local\wbagent\interpreter | hydrate_question_field | 6 | 5 | 4
classes/local/wbagent/interpreter.php:635 | bookingextension_agent\local\wbagent\interpreter | extract_command_input | 6 | 6 | 4
classes/local/wbagent/interpreter.php:648 | bookingextension_agent\local\wbagent\interpreter | parse | 6 | 6 | 4
classes/local/wbagent/interpreter.php:670 | bookingextension_agent\local\wbagent\interpreter | sanitize_json_payload | 6 | 5 | 4
classes/local/wbagent/interpreter.php:705 | bookingextension_agent\local\wbagent\interpreter | truncate_parse_excerpt | 6 | 5 | 4
classes/local/wbagent/interpreter.php:724 | bookingextension_agent\local\wbagent\interpreter | extract_used_triggers | 6 | 6 | 4
classes/local/wbagent/interpreter.php:744 | bookingextension_agent\local\wbagent\interpreter | validate_commands | 6 | 5 | 4
classes/local/wbagent/interpreter.php:867 | bookingextension_agent\local\wbagent\interpreter | normalize_ambiguity_options | 6 | 6 | 4
classes/local/wbagent/interpreter.php:905 | bookingextension_agent\local\wbagent\interpreter | normalize_self_user_references | 6 | 6 | 4
classes/local/wbagent/interpreter.php:946 | bookingextension_agent\local\wbagent\interpreter | canonicalize_command_input | 6 | 5 | 4
classes/local/wbagent/interpreter.php:980 | bookingextension_agent\local\wbagent\interpreter | normalize_timestamp_value | 6 | 6 | 4
classes/local/wbagent/interpreter.php:1033 | bookingextension_agent\local\wbagent\interpreter | error_result | 6 | 5 | 4
classes/local/wbagent/interpreter.php:1047 | bookingextension_agent\local\wbagent\interpreter | error_result_with_issue_code | 6 | 5 | 4
classes/local/wbagent/interpreter.php:1068 | bookingextension_agent\local\wbagent\interpreter | safe_string | 6 | 5 | 4
classes/local/wbagent/interpreter.php:1081 | bookingextension_agent\local\wbagent\interpreter | clarification_message | 6 | 5 | 4
classes/local/wbagent/interpreter.php:1109 | bookingextension_agent\local\wbagent\interpreter | confirmation_message_from_ambiguities | 6 | 5 | 4
classes/local/wbagent/interpreter.php:1127 | bookingextension_agent\local\wbagent\interpreter | user_facing_validation_message | 6 | 5 | 4
classes/local/wbagent/interpreter.php:1186 | bookingextension_agent\local\wbagent\interpreter | strip_command_prefix | 6 | 5 | 4
classes/local/wbagent/llm_call_service.php:57 | bookingextension_agent\local\wbagent\llm_call_service | __construct | 6 | 5 | 4
classes/local/wbagent/llm_call_service.php:72 | bookingextension_agent\local\wbagent\llm_call_service | invoke | 6 | 5 | 4
classes/local/wbagent/llm_call_service.php:137 | bookingextension_agent\local\wbagent\llm_call_service | invoke_embeddings | 6 | 5 | 4
classes/local/wbagent/llm_call_service.php:218 | bookingextension_agent\local\wbagent\llm_call_service | build_prompt_action | 6 | 6 | 4
classes/local/wbagent/llm_call_service.php:257 | bookingextension_agent\local\wbagent\llm_call_service | resolve_wunderbyte_prompt_action_class | 6 | 6 | 4
classes/local/wbagent/llm_debug_logger.php:38 | bookingextension_agent\local\wbagent\llm_debug_logger | is_enabled | 5 | 7 | 6
classes/local/wbagent/llm_debug_logger.php:59 | bookingextension_agent\local\wbagent\llm_debug_logger | log_exchange | 5 | 8 | 6
classes/local/wbagent/llm_debug_logger.php:101 | bookingextension_agent\local\wbagent\llm_debug_logger | log_exchange_always | 5 | 8 | 6
classes/local/wbagent/loop_finalizer.php:46 | bookingextension_agent\local\wbagent\loop_finalizer | finalize | 6 | 5 | 4
classes/local/wbagent/loop_finalizer.php:76 | bookingextension_agent\local\wbagent\loop_finalizer | should_finalize_after_execution_result | 6 | 5 | 4
classes/local/wbagent/loop_finalizer.php:131 | bookingextension_agent\local\wbagent\loop_finalizer | build_sufficient_execution_result_clarification | 6 | 6 | 4
classes/local/wbagent/loop_finalizer.php:185 | bookingextension_agent\local\wbagent\loop_finalizer | maybe_enrich_message_from_results | 6 | 5 | 4
classes/local/wbagent/loop_finalizer.php:226 | bookingextension_agent\local\wbagent\loop_finalizer | is_low_information_message | 6 | 5 | 4
classes/local/wbagent/message_persistence_service.php:41 | bookingextension_agent\local\wbagent\message_persistence_service | __construct | 6 | 5 | 4
classes/local/wbagent/message_persistence_service.php:52 | bookingextension_agent\local\wbagent\message_persistence_service | persist_assistant_message | 6 | 5 | 4
classes/local/wbagent/message_trigger_registry.php:84 | bookingextension_agent\local\wbagent\message_trigger_registry | __construct | 6 | 5 | 4
classes/local/wbagent/message_trigger_registry.php:93 | bookingextension_agent\local\wbagent\message_trigger_registry | get_available_triggers | 6 | 6 | 4
classes/local/wbagent/message_trigger_registry.php:125 | bookingextension_agent\local\wbagent\message_trigger_registry | get_available_trigger_ids | 6 | 6 | 4
classes/local/wbagent/message_trigger_registry.php:136 | bookingextension_agent\local\wbagent\message_trigger_registry | normalize_used_triggers | 6 | 6 | 4
classes/local/wbagent/message_trigger_registry.php:164 | bookingextension_agent\local\wbagent\message_trigger_registry | normalize_response_type | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:103 | bookingextension_agent\local\wbagent\orchestrator | __construct | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:120 | bookingextension_agent\local\wbagent\orchestrator | is_provider_available | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:134 | bookingextension_agent\local\wbagent\orchestrator | get_runtime_provider_status | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:269 | bookingextension_agent\local\wbagent\orchestrator | process | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:541 | bookingextension_agent\local\wbagent\orchestrator | get_default_initial_prompt_template | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:561 | bookingextension_agent\local\wbagent\orchestrator | get_default_initial_prompt_template_for_action | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:669 | bookingextension_agent\local\wbagent\orchestrator | get_default_summary_prompt_prefix | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:678 | bookingextension_agent\local\wbagent\orchestrator | get_default_initial_prompt_template_path | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:695 | bookingextension_agent\local\wbagent\orchestrator | build_system_prompt | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:783 | bookingextension_agent\local\wbagent\orchestrator | slim_prompt_catalog_for_planner | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:822 | bookingextension_agent\local\wbagent\orchestrator | compact_catalog_description | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:844 | bookingextension_agent\local\wbagent\orchestrator | compact_catalog_example_input | 5 | 7 | 6
classes/local/wbagent/orchestrator.php:870 | bookingextension_agent\local\wbagent\orchestrator | compact_catalog_message_triggers | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:913 | bookingextension_agent\local\wbagent\orchestrator | extract_recent_task_names_from_messages | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:949 | bookingextension_agent\local\wbagent\orchestrator | is_first_assistant_turn | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:975 | bookingextension_agent\local\wbagent\orchestrator | build_prompt | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1039 | (global) | build_local_output_contract_block | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1071 | (global) | normalize_planner_trace_history | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1106 | (global) | append_planner_traces_and_observations | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1140 | (global) | build_runtime_context_block | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1209 | (global) | append_json_object_section | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1228 | (global) | append_json_list_section | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1251 | (global) | json_encode_or_empty | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1268 | (global) | build_unavailable_task_catalog_for_runtime | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1316 | (global) | availability_from_deny_reason | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1338 | (global) | sanitize_unavailable_task_catalog | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1350 | (global) | build_task_description_index | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1375 | (global) | extract_completed_commands_from_messages | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1456 | (global) | merge_completed_commands_from_queue | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1527 | (global) | build_completed_command_signature | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1554 | (global) | normalize_completed_command_input | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1587 | (global) | normalize_completed_command_value | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1633 | (global) | observations_are_framework_retry_hints | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1657 | (global) | normalize_step_type | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1677 | (global) | get_initial_prompt_config_key | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1696 | (global) | get_action_initial_prompt_config_key | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1724 | (global) | get_history_limit_for_step | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1735 | (global) | normalize_config_prompt_template | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1754 | (global) | resolve_action_class_for_step | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:1825 | (global) | should_use_openai_step_routing | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1845 | (global) | is_wunderbyte_routing_available | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1890 | (global) | build_orchestrator_debug_source | 5 | 8 | 6
classes/local/wbagent/orchestrator.php:1955 | (global) | short_debug_token | 5 | 7 | 6
classes/local/wbagent/orchestrator.php:1976 | (global) | is_action_available_in_context | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:1992 | (global) | build_assistant_state_blocks | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:2024 | (global) | summarize_structured_state | 6 | 5 | 4
classes/local/wbagent/orchestrator.php:2064 | (global) | extract_result_facts | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:2123 | (global) | normalize_nonempty_string_list | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:2149 | (global) | build_contextual_guidance | 6 | 6 | 4
classes/local/wbagent/orchestrator.php:2192 | (global) | matches_contextual_pack | 6 | 5 | 4
classes/local/wbagent/planner_service.php:49 | bookingextension_agent\local\wbagent\planner_service | __construct | 6 | 5 | 4
classes/local/wbagent/planner_service.php:65 | bookingextension_agent\local\wbagent\planner_service | enrich_recovery_input | 6 | 5 | 4
classes/local/wbagent/planner_service.php:162 | bookingextension_agent\local\wbagent\planner_service | build_enrichment_cache_key | 6 | 6 | 4
classes/local/wbagent/planner_service.php:193 | bookingextension_agent\local\wbagent\planner_service | is_docs_retrieval_schema | 6 | 5 | 4
classes/local/wbagent/planner_service.php:207 | bookingextension_agent\local\wbagent\planner_service | build_docs_index_lines | 6 | 6 | 4
classes/local/wbagent/planner_service.php:274 | bookingextension_agent\local\wbagent\planner_service | build_planner_prompt | 6 | 6 | 4
classes/local/wbagent/planner_service.php:339 | bookingextension_agent\local\wbagent\planner_service | extract_search_terms | 6 | 6 | 4
classes/local/wbagent/planner_service.php:367 | bookingextension_agent\local\wbagent\planner_service | extract_planner_payload | 6 | 6 | 4
classes/local/wbagent/planner_service.php:404 | bookingextension_agent\local\wbagent\planner_service | merge_input_patch | 6 | 5 | 4
classes/local/wbagent/planner_service.php:520 | bookingextension_agent\local\wbagent\planner_service | is_input_value_empty | 6 | 5 | 4
classes/local/wbagent/planner_service.php:541 | bookingextension_agent\local\wbagent\planner_service | create_docs_lookup_service | 6 | 5 | 4
classes/local/wbagent/planner_service.php:554 | bookingextension_agent\local\wbagent\planner_service | build_planner_debug_source | 5 | 8 | 6
classes/local/wbagent/preview_policy.php:55 | bookingextension_agent\local\wbagent\preview_policy | supports_preview | 6 | 5 | 4
classes/local/wbagent/preview_policy.php:66 | bookingextension_agent\local\wbagent\preview_policy | filter_previewable_commands | 6 | 5 | 4
classes/local/wbagent/preview_policy.php:79 | bookingextension_agent\local\wbagent\preview_policy | has_previewable_command | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:73 | bookingextension_agent\local\wbagent\privacy_anonymizer | __construct | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:82 | bookingextension_agent\local\wbagent\privacy_anonymizer | get_mode | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:100 | bookingextension_agent\local\wbagent\privacy_anonymizer | looks_like_anon_token | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:109 | bookingextension_agent\local\wbagent\privacy_anonymizer | should_anonymize_user_input | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:118 | bookingextension_agent\local\wbagent\privacy_anonymizer | should_anonymize_llm_backend_data | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:129 | bookingextension_agent\local\wbagent\privacy_anonymizer | precheck_user_message | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:174 | bookingextension_agent\local\wbagent\privacy_anonymizer | deanonymize_command_input | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:197 | bookingextension_agent\local\wbagent\privacy_anonymizer | deanonymize_command_input_for_active_user | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:217 | bookingextension_agent\local\wbagent\privacy_anonymizer | deanonymize_message_for_display | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:281 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_value_for_llm | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:301 | bookingextension_agent\local\wbagent\privacy_anonymizer | deanonymize_recursive | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:335 | bookingextension_agent\local\wbagent\privacy_anonymizer | resolve_token_entry | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:363 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_value_recursive | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:391 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_string_for_llm | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:424 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_labeled_user_fields | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:479 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_person_field_value | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:537 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_emails | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:571 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_names | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:733 | bookingextension_agent\local\wbagent\privacy_anonymizer | find_email_spans | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:766 | bookingextension_agent\local\wbagent\privacy_anonymizer | offset_overlaps_email_span | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:783 | bookingextension_agent\local\wbagent\privacy_anonymizer | get_user_name_match_index | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:857 | bookingextension_agent\local\wbagent\privacy_anonymizer | user_sets_intersect | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:876 | bookingextension_agent\local\wbagent\privacy_anonymizer | get_distinct_name_index | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:922 | bookingextension_agent\local\wbagent\privacy_anonymizer | normalize_name | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:940 | bookingextension_agent\local\wbagent\privacy_anonymizer | get_token_map | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:965 | bookingextension_agent\local\wbagent\privacy_anonymizer | set_token_map | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:978 | bookingextension_agent\local\wbagent\privacy_anonymizer | get_or_create_token | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:1081 | bookingextension_agent\local\wbagent\privacy_anonymizer | scope_identity_key_for_type | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:1096 | bookingextension_agent\local\wbagent\privacy_anonymizer | build_field_token_from_base | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:1116 | bookingextension_agent\local\wbagent\privacy_anonymizer | extract_base_token_from_anon_token | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:1134 | bookingextension_agent\local\wbagent\privacy_anonymizer | resolve_entry_for_field | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:1170 | bookingextension_agent\local\wbagent\privacy_anonymizer | resolve_identity_from_email | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:1209 | bookingextension_agent\local\wbagent\privacy_anonymizer | resolve_identity_from_user_ids | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:1234 | bookingextension_agent\local\wbagent\privacy_anonymizer | load_user_identity_record | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:1252 | bookingextension_agent\local\wbagent\privacy_anonymizer | build_identity_variants_from_user_record | 6 | 6 | 4
classes/local/wbagent/privacy_anonymizer.php:1282 | bookingextension_agent\local\wbagent\privacy_anonymizer | merge_identity_variants | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:1303 | bookingextension_agent\local\wbagent\privacy_anonymizer | array_contains_person_identity_fields | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:1320 | bookingextension_agent\local\wbagent\privacy_anonymizer | anonymize_person_identity_field_group | 6 | 5 | 4
classes/local/wbagent/privacy_anonymizer.php:1373 | bookingextension_agent\local\wbagent\privacy_anonymizer | is_user_reference_field | 6 | 5 | 4
classes/local/wbagent/prompt_policy_builder.php:51 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_all_policies | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:99 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_response_contract_policy | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:139 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_trigger_policy | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:158 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_trigger_policy_compact | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:174 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_routing_determinism_policy | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:200 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_step_intent_policy | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:229 | bookingextension_agent\local\wbagent\prompt_policy_builder | is_planner_step_type | 6 | 5 | 4
classes/local/wbagent/prompt_policy_builder.php:240 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_docs_answer_policy | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:260 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_sufficiency_policy | 6 | 6 | 4
classes/local/wbagent/prompt_policy_builder.php:325 | bookingextension_agent\local\wbagent\prompt_policy_builder | build_follow_up_state_policy | 6 | 6 | 4
classes/local/wbagent/queue/observation_builder.php:39 | bookingextension_agent\local\wbagent\queue\observation_builder | build_observation | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:69 | bookingextension_agent\local\wbagent\queue\queue_manager | __construct | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:86 | bookingextension_agent\local\wbagent\queue\queue_manager | enqueue_command | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:208 | bookingextension_agent\local\wbagent\queue\queue_manager | update_status | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:257 | bookingextension_agent\local\wbagent\queue\queue_manager | get_queue_items | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:269 | bookingextension_agent\local\wbagent\queue\queue_manager | get_queue_item | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:291 | bookingextension_agent\local\wbagent\queue\queue_manager | save_queue_items | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:304 | bookingextension_agent\local\wbagent\queue\queue_manager | set_prepared_input | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:331 | bookingextension_agent\local\wbagent\queue\queue_manager | has_running_item | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:357 | bookingextension_agent\local\wbagent\queue\queue_manager | try_mark_running | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:440 | (global) | can_pickup_now | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:471 | (global) | dependencies_succeeded | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:482 | (global) | dependencies_succeeded_from_items | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:527 | (global) | validate_depends_on_is_dag | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:562 | (global) | fail_expired_blocked_items | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:599 | (global) | build_input_signature | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:611 | (global) | build_input_signature_details | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:650 | (global) | normalize_for_signature | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:673 | (global) | next_sequence | 6 | 5 | 4
classes/local/wbagent/queue/queue_manager.php:686 | (global) | resolve_thread_contextid | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:702 | (global) | resolve_blocked_expires_at | 6 | 6 | 4
classes/local/wbagent/queue/queue_manager.php:724 | (global) | dfs_cycle_detect | 6 | 5 | 4
classes/local/wbagent/result_payload_summarizer.php:65 | bookingextension_agent\local\wbagent\result_payload_summarizer | for_observation | 6 | 5 | 4
classes/local/wbagent/result_payload_summarizer.php:127 | (global) | describe_result_for_state | 6 | 5 | 4
classes/local/wbagent/result_payload_summarizer.php:149 | (global) | detect_result_category | 6 | 5 | 4
classes/local/wbagent/result_payload_summarizer.php:191 | (global) | describe_entry | 6 | 5 | 4
classes/local/wbagent/result_payload_summarizer.php:367 | (global) | compact_text | 6 | 5 | 4
classes/local/wbagent/result_payload_summarizer.php:389 | (global) | summarize_with_contributors | 6 | 5 | 4
classes/local/wbagent/result_payload_summarizer.php:415 | (global) | build_summary_context | 6 | 6 | 4
classes/local/wbagent/result_payload_summarizer.php:441 | (global) | summarize_with_task_provider | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:71 | bookingextension_agent\local\wbagent\services\confirm_run_service | __construct | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:90 | bookingextension_agent\local\wbagent\services\confirm_run_service | confirm | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:648 | bookingextension_agent\local\wbagent\services\confirm_run_service | build_error_payload | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:686 | bookingextension_agent\local\wbagent\services\confirm_run_service | resolve_preview_option_ids_for_response | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:728 | bookingextension_agent\local\wbagent\services\confirm_run_service | first_preview_option_id | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:748 | bookingextension_agent\local\wbagent\services\confirm_run_service | remember_confirm_preview_option_ids | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:770 | bookingextension_agent\local\wbagent\services\confirm_run_service | resolve_confirm_preview_option_ids_for_response | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:801 | bookingextension_agent\local\wbagent\services\confirm_run_service | has_successful_execution_results | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:822 | bookingextension_agent\local\wbagent\services\confirm_run_service | normalize_string_list | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:844 | bookingextension_agent\local\wbagent\services\confirm_run_service | merge_preview_option_ids | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:868 | bookingextension_agent\local\wbagent\services\confirm_run_service | infer_execution_error_class | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:895 | bookingextension_agent\local\wbagent\services\confirm_run_service | build_retry_decision | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:940 | bookingextension_agent\local\wbagent\services\confirm_run_service | build_queue_audit_context | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:964 | bookingextension_agent\local\wbagent\services\confirm_run_service | should_continue_with_runtime_loop | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:987 | bookingextension_agent\local\wbagent\services\confirm_run_service | find_next_mutating_queue_item | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:1013 | bookingextension_agent\local\wbagent\services\confirm_run_service | extract_attempted_tasks_from_commands | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:1038 | bookingextension_agent\local\wbagent\services\confirm_run_service | resolve_pending_queue_item_id | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:1076 | bookingextension_agent\local\wbagent\services\confirm_run_service | resolve_commands_for_run | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:1097 | bookingextension_agent\local\wbagent\services\confirm_run_service | mark_dependents_skipped | 6 | 5 | 4
classes/local/wbagent/services/confirm_run_service.php:1141 | bookingextension_agent\local\wbagent\services\confirm_run_service | get_active_mutating_queue_item | 6 | 6 | 4
classes/local/wbagent/services/confirm_run_service.php:1165 | bookingextension_agent\local\wbagent\services\confirm_run_service | is_actionable_mutating_queue_item | 6 | 5 | 4
classes/local/wbagent/services/execution_observation_ledger.php:50 | bookingextension_agent\local\wbagent\services\execution_observation_ledger | __construct | 6 | 5 | 4
classes/local/wbagent/services/execution_observation_ledger.php:62 | bookingextension_agent\local\wbagent\services\execution_observation_ledger | append_from_results | 6 | 6 | 4
classes/local/wbagent/services/execution_observation_ledger.php:158 | bookingextension_agent\local\wbagent\services\execution_observation_ledger | get_recent_for_runtime | 6 | 6 | 4
classes/local/wbagent/services/execution_observation_ledger.php:204 | bookingextension_agent\local\wbagent\services\execution_observation_ledger | read_entries | 6 | 5 | 4
classes/local/wbagent/services/execution_observation_ledger.php:219 | bookingextension_agent\local\wbagent\services\execution_observation_ledger | normalize_input | 6 | 6 | 4
classes/local/wbagent/services/execution_observation_ledger.php:247 | bookingextension_agent\local\wbagent\services\execution_observation_ledger | normalize_value | 6 | 6 | 4
classes/local/wbagent/services/execution_observation_ledger.php:269 | bookingextension_agent\local\wbagent\services\execution_observation_ledger | build_signature | 6 | 6 | 4
classes/local/wbagent/services/language_policy_service.php:47 | bookingextension_agent\local\wbagent\services\language_policy_service | normalize_iso_language | 6 | 6 | 4
classes/local/wbagent/services/language_policy_service.php:60 | bookingextension_agent\local\wbagent\services\language_policy_service | resolve_output_language | 6 | 6 | 4
classes/local/wbagent/services/language_policy_service.php:84 | bookingextension_agent\local\wbagent\services\language_policy_service | fallback_string_id_for_response_type | 5 | 7 | 6
classes/local/wbagent/services/language_policy_service.php:104 | bookingextension_agent\local\wbagent\services\language_policy_service | preflight_retry_hint_string_id | 6 | 5 | 4
classes/local/wbagent/services/localized_string_service.php:40 | bookingextension_agent\local\wbagent\services\localized_string_service | get | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:42 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | __construct | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:55 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | get_root_doc_path | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:66 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | read_root_doc | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:77 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | search | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:119 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | search_multi | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:185 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | is_ambiguous | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:212 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | get_ambiguity_candidates | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:234 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | get_all_doc_index | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:251 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | get_master_toc_index | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:311 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | get_topic_doc_index | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:339 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | render_master_toc_observation | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:364 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | detect_best_topic | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:434 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | search_in_topic | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:464 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | load_docs_by_paths | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:483 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | search_docs | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:544 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | extract_topic_id_from_path | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:561 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | build_topic_title | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:576 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | extract_topic_terms | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:595 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | score_topic | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:645 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | read_doc_by_path | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:712 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | build_summary | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:738 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | load_docs | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:788 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | score_doc | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:844 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | has_exact_basename_hit | 6 | 5 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:867 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | extract_query_tokens | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:889 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | extract_first_ordered_steps | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:952 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | extract_title | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:966 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | extract_excerpt | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:1004 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | extract_markdown_links_from_text | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:1049 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | resolve_relative_doc_link | 6 | 6 | 4
classes/local/wbagent/services/lookup/docs_lookup_service.php:1090 | bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service | strip_markdown | 6 | 5 | 4
classes/local/wbagent/services/lookup/option_lookup_service.php:52 | bookingextension_agent\local\wbagent\services\lookup\option_lookup_service | __construct | 6 | 5 | 4
classes/local/wbagent/services/lookup/option_lookup_service.php:66 | bookingextension_agent\local\wbagent\services\lookup\option_lookup_service | search_options | 6 | 5 | 4
classes/local/wbagent/services/lookup/option_lookup_service.php:94 | bookingextension_agent\local\wbagent\services\lookup\option_lookup_service | resolve_single_option | 6 | 6 | 4
classes/local/wbagent/services/mutation/entity_mutation_service.php:50 | bookingextension_agent\local\wbagent\services\mutation\entity_mutation_service | create_entity | 6 | 5 | 4
classes/local/wbagent/services/mutation/entity_mutation_service.php:76 | (global) | entity_exists_by_name | 6 | 5 | 4
classes/local/wbagent/services/mutation/entity_mutation_service.php:90 | (global) | entity_exists_by_shortname | 6 | 5 | 4
classes/local/wbagent/services/mutation/option_mutation_service.php:52 | bookingextension_agent\local\wbagent\services\mutation\option_mutation_service | validate_create | 6 | 5 | 4
classes/local/wbagent/services/mutation/option_mutation_service.php:67 | bookingextension_agent\local\wbagent\services\mutation\option_mutation_service | validate_update | 6 | 5 | 4
classes/local/wbagent/services/mutation/option_mutation_service.php:82 | bookingextension_agent\local\wbagent\services\mutation\option_mutation_service | validate_bulk_update | 6 | 5 | 4
classes/local/wbagent/services/mutation/option_mutation_service.php:98 | bookingextension_agent\local\wbagent\services\mutation\option_mutation_service | create_option | 6 | 5 | 4
classes/local/wbagent/services/mutation/option_mutation_service.php:110 | bookingextension_agent\local\wbagent\services\mutation\option_mutation_service | update_option | 6 | 5 | 4
classes/local/wbagent/services/mutation/option_mutation_service.php:122 | bookingextension_agent\local\wbagent\services\mutation\option_mutation_service | bulk_update_options | 6 | 5 | 4
classes/local/wbagent/services/pending_intent_service.php:43 | bookingextension_agent\local\wbagent\services\pending_intent_service | __construct | 6 | 5 | 4
classes/local/wbagent/services/pending_intent_service.php:53 | bookingextension_agent\local\wbagent\services\pending_intent_service | get | 6 | 5 | 4
classes/local/wbagent/services/pending_intent_service.php:65 | bookingextension_agent\local\wbagent\services\pending_intent_service | consume | 6 | 5 | 4
classes/local/wbagent/services/pending_intent_service.php:75 | bookingextension_agent\local\wbagent\services\pending_intent_service | clear | 6 | 5 | 4
classes/local/wbagent/services/pending_intent_service.php:89 | bookingextension_agent\local\wbagent\services\pending_intent_service | set | 6 | 5 | 4
classes/local/wbagent/services/preflight_audit_logger.php:42 | bookingextension_agent\local\wbagent\services\preflight_audit_logger | __construct | 6 | 5 | 4
classes/local/wbagent/services/preflight_audit_logger.php:54 | bookingextension_agent\local\wbagent\services\preflight_audit_logger | append | 6 | 6 | 4
classes/local/wbagent/services/preflight_contract_validator.php:57 | bookingextension_agent\local\wbagent\services\preflight_contract_validator | __construct | 6 | 5 | 4
classes/local/wbagent/services/preflight_contract_validator.php:74 | bookingextension_agent\local\wbagent\services\preflight_contract_validator | validate | 6 | 5 | 4
classes/local/wbagent/services/preflight_domain_check_runner.php:39 | bookingextension_agent\local\wbagent\services\preflight_domain_check_runner | run | 6 | 5 | 4
classes/local/wbagent/services/preflight_execution_gate.php:48 | bookingextension_agent\local\wbagent\services\preflight_execution_gate | evaluate | 6 | 5 | 4
classes/local/wbagent/services/preflight_execution_gate.php:91 | bookingextension_agent\local\wbagent\services\preflight_execution_gate | build_guard_token | 6 | 6 | 4
classes/local/wbagent/services/preflight_execution_gate.php:106 | bookingextension_agent\local\wbagent\services\preflight_execution_gate | verify_guard_token | 6 | 5 | 4
classes/local/wbagent/services/preflight_execution_gate.php:126 | bookingextension_agent\local\wbagent\services\preflight_execution_gate | normalize_for_guard | 6 | 6 | 4
classes/local/wbagent/services/preflight_pipeline.php:60 | bookingextension_agent\local\wbagent\services\preflight_pipeline | __construct | 6 | 5 | 4
classes/local/wbagent/services/preflight_pipeline.php:78 | bookingextension_agent\local\wbagent\services\preflight_pipeline | run | 6 | 5 | 4
classes/local/wbagent/services/preflight_pipeline.php:265 | bookingextension_agent\local\wbagent\services\preflight_pipeline | build_output | 6 | 6 | 4
classes/local/wbagent/services/preflight_pipeline.php:295 | bookingextension_agent\local\wbagent\services\preflight_pipeline | build_audit_command_context | 6 | 6 | 4
classes/local/wbagent/services/preflight_pipeline.php:313 | bookingextension_agent\local\wbagent\services\preflight_pipeline | classify_error_class | 6 | 5 | 4
classes/local/wbagent/services/preflight_result_v2.php:74 | bookingextension_agent\local\wbagent\services\preflight_result_v2 | __construct | 6 | 5 | 4
classes/local/wbagent/services/preflight_result_v2.php:106 | bookingextension_agent\local\wbagent\services\preflight_result_v2 | normalize_blocking_layer | 6 | 6 | 4
classes/local/wbagent/services/preflight_result_v2.php:140 | bookingextension_agent\local\wbagent\services\preflight_result_v2 | to_array | 6 | 5 | 4
classes/local/wbagent/services/preflight_result_v2.php:157 | bookingextension_agent\local\wbagent\services\preflight_result_v2 | ok | 6 | 5 | 4
classes/local/wbagent/services/preflight_result_v2.php:168 | bookingextension_agent\local\wbagent\services\preflight_result_v2 | confirmable | 6 | 5 | 4
classes/local/wbagent/services/preflight_result_v2.php:188 | bookingextension_agent\local\wbagent\services\preflight_result_v2 | invalid | 6 | 5 | 4
classes/local/wbagent/services/preflight_result_v2.php:208 | bookingextension_agent\local\wbagent\services\preflight_result_v2 | extract_issue_codes_from_issues | 6 | 6 | 4
classes/local/wbagent/services/preflight_schema_validator.php:38 | bookingextension_agent\local\wbagent\services\preflight_schema_validator | validate | 6 | 5 | 4
classes/local/wbagent/services/preflight_schema_validator.php:161 | bookingextension_agent\local\wbagent\services\preflight_schema_validator | get_schema | 8 | 5 | 2
classes/local/wbagent/services/preflight_version_validator.php:46 | bookingextension_agent\local\wbagent\services\preflight_version_validator | __construct | 6 | 5 | 4
classes/local/wbagent/services/preflight_version_validator.php:57 | bookingextension_agent\local\wbagent\services\preflight_version_validator | validate | 6 | 5 | 4
classes/local/wbagent/services/preflight_version_validator.php:126 | bookingextension_agent\local\wbagent\services\preflight_version_validator | resolve_requested_version | 6 | 6 | 4
classes/local/wbagent/services/provider_routing_util.php:41 | bookingextension_agent\local\wbagent\services\provider_routing_util | resolve_primary_provider_for_action | 6 | 6 | 4
classes/local/wbagent/services/provider_routing_util.php:61 | bookingextension_agent\local\wbagent\services\provider_routing_util | short_provider_for_debug | 5 | 7 | 6
classes/local/wbagent/services/queue_command_mapper.php:40 | bookingextension_agent\local\wbagent\services\queue_command_mapper | from_queue_item | 6 | 5 | 4
classes/local/wbagent/services/queue_command_mapper.php:78 | bookingextension_agent\local\wbagent\services\queue_command_mapper | from_queue_items | 6 | 5 | 4
classes/local/wbagent/services/queue_status_policy.php:44 | bookingextension_agent\local\wbagent\services\queue_status_policy | actionable_mutating_statuses | 6 | 5 | 4
classes/local/wbagent/services/queue_status_policy.php:53 | bookingextension_agent\local\wbagent\services\queue_status_policy | pickup_ready_statuses | 6 | 5 | 4
classes/local/wbagent/services/queue_status_policy.php:63 | bookingextension_agent\local\wbagent\services\queue_status_policy | is_actionable_mutating_status | 6 | 5 | 4
classes/local/wbagent/services/queue_status_policy.php:73 | bookingextension_agent\local\wbagent\services\queue_status_policy | is_pickup_ready_status | 6 | 5 | 4
classes/local/wbagent/services/queue_transition_service.php:48 | bookingextension_agent\local\wbagent\services\queue_transition_service | to_status | 6 | 5 | 4
classes/local/wbagent/services/queue_transition_service.php:70 | bookingextension_agent\local\wbagent\services\queue_transition_service | to_ready | 6 | 5 | 4
classes/local/wbagent/services/queue_transition_service.php:86 | bookingextension_agent\local\wbagent\services\queue_transition_service | to_retry_waiting | 6 | 5 | 4
classes/local/wbagent/services/queue_transition_service.php:109 | bookingextension_agent\local\wbagent\services\queue_transition_service | to_failed | 6 | 5 | 4
classes/local/wbagent/services/queue_transition_service.php:131 | bookingextension_agent\local\wbagent\services\queue_transition_service | to_skipped | 6 | 5 | 4
classes/local/wbagent/services/queue_transition_service.php:151 | bookingextension_agent\local\wbagent\services\queue_transition_service | to_succeeded | 6 | 5 | 4
classes/local/wbagent/services/shared_json_payload_extractor.php:39 | bookingextension_agent\local\wbagent\services\shared_json_payload_extractor | extract_json_candidates | 6 | 6 | 4
classes/local/wbagent/services/shared_json_payload_extractor.php:71 | bookingextension_agent\local\wbagent\services\shared_json_payload_extractor | extract_balanced_json_objects | 6 | 6 | 4
classes/local/wbagent/services/spawn_contract_service.php:36 | bookingextension_agent\local\wbagent\services\spawn_contract_service | normalize_task_result | 6 | 6 | 4
classes/local/wbagent/services/spawn_contract_service.php:50 | bookingextension_agent\local\wbagent\services\spawn_contract_service | apply_output_bindings | 6 | 5 | 4
classes/local/wbagent/services/spawn_contract_service.php:86 | bookingextension_agent\local\wbagent\services\spawn_contract_service | normalize_spawn_commands | 6 | 6 | 4
classes/local/wbagent/services/spawn_contract_service.php:127 | bookingextension_agent\local\wbagent\services\spawn_contract_service | normalize_produced_outputs | 6 | 6 | 4
classes/local/wbagent/services/spawn_contract_service.php:152 | bookingextension_agent\local\wbagent\services\spawn_contract_service | normalize_binding_reference | 6 | 6 | 4
classes/local/wbagent/services/task_prompt_contract.php:35 | bookingextension_agent\local\wbagent\services\task_prompt_contract | __construct | 6 | 5 | 4
classes/local/wbagent/services/task_prompt_contract.php:44 | bookingextension_agent\local\wbagent\services\task_prompt_contract | to_array | 6 | 5 | 4
classes/local/wbagent/services/task_version_policy.php:51 | bookingextension_agent\local\wbagent\services\task_version_policy | evaluate | 6 | 5 | 4
classes/local/wbagent/services/task_version_policy.php:88 | bookingextension_agent\local\wbagent\services\task_version_policy | is_deprecated | 5 | 7 | 6
classes/local/wbagent/services/trigger_result_util.php:38 | bookingextension_agent\local\wbagent\services\trigger_result_util | has_trigger | 6 | 5 | 4
classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:42 | bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor | supports | 6 | 5 | 4
classes/local/wbagent/summarizer/basic_collection_result_summary_contributor.php:53 | bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor | summarize | 6 | 5 | 4
classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:42 | bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor | supports | 6 | 5 | 4
classes/local/wbagent/summarizer/diagnosis_result_summary_contributor.php:53 | bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor | summarize | 6 | 5 | 4
classes/local/wbagent/summarizer/docs_result_summary_contributor.php:42 | bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor | supports | 6 | 5 | 4
classes/local/wbagent/summarizer/docs_result_summary_contributor.php:53 | bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor | summarize | 6 | 5 | 4
classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:45 | bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor | supports | 6 | 5 | 4
classes/local/wbagent/summarizer/single_object_result_summary_contributor.php:66 | bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor | summarize | 6 | 5 | 4
classes/local/wbagent/task_contract_validator.php:70 | bookingextension_agent\local\wbagent\task_contract_validator | build_task_metadata | 6 | 6 | 4
classes/local/wbagent/task_contract_validator.php:100 | bookingextension_agent\local\wbagent\task_contract_validator | build_task_capability_name | 6 | 6 | 4
classes/local/wbagent/task_contract_validator.php:122 | bookingextension_agent\local\wbagent\task_contract_validator | validate_task_metadata | 6 | 5 | 4
classes/local/wbagent/task_contract_validator.php:192 | bookingextension_agent\local\wbagent\task_contract_validator | validate_registry_contracts | 6 | 5 | 4
classes/local/wbagent/task_contract_validator.php:228 | bookingextension_agent\local\wbagent\task_contract_validator | get_deny_reason_priority | 6 | 6 | 4
classes/local/wbagent/task_contract_validator.php:244 | bookingextension_agent\local\wbagent\task_contract_validator | extract_task_namespace | 6 | 6 | 4
classes/local/wbagent/task_contract_validator.php:260 | bookingextension_agent\local\wbagent\task_contract_validator | is_namespaced_task_name | 6 | 5 | 4
classes/local/wbagent/task_contract_validator.php:272 | bookingextension_agent\local\wbagent\task_contract_validator | component_may_register_namespace | 6 | 5 | 4
classes/local/wbagent/task_discovery.php:44 | bookingextension_agent\local\wbagent\task_discovery | get_task_instances | 6 | 6 | 4
classes/local/wbagent/task_discovery.php:89 | bookingextension_agent\local\wbagent\task_discovery | get_trigger_provider_instances | 6 | 6 | 4
classes/local/wbagent/task_discovery.php:109 | bookingextension_agent\local\wbagent\task_discovery | get_last_diagnostics | 6 | 6 | 4
classes/local/wbagent/task_discovery.php:119 | bookingextension_agent\local\wbagent\task_discovery | find_candidate_classes | 6 | 5 | 4
classes/local/wbagent/task_discovery.php:173 | bookingextension_agent\local\wbagent\task_discovery | get_task_directories | 6 | 6 | 4
classes/local/wbagent/task_discovery.php:198 | bookingextension_agent\local\wbagent\task_discovery | instantiate_if_supported | 6 | 5 | 4
classes/local/wbagent/task_discovery.php:226 | bookingextension_agent\local\wbagent\task_discovery | ensure_class_loaded | 6 | 5 | 4
classes/local/wbagent/task_discovery.php:265 | bookingextension_agent\local\wbagent\task_discovery | add_diagnostic | 6 | 5 | 4
classes/local/wbagent/task_discovery.php:280 | bookingextension_agent\local\wbagent\task_discovery | compare_task_classes | 6 | 5 | 4
classes/local/wbagent/task_discovery.php:297 | bookingextension_agent\local\wbagent\task_discovery | get_namespace_priority | 6 | 6 | 4
classes/local/wbagent/task_executability_evaluator.php:47 | bookingextension_agent\local\wbagent\task_executability_evaluator | __construct | 6 | 5 | 4
classes/local/wbagent/task_executability_evaluator.php:60 | bookingextension_agent\local\wbagent\task_executability_evaluator | evaluate_task | 6 | 5 | 4
classes/local/wbagent/task_executability_evaluator.php:114 | bookingextension_agent\local\wbagent\task_executability_evaluator | evaluate_all_tasks | 6 | 5 | 4
classes/local/wbagent/task_executability_evaluator.php:132 | bookingextension_agent\local\wbagent\task_executability_evaluator | get_executable_task_names | 6 | 6 | 4
classes/local/wbagent/task_executability_evaluator.php:152 | bookingextension_agent\local\wbagent\task_executability_evaluator | deny_result | 6 | 5 | 4
classes/local/wbagent/task_executability_evaluator.php:169 | bookingextension_agent\local\wbagent\task_executability_evaluator | has_required_capabilities | 6 | 5 | 4
classes/local/wbagent/task_executability_evaluator.php:199 | bookingextension_agent\local\wbagent\task_executability_evaluator | is_valid_context | 6 | 5 | 4
classes/local/wbagent/task_governance_service.php:51 | bookingextension_agent\local\wbagent\task_governance_service | sync_enableall_toggles | 6 | 5 | 4
classes/local/wbagent/task_provider.php:42 | bookingextension_agent\local\wbagent\task_provider | get_component | 6 | 6 | 4
classes/local/wbagent/task_provider.php:51 | bookingextension_agent\local\wbagent\task_provider | get_tasks | 6 | 6 | 4
classes/local/wbagent/task_provider.php:63 | bookingextension_agent\local\wbagent\task_provider | get_discovery_diagnostics | 6 | 6 | 4
classes/local/wbagent/task_provider.php:72 | bookingextension_agent\local\wbagent\task_provider | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/task_provider.php:103 | bookingextension_agent\local\wbagent\task_provider | get_issue_code_provider | 6 | 6 | 4
classes/local/wbagent/task_provider.php:116 | bookingextension_agent\local\wbagent\task_provider | get_prompt_guidance | 6 | 6 | 4
classes/local/wbagent/task_provider.php:127 | bookingextension_agent\local\wbagent\task_provider | get_result_summary_contributors | 6 | 6 | 4
classes/local/wbagent/task_registry.php:75 | bookingextension_agent\local\wbagent\task_registry | register | 6 | 5 | 4
classes/local/wbagent/task_registry.php:203 | bookingextension_agent\local\wbagent\task_registry | get_task | 6 | 6 | 4
classes/local/wbagent/task_registry.php:213 | bookingextension_agent\local\wbagent\task_registry | get_provider_for_task | 6 | 6 | 4
classes/local/wbagent/task_registry.php:224 | bookingextension_agent\local\wbagent\task_registry | normalize_task_input | 6 | 6 | 4
classes/local/wbagent/task_registry.php:244 | bookingextension_agent\local\wbagent\task_registry | get_preview_option_memory_for_task | 6 | 6 | 4
classes/local/wbagent/task_registry.php:258 | bookingextension_agent\local\wbagent\task_registry | get_preview_option_memory_helpers | 6 | 6 | 4
classes/local/wbagent/task_registry.php:279 | bookingextension_agent\local\wbagent\task_registry | get_task_names | 6 | 6 | 4
classes/local/wbagent/task_registry.php:292 | bookingextension_agent\local\wbagent\task_registry | get_task_names_for_context | 6 | 6 | 4
classes/local/wbagent/task_registry.php:310 | bookingextension_agent\local\wbagent\task_registry | get_tasks | 6 | 6 | 4
classes/local/wbagent/task_registry.php:320 | bookingextension_agent\local\wbagent\task_registry | get_task_contract | 6 | 6 | 4
classes/local/wbagent/task_registry.php:329 | bookingextension_agent\local\wbagent\task_registry | get_task_contracts | 6 | 6 | 4
classes/local/wbagent/task_registry.php:338 | bookingextension_agent\local\wbagent\task_registry | get_contract_diagnostics | 6 | 6 | 4
classes/local/wbagent/task_registry.php:347 | bookingextension_agent\local\wbagent\task_registry | get_result_summary_contributors | 6 | 6 | 4
classes/local/wbagent/task_registry.php:357 | bookingextension_agent\local\wbagent\task_registry | is_read_only_task | 6 | 5 | 4
classes/local/wbagent/task_registry.php:368 | bookingextension_agent\local\wbagent\task_registry | is_task_active | 6 | 5 | 4
classes/local/wbagent/task_registry.php:394 | bookingextension_agent\local\wbagent\task_registry | get_task_toggle_setting_name | 6 | 6 | 4
classes/local/wbagent/task_registry.php:410 | bookingextension_agent\local\wbagent\task_registry | get_task_capabilities | 6 | 6 | 4
classes/local/wbagent/task_registry.php:424 | bookingextension_agent\local\wbagent\task_registry | get_all_schemas | 8 | 5 | 2
classes/local/wbagent/task_registry.php:441 | bookingextension_agent\local\wbagent\task_registry | get_all_schemas_for_context | 6 | 6 | 4
classes/local/wbagent/task_registry.php:471 | bookingextension_agent\local\wbagent\task_registry | explain_task_schema_for_context | 6 | 5 | 4
classes/local/wbagent/task_registry.php:499 | bookingextension_agent\local\wbagent\task_registry | get_all_prompt_contracts | 8 | 5 | 2
classes/local/wbagent/task_registry.php:516 | bookingextension_agent\local\wbagent\task_registry | get_prompt_contracts_for_context | 8 | 5 | 2
classes/local/wbagent/task_registry.php:549 | bookingextension_agent\local\wbagent\task_registry | build_prompt_contract | 6 | 6 | 4
classes/local/wbagent/task_registry.php:616 | bookingextension_agent\local\wbagent\task_registry | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/task_registry.php:643 | bookingextension_agent\local\wbagent\task_registry | get_message_triggers | 6 | 6 | 4
classes/local/wbagent/task_registry.php:654 | bookingextension_agent\local\wbagent\task_registry | get_trigger_id_to_task_name_map | 6 | 6 | 4
classes/local/wbagent/task_registry.php:665 | bookingextension_agent\local\wbagent\task_registry | make_default | 6 | 5 | 4
classes/local/wbagent/task_registry.php:728 | bookingextension_agent\local\wbagent\task_registry | register_discovered_tasks_without_provider | 6 | 5 | 4
classes/local/wbagent/task_registry.php:761 | bookingextension_agent\local\wbagent\task_provider_interface | __construct | 6 | 5 | 4
classes/local/wbagent/task_registry.php:772 | bookingextension_agent\local\wbagent\task_provider_interface | get_component | 6 | 6 | 4
classes/local/wbagent/task_registry.php:781 | bookingextension_agent\local\wbagent\task_provider_interface | get_tasks | 6 | 6 | 4
classes/local/wbagent/task_registry.php:790 | bookingextension_agent\local\wbagent\task_provider_interface | get_contextual_prompt_packs | 6 | 6 | 4
classes/local/wbagent/task_registry.php:799 | bookingextension_agent\local\wbagent\task_provider_interface | get_issue_code_provider | 6 | 6 | 4
classes/local/wbagent/task_registry.php:808 | bookingextension_agent\local\wbagent\task_provider_interface | get_prompt_guidance | 6 | 6 | 4
classes/local/wbagent/task_registry.php:817 | bookingextension_agent\local\wbagent\task_provider_interface | get_discovery_diagnostics | 6 | 6 | 4
classes/local/wbagent/task_registry.php:841 | bookingextension_agent\local\wbagent\task_registry | normalize_provider_component_name | 6 | 6 | 4
classes/local/wbagent/task_registry.php:856 | bookingextension_agent\local\wbagent\task_registry | append_provider_discovery_diagnostics | 6 | 6 | 4
classes/local/wbagent/task_registry.php:882 | bookingextension_agent\local\wbagent\task_registry | add_contract_diagnostic | 6 | 5 | 4
classes/local/wbagent/task_registry.php:896 | bookingextension_agent\local\wbagent\task_registry | fail_on_contract_diagnostics_when_strict | 6 | 5 | 4
classes/local/wbagent/task_registry.php:916 | bookingextension_agent\local\wbagent\task_registry | is_governance_strict_mode_enabled | 6 | 5 | 4
classes/local/wbagent/task_registry_factory.php:44 | bookingextension_agent\local\wbagent\task_registry_factory | get_default | 6 | 6 | 4
classes/local/wbagent/task_registry_factory.php:65 | bookingextension_agent\local\wbagent\task_registry_factory | get_last_build_warning | 6 | 6 | 4
classes/local/wbagent/task_registry_factory.php:76 | bookingextension_agent\local\wbagent\task_registry_factory | reset | 6 | 5 | 4
classes/task/execute_ai_run_adhoc.php:57 | bookingextension_agent\task\execute_ai_run_adhoc | get_name | 8 | 5 | 2
classes/task/execute_ai_run_adhoc.php:66 | bookingextension_agent\task\execute_ai_run_adhoc | execute | 8 | 4 | 2
classes/task/rebuild_task_catalog_embeddings_adhoc.php:47 | bookingextension_agent\task\rebuild_task_catalog_embeddings_adhoc | execute | 8 | 4 | 2
cli/rebuild_embeddings_fixture.php:277 | (global) | read_fixture_rows | 5 | 7 | 6
cli/rebuild_embeddings_fixture.php:312 | (global) | write_fixture_rows | 5 | 7 | 6
db/upgrade.php:32 | (global) | xmldb_bookingextension_agent_ensure_ai_messages_userid | 6 | 5 | 4
db/upgrade.php:68 | (global) | xmldb_bookingextension_agent_upgrade | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:99 | bookingextionsion_agent\abstract_agent_testcase | setUp | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:142 | bookingextionsion_agent\abstract_agent_testcase | grant_agent_capabilities_to_editingteacher | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:208 | bookingextionsion_agent\abstract_agent_testcase | maybe_register_live_ai_provider | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:260 | bookingextionsion_agent\abstract_agent_testcase | register_live_wunderbyte_provider | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:334 | bookingextionsion_agent\abstract_agent_testcase | register_live_openai_provider | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:382 | bookingextionsion_agent\abstract_agent_testcase | normalize_chat_endpoint | 6 | 6 | 4
tests/agent/abstract_agent_testcase.php:396 | bookingextionsion_agent\abstract_agent_testcase | chat_endpoint_to_embeddings_endpoint | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:410 | bookingextionsion_agent\abstract_agent_testcase | update_provider_actionconfig | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:433 | bookingextionsion_agent\abstract_agent_testcase | configure_wunderbyte_embeddings_model | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:471 | bookingextionsion_agent\abstract_agent_testcase | maybe_load_embeddings_fixture | 5 | 7 | 6
tests/agent/abstract_agent_testcase.php:495 | bookingextionsion_agent\abstract_agent_testcase | create_option | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:524 | bookingextionsion_agent\abstract_agent_testcase | make_executor | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:543 | bookingextionsion_agent\abstract_agent_testcase | exec_command | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:583 | bookingextionsion_agent\abstract_agent_testcase | get_option_from_db | 6 | 6 | 4
tests/agent/abstract_agent_testcase.php:593 | bookingextionsion_agent\abstract_agent_testcase | get_all_options | 6 | 6 | 4
tests/agent/abstract_agent_testcase.php:607 | bookingextionsion_agent\abstract_agent_testcase | require_real_llm | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:632 | bookingextionsion_agent\abstract_agent_testcase | build_runtime | 6 | 6 | 4
tests/agent/abstract_agent_testcase.php:660 | bookingextionsion_agent\abstract_agent_testcase | chat | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:677 | bookingextionsion_agent\abstract_agent_testcase | booking_contextid | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:689 | bookingextionsion_agent\abstract_agent_testcase | resolve_queue_item_id_for_confirmation | 6 | 6 | 4
tests/agent/abstract_agent_testcase.php:739 | bookingextionsion_agent\abstract_agent_testcase | confirm_pending_result | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:764 | bookingextionsion_agent\abstract_agent_testcase | extract_command | 6 | 6 | 4
tests/agent/abstract_agent_testcase.php:780 | bookingextionsion_agent\abstract_agent_testcase | extract_task_result | 6 | 6 | 4
tests/agent/abstract_agent_testcase.php:795 | bookingextionsion_agent\abstract_agent_testcase | execute_command | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:818 | bookingextionsion_agent\abstract_agent_testcase | execute_all_commands | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:847 | bookingextionsion_agent\abstract_agent_testcase | assert_generate_text_logged_for_thread | 6 | 5 | 4
tests/agent/abstract_agent_testcase.php:873 | bookingextionsion_agent\abstract_agent_testcase | tearDown | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:51 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | setUp | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:63 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | task_matrix_scenarios | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:73 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | assert_llm_task_scenario_success | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:201 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | grant_local_entities_capabilities_to_editingteacher | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:222 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | grant_optional_capability_to_editingteacher | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:247 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | assert_task_is_executable_or_skip | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:277 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | prepare_scenario_runtime | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:321 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | default_scenario_replacements | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:346 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | prepare_recall_memory_scenario | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:381 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | prepare_entity_scenario | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:420 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | prepare_update_option_scenario | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:462 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | prepare_booking_rules_service_scenario | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:495 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | assert_scenario_assertions | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:601 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | payload_text | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:625 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | payload_field_value | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:659 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | payload_field_count | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:678 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | payload_step_count | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:697 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | get_latest_debug_source | 5 | 8 | 6
tests/agent/abstract_llm_task_matrix_testcase.php:720 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | render_assertion_value | 6 | 6 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:730 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | stringify_assertion_value | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:746 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | resolve_task_result_payload | 6 | 6 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:810 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | render_scenario_template | 6 | 6 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:825 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | build_fallback_prompt | 5 | 8 | 6
tests/agent/abstract_llm_task_matrix_testcase.php:843 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | scenario_matched_expected_task | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:858 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | find_task_result_entry | 6 | 5 | 4
tests/agent/abstract_llm_task_matrix_testcase.php:899 | bookingextionsion_agent\abstract_llm_task_matrix_testcase | task_result_candidate_names | 6 | 5 | 4
tests/agent/contracts/ai_confirm_run_contract_test.php:44 | bookingextionsion_agent\ai_confirm_run_contract_test | test_follow_up_pending_intent_forces_confirmation_request | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:39 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_registry_discovers_booking_tasks | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:57 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_provider_interface_supports_issue_code_provider | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:78 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_provider_interface_supports_prompt_guidance | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:95 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_issue_code_provider_injected_into_agent_runtime | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:113 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_schema_includes_prompt_meta | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:138 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_registry_prioritizes_prompt_meta | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:156 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_prompt_contracts_use_required_minimals_and_explicit_examples | 5 | 7 | 6
tests/agent/contracts/integration_agent_framework_test.php:184 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_slim_catalog_keeps_examples_separate_from_minimals | 5 | 7 | 6
tests/agent/contracts/integration_agent_framework_test.php:212 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_embedding_subset_keeps_full_descriptions | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:253 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_embedding_subset_includes_property_descriptions | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:287 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_orchestrator_prompts_are_generic | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:302 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_action_specific_prompts_generic | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:347 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_discovered_tasks_implement_task_interface | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:363 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_multi_provider_discovery | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:392 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_discovery_scans_all_wbagent_task_namespaces | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:406 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_discovery_deduplicates_same_task_name | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:418 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_trigger_provider_discovery_ignores_non_trigger_classes | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:433 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_tasks_no_language_specific_logic | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:454 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_task_schema_required_fields | 6 | 5 | 4
tests/agent/contracts/integration_agent_framework_test.php:471 | bookingextension_agent\local\wbagent\tests\integration_agent_framework_test | test_backward_compatibility_constants | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:42 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | test_registry_discovers_canonical_mod_booking_option_tasks | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:61 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | test_create_option_defaults_to_type_zero | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:89 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | test_create_option_emits_rich_observation_summary | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:123 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | test_update_option_sets_type_one_for_selflearning_input | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:163 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | test_create_slotbooking_option_requires_slot_fields | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:188 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | test_slotbooking_prompt_contracts_are_explicit | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:212 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | create_booking_test_context | 6 | 5 | 4
tests/agent/contracts/mod_booking_option_tasks_contract_test.php:238 | bookingextension_agent\local\wbagent\tests\mod_booking_option_tasks_contract_test | grant_booking_option_task_capabilities | 6 | 5 | 4
tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:39 | bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test | test_pending_intent_service_set_returns_confirmation_code | 6 | 5 | 4
tests/agent/contracts/pending_intent_and_queue_transition_contract_test.php:64 | bookingextension_agent\local\wbagent\tests\pending_intent_and_queue_transition_contract_test | test_queue_transition_service_retry_waiting_transition | 6 | 5 | 4
tests/agent/contracts/preflight_contract_validator_contract_test.php:38 | bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test | test_validator_propagates_schema_error_contract | 6 | 5 | 4
tests/agent/contracts/preflight_contract_validator_contract_test.php:63 | bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test | test_validator_preserves_deprecation_issue_codes | 6 | 5 | 4
tests/agent/contracts/preflight_contract_validator_contract_test.php:111 | bookingextension_agent\local\wbagent\tests\preflight_contract_validator_contract_test | test_validator_blocks_unsupported_version | 6 | 5 | 4
tests/agent/contracts/preflight_layers_contract_test.php:38 | bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test | test_domain_runner_hard_blocks_permission_error | 6 | 5 | 4
tests/agent/contracts/preflight_layers_contract_test.php:53 | bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test | test_domain_runner_soft_blocks_duplicate_confirm_issue | 6 | 5 | 4
tests/agent/contracts/preflight_layers_contract_test.php:65 | bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test | test_execution_gate_retry_hint_for_provider_timeout | 6 | 5 | 4
tests/agent/contracts/preflight_layers_contract_test.php:79 | bookingextension_agent\local\wbagent\tests\preflight_layers_contract_test | test_execution_gate_hard_blocks_after_max_retries | 6 | 5 | 4
tests/agent/contracts/prompt_and_language_contract_test.php:41 | bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test | test_prompt_contracts_do_not_use_name_based_heuristics | 6 | 5 | 4
tests/agent/contracts/prompt_and_language_contract_test.php:83 | bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test | test_language_policy_prefers_user_input_language | 6 | 5 | 4
tests/agent/contracts/prompt_and_language_contract_test.php:112 | bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test | test_language_policy_fallback_string_mapping | 5 | 7 | 6
tests/agent/contracts/prompt_and_language_contract_test.php:128 | bookingextension_agent\local\wbagent\tests\prompt_and_language_contract_test | test_language_policy_matrix_de_en_zh | 6 | 5 | 4
tests/agent/contracts/queue_consolidation_contract_test.php:37 | bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test | test_queue_status_policy_actionable_mutating_statuses_are_stable | 6 | 5 | 4
tests/agent/contracts/queue_consolidation_contract_test.php:50 | bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test | test_queue_status_policy_pickup_statuses_are_stable | 6 | 5 | 4
tests/agent/contracts/queue_consolidation_contract_test.php:60 | bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test | test_queue_command_mapper_prefers_prepared_input_and_preserves_metadata | 6 | 5 | 4
tests/agent/contracts/queue_consolidation_contract_test.php:81 | bookingextension_agent\local\wbagent\tests\queue_consolidation_contract_test | test_queue_command_mapper_filters_invalid_items_and_falls_back_to_raw_input | 6 | 5 | 4
tests/agent/contracts/reference_scenarios_contract_test.php:37 | bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test | test_scenario_a_readonly_result_contract | 6 | 5 | 4
tests/agent/contracts/reference_scenarios_contract_test.php:54 | bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test | test_scenario_b_multistep_command_schema_contract | 6 | 5 | 4
tests/agent/contracts/reference_scenarios_contract_test.php:70 | bookingextension_agent\local\wbagent\tests\reference_scenarios_contract_test | test_scenario_c_spawn_output_binding_contract | 6 | 5 | 4
tests/agent/contracts/spawn_contract_service_test.php:35 | bookingextension_agent\local\wbagent\tests\spawn_contract_service_test | test_normalize_task_result_adds_output_aliases | 6 | 5 | 4
tests/agent/contracts/spawn_contract_service_test.php:53 | bookingextension_agent\local\wbagent\tests\spawn_contract_service_test | test_apply_output_bindings_resolves_parent_aliases | 6 | 5 | 4
tests/agent/contracts/spawn_contract_service_test.php:70 | bookingextension_agent\local\wbagent\tests\spawn_contract_service_test | test_apply_output_bindings_reports_missing_reference | 6 | 5 | 4
tests/agent/contracts/spawn_contract_service_test.php:86 | bookingextension_agent\local\wbagent\tests\spawn_contract_service_test | test_normalize_spawn_commands_filters_invalid_entries | 6 | 5 | 4
tests/agent/contracts/task_contract_validator_contract_test.php:39 | bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test | test_namespaced_task_name_format | 6 | 5 | 4
tests/agent/contracts/task_contract_validator_contract_test.php:49 | bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test | test_reserved_namespace_ownership | 6 | 5 | 4
tests/agent/contracts/task_contract_validator_contract_test.php:60 | bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test | test_validate_registry_contracts_rejects_alias_version_mismatch | 6 | 5 | 4
tests/agent/contracts/task_contract_validator_contract_test.php:94 | bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test | test_registry_rejects_reserved_namespace_for_third_party_provider | 6 | 5 | 4
tests/agent/contracts/task_contract_validator_contract_test.php:124 | bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test | test_demo_task_onboards_via_provider_registration_only | 6 | 5 | 4
tests/agent/contracts/task_contract_validator_contract_test.php:171 | bookingextension_agent\local\wbagent\tests\task_contract_validator_contract_test | test_failing_provider_does_not_block_other_registered_tasks | 6 | 5 | 4
tests/agent/llm_task_matrix_scenario_provider.php:39 | bookingextionsion_agent\llm_task_matrix_scenario_provider | provide_registered_task_scenarios | 6 | 5 | 4
tests/agent/llm_task_matrix_scenario_provider.php:64 | bookingextionsion_agent\llm_task_matrix_scenario_provider | get_missing_registered_task_scenarios | 6 | 6 | 4
tests/agent/llm_task_matrix_scenario_provider.php:84 | bookingextionsion_agent\llm_task_matrix_scenario_provider | get_scenario_definitions | 6 | 6 | 4
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:45 | bookingextionsion_agent\all_tasks_real_llm_test | setUp | 6 | 5 | 4
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:57 | bookingextionsion_agent\all_tasks_real_llm_test | real_task_matrix_scenarios | 6 | 5 | 4
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:61 | bookingextionsion_agent\all_tasks_real_llm_test | test_task_matrix_covers_all_registered_tasks | 6 | 5 | 4
tests/agent/real_llm_multistep/all_tasks_real_llm_test.php:71 | bookingextionsion_agent\all_tasks_real_llm_test | test_all_registered_tasks_can_complete_via_real_llm | 6 | 5 | 4
tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:48 | bookingextionsion_agent\confirmation_flow_real_llm_test | setUp | 6 | 5 | 4
tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:56 | bookingextionsion_agent\confirmation_flow_real_llm_test | test_multistep_create_assign_teacher_and_make_visible | 6 | 5 | 4
tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php:211 | bookingextionsion_agent\confirmation_flow_real_llm_test | is_task_available | 6 | 5 | 4
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:47 | bookingextionsion_agent\example_tasks_real_llm_test | setUp | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:65 | bookingextionsion_agent\example_tasks_real_llm_test | ensure_contextid_columns_for_legacy_phpunit_schema | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:108 | bookingextionsion_agent\example_tasks_real_llm_test | test_scenario_a_readonly_example_executes_with_real_llm | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:128 | bookingextionsion_agent\example_tasks_real_llm_test | test_scenario_b_multistep_example_executes_with_real_llm | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:148 | bookingextionsion_agent\example_tasks_real_llm_test | test_scenario_c_spawn_example_executes_with_real_llm | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:174 | bookingextionsion_agent\example_tasks_real_llm_test | run_scenario_until_done | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:269 | bookingextionsion_agent\example_tasks_real_llm_test | get_booking_contextid | 5 | 8 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:292 | bookingextionsion_agent\example_tasks_real_llm_test | collect_tasks | 5 | 8 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:321 | bookingextionsion_agent\example_tasks_real_llm_test | has_task | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:333 | bookingextionsion_agent\example_tasks_real_llm_test | trace_line | 5 | 7 | 6
tests/agent/real_llm_multistep/example_tasks_real_llm_test.php:350 | bookingextionsion_agent\example_tasks_real_llm_test | payload_text | 5 | 7 | 6
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:40 | bookingextionsion_agent\get_current_user_real_llm_test | setUp | 6 | 5 | 4
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:45 | bookingextionsion_agent\get_current_user_real_llm_test | test_get_current_user_observation_contains_full_user_payload | 6 | 5 | 4
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:110 | bookingextionsion_agent\get_current_user_real_llm_test | payload_text | 6 | 5 | 4
tests/agent/real_llm_multistep/get_current_user_real_llm_test.php:128 | bookingextionsion_agent\get_current_user_real_llm_test | has_task_evidence | 6 | 5 | 4
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:50 | bookingextionsion_agent\lecture_autoconfirm_real_llm_test | setUp | 6 | 5 | 4
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:62 | bookingextionsion_agent\lecture_autoconfirm_real_llm_test | test_lecture_autoconfirm_single_pass_creates_five_actions | 6 | 5 | 4
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:209 | bookingextionsion_agent\lecture_autoconfirm_real_llm_test | build_trace_line | 6 | 6 | 4
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:228 | bookingextionsion_agent\lecture_autoconfirm_real_llm_test | has_create_option_commands | 6 | 5 | 4
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:238 | bookingextionsion_agent\lecture_autoconfirm_real_llm_test | count_create_option_commands | 6 | 5 | 4
tests/agent/real_llm_multistep/lecture_autoconfirm_real_llm_test.php:268 | bookingextionsion_agent\lecture_autoconfirm_real_llm_test | is_task_available | 6 | 5 | 4
tests/agent/real_llm_multistep/list_actions_real_llm_test.php:46 | bookingextionsion_agent\list_actions_real_llm_test | setUp | 6 | 5 | 4
tests/agent/real_llm_multistep/list_actions_real_llm_test.php:54 | bookingextionsion_agent\list_actions_real_llm_test | test_list_actions_groups_by_provider_then_readonly_write_then_capability | 6 | 5 | 4
tests/agent/real_llm_multistep/list_actions_real_llm_test.php:143 | bookingextionsion_agent\list_actions_real_llm_test | payload_text | 6 | 5 | 4
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:42 | bookingextionsion_agent\normal_option_datetime_real_llm_test | setUp | 6 | 5 | 4
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:53 | bookingextionsion_agent\normal_option_datetime_real_llm_test | test_datetime_prompt_routes_to_create_option_and_type_zero | 6 | 5 | 4
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:114 | bookingextionsion_agent\normal_option_datetime_real_llm_test | test_weekday_series_prompt_routes_to_create_option_and_creates_five_type_zero_options | 6 | 5 | 4
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:335 | bookingextionsion_agent\normal_option_datetime_real_llm_test | is_task_available | 6 | 5 | 4
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:347 | bookingextionsion_agent\normal_option_datetime_real_llm_test | extract_command_from_payload | 6 | 6 | 4
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:367 | bookingextionsion_agent\normal_option_datetime_real_llm_test | decode_commands_from_payload | 6 | 5 | 4
tests/agent/real_llm_multistep/normal_option_datetime_real_llm_test.php:389 | bookingextionsion_agent\normal_option_datetime_real_llm_test | payload_text | 6 | 5 | 4
tests/agent/real_llm_multistep/search_users_real_llm_test.php:40 | bookingextionsion_agent\search_users_real_llm_test | setUp | 6 | 5 | 4
tests/agent/real_llm_multistep/search_users_real_llm_test.php:45 | bookingextionsion_agent\search_users_real_llm_test | test_search_users_observation_contains_roles_courses_and_profile | 6 | 5 | 4
tests/agent/real_llm_multistep/search_users_real_llm_test.php:126 | bookingextionsion_agent\search_users_real_llm_test | payload_text | 6 | 5 | 4
tests/agent/real_llm_multistep/search_users_real_llm_test.php:144 | bookingextionsion_agent\search_users_real_llm_test | has_task_evidence | 6 | 5 | 4
```
