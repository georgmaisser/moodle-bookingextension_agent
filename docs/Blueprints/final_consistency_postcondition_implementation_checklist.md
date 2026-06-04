# Final Consistency and Postcondition Implementation Checklist

Status: Teilweise umgesetzt (P0 erweitert, P1/P2 in Arbeit)
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
- [ ] Keine versteckte Task-spezifische Entscheidungslogik im Framework-Prompt.
- [ ] Bestehende Retry-Architektur nicht aufweichen, nur sauber ergaenzen.

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
- [ ] Unit-Test: Postcondition-Verifier erzeugt reproduzierbare issue_codes pro Task-Familie.

### P2 - Duplicate-Hardening und Betriebsstabilitaet vervollstaendigen

Ziel: Doppelte Seiteneffekte bei Folge-Calls und Retry-Randfaellen minimieren.

Umsetzungspaket:
- [x] create_option Intent-Fingerprint gegen reale Folge-Call-Muster haerten.
- [ ] Confirm-Overrides sauber auf den konkreten Schritt begrenzen (kein Leaking).
- [ ] Retry/Konsistenz-Gate-Kollisionen und Budgets final abstimmen.
- [ ] Rollout mit observe -> warn -> enforce inklusive Monitoring etablieren.

Exit-Kriterien:
- [x] Doppelter create-Folge-Call wird verhindert oder sauber als Konflikt eskaliert.
- [ ] Retry-Verhalten bleibt stabil, ohne semantische Fehlabschluesse.
- [ ] Betriebsmetriken zeigen konsistente Verbesserung (fail-rate und overrides nachvollziehbar).

Go/No-Go Tests fuer P2:
- [x] End-to-End Szenario: gleiche Option wird in spaeterem Schritt erneut angefragt -> kein unbeabsichtigter Duplicate.
- [ ] Regressionstest: bestehende Retry-Refactoring-Guardrails bleiben unveraendert grün.

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
- [ ] Intent-Fingerprint fuer create_option gegen reale Problemfaelle pruefen:
  - [ ] Titel,
  - [ ] Startzeit,
  - [ ] Endzeit,
  - [ ] Zielkontext.
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

- [ ] Dokumentieren: completed_commands = ausgefuehrte Befehlsabsicht.
- [ ] Dokumentieren: completed_observations = beobachtetes, fachliches Ergebnis.
- [ ] Final-Synthese auf observations-first umstellen.
- [ ] Commands nur als Sekundaerquelle fuer Rekonstruktion nutzen.
- [ ] Widerspruch commands vs observations als issue_code ausgeben.

## G. Retry-Abgrenzung (was Retry darf und was nicht)

- [ ] Klar dokumentieren: Retry behebt technische/contractuelle Fehler, nicht semantische Widersprueche.
- [ ] Bestehende Retry-Layer unveraendert lassen, aber um Semantik-Gate ergaenzen.
- [x] Kollisionen Retry vs Konsistenz-Gate verhindern (keine Endlosschleifen).
- [ ] Budget-Regeln fuer Reconcile-Pfad definieren.

## H. Final-Synthese-Guardrails

- [x] Final-Synthese darf keine veraltete Success-Narration ueber neuere Observations heben.
- [ ] Pflichtcheck vor Ausgabe:
  - [ ] Gibt es doppelte Entitaeten fuer denselben Auftrag?
  - [x] Gibt es nicht erfuellte Teilauftraege trotz Success-Text?
- [x] Wenn ja: final response_type nicht als vollstaendig-success klassifizieren.
- [ ] Eindeutige, nutzerverstaendliche Teilstatus-Ausgabe definieren.

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

- [ ] Normale create/update/book Flows bleiben funktionsfaehig.
- [ ] Bestehende Retry-Refactoring-Verhalten bleiben unveraendert.
- [ ] Keine Mehrkosten durch unnoetige Zusatz-LLM-Calls in Standardfaellen.

## J. Observability und Betrieb

- [ ] Neue Metriken einfuehren:
  - [ ] consistency_gate_fail_rate,
  - [ ] postcondition_fail_rate_by_task,
  - [ ] stale_narrative_override_count.
- [ ] Debug-Log Payloads um Konsistenzentscheid und Postcondition-Ergebnis erweitern.
- [ ] Dashboards/Abfragen fuer schnelle Root-Cause Analyse dokumentieren.

## K. Rollout-Strategie

- [ ] Feature-Flag fuer Konsistenz-Gate vorsehen.
- [ ] Feature-Flag fuer strikte Postcondition-Enforcement-Stufe vorsehen.
- [ ] Stufenweiser Rollout:
  1) observe-only,
  2) warn,
  3) enforce.
- [ ] Rollback-Kriterien und Notfallpfad festlegen.

## Definition of Done

- [x] Finale Antworten sind faktenkonsistent (keine veraltete Erfolgsnarration).
- [x] Doppelte Seiteneffekte sind gegen bekannte Muster abgesichert.
- [x] Mutationen gelten nur dann als fachlich erfolgreich, wenn Postconditions erfuellt sind.
- [ ] Retry bleibt technisch robust und semantisch klar abgegrenzt.
- [ ] Alle Unit-, Integrations- und Regressionstests sind gruen.
