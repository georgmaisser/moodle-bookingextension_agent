# Zwischenstand Konsolidierung bookingextension_agent

Datum: 2026-05-30
Scope: mod/booking/bookingextension/agent

## Durchgefuehrte Konsolidierungsmassnahmen

1. Baseline angelegt
- Branch erstellt: consolidation-20260530
- Tag erstellt: baseline-pre-consolidation-20260530-1

2. Duplikatbereinigung bei Test-Fixtures umgesetzt
- Geloescht: tests/agent/embedded_llm/fixtures/task_catalog_embeddings.csv
- Geloescht: tests/fixtures/task_catalog_embeddings.csv
- Kanonische Datei bleibt: tests/agent/fixtures/task_catalog_embeddings.csv

3. CLI auf kanonische Fixture vereinheitlicht
- Datei: cli/rebuild_embeddings_fixture.php
- Hilfe-Text angepasst auf neuen Default-Pfad
- Default-Outputpfad angepasst auf tests/agent/fixtures/task_catalog_embeddings.csv

4. CLI/Public-Spiegelung geprueft
- Ergebnis: unter cli/public existieren keine Dateien (0), damit kein aktiver Spiegelungsbestand

5. Grosses Dead-Code-Paket umgesetzt (Beispiel-/Demo-Schiene)
- Geloescht: classes/local/wbagent/examples/README.md
- Geloescht: classes/local/wbagent/examples/tasks/multistep_example_task.php
- Geloescht: classes/local/wbagent/examples/tasks/readonly_example_task.php
- Geloescht: classes/local/wbagent/examples/tasks/spawn_child_example_task.php
- Geloescht: classes/local/wbagent/examples/tasks/spawn_parent_example_task.php
- Geloescht: tests/agent/real_llm_multistep/example_tasks_real_llm_test.php

6. Inventur nach Sprint aktualisiert
- docs/Blueprints/bookingextension_agent_inventur_vollstaendig.md neu generiert

7. Messpunkte/Baseline aktualisiert
- Dateien gesamt (aktuell): 163
- PHP-Methoden/Funktionen (aktuell): 1006

## Tatsaechlich geloeschte Zeilen

Quelle: git diff --shortstat / --numstat

- Gesamte Loeschungen: 1404 Zeilen
- Gesamte Einfuegungen: 92 Zeilen
- Geaenderte Dateien: 11

Detail (grosses Paket):
- classes/local/wbagent/examples/README.md: -34
- classes/local/wbagent/examples/tasks/multistep_example_task.php: -233
- classes/local/wbagent/examples/tasks/readonly_example_task.php: -206
- classes/local/wbagent/examples/tasks/spawn_child_example_task.php: -175
- classes/local/wbagent/examples/tasks/spawn_parent_example_task.php: -194
- tests/agent/real_llm_multistep/example_tasks_real_llm_test.php: -360
- tests/agent/embedded_llm/fixtures/task_catalog_embeddings.csv: -31
- tests/fixtures/task_catalog_embeddings.csv: -31
- cli/rebuild_embeddings_fixture.php: +2 / -2
- docs/Blueprints/bookingextension_agent_inventur_vollstaendig.md: +85 / -133
- docs/Blueprints/bookingextension_agent_konsolidierung_checkliste_vollstaendig.md: +5 / -5

## Checkbox-Status dieser Runde

- Erledigt: Schritt 01
- Erledigt: Schritt 03
- Erledigt: Schritt 04
- Erledigt: Schritt 06
- Erledigt: Schritt 07

## Verifikation

- Syntaxcheck ok: cli/rebuild_embeddings_fixture.php
- PHPUnit-Testlauf war in dieser Umgebung nicht direkt nutzbar (Autoload/Runner-Kontext), daher Schritt 02 noch offen.

## Sprint-Update Schritte 8 bis 18

Durchgefuehrte Maßnahmen in diesem Sprint:

1. Schritt 08 (teilweise)
- Entfernt: classes/local/wbagent/wunderbyte_trial_endpoint.py
- Trial-Pfade geprueft: request_trial_key, activate_trial_context, trial_challenge.php sind produktiv verknuepft und bleiben vorerst.

2. Schritt 09 (erledigt)
- Inventur neu generiert: docs/Blueprints/bookingextension_agent_inventur_vollstaendig.md
- Dokumentations-Fehlablage ausserhalb Plugin bereits bereinigt.

3. Schritt 10 (erledigt)
- Neu: classes/external/ws_message_formatter.php
- Duplikate entfernt in:
	- classes/external/ai_send_message.php
	- classes/external/ai_poll_thread.php
	- classes/external/ai_confirm_run.php

4. Schritt 11 (erledigt)
- Neu: classes/local/wbagent/services/preflight_error_classifier.php
- Umgestellt auf zentrale Klassifikation:
	- classes/local/wbagent/services/preflight_pipeline.php
	- classes/local/wbagent/services/preflight_execution_gate.php
	- classes/local/wbagent/services/confirm_run_service.php

5. Schritte 12 bis 18 (weiter vorangetrieben)
- Neu: classes/local/wbagent/services/pending_queue_command_service.php
- `agent_decision_service` nutzt jetzt den neuen Pending-Queue-Command-Service.
- `queue_transition_service` uebernimmt jetzt die zentrale Preflight-Queue-Entscheidung (`apply_preflight_decision`).
- `agent_decision_service` wurde um diese Transition-Entscheidungslogik reduziert.
- Neu: classes/local/wbagent/services/completed_command_history_service.php
- `orchestrator` nutzt jetzt den neuen Completed-Command-History-Service fuer Extraktion/Queue-Merge/Signaturbildung.
- Neu: classes/local/wbagent/services/assistant_state_guidance_service.php
- `orchestrator` nutzt jetzt den Assistant-State/Guidance-Service fuer Contextual-Guidance, State-Blocks und String-Normalisierung.
- Neu: classes/local/wbagent/services/orchestrator_routing_service.php
- `orchestrator` nutzt jetzt den Routing-/Debug-Service fuer Action-Resolution, Context-Availability und Debug-Source-Building.
- Neu: classes/local/wbagent/services/orchestrator_prompt_profile_service.php
- `orchestrator` nutzt Prompt-Profil-Normalisierung/Retry-Hinweis-Klassifikation/Config-Template-Aufbereitung jetzt zentral ueber den neuen Prompt-Profile-Service.
- Neu: classes/local/wbagent/services/runtime_synthesis_policy_service.php
- `agent_runtime` nutzt Sufficiency-/Clarification-/Explain-Diagnose-Policy jetzt ueber den neuen Synthesis-Policy-Service.
- Neu: classes/local/wbagent/services/confirm_preview_option_service.php
- `confirm_run_service` nutzt Preview-Option-ID-Aufloesung/Aggregation jetzt ueber den neuen Confirm-Preview-Service.
- Runtime/Decision-Heuristikabbau umgesetzt:
	- `agent_runtime`: Option-Type-Follow-up nicht mehr ueber freie Frage-Pattern, sondern ueber strukturierte Vorbedingung + non-empty Follow-up.
	- `agent_decision_service`: ungenutzte sprachmarkerbasierte Clarification-Heuristik entfernt.
- Vollmigration von Runtime/Orchestrator/Decision/Preflight bleibt als Folgesprint teilweise offen.

6. Schritt 02 (Sicherheitsnetz) aktualisiert
- `queue_consolidation_contract_test.php`: 4/4 gruen.
- Gezieltes Scope-Gate (Queue-Consolidation + Slim-Catalog-Contract): 5/5 gruen.
- Vollstaendige Contract-Dateiliste: 57 Tests, 6 Failures (Altbaustellen ausserhalb des aktuellen Refactor-Scopes).

Aktuelle Bilanz dieses Gesamtstands:

- Dateien gesamt: 165
- Methoden/Funktionen gesamt: 1087
- Git-Diff: 406 Einfuegungen, 2961 Loeschungen

Status Schritte 8-18:

- Schritt 08: teilweise
- Schritt 09: erledigt
- Schritt 10: erledigt
- Schritt 11: erledigt
- Schritt 12: erledigt
	- Neu in diesem Sprint: Runtime-Step-Analyse aus `agent_runtime.php` in `classes/local/wbagent/services/runtime_step_analysis_service.php` extrahiert.
	- Direktbilanz Runtime-Split: `agent_runtime.php` +20 / -162, neuer Service +171 / -0.
	- Neu in diesem Sprint: ORCH-Completed-Command-Historie aus `orchestrator.php` in `classes/local/wbagent/services/completed_command_history_service.php` extrahiert.
	- Direktbilanz ORCH-Split: `orchestrator.php` +7 / -260, neuer Service +299 / -0.
	- Neu in diesem Sprint: ORCH-Assistant-State/Guidance-Cluster aus `orchestrator.php` in `classes/local/wbagent/services/assistant_state_guidance_service.php` extrahiert.
	- Direktbilanz ORCH-Split 2: `orchestrator.php` +15 / -496, neuer Service +276 / -0.
- Schritt 13: erledigt
- Schritt 14: teilweise
	- Neu in diesem Sprint: Zwischenschritt 14.2 abgeschlossen (Runtime-/Decision-Heuristik reduziert).
- Schritt 15: erledigt
	- Neu in diesem Sprint: Zwischenschritt 15.2 abgeschlossen (Runtime-Policy-Logik in `runtime_synthesis_policy_service` ausgelagert).
- Schritt 16: erledigt
	- Neu in diesem Sprint: Zwischenschritt 16.2 abgeschlossen (Completed-Command-Historie ausgelagert).
	- Neu in diesem Sprint: Zwischenschritt 16.3 abgeschlossen (Assistant-State/Guidance ausgelagert).
	- Neu in diesem Sprint: Zwischenschritt 16.4 abgeschlossen (Routing-/Debug-Cluster ausgelagert).
	- Neu in diesem Sprint: Zwischenschritt 16.5 abgeschlossen (Prompt-Profile-Cluster in `orchestrator_prompt_profile_service` ausgelagert).
- Schritt 17: teilweise
- Schritt 18: teilweise
	- Neu in diesem Sprint: 18.2 weiter vorangetrieben (Confirm-Preview-Option-Cluster in `confirm_preview_option_service` ausgelagert; tiefe Restpfade bleiben offen).

Gezielter Gate-Status nach den letzten Migrationen:

- `php -l` gruen fuer alle geaenderten Runtime/Orchestrator/Confirm-Services.
- Gezielter PHPUnit-Lauf (`integration_agent_framework_test`, `ai_confirm_run_contract_test`, `prompt_and_language_contract_test`): 25 Tests, Assertions gruen.
- Bekannte Umgebungswarnung bleibt bestehen: fehlende Datei `public/local/wbagent/version.php` im Plugin-Manager-Include-Pfad (ausserhalb des aktuellen Refactor-Scopes).
