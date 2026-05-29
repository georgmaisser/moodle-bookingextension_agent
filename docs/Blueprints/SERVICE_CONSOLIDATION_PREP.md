# Service Consolidation Prep (Radikale Reduktion)

## Ziel
Massive Reduktion der Service- und Utility-Duplikate im bookingextension/agent-Plugin bei gleichbleibendem Agent-Konzept:
- deterministisches Routing
- preflight-first fuer Mutationen
- queue-backed confirmation flow
- keine task-spezifische Heuristik im Framework

## Vollstaendig gescannter Scope
Scan-Basis: alle Klassen unter classes/local/wbagent, inkl. services-Unterordner und service-aehnliche Klassen ausserhalb.

Besonders relevante service-nahe Klassen:
- classes/local/wbagent/agent_runtime.php
- classes/local/wbagent/agent_decision_service.php
- classes/local/wbagent/orchestrator.php
- classes/local/wbagent/interpreter.php
- classes/local/wbagent/services/confirm_run_service.php
- classes/local/wbagent/execution_feedback_service.php
- classes/local/wbagent/queue/queue_manager.php
- classes/local/wbagent/conversation_store.php
- classes/local/wbagent/planner_service.php
- classes/local/wbagent/llm_call_service.php
- classes/local/wbagent/task_registry.php
- classes/local/wbagent/task_executability_evaluator.php
- classes/local/wbagent/adaptive_task_catalog_service.php
- classes/local/wbagent/embeddings_readiness_service.php
- classes/local/wbagent/embeddings_catalog_builder_service.php
- classes/local/wbagent/embeddings_retrieval_service.php
- classes/local/wbagent/services/preflight_*.php
- classes/local/wbagent/services/lookup/*.php
- classes/local/wbagent/services/mutation/*.php

## Hotspots nach Groesse (Reduktionshebel)
- agent_runtime.php (3228)
- agent_decision_service.php (2440)
- orchestrator.php (2205)
- interpreter.php (1291)
- services/confirm_run_service.php (1211)
- execution_feedback_service.php (1161)
- services/lookup/docs_lookup_service.php (1095)

## Harte Duplikate (direkt konsolidierbar)

### 1) JSON-Kandidatenextraktion (nahezu identischer Code)
- execution_feedback_service::extract_json_candidates
- interpreter::extract_json_candidates
- execution_feedback_service::extract_balanced_json_objects
- interpreter::extract_balanced_json_objects

Ziel: shared_json_payload_extractor (ein Utility, eine Wahrheit).

### 2) Provider-Resolver und Provider-Shortcode
- execution_feedback_service::resolve_primary_provider_for_action
- orchestrator::resolve_primary_provider_for_action
- execution_feedback_service::short_provider_for_debug
- orchestrator::short_provider_for_debug

Ziel: provider_routing_util.

### 3) Lokalisierungshelper (gleich/fast gleich)
- agent_runtime::localized_string
- agent_decision_service::localized_string
- execution_feedback_service::localized_string
- executor::localized_string

Ziel: localized_string_service mit expliziter Sprachuebergabe.

### 4) Trigger-Pruefung auf used_triggers
- agent_runtime::result_has_trigger
- agent_decision_service::result_has_trigger

Ziel: trigger_result_util::has_trigger.

## Semantisch sehr aehnliche Logik (zu vereinheitlichen)

### 5) Queue-Item -> Command Mapping
- services/confirm_run_service.php
- agent_decision_service::build_commands_from_pending_queue

Heute: beide bauen task/version/input/depends_on aus queue item; Regeln unterscheiden sich leicht.

Ziel:
- queue_command_mapper::from_queue_item()
- queue_command_mapper::from_queue_items()

### 6) Actionable Queue Status Regeln
- confirm_run_service nutzt ACTIVE_MUTATING_STATUSES
- agent_decision_service hat eigene Status-Whitelist
- queue_manager hat weitere statusbezogene Gates

Ziel: queue_status_policy als einzige Quelle fuer aktive/pickup-faehige Mutationsstatus.

### 7) Sprachauflosung / Fallback
- language_policy_service ist bereits vorhanden
- agent_runtime kapselt teils nur Wrapper auf language_policy_service

Ziel: agent_runtime/decision/orchestrator nutzen direkt language_policy_service; lokale Wrapper entfernen.

### 8) Follow-up Suggestion Limit/Erzeugung
- execution_feedback_service und executor haben jeweils follow-up limit / suggestion logic

Ziel: follow_up_suggestion_service mit zwei Modi:
- post-execution suggestions
- narration-friendly suggestions

### 9) Preflight-Version/Schema Policy-Zersplitterung
- preflight_schema_validator
- preflight_version_validator
- task_version_policy

Ziel: preflight_contract_validator (schema + version + deprecation) als ein konsolidierter Einstiegspunkt.

### 10) Catalog/Embedding Auswahlpfad
- adaptive_task_catalog_service
- embeddings_readiness_service
- embeddings_catalog_builder_service
- embeddings_retrieval_service

Ziel: catalog_selection_service (Fassade) mit klaren Subkomponenten:
- readiness
- retrieval
- rebuild scheduling
- fallback selection

## Zielarchitektur fuer radikale Reduktion

### A) Shared Utilities Layer (neu)
- localized_string_service
- shared_json_payload_extractor
- provider_routing_util
- trigger_result_util
- queue_status_policy

### B) Queue Consolidation Layer (neu)
- queue_command_mapper
- pending_intent_service
- queue_transition_service (ready/retry_waiting/failed/skipped)

### C) Catalog Fassade (neu)
- catalog_selection_service als einziger Orchestrator-Einstieg

### D) Preflight Fassade (neu)
- preflight_contract_validator als ein Einstieg fuer L1

## Was explizit nicht gebrochen werden darf
- Keine task-spezifischen Feldheuristiken im Framework.
- Mutationen bleiben preflight-first + confirmation-gated.
- Session-confirmation Scope bleibt user + contextid.
- Queue bleibt authoritative fuer mutating flows.
- Antwortsprache bleibt durch language policy determiniert.

## Umsetzungsreihenfolge (sicher, aber radikal)

1. Shared Utilities einfuehren und intern adaptieren (ohne Verhaltensaenderung)
- localized_string_service
- shared_json_payload_extractor
- provider_routing_util
- trigger_result_util

2. Queue Mapping/Status zentralisieren
- queue_status_policy
- queue_command_mapper
- alle Command-Mappings in decision + confirm auf Mapper umstellen

3. Pending Intent + Queue Transition konsolidieren
- pending_intent_service
- queue_transition_service

4. Preflight L1 konsolidieren
- schema/version/policy in preflight_contract_validator zusammenfassen

5. Catalog/Embedding Fassade bilden
- orchestrator greift nur noch ueber catalog_selection_service zu

6. Shrink Phase
- duplizierte private helper entfernen
- tote Pfade entfernen
- method signatures vereinheitlichen

## Erwarteter Reduktionseffekt
- Deutliche Reduktion duplizierter Helper in den groessten Dateien.
- Hoher Nettoeffekt in agent_runtime, agent_decision_service, orchestrator, confirm_run_service.
- Bessere Lesbarkeit durch klare Verantwortungsgrenzen statt verteilter Mikro-Utilities.

## Konkrete Starttickets (direkt umsetzbar)

- T1: shared_json_payload_extractor einziehen und in interpreter + execution_feedback_service verdrahten.
- T2: provider_routing_util einziehen und in orchestrator + execution_feedback_service verdrahten.
- T3: localized_string_service einziehen und localized_string-Helper in runtime/decision/executor/feedback entfernen.
- T4: queue_status_policy + queue_command_mapper einziehen; decision + confirm_run auf einen Mapper umstellen.
- T5: preflight_contract_validator erstellen; preflight_pipeline auf neuen Einstieg umstellen.

## Risiko- und Regressionspunkte
- response_type- und issue_code-Vertraege duerfen nicht aufweichen.
- pending_confirmation_code und queue_item_id muessen in allen Pfaden stabil bleiben.
- retry_waiting/backoff Metadaten duerfen nicht regressieren.
- language fallback Reihenfolge darf sich nur bewusst aendern.

## Teststrategie fuer die Konsolidierung
- Contract-Tests fuer response payloads (decision + confirm).
- Queue-State-Transition Tests (blocked_confirmation, retry_waiting, skipped).
- Preflight L1/L2/L3 Contract-Tests.
- Snapshot-Tests fuer JSON extraction edge-cases (plain, fenced, mixed text).
- Regressionstests fuer language policy bei error/confirmation/sufficient.
