# Non-Service Consolidation Prep (Radikale Reduktion)

## Ziel
Massive Reduktion der Duplikate in allen restlichen Klassen ausserhalb des bereits geplanten Service-Scope, bei unveraendertem Agent-Gesamtkonzept:
- deterministisches Routing
- preflight-first fuer Mutationen
- queue-backed confirmation flow
- keine task-spezifische Heuristik im Framework

## Abgrenzung zu SERVICE_CONSOLIDATION_PREP
Dieses Dokument deckt explizit den Rest-Scope ab, der nicht bereits durch `SERVICE_CONSOLIDATION_PREP.md` priorisiert wurde.

Bereits geplant (hier bewusst nicht erneut fokussiert):
- `agent_runtime`, `agent_decision_service`, `orchestrator`, `interpreter`
- `services/*` inkl. `confirm_run_service`, Preflight-Services, Lookup-/Mutation-Services
- `queue/queue_manager`
- `conversation_store`, `planner_service`, `llm_call_service`
- `task_registry`, `task_executability_evaluator`
- adaptive/embeddings Service-Hauptpfad

## Vollstaendig gescannter Rest-Scope
Scan-Basis: alle Klassen unter `classes/` im Plugin, exklusive oben genannter Service-Schwergewichte und exklusive `classes/local/wbagent/services/*`.

Enthaltene Rest-Bereiche:
- External API Adapter:
  - `classes/external/ai_*.php`
  - `classes/external/booking_*.php`
  - `classes/external/activate_trial_context.php`
  - `classes/external/request_trial_key.php`
- Task Layer (nicht Service):
  - `classes/local/wbagent/base_task.php`
  - `classes/local/wbagent/core/tasks/*.php`
  - `classes/local/wbagent/examples/tasks/*.php`
  - `classes/local/wbagent/task_provider.php`
  - `classes/local/wbagent/task_registry_factory.php`
  - `classes/local/wbagent/task_discovery.php`
  - `classes/local/wbagent/task_contract_validator.php`
- DTO/Contract Layer:
  - `classes/local/wbagent/dto/*.php`
  - `classes/local/wbagent/interfaces/*.php`
- Runtime-nahe Utilities ausserhalb Service-Scope:
  - `authorization_service`, `agent_state`, `loop_finalizer`, `message_trigger_registry`
  - `result_payload_summarizer`, `prompt_policy_builder`, `privacy_anonymizer`, `ai_error_classifier`, `aiready`
  - `queue/observation_builder`
  - `summarizer/*.php`
- Adhoc Tasks:
  - `classes/task/execute_ai_run_adhoc.php`
  - `classes/task/rebuild_task_catalog_embeddings_adhoc.php`

## Hotspots nach Groesse (Rest-Scope)
- `privacy_anonymizer.php` (1384)
- `executor.php` (787)
- `core/tasks/core_task_base.php` (724)
- `external/ai_send_message.php` (507)
- `core/tasks/list_actions_task.php` (502)
- `external/ai_get_doc_content.php` (495)
- `result_payload_summarizer.php` (455)
- `external/ai_render_command_preview.php` (442)

## Harte Duplikate (direkt konsolidierbar)

### 1) External Context/Auth Bootstrap (nahezu identisch)
Wiederholt in nahezu allen `classes/external/*` Endpunkten:
- `context::instance_by_id(...)` + Fallback auf `context_module::instance(...)`
- `authorization_service::require_valid_context(...)`
- `self::validate_context(...)`
- Capability-Pruefung

Ziel: `external_context_guard` (shared helper fuer Context-Aufloesung + Auth-Gates).

### 2) WS Message Rendering fuer Assistant-Text
Nahezu identische Methode:
- `ai_send_message::format_ws_message`
- `ai_confirm_run::format_ws_message`
- `ai_poll_thread::format_ws_message`

Ziel: `external_message_formatter::format_assistant_message(...)`.

### 3) Mutation Endpoint Pipeline (booking_create/update/bulk)
Stark dupliziert in:
- `booking_create_option`
- `booking_update_option`
- `booking_bulk_update_options`

Duplikate:
- idempotency lookup + skip response
- JSON decode + invalid-json response
- validation-error payload mapping
- Result-DT0 -> WS response mapping

Ziel: `mutation_ws_pipeline` (template flow + task-spezifische hooks).

### 4) DTO Boilerplate
Nahezu gleich in:
- `create_option_input_dto`
- `update_option_input_dto`
- `create_entity_input_dto`
- `bulk_update_options_input_dto`

Duplikate:
- `from_array`, `to_array`, `get`
- identische interne Feldspeicherung

Ziel: `abstract_input_dto` + schlanke spezialisierte Required-Field Policies.

### 5) Task Skeleton Boilerplate
Wiederkehrend in Core- und Example-Tasks:
- `get_name`
- `get_schema`
- `check_structure`
- `get_example_input`
- `get_message_triggers`

Ziel: `task_contract_trait` + `task_schema_builder` fuer deklarative Task-Metadaten.

## Semantisch sehr aehnliche Logik (zu vereinheitlichen)

### 6) External Error/Response Envelope Vereinheitlichung
Viele Endpunkte liefern semantisch aehnliche Fehlerstrukturen, aber mit leicht verschiedenen Keys/Defaultwerten.

Ziel: `external_response_factory` mit klaren Profilen:
- ai-conversation profile
- mutation profile
- docs/profile helper

### 7) Capability Policy Split (AI use vs booking update)
External Endpunkte pruefen unterschiedliche Capabilities mit wiederholtem Code.

Ziel: `external_capability_policy` als zentrale Matrix:
- ai conversation endpoints
- mutating booking endpoints
- preview/read-only endpoints

### 8) Summarizer Contributor Muster
Contributor teilen dieselbe Selektions-/Summarize-Form, aber mit verstreuten Limits und formatierenden Details.

Ziel: `summary_contributor_base` + `summary_length_policy`.

### 9) Task Provider/Discovery Verdrahtung
`task_provider`, `task_discovery`, `task_registry_factory` haben teils ueberlappende Verantwortung in Discovery/Sortierung/Fallback.

Ziel: `task_catalog_bootstrap` (ein Einstieg fuer Discovery + Ordering + Diagnostics).

### 10) Adhoc Task Runtime-Guards
`execute_ai_run_adhoc` und `rebuild_task_catalog_embeddings_adhoc` haben jeweils eigene Guard-/Fallback-/Logging-Muster.

Ziel: `adhoc_task_guard` (common validate-context/logging/early-abort helpers).

## Zielarchitektur fuer radikale Rest-Konsolidierung

### A) External Adapter Consolidation Layer (neu)
- `external_context_guard`
- `external_message_formatter`
- `external_response_factory`
- `external_capability_policy`

### B) Mutation WS Consolidation Layer (neu)
- `mutation_ws_pipeline`
- `idempotency_result_resolver`
- `json_input_decoder` (strict + deterministic error contract)

### C) Task Contract Consolidation Layer (neu)
- `task_contract_trait`
- `task_schema_builder`
- `task_catalog_bootstrap`

### D) DTO/Summary Consolidation Layer (neu)
- `abstract_input_dto`
- `summary_contributor_base`
- `summary_length_policy`

### E) Adhoc Runtime Utility Layer (neu)
- `adhoc_task_guard`

## Was explizit nicht gebrochen werden darf
- Keine task-spezifischen Feldheuristiken im Framework.
- Mutationen bleiben preflight-first + confirmation-gated.
- Session-confirmation Scope bleibt user + contextid.
- Queue bleibt authoritative fuer mutating flows.
- Antwortsprache bleibt durch language policy determiniert.
- `response_type`/`issue_codes` Vertraege bleiben strikt kompatibel.

## Umsetzungsreihenfolge (sicher, aber radikal)

1. External Adapter konsolidieren (ohne Verhaltensaenderung)
- `external_context_guard`
- `external_message_formatter`
- `external_response_factory` (nur intern geroutet)

2. Mutation WS Pipeline zentralisieren
- `mutation_ws_pipeline`
- idempotency/json/validation Mapping aus `booking_*` Endpunkten zusammenziehen

3. DTO Layer reduzieren
- `abstract_input_dto` einfuehren
- vorhandene Input-DT0s auf gemeinsame Basis umstellen

4. Task Contract Layer vereinheitlichen
- deklarative Task-Schema-Bausteine
- Provider/Discovery/Factory bootstrap vereinheitlichen

5. Summarizer + Adhoc Helpers konsolidieren
- length policy + contributor base
- adhoc guards/logging helper

6. Shrink Phase
- duplizierte private helper entfernen
- tote Pfade entfernen
- method signatures vereinheitlichen

## Konkrete Starttickets (direkt umsetzbar)

- T1: `external_message_formatter` einziehen und in `ai_send_message`, `ai_confirm_run`, `ai_poll_thread` verdrahten.
- T2: `external_context_guard` einziehen und in allen `classes/external/*.php` verwenden.
- T3: `mutation_ws_pipeline` einfuehren und `booking_create_option`, `booking_update_option`, `booking_bulk_update_options` angleichen.
- T4: `abstract_input_dto` einfuehren; `create_*_input_dto` und `update/bulk_*_input_dto` auf Basisklasse bringen.
- T5: `task_catalog_bootstrap` erstellen; `task_provider` + `task_registry_factory` + `task_discovery` entflechten.
- T6: `summary_length_policy` einziehen; Summarizer Contributor auf einheitliche Limits/Formatierung umstellen.

## Risiko- und Regressionspunkte
- WS-Response-Contracts der External APIs duerfen sich nicht stillschweigend aendern.
- `pendingconfirmationcode` und `queueitemid` muessen in allen Antwortpfaden stabil bleiben.
- Idempotency-Semantik der `booking_*` Endpunkte darf nicht regressieren.
- Privacy-Anonymisierungs- und Display-Deanonymisierungsverhalten muss identisch bleiben.
- Trigger-Allowlist und deterministische Routing-Signale duerfen nicht aufgeweicht werden.

## Teststrategie fuer die Rest-Konsolidierung
- Contract-Tests fuer alle `classes/external/*` Response-Schemata (inkl. Fehlerfaelle).
- Golden-Tests fuer `format_ws_message` Rendering (Markdown->HTML deterministisch).
- Idempotency-Regressionstests fuer `booking_create/update/bulk`.
- DTO-Contract-Tests (`from_array`, required fields, serialisierungsgleiche `to_array`).
- Task-Contract-Tests fuer Prompt-Metadaten und `check_structure`-Verhalten.
- Summarizer Snapshot-Tests fuer `options/users/courses/docs/diagnosis/generic`.
- Adhoc-Task Guard-Tests fuer invalid customdata/context fail-fast Pfade.

## Abschlusskriterium
Die Konsolidierung gilt als abgeschlossen, wenn:
- alle genannten Duplikatcluster auf gemeinsame Utilities/Pipelines reduziert sind,
- externe API-Contracts byte-kompatibel bleiben,
- und das Agent-Konzept aus dem Implementierungs-Flow (deterministisch, preflight-first, confirmation-gated, queue-authoritative) unveraendert nachweisbar bleibt.