# Roadmap: bookingextension_agent

## 1) Vision
Der Agent wird von einem reinen Command-Interface zu einem verlässlichen, kontextbewussten Assistenten fuer mod_booking ausgebaut: sicher, nachvollziehbar, testbar und im Alltag nutzbar.

## 2) Strategische Ziele
- Sicherheit zuerst: mutierende Aktionen klar klassifizieren und kontrollieren.
- Bessere UX: weniger Klicks, bessere Orientierung, klarere Rueckmeldungen.
- Operative Stabilitaet: reproduzierbare Ablaeufe, starke Tests, gute Beobachtbarkeit.
- Integrationsfaehigkeit: saubere APIs fuer Preview, Simulation und externe Aufrufer.

## 3) Priorisierte Workstreams

### WS1: Risk-Class Framework (P0)
Ziel: Einfuehrung eines deklarativen Risikoklassen-Systems (R0-R3) ueber den gesamten Agent-Flow.

Deliverables:
- task_risk_class als verpflichtende Deklaration in Task-Interface und Prompt-Contract.
- Validierung in skill_contract_validator (keine stillen Defaults).
- Durchsetzung in decision, preflight, queue und synchronizer.
- Klassenbasierte Regeln fuer confirmation, TTL und retry.

Definition of Done:
- Alle produktiven Tasks tragen eine explizite risk_class.
- Contract-Tests fuer R0-R3 und Gate-Logik gruen.

### WS2: Benchmarking und Performance-Messung (P0)
Ziel: Modell-Performance und Agent-Performance klar definieren, versioniert speichern und ueber Zeit vergleichbar messen.

Umfang:
- Einheitliche Benchmark-Suite fuer Kernszenarien (read-only, mutation mit confirmation, Fehler-/Retry-Pfade).
- Trennung von Modellmetriken und Agentmetriken, damit Ursachen sauber zugeordnet werden koennen.
- Persistente Speicherung je Lauf (Model-Version, Prompt-Profil, Task-Set, Ergebnis, Laufzeit, Kosten).
- Trendanalyse (tages-/wochenweise) inkl. Drift- und Regressionserkennung.

Kernmetriken Modell:
- Strukturtreue der Ausgabe (response_type, JSON-Validitaet, Contract-Compliance).
- Task-/Intent-Trefferrate auf Referenz-Szenarien.
- Antwortqualitaet fuer sufficient/clarification (bewertbar ueber Rubrik oder Golden Labels).
- Token-/Kostenprofil pro erfolgreich abgeschlossenem Workflow.

Kernmetriken Agent:
- End-to-End Erfolgsquote pro Workflow-Klasse.
- Anteil noetiger Clarification- und Confirmation-Schleifen.
- Retry-Rate (preflight, queue, execution) und Terminal-Fehlerquote.
- Zeit bis Abschluss (p50/p95) und Schrittanzahl pro Run.

Deliverables:
- Benchmark-Runner (CLI) mit reproduzierbaren Testfaellen und Seed-Daten.
- Ergebnis-Schema + Storage (historisierte Runs mit Vergleich auf Baseline).
- Reporting-Ansicht (Delta zur letzten stabilen Baseline, Ampelstatus pro Metrik).
- CI-Gate fuer Regressionen in kritischen Metriken.

Definition of Done:
- Fuer jede Release-Kandidaten-Version liegt ein Benchmark-Report mit Baseline-Vergleich vor.
- Kritische Regressionen blockieren den Rollout automatisch.
- Team kann Trends ueber mehrere Wochen nachvollziehen und erklaeren.

### WS3: Bild-Input und visuelle Verarbeitung (P0)
Ziel: Nutzer koennen Bilder direkt in den Agent-Chat droppen; der Agent verarbeitet den visuellen Inhalt und leitet daraus strukturierte Aktionen ab.

Deliverables:
- Drag-and-Drop- und Paste-Unterstuetzung fuer Bilder im Chat-UI (JPEG, PNG, WebP; konfigurierbare Groessengrenzen).
- Upload-Handler: Bild wird als temporaere Moodle-Datei abgelegt und als Attachment an den Thread gehaengt.
- Multimodaler LLM-Aufruf: Attachment-URL wird zusammen mit der Nutzernachricht an den Planner weitergegeben (vision-faehige Aktion via ai_manager).
- Extraktion strukturierter Felder aus Bild-Inhalt (z. B. Tabellen, Formulare, Screenshots von Buchungsdaten) und Uebergabe als observierter Kontext an nachfolgende Tasks.
- Klar definierte Fallbacks: Falls kein vision-faehiges Modell konfiguriert ist, wird dem Nutzer eine verstaendliche Fehlermeldung gezeigt, kein stiller Fehler.
- Sicherheitskontrollen: MIME-Validierung, Groessenlimit, keine Persistenz ueber Session hinaus ohne explizite Nutzeraktion.

Definition of Done:
- Bilder koennen in einen aktiven Chat-Thread gedroppt und an den Planner uebergeben werden.
- Extrahierte Daten aus Bildern werden als Kontext in nachfolgende Task-Parameter einspeist.
- Contract-Test sichert Attachment-Handling und Fallback bei fehlendem Vision-Modell ab.

### WS4: Preview API und UI (P0, ehem. WS3)
Ziel: Jede mutierende Aktion kann vor Ausfuehrung als belastbare Vorschau dargestellt werden.

Deliverables:
- API fuer strukturierte Preview-Payloads (diff, impact summary, warnings).
- Einheitliches Rendering fuer Tabellen, Feldaenderungen und betroffene Entitaeten.
- Klarer Uebergang Preview -> Confirm -> Execute inkl. queue_item Referenz.

Definition of Done:
- Preview fuer alle High-Impact Tasks verfuegbar.
- Contract-Tests decken Preview-Konsistenz ab.

### WS5: Agent im Kontext der Navigation (P1, ehem. WS4)
Ziel: Der Agent wird kontextbewusst im UI verankert (Kurs, Option, Rolle, Filter).

Deliverables:
- Einstiegspunkte in relevanten mod_booking Screens.
- Kontext-Resolver fuer aktive Entitaeten (course, option, user scope).
- "Was kann ich hier tun?"-Antworten auf Basis des aktuellen Kontexts.

Definition of Done:
- Kontextsensitive Vorschlaege reduzieren Freitext-Fehleingaenge messbar.

### WS6: Real Simulation Mode (P1, ehem. WS5)
Ziel: Trockenlauf fuer mutierende Operationen mit entsorgbarem Ergebnisraum.

Deliverables:
- Simulationsmodus mit isoliertem Write-Layer (kein produktiver Commit).
- Ergebnis als "would change" Report inkl. Konflikten und Nebenwirkungen.
- Explizites Verwerfen oder Uebernahmepfad in echte Ausfuehrung.

Definition of Done:
- Simulation liefert fuer Kern-Workflows stabile und nachvollziehbare Differenzen.

## 4) Zusaetzliche sinnvolle Ideen (Vorschlaege)

### 4.1 Observability und Audit Trail (P0)
- Thread- und Queue-Lifecycle Dashboard (state transitions, retries, blocker).
- Korrelation von user action -> planner output -> execution result.
- Exportierbare Audit-Events fuer Support und Compliance.

### 4.2 Safety Policy Packs (P1)
- Konfigurierbare Sicherheitsprofile pro Installation (strict, balanced, fast).
- Regeln fuer "always require manual confirmation" je Task/Scope.

### 4.3 Prompt/Contract Eval Harness (P1)
- Golden Scenarios fuer typische Buchungsablaeufe.
- CI-Checks gegen Strukturdrift im Planner/Synchronizer Output.

### 4.4 API Versioning und External Integrations (P2)
- Versionierte Endpunkte fuer Preview/Confirm/Poll.
- Stabiler Integrationsvertrag fuer externe Automations-Clients.

### 4.5 Onboarding und Admin Enablement (P2)
- Setup-Wizard fuer erste Inbetriebnahme (Capabilities, Defaults, Logging).
- "Health Check" Seite fuer fehlende Voraussetzungen.

## 5) Zeitplan (Vorschlag)

### Phase 1 (0-6 Wochen)
- WS1 Risk-Class Framework (MVP, durchgaengig verdrahtet)
- WS2 Benchmarking (MVP: Metrik-Schema + erster Runner + Baseline-Report)
- WS3 Bild-Input (MVP: Upload + multimodaler Planner-Aufruf)
- WS4 Preview API (MVP fuer Kern-Tasks)

### Phase 2 (6-12 Wochen)
- WS2 Benchmark-Reporting + CI-Gates fuer kritische Regressionen
- WS3 Bild-Extraktion und Kontext-Einspeisung vervollstaendigen
- WS4 Preview UI vervollstaendigen
- WS5 Kontextintegration in Navigation
- Start WS6 Simulation Mode (technischer Prototyp)

### Phase 3 (12-20 Wochen)
- Benchmark-Trendanalyse und automatische Drift-Erkennung
- WS6 produktionsreif machen
- Observability/Audit Trail
- Safety Policy Packs und Eval Harness

## 6) Messbare Erfolgskriterien
- -30% Rueckfragen vor mutierenden Aktionen (durch bessere Preview/Context).
- -40% manuelle Fehlkorrekturen nach Ausfuehrung.
- >= 95% gruen fuer Contract- und Integrations-Tests in CI.
- < 2% fehlgeschlagene Runs durch Struktur-/Routingfehler.
- 0 kritische Benchmark-Regressions ungeprueft in Produktion.
- Benchmark-Abdeckung fuer >= 80% der priorisierten Kern-Workflows.

## 7) Risiken und Gegenmassnahmen
- Risiko: zu viele Sonderfaelle in Task-Logik. Gegenmassnahme: agentic-first Architektur, skill contracts strikt halten.
- Risiko: UX-Verbesserungen ohne robuste Guardrails. Gegenmassnahme: Risk-Class Gates zuerst (WS1 vor breitem UI-Rollout).
- Risiko: Regressionen bei Refactoring. Gegenmassnahme: Golden Scenarios, Contract-Tests, schrittweises Rollout.
- Risiko: Metriken sind nicht stabil vergleichbar (Szenario-/Daten-Drift). Gegenmassnahme: feste Benchmark-Sets, versionierte Seeds, Baseline-Freeze pro Release-Zyklus.

## 8) Naechste konkrete Schritte
1. Flowchart fuer Risk-Class Konzept finalisieren und abnehmen.
2. Benchmark-Metriken und Run-Schema verbindlich festlegen (Model + Agent).
3. Backlog in Epics schneiden (Benchmarking + WS1-WS5 + Zusatzthemen).
4. Akzeptanzkriterien pro Epic als Testfaelle formulieren.
5. Phase-1 Scope auf 2-3 Kern-Workflows begrenzen und starten.