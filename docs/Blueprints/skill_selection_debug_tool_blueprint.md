# Blueprint: Task Selection Debug Tool

## Ziel

Ein internes Debug-Tool bereitstellen, um Task Selection reproduzierbar zu testen und bei wachsendem Task-Katalog stabil zu halten.

Dieses Blueprint fokussiert auf zwei Hauptfaelle:

1. Task-Embedding/Prompt-Kollisionen zwischen Tasks sichtbar machen.
2. Einzelne Eingaben sofort gegen den Selection-Stack testen (dry-run, ohne Execution).

## Leitplanken

- Keine task-spezifischen Klaerungen im allgemeinen Prompt-Teil.
- Task-spezifische Guidance bleibt in Task Contracts / Task Prompt Packs.
- Debug-Tool darf keine mutierenden Tasks ausfuehren.
- Zugriff nur mit eigener Capability.

## Scope (MVP)

### A. Selection Simulator (dry-run)

Eingabeformular mit:

- User input (Text)
- Optional contextid/cmid
- Optional: include unavailable tasks (ja/nein)
- Optional: top-k limit

Ausgabe:

- Selektierter Task (Top-1)
- Top-N Kandidaten mit Ranking/Score (sofern verfuegbar)
- Katalogmodus (slim_all, embed_topk, family stage, fallback)
- Warum ein Kandidat ausgeschlossen wurde (capability, disabled, family filter)
- Rohdaten (JSON) als ausklappbarer Block

### B. Task Collision Analyzer

Analyse ueber alle aktiven Task Contracts:

- Paarweise Similarity-Matrix (cosine)
- Heatmap/Sortierung nach hoechster Kollision
- Schwellwerte (z.B. warn >= 0.82, high >= 0.90)
- Diff-Ansicht fuer kollidierende Paare:
  - description
  - message_triggers
  - minimal_input
  - example_input

### C. Dataset Regression Runner (CLI)

Batch-Test fuer Selection-Faelle:

- Input -> expected task
- optional acceptable alternatives
- Ergebnisbericht:
  - accuracy top-1
  - recall top-3
  - regressions seit baseline

## Capability und Sicherheit

Neue Capability:

- bookingextension/agent:debugskillselection

Empfehlung:

- captype: write
- contextlevel: CONTEXT_MODULE
- archetypes:
  - manager: CAP_ALLOW
- teacher standardmaessig NICHT erlauben

Sicherheitsregeln:

- Seite nur fuer capability-Inhaber sichtbar.
- Nur dry-run, keine Queue/Execution.
- PII in Outputs standardmaessig anonymisieren.
- Optionaler Raw-Output nur mit moodle/site:config.

## Technisches Design

### 1) UI Seite

Empfohlener Einstiegspunkt:

- admin external page unter modbookingfolder
- Seite: Task Selection Debug

Form-Submit verarbeitet serverseitig:

- Input normalisieren
- Selection pipeline in dry-run Modus aufrufen
- Ergebnis rendern

### 2) Service Layer

Neue Services (Vorschlag):

- classes/local/wbagent/services/debug/skill_selection_debug_service.php
- classes/local/wbagent/services/debug/task_similarity_analyzer.php
- classes/local/wbagent/services/debug/skill_selection_dataset_runner.php

Service-Aufgaben:

- Selection simulation kapseln
- Candidate/score details vereinheitlichen
- Similarity matrix berechnen
- Dataset Result DTOs liefern

### 3) CLI

Neue CLI Kommandos (Vorschlag):

- cli/skill_selection_debug.php
  - --input="..."
  - --contextid=...
  - --topk=...
  - --json
- cli/skill_selection_dataset.php
  - --file=/path/to/cases.json
  - --contextid=...
  - --out=/path/to/report.json

### 4) Datenformat Testfaelle

Beispiel JSON:

```json
[
  {
    "id": "book-user-portishead2",
    "input": "Buche Billy Teachy als Teilnehmer in Portishead 2",
    "expected": "mod_booking.book_users",
    "alternatives": []
  },
  {
    "id": "assign-trainer-portishead1",
    "input": "Mach Billy Teachy zum Trainer von Portishead 1",
    "expected": "mod_booking.update_option",
    "alternatives": []
  }
]
```

## Metriken

- Selection Accuracy (Top-1)
- Selection Recall (Top-3)
- Anzahl High-Collision Paare
- Anzahl Fälle mit leerem selected_skill
- Anteil faelschlicher Clarification bei bereits vorhandenen Schluesselparametern

## Umsetzungsplan mit Checkboxen

### Phase 1 - Infrastruktur

- [ ] Neue Capability in db/access.php definieren
- [ ] Sprachstrings fuer Capability und Debug-Seite anlegen
- [ ] Admin/Navigation-Eintrag fuer Debug-Seite anlegen
- [ ] Grundgeruest der Seite (Form + Result Container) erstellen

### Phase 2 - Selection Simulator

- [ ] Debug-Service fuer dry-run Selection implementieren
- [ ] Ausgabe: selected task + top candidates + routing metadata
- [ ] Ausgabe: exclusion reasons (disabled/capability/filter)
- [ ] JSON-Rohausgabe mit Toggle integrieren

### Phase 3 - Collision Analyzer

- [ ] Similarity-Analyzer Service implementieren
- [ ] Pairwise Matrix berechnen
- [ ] Schwellwerte warn/high konfigurierbar machen
- [ ] Top-Kollisionen + Diff-Ansicht anzeigen

### Phase 4 - CLI Regression

- [ ] CLI fuer single-input dry-run implementieren
- [ ] CLI fuer Dataset-Batch implementieren
- [ ] JSON Report + einfache textuelle Zusammenfassung
- [ ] Baseline Vergleich (optional Datei) integrieren

### Phase 5 - Tests

- [ ] Unit-Tests fuer Similarity Analyzer
- [ ] Unit-Tests fuer Dataset Runner
- [ ] Integrationstest fuer dry-run Selection ohne Execution
- [ ] Capability-Test: Seite ohne Recht nicht erreichbar

### Phase 6 - Rollout

- [ ] Dokumentation im Team teilen
- [ ] Initiales Golden Dataset fuer kritische Booking-Flows erstellen
- [ ] Erste Kollisionsrunde fahren und Top 10 Paare bereinigen
- [ ] CI Job fuer Dataset-Regression (nightly) vorbereiten

## Akzeptanzkriterien (Definition of Done)

- Debug-Seite ist nur mit bookingextension/agent:debugskillselection sichtbar.
- Dry-run fuehrt keinen Task aus und schreibt nichts in Queue/Runs.
- Fuer einen Input ist Top-1 + Top-N nachvollziehbar sichtbar.
- Kollisionen sind als sortierte Liste mit Score und Diff sichtbar.
- Dataset CLI erzeugt reproduzierbaren JSON-Report.

## Risiken

- Zugriff auf Provider-Embeddings kann teuer/langsam sein.
  - Mitigation: Cache + local fallback + top-k limit.
- Debug-Ausgaben koennen intern zu viele Details zeigen.
  - Mitigation: Rolle/Capability-gestufte Sicht.
- Uneinheitliche Score-Skalen zwischen Quellen.
  - Mitigation: einheitliches Debug DTO mit score_source Kennzeichnung.

## Offene Entscheidungen

- Soll die Seite pro Kursmodul-Kontext oder global betrieben werden?
- Soll teacher eine read-only Variante erhalten?
- Welche Schwellwerte gelten initial fuer warn/high collision?
- Soll der Dataset Runner Teil der CI werden oder nur manuell laufen?
