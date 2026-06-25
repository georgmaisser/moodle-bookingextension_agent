# Roadmap: bookingextension_agent

## 1) Vision
Der Agent wird von einem reinen Command-Interface zu einem verlässlichen, kontextbewussten Assistenten fuer mod_booking ausgebaut: sicher, nachvollziehbar, testbar und im Alltag nutzbar.

## 2) Strategische Ziele
- Sicherheit zuerst: mutierende Aktionen klar klassifizieren und kontrollieren.
- Bessere UX: weniger Klicks, bessere Orientierung, klarere Rueckmeldungen.
- Operative Stabilitaet: reproduzierbare Ablaeufe, starke Tests, gute Beobachtbarkeit.
- Integrationsfaehigkeit: saubere APIs fuer Preview, Simulation und externe Aufrufer.

## 2a) Umsetzungsstand (Stand 2026-06-18)

Legende: ✅ Erledigt · 🟡 Teilweise · ⏳ Offen · 📋 Nur Blueprint

| Workstream | Prioritaet | Status |
|---|---|---|
| WS1 Risk-Class Framework | P0 | ✅ Erledigt |
| WS2 Benchmarking | P0 | ✅ Erledigt |
| WS3 Bild-Input / Vision | P0 | 🟡 Teilweise (Upload/PDF ja, Vision-Aufruf offen) |
| WS4 Preview API und UI | P0 | ✅ Erledigt |
| WS5 Navigation-Kontext | P1 | ✅ Erledigt |
| WS6 Real Simulation Mode | P1 | ⏳ Offen |
| WS7 Semantische Site-Suche | P2 | 📋 Nur Blueprint |
| WS8 Whisper-Spracheingabe | P1 | ⏳ Offen (neu) |
| WS9 Event-getriggerte Agent-Aktionen | P2 | ⏳ Offen (neu) |
| 4.1 Observability / Audit Trail | P0 | 🟡 Teilweise |
| 4.2 Safety Policy Packs | P1 | ⏳ Offen |
| 4.3 Prompt/Contract Eval Harness | P1 | ✅ Erledigt |
| 4.4 API Versioning | P2 | ⏳ Offen |
| 4.5 Onboarding / Admin Enablement | P2 | ✅ Erledigt |

## 3) Priorisierte Workstreams

### WS1: Risk-Class Framework (P0) — ✅ Erledigt
**Status (2026-06-18):** Umgesetzt. R0-R3 sind verpflichtend im Skill-Contract (skill_contract_validator, keine stillen Defaults) und durchgaengig in decision/preflight/queue/synchronizer verdrahtet; Contract-Tests fuer Gate-Logik gruen.

Ziel: Einfuehrung eines deklarativen Risikoklassen-Systems (R0-R3) ueber den gesamten Agent-Flow.

Deliverables:
- task_risk_class als verpflichtende Deklaration in Task-Interface und Prompt-Contract.
- Validierung in skill_contract_validator (keine stillen Defaults).
- Durchsetzung in decision, preflight, queue und synchronizer.
- Klassenbasierte Regeln fuer confirmation, TTL und retry.

Definition of Done:
- Alle produktiven Tasks tragen eine explizite risk_class.
- Contract-Tests fuer R0-R3 und Gate-Logik gruen.

### WS2: Benchmarking und Performance-Messung (P0) — ✅ Erledigt
**Status (2026-06-18):** Umgesetzt. Benchmark-Tabellen (runs/scenarios/baselines/metrics), CLI-Runner inkl. ci-gate/export/import, Report-Seiten (compare/report/run_detail), Baseline-Pinning, Retention-Task und drei Schwellen-Settings (CI-Gate) sind vorhanden. Vertiefte automatische Drift-Erkennung ist noch ausbaufaehig.

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

### WS3: Bild-Input und visuelle Verarbeitung (P0) — 🟡 Teilweise
**Status (2026-06-18):** Teilweise. Erledigt sind Upload (Drag-and-Drop/Paste im Chat), Attachment-Token-Service, Ablage als temporaere Moodle-Datei + Cleanup-Task sowie PDF-Textextraktion (Text wird als Dokumentblock injiziert). OFFEN ist der eigentliche multimodale Vision-Aufruf: Bilder werden derzeit nur als Token-Hinweis im Text an den Planner uebergeben (attachment_processor), nicht als Bildinhalt an ein vision-faehiges Modell; die Feld-Extraktion aus Bildinhalt fehlt entsprechend noch.

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

### WS4: Preview API und UI (P0, ehem. WS3) — ✅ Erledigt
**Status (2026-06-18):** Umgesetzt. Skills liefern Preview als Daten (get_result_preview -> html/js_module/payload), die Engine reicht generisch durch; Renderer fuer Aktivitaeten, Fragen und Diagnose-Checklisten vorhanden; Uebergang Preview -> Confirm -> Execute mit queue_item-Referenz steht.

Ziel: Jede mutierende Aktion kann vor Ausfuehrung als belastbare Vorschau dargestellt werden.

Deliverables:
- API fuer strukturierte Preview-Payloads (diff, impact summary, warnings).
- Einheitliches Rendering fuer Tabellen, Feldaenderungen und betroffene Entitaeten.
- Klarer Uebergang Preview -> Confirm -> Execute inkl. queue_item Referenz.

Definition of Done:
- Preview fuer alle High-Impact Tasks verfuegbar.
- Contract-Tests decken Preview-Konsistenz ab.

### WS5: Agent im Kontext der Navigation (P1, ehem. WS4) — ✅ Erledigt
**Status (2026-06-18):** Umgesetzt. Globaler Einstieg via Navbar-Zauberstab (Hook + Fragment), Kontext-Resolver (context_resolver + skill_operating_context_resolver) fuer aktive Entitaeten und "Was kann ich hier tun"-Antworten ueber die Skill-Discovery.

Ziel: Der Agent wird kontextbewusst im UI verankert (Kurs, Option, Rolle, Filter).

Deliverables:
- Einstiegspunkte in relevanten mod_booking Screens.
- Kontext-Resolver fuer aktive Entitaeten (course, option, user scope).
- "Was kann ich hier tun?"-Antworten auf Basis des aktuellen Kontexts.

Definition of Done:
- Kontextsensitive Vorschlaege reduzieren Freitext-Fehleingaenge messbar.

### WS6: Real Simulation Mode (P1, ehem. WS5) — ⏳ Offen
**Status (2026-06-18):** Offen. Es existiert nur ein Validierungs-Dry-Run im Preflight (z. B. mform validation() vor add_activity); ein echter Simulationsmodus mit isoliertem Write-Layer und "would change"-Report ist noch nicht implementiert.

Ziel: Trockenlauf fuer mutierende Operationen mit entsorgbarem Ergebnisraum.

Deliverables:
- Simulationsmodus mit isoliertem Write-Layer (kein produktiver Commit).
- Ergebnis als "would change" Report inkl. Konflikten und Nebenwirkungen.
- Explizites Verwerfen oder Uebernahmepfad in echte Ausfuehrung.

Definition of Done:
- Simulation liefert fuer Kern-Workflows stabile und nachvollziehbare Differenzen.

### WS7: Semantische Site-Suche (P2, spaeter) — 📋 Nur Blueprint
**Status (2026-06-18):** Noch nicht begonnen. Es liegt nur das Konzeptdokument vor (siehe Blueprint unten); bewusst Phase 3+.

Ziel: Inhalte der ganzen Moodle-Site semantisch ueber den Agent auffindbar machen, ohne pro Plugin zu scrapen.

Ansatz: Moodles Search-Areas (`\core_search\base`) als Korpus nutzen — sie liefern bereits indexierbare,
zugriffskontrollierte Chunks (`\core_search\document` + `check_access()`). Die Keyword-Engine wird durch unsere
Embeddings ersetzt: Chunks embedden, in einem Vektor-Store ablegen, semantisch retrieven.

Kernpunkte:
- Sicherheit ist Make-or-Break: Retrieval IMMER per-User mit `check_access()` nachfiltern (Option A) oder als
  `\core_search\engine` implementieren, das Access nativ erbt (Option B, empfohlen pruefen).
- Skalierung: CSV-Katalog reicht nicht — echter Vektor-Store, Chunking, inkrementelles Indexing (an
  Such-Index-Task andocken) inkl. Deletes, kuratierte Area-Whitelist.
- Engine-clean: als Skill (`core.find_content`) + skill-eigene Services; Agent-Engine kennt von Site-Suche nichts.

Details + Datenmodell + Indexing-Flow: siehe Blueprint
`semantische_site_suche_embeddings_adapter_2026-06-10.md`. Bewusst spaeter (Phase 3+), nicht jetzt.

### WS8: Whisper-Spracheingabe (Voice Input) (P1) — ⏳ Offen (neu)
**Status (2026-06-18):** Neu aufgenommen, noch nicht begonnen.

Ziel: Nutzer koennen ihre Anweisung per Mikrofon einsprechen; die Aufnahme wird per Whisper (Speech-to-Text) transkribiert und als editierbare Chat-Nachricht uebernommen — analog zum bestehenden Attachment-Pfad, aber fuer Audio.

Deliverables:
- Mikrofon-Button im Chat-UI (aiinstructions) mit Aufnahme-Status, Stop/Abbrechen und Datenschutz-Hinweis.
- Browser-Audioaufnahme (MediaRecorder), Upload als temporaere Moodle-Datei ueber den bestehenden Attachment-/Token-Pfad (attachment_token_service), keine Persistenz ueber die Transkription hinaus.
- Server-seitiger Transkriptions-Service: Whisper-/STT-Aufruf ueber den konfigurierten Provider (ai_manager / LiteLLM-Endpoint, analog zu den uebrigen LLM-Aufrufen). Sprache automatisch erkannt oder aus der Moodle-Sprache abgeleitet.
- Transkript wird in das Eingabefeld geschrieben; der Nutzer korrigiert und sendet selbst — KEIN automatisches Ausfuehren.
- Privacy: Audio nur temporaer; der bestehende Anonymisierungs-/Privacy-Pfad gilt fuer den transkribierten Text wie fuer Tippeingaben; die Audio-Uebermittlung an den externen STT-Provider wird in der Privacy-API als external_location deklariert.
- Sicherheit/Fallbacks: MIME- und Groessenlimit fuer Audio; Capability wie der uebrige Agent-Zugang (useaiinstructions); kein konfiguriertes STT-Modell -> Button ausgeblendet + verstaendliche Meldung; verweigerte Mikrofon-Freigabe -> klarer Hinweis (kein stiller Fehler).

Definition of Done:
- Eine Sprachaufnahme wird transkribiert und erscheint als editierbarer Text im Chat-Eingabefeld.
- Fallback bei fehlendem STT-Modell und bei verweigerter Mikrofon-Freigabe ist abgedeckt.
- Privacy-API deklariert die Audio-Uebermittlung an den externen STT-Provider; Audio wird nicht dauerhaft gespeichert.

### WS9: Event-getriggerte Agent-Aktionen / Automationen (P2) — ⏳ Offen (neu)
**Status (2026-06-22):** Neu aufgenommen, noch nicht begonnen.

Ziel: Nutzer koennen in natuerlicher Sprache Automationen definieren, bei denen der Agent auf mod_booking-Events reagiert und daraufhin (ggf. mehrstufige, kontextabhaengige) Aktionen ausfuehrt. Beispiele:
- "Immer wenn eine Mail verschickt wird, schreib auch mir eine Mail."
- "Wenn eine Buchung storniert wird, schau in den Dienstplan und benachrichtige die Person, die gerade Dienst hat."
- "Wenn jemand auf die Warteliste kommt, informiere den Kursverantwortlichen."

Abgrenzung zu den bestehenden booking_rules: Die `rule_react_on_event`-Engine deckt feste Condition->Action-Muster ab. WS9 zielt auf **agentisch definierte und ausgefuehrte** Reaktionen mit echtem Reasoning (z. B. Dienstplan/Teacher-Assignments auslesen, Empfaenger dynamisch ermitteln, Inhalt formulieren) — ueber starre Conditions hinaus. Die bestehende Rules-/Observer-Schicht ('*'-Observer -> rules_info) ist der natuerliche Eintrittspunkt; die Agent-Engine bleibt frei von Skill-Wissen.

Deliverables:
- Natuerlichsprachliche Erfassung einer Automation (Trigger-Event + gewuenschte Aktion) -> strukturierte, persistierte Automations-Definition, mit Preview/Bestaetigung VOR Aktivierung.
- Event-Anbindung: getriggerte Ausfuehrung ueber den vorhandenen Event-/Rules-Eintrittspunkt, ohne die Engine zu verschmutzen (Automation als Skill-/Datenschicht).
- Sichere Ausfuehrung: jede getriggerte Aktion laeuft durch denselben Risk-Class/Preflight/Confirm-Pfad (WS1); mutierende Aktionen (R>=1) erfordern eine vorab erteilte, scope-begrenzte Genehmigung — kein unkontrolliertes autonomes Versenden.
- Reasoning-Schritte: read-only Datenlookups (Dienstplan, Verantwortliche, Teilnehmende) -> Empfaenger-/Inhaltsbestimmung -> Aktion (z. B. send_mail).
- Schutzmechanismen: Loop-/Rekursionsschutz (z. B. "Mail bei Mail" darf sich nicht selbst triggern), Rate-Limit, Idempotenz pro Event; klare Pausierung/Deaktivierung je Automation.
- Audit: jede automatische Ausfuehrung nachvollziehbar (welches Event -> welche Automation -> welche Aktion/Empfaenger), siehe 4.1.

Definition of Done:
- Eine natuerlichsprachlich definierte Automation laesst sich anlegen, in einer Vorschau pruefen, aktivieren und loest bei ihrem Event zuverlaessig die korrekte Aktion aus.
- Loop-Schutz, Rate-Limit und Audit greifen; mutierende Aktionen sind durch Risk-Class/Confirmation abgesichert.

Risiken/Gegenmassnahmen: Endlos-Trigger und unbeabsichtigter Massenversand -> Loop-/Rate-Schutz + Idempotenz; Datenschutz bei automatischer Empfaengerwahl und falsche Empfaenger durch fehlerhaftes Reasoning -> Confirmation-Gating, read-only-Lookups, Audit. Baut sinnvoll auf WS1 (Risk-Class), 4.1 (Observability) und 4.2 (Safety Policy Packs) auf.

## 4) Zusaetzliche sinnvolle Ideen (Vorschlaege)

### 4.1 Observability und Audit Trail (P0) — 🟡 Teilweise
**Status (2026-06-18):** Teilweise. LLM-Debug-Logging (Tabelle ai_llm_debug + llm_debug_logger + Debug-Logs-Endpoint), observability-Doku und das trial_consent_given-Event sind vorhanden. Ein vollstaendiges Lifecycle-Dashboard und exportierbare Audit-Events fuer Support/Compliance fehlen noch.

- Thread- und Queue-Lifecycle Dashboard (state transitions, retries, blocker).
- Korrelation von user action -> planner output -> execution result.
- Exportierbare Audit-Events fuer Support und Compliance.

### 4.2 Safety Policy Packs (P1) — ⏳ Offen
**Status (2026-06-18):** Offen. Es gibt eine Skill-Governance-Schicht (skill_governance), aber keine konfigurierbaren Sicherheitsprofile (strict/balanced/fast) pro Installation.

- Konfigurierbare Sicherheitsprofile pro Installation (strict, balanced, fast).
- Regeln fuer "always require manual confirmation" je Task/Scope.

### 4.3 Prompt/Contract Eval Harness (P1) — ✅ Erledigt
**Status (2026-06-18):** Weitgehend umgesetzt. Umfangreiche Contract-Tests (tests/agent/contracts), Real-LLM-Mehrschritt-Matrix, Benchmark-Golden-Scenarios und CI-Guards gegen Strukturdrift (u. a. ai_error_messaging) sind vorhanden.

- Golden Scenarios fuer typische Buchungsablaeufe.
- CI-Checks gegen Strukturdrift im Planner/Synchronizer Output.

### 4.4 API Versioning und External Integrations (P2) — ⏳ Offen
**Status (2026-06-18):** Offen. Die Webservice-Endpunkte sind funktional und readiness-gehaertet, aber (noch) nicht versioniert; ein stabiler externer Integrationsvertrag steht aus.

- Versionierte Endpunkte fuer Preview/Confirm/Poll.
- Stabiler Integrationsvertrag fuer externe Automations-Clients.

### 4.5 Onboarding und Admin Enablement (P2) — ✅ Erledigt
**Status (2026-06-18):** Weitgehend umgesetzt. Gefuehrter Trial-Setup-Assistent (request_trial_key / activate_trial_context / trial_provisioner inkl. GDPR-Consent) und eine Health-Check-/Readiness-Anzeige (aiready) fuer fehlende Voraussetzungen sind vorhanden.

- Setup-Wizard fuer erste Inbetriebnahme (Capabilities, Defaults, Logging).
- "Health Check" Seite fuer fehlende Voraussetzungen.

## 5) Zeitplan (Vorschlag)

> Hinweis (2026-06-18): Phase 1 ist grossteils abgeschlossen (WS1, WS2, WS4 erledigt; WS3 nur noch der Vision-Aufruf offen — siehe Abschnitt 2a). Aktueller Fokus verschiebt sich auf die offenen Punkte WS3-Vision, WS6, WS8 sowie die Zusatzthemen 4.1/4.2.

### Phase 1 (0-6 Wochen)
- WS1 Risk-Class Framework (MVP, durchgaengig verdrahtet)
- WS2 Benchmarking (MVP: Metrik-Schema + erster Runner + Baseline-Report)
- WS3 Bild-Input (MVP: Upload + multimodaler Planner-Aufruf)
- WS4 Preview API (MVP fuer Kern-Tasks)

### Phase 2 (6-12 Wochen)
- WS3 Vision-Aufruf nachziehen (Bild an vision-faehiges Modell, Feld-Extraktion und Kontext-Einspeisung)
- WS8 Whisper-Spracheingabe (MVP: Aufnahme + Upload + Transkription ins Eingabefeld)
- Start WS6 Simulation Mode (technischer Prototyp)
- 4.1 Observability: Lifecycle-Dashboard + exportierbare Audit-Events

### Phase 3 (12-20 Wochen)
- Benchmark-Trendanalyse und automatische Drift-Erkennung
- WS6 produktionsreif machen
- Observability/Audit Trail
- Safety Policy Packs und Eval Harness
- WS7 Semantische Site-Suche (Analyse/Prototyp; Option A vs. `\core_search\engine`)
- WS9 Event-getriggerte Agent-Aktionen (Analyse/Prototyp; baut auf WS1 + 4.1 + 4.2 auf)

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