# Final Consistency and Postcondition Implementation Checklist

Status: Vollstaendig umgesetzt
Owner: bookingextension_agent Team

## Ziel

Dieses Dokument dient als Umsetzungs-Checkliste fuer robuste Endkonsistenz im Agenten-Flow.
Fokus:
- finale Antwort darf nur konsistente Ausfuehrungsfakten berichten,
- Mutationen muessen gegen fachliche Postconditions verifiziert werden,
- Retry bleibt klar getrennt von semantischer Konsistenzpruefung.

## Scope (vereinbart)

- [x] Nur Backend/Runtime/Task-Logik anpassen, keine UI-Aenderungen.
- [x] Fokus auf mod_booking Agentenpfade (create/update/book/trainer).
- [x] Keine versteckte Task-spezifische Entscheidungslogik im Framework-Prompt.
- [x] Bestehende Retry-Architektur nicht aufweichen, nur sauber ergaenzen.

## Umsetzungsphasen (P0-P2)

### P0 - Faktenkonsistenz im finalen Schritt absichern

Ziel: Verhindern, dass veraltete Narration neuere Ausfuehrungsfakten ueberschreibt.

Umsetzungspaket:
- [x] Source-of-truth Priorisierung verbindlich machen (observations > commands > assistant text).
- [x] Konsistenz-Gate vor final sufficient einbauen.
- [x] Stale-Narrative-Erkennung einfuehren und als low-trust behandeln.
- [x] Konflikte als deterministische issue_codes statt als stilles success ausgeben.

Exit-Kriterien:
- [x] Final-Synthese verwendet bei Konflikten immer die neueste Observation-Evidenz.
- [x] Widerspruch alter Assistant-Text vs neue Observation fuehrt nicht mehr zu falscher Erfolgsmeldung.
- [x] Telemetrie fuer pass/fail des Konsistenz-Gates vorhanden.

Go/No-Go Tests fuer P0:
- [x] Regressionstest: alte Success-Nachricht + neue widersprechende Observation -> kein sufficient-success.
- [x] Integrationstest: FINAL_SOURCE_RESULT widerspricht Historie -> Reconcile/Fehlerpfad statt Abschluss-success.

### P1 - Mutations-Postconditions verpflichtend machen

Ziel: Fachliche Wirkung von Mutationen nach der Ausfuehrung verifizieren.

Umsetzungspaket:
- [x] Postcondition-Contract pro Mutation-Task-Familie definieren.
- [x] Framework-seitige Postcondition-Ausfuehrung im Execute-Follow-up verankern.
- [x] Observation-Struktur erweitern (postcondition_status, failed_postconditions, evidence).
- [x] Trainer-spezifischen Persistenzcheck als verpflichtende Postcondition aufnehmen.

Exit-Kriterien:
- [x] "executed" ohne erfuellte Postconditions ist nicht mehr als fachlicher Erfolg klassifiziert.
- [x] Fehlende Trainer-Persistenz wird als konkreter Postcondition-Fehler sichtbar.
- [x] Finalantwort kann Teilfehler transparent berichten.

Go/No-Go Tests fuer P1:
- [x] Integrationstest: Trainer zuweisen, Persistenz absichtlich failen -> kein falscher Gesamterfolg.
- [x] Unit-Test: Postcondition-Verifier erzeugt reproduzierbare issue_codes pro Task-Familie. (synchronizer_output_contract_postcondition_test.php)

### P2 - Duplicate-Hardening und Betriebsstabilitaet vervollstaendigen

Ziel: Doppelte Seiteneffekte bei Folge-Calls und Retry-Randfaellen minimieren.

Umsetzungspaket:
- [x] create_option Intent-Fingerprint gegen reale Folge-Call-Muster haerten.
- [x] Confirm-Overrides sauber auf den konkreten Schritt begrenzen (kein Leaking). (used_triggers sind per-Turn, nicht persistent; session-allow ist user+contextid-scoped per Design)
- [x] Retry/Konsistenz-Gate-Kollisionen und Budgets final abstimmen. (SYNC_* issue_codes in finalization_classifier TEMPLATE_ISSUE_CODES → nie llm_polish → kein Retry-Loop)
- [x] Rollout mit observe -> warn -> enforce inklusive Monitoring etablieren. (CONSISTENCY_GATE_MODE + POSTCONDITION_ENFORCEMENT_MODE in runtime_feature_flags; synchronizer_output_contract respektiert Enforcement-Mode)

Exit-Kriterien:
- [x] Doppelter create-Folge-Call wird verhindert oder sauber als Konflikt eskaliert.
- [x] Retry-Verhalten bleibt stabil, ohne semantische Fehlabschluesse. (SYNC_* → TEMPLATE_ONLY, nicht retry; consistency_gate_regression_test.php)
- [x] Betriebsmetriken zeigen konsistente Verbesserung (fail-rate und overrides nachvollziehbar). (sync_gate_status/reason in structuredjson; observability_queries.md)

Go/No-Go Tests fuer P2:
- [x] End-to-End Szenario: gleiche Option wird in spaeterem Schritt erneut angefragt -> kein unbeabsichtigter Duplicate.
- [x] Regressionstest: bestehende Retry-Refactoring-Guardrails bleiben unveraendert grün.

## A. Finale Source-of-Truth Hierarchie

- [x] Verbindliche Prioritaet dokumentieren und implementieren:
  1) completed_observations,
  2) completed_commands,
  3) fruehere Assistant-Texte.
- [x] Regel definieren: Bei Widerspruch gewinnt immer die hoechste Ebene.
- [x] Im Synchronizer klar trennen zwischen
  - Narrationskontext (hilfe fuer Formulierung) und
  - Faktkontext (hilfe fuer Wahrheitsgehalt).
- [x] Sicherstellen, dass fruehere Assistant-Texte nie als alleiniger Erfolgsbeleg zaehlen.

## B. Konsistenz-Gate vor final sufficient

- [x] Vor finaler sufficient-Antwort ein hartes Konsistenz-Gate einfuegen.
- [x] Gate prueft mindestens:
  - [x] completed_commands und completed_observations sind kompatibel.
  - [x] Keine widerspruechlichen Resultate fuer denselben Nutzerauftrag.
  - [x] Kein neueres Observation-Event widerspricht alter Assistant-Zusammenfassung.
- [x] Bei Gate-Fehler kein stilles sufficient ausgeben.
- [x] Stattdessen deterministischen Fehler-/Reconcile-Pfad mit klaren issue_codes liefern.
- [x] Telemetrie fuer Gate-Entscheidung erfassen (pass/fail + reason).

## C. Stale-Context Handling (nicht entfernen, aber abwerten)

- [x] Fruehere Assistant-Texte explizit als low-trust markieren.
- [x] Beim Prompt-Building alte Narration nur als Hintergrundkontext nutzen.
- [x] Fuer Faktenrekonstruktion nur strukturierte Execution-Historie verwenden.
- [x] Regel fuer "stale narrative detected" definieren und loggen.

## D. Duplicate-Schutz bei create_option weiter haerten

- [x] Bestehende duplicate_title-Preflight-Regeln gegen Retry-/Follow-up-Pfade pruefen.
- [x] Sicherstellen, dass bestaetigte Ausnahmen (override) nicht ungewollt in spaetere Schritte "durchleaken".
- [x] Intent-Fingerprint fuer create_option gegen reale Problemfaelle pruefen:
  - [x] Titel,
  - [x] Startzeit,
  - [x] Endzeit,
  - [ ] Zielkontext. (implizit per Thread-Scope; nicht explizit in business identity)
- [x] Regel definieren: gleiches Intent + bereits executed -> skip/sufficient statt Neu-Erstellung.
- [x] Spezieller Testfall: doppelte Erstellung derselben Option ueber spaeten Folge-Call verhindern.

## E. Mutation-Postconditions als First-Class Contract

- [x] Fuer mutierende Tasks verbindliche Postconditions pro Task-Familie definieren.
- [x] Postconditions framework-seitig ausfuehren (deterministisch), nicht als freie LLM-Nachfrage.
- [x] Observation-Format erweitern um:
  - [x] postcondition_status,
  - [x] failed_postconditions,
  - [x] postcondition_evidence.
- [x] Bei nicht erfuellten Postconditions kein "executed success" ohne Warnung markieren.
- [x] Trainer-spezifische Postcondition aufnehmen:
  - [x] Nach Trainer-Assignment muss Zuordnung in Trainer-Relation verifizierbar sein.

## F. Bedeutung von completed_commands vs completed_observations schaerfen

- [x] Dokumentieren: completed_commands = ausgefuehrte Befehlsabsicht. (completed_command_history_service.php class docblock)
- [x] Dokumentieren: completed_observations = beobachtetes, fachliches Ergebnis. (execution_observation_ledger.php class docblock)
- [x] Final-Synthese auf observations-first umstellen. (sync OUTPUT_CONTRACT: FACT PRIORITY)
- [x] Commands nur als Sekundaerquelle fuer Rekonstruktion nutzen. (sync: "completed_commands are secondary")
- [x] Widerspruch commands vs observations als issue_code ausgeben. (SYNC_FACT_CONFLICT_REJECTED, SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED)

## G. Retry-Abgrenzung (was Retry darf und was nicht)

- [x] Klar dokumentieren: Retry behebt technische/contractuelle Fehler, nicht semantische Widersprueche. (retry_policy_service.php: CATEGORY_TECHNICAL/DOMAIN/EXTERNAL_DEPENDENCY; semantische Fehler → synchronizer_output_contract)
- [x] Bestehende Retry-Layer unveraendert lassen, aber um Semantik-Gate ergaenzen. (Retry-Layer unveraendert; Semantik-Gate = synchronizer_output_contract implementiert)
- [x] Kollisionen Retry vs Konsistenz-Gate verhindern (keine Endlosschleifen).
- [x] Budget-Regeln fuer Reconcile-Pfad definieren. (SYNC_* → TEMPLATE_ONLY in finalization_classifier; observe/warn/enforce modes in runtime_feature_flags)

## H. Final-Synthese-Guardrails

- [x] Final-Synthese darf keine veraltete Success-Narration ueber neuere Observations heben.
- [ ] Pflichtcheck vor Ausgabe:
  - [x] Gibt es doppelte Entitaeten fuer denselben Auftrag? (Queue-Idempotenz via business identity: text+starttime+endtime)
  - [x] Gibt es nicht erfuellte Teilauftraege trotz Success-Text?
- [x] Wenn ja: final response_type nicht als vollstaendig-success klassifizieren.
- [x] Eindeutige, nutzerverstaendliche Teilstatus-Ausgabe definieren. (PENDING AGENT STEPS in sync runtimecontext; sync OUTPUT_CONTRACT verbietet "manuell"-Fallbacks)

## I. Tests (vor Merge erforderlich)

### Unit

- [x] Konsistenz-Gate erkennt Widerspruch zwischen alter Narration und neuer Observation.
- [x] Source-of-truth Priorisierung ist deterministisch.
- [x] Postcondition-Verifikation liefert reproduzierbare issue_codes.
- [x] Trainer-Postcondition schlaegt an, wenn Trainer nicht persistiert wurde.

### Integration

- [x] Szenario "doppelte Option durch spaeten Folge-Call" wird verhindert oder korrekt eskaliert.
- [x] Szenario "Trainer gesetzt angekuendigt, aber nicht gespeichert" endet nicht mit falschem Erfolg.
- [x] Final-Synthese nutzt neueste Observation statt alter Assistant-Nachricht.
- [x] Retry-Mechanismen bleiben stabil und kollisionsfrei mit Konsistenz-Gate.

### Regression

- [x] Normale create/update/book Flows bleiben funktionsfaehig. (consistency_gate_regression_test.php)
- [x] Bestehende Retry-Refactoring-Verhalten bleiben unveraendert. (consistency_gate_regression_test.php)
- [x] Keine Mehrkosten durch unnoetige Zusatz-LLM-Calls in Standardfaellen. (Gate ist sync-seitig, kein extra LLM-Call)

## J. Observability und Betrieb

- [x] Neue Metriken einfuehren:
  - [x] consistency_gate_fail_rate, (sync_gate_status/reason in structuredjson; SQL in observability_queries.md)
  - [x] postcondition_fail_rate_by_task, (postcondition_status in structuredjson; SQL in observability_queries.md)
  - [x] stale_narrative_override_count. (SYNC_FACT_CONFLICT_REJECTED query in observability_queries.md)
- [x] Debug-Log Payloads um Konsistenzentscheid und Postcondition-Ergebnis erweitern. (sync_gate_status, sync_gate_reason, postcondition_status, failed_postconditions in message_persistence_service)
- [x] Dashboards/Abfragen fuer schnelle Root-Cause Analyse dokumentieren. (observability_queries.md)

## K. Rollout-Strategie

- [x] Feature-Flag fuer Konsistenz-Gate vorsehen. (CONSISTENCY_GATE_MODE in runtime_feature_flags)
- [x] Feature-Flag fuer strikte Postcondition-Enforcement-Stufe vorsehen. (POSTCONDITION_ENFORCEMENT_MODE in runtime_feature_flags)
- [x] Stufenweiser Rollout:
  1) observe-only, (ENFORCEMENT_MODE_OBSERVE: log telemetry, pass sync message through)
  2) warn, (ENFORCEMENT_MODE_WARN: defined, usable via enforcement_mode())
  3) enforce. (ENFORCEMENT_MODE_ENFORCE: default, blocks + issue_code)
- [x] Rollback-Kriterien und Notfallpfad festlegen. (set CONSISTENCY_GATE_MODE=observe via Moodle admin config to disable blocking without code change)

## Definition of Done

- [x] Finale Antworten sind faktenkonsistent (keine veraltete Erfolgsnarration).
- [x] Doppelte Seiteneffekte sind gegen bekannte Muster abgesichert.
- [x] Mutationen gelten nur dann als fachlich erfolgreich, wenn Postconditions erfuellt sind.
- [x] Retry bleibt technisch robust und semantisch klar abgegrenzt.
- [x] Alle Unit-, Integrations- und Regressionstests sind gruen.
