# Selector Prompt & Task Catalog Optimization

Status: Planung
Owner: bookingextension_agent Team
Erstellt: 2026-06-04

## Motivation

Analyse der LLM-Debug-Logs (Threads 222–227) zeigt drei strukturelle Schwachpunkte im Selector-Prompt:

1. **`planned_steps` ist "optional"** — steht nicht im OUTPUT_CONTRACT → LLM lässt es unzuverlässig weg
2. **Task Catalog als JSON** — token-schwer, schwer scanbar, wenig Raum für mehr Tasks
3. **Prompt-Redundanzen und Widersprüche** — "NON-OPTIONAL" Policies mit optionalem Inhalt, Dopplungen zwischen SYSTEM und OUTPUT_CONTRACT

Ziel: Den Selector-Prompt so umstrukturieren, dass er zuverlässiger, kompakter und leichter für das LLM zu verarbeiten ist.

---

## A — `planned_steps` im OUTPUT_CONTRACT verankern

### Kontext
`planned_steps` ist aktuell in der NON-OPTIONAL STEP INTENT POLICY als "optional" deklariert und fehlt komplett im OUTPUT_CONTRACT. Das LLM priorisiert den OUTPUT_CONTRACT (direkt vor `[ASSISTANT]`) — deshalb wird `planned_steps` inkonsistent erzeugt (in Thread 225/226 ja, in 227 nein).

### Umsetzung

- [ ] **A1** `planned_steps` aus der STEP INTENT POLICY entfernen (separates Dokument, weniger Verwirrung)
- [ ] **A2** `planned_steps` in den OUTPUT_CONTRACT aufnehmen — Required, kein Optional:
  ```
  planned_steps: required array.
    - Empty [] for single-step requests.
    - For multi-step requests (multiple sequential mutations): list remaining future steps
      as intent strings, beyond the current step being planned.
      Example: [{"intent":"Set trainer for Tuesday"},{"intent":"Book user for Wednesday"}]
    - Omit from OUTPUT_CONTRACT only if [PENDING PLANNED STEPS] section is present in context
      (placeholders already exist from a previous turn).
  ```
- [ ] **A3** Valides Beispiel im OUTPUT_CONTRACT ergänzen:
  ```json
  {"response_type":"task_call","commands":[{"task":"mod_booking.create_option","input":{}}],
   "planned_steps":[{"intent":"Set trainer"},{"intent":"Book user"}],"next_step_intent":"..."}
  ```
- [ ] **A4** `prompt_policy_builder.php::build_step_intent_policy()` — `planned_steps` Beschreibung entfernen (ist jetzt OUTPUT_CONTRACT)
- [ ] **A5** `phase_prompt_bundle_builder.php::build_local_output_contract_block()` — `planned_steps` zur Selector-Phase hinzufügen
- [ ] **A6** Testen: Thread mit 4-Schritt-Anfrage → prüfen ob `planned_steps` in jedem Turn vorhanden

---

## B — Task Catalog: JSON → Plain Text Format

### Kontext
Der aktuelle JSON-Katalog kostet pro Task ~600–900 Token durch verschachtelte Strukturen, escaped Characters und redundante Felder. Mit 8 Tasks = ~6.000–7.000 Token allein für den Katalog. Das lässt wenig Raum für Conversation-History und verdrängt Tasks wie `update_option_trainer` aus dem verfügbaren Kontext.

### Zielformat

Statt JSON-Array ein kompaktes, scannable Plain-Text-Format:

```
## mod_booking.create_option [mutating]
Erstellt eine neue Buchungsoption mit fixem Datum/Uhrzeit.
WANN: User will ein neues Event/eine Veranstaltung mit festem Termin anlegen
NICHT FÜR: Slot-Buchung (→ create_slotbooking_option), Selbstlernkurs (→ create_selflearning_option)
PFLICHT: text
OPTIONAL: coursestarttime, courseendtime, maxanswers, optiondates
TRIGGER: normaler Termin, Veranstaltung, Workshop, Kurs mit Datum

## mod_booking.update_option_trainer [mutating]
Weist einer Buchungsoption einen oder mehrere Trainer zu.
WANN: User will Trainer/Referent/Leiter für eine Veranstaltung setzen oder ändern
PFLICHT: optionquery, teacherquery
TRIGGER: Trainer setzen, Referent zuweisen, Kursleiter ändern
```

**Token-Einsparung:** ~75–80% pro Task → bei 8 Tasks ca. 5.000 Token gespart → Raum für 12–15 Tasks statt 8.

### Umsetzung

- [ ] **B1** `orchestrator::slim_prompt_catalog_for_planner()` — neue Serialisierungsmethode `render_catalog_as_text(array $catalog): string`:
  - `## {task} [{readonly ? 'readonly' : 'mutating'}]`
  - Beschreibung: erste 120 Zeichen, klar formuliert
  - `WANN:` — kondensierter Use-Case aus `intent` + erster `message_trigger.description`
  - `NICHT FÜR:` — Abgrenzung zu ähnlichen Tasks (wenn vorhanden)
  - `PFLICHT:` — `minimal_input` als komma-separierte Liste
  - `OPTIONAL:` — weitere `example_input` Felder (max. 6)
  - `TRIGGER:` — Keywords aus `message_triggers[].examples` (komprimiert, max. 1 Zeile)
- [ ] **B2** `sanitize_runtime_catalog_for_prompt()` — gleiche neue Serialisierung für den embeddings-retrieved Katalog
- [ ] **B3** `[TASK CATALOG]` Block im System-Prompt: von JSON-Array auf Plain-Text-Block umstellen
- [ ] **B4** `[UNAVAILABLE TASKS]` Block analog umstellen (aktuell auch JSON)
- [ ] **B5** Testen: Token-Count vorher/nachher messen (via LLM-Debug-Log requesttext Länge)
- [ ] **B6** Qualitätsprüfung: Selektor wählt korrekte Tasks in 5 Standard-Szenarien

### Abgrenzung
Die Änderung betrifft ausschließlich die **Prompt-Serialisierung** (`slim_prompt_catalog_for_planner`, `sanitize_runtime_catalog_for_prompt`). Die Task-Contracts selbst (PHP-Klassen, `get_prompt_contract()`, Embeddings-Index) bleiben unverändert.

---

## C — Prompt-Struktur bereinigen

### C1 — "NON-OPTIONAL" Widersprüche auflösen

- [ ] **C1a** Alle Policy-Abschnitte prüfen: Enthält ein "NON-OPTIONAL"-Block optional-Formulierungen ("if present", "may be omitted")? → Entweder wirklich verpflichtend machen oder in optionalen Block verschieben
- [ ] **C1b** STEP INTENT POLICY: `next_step_intent` ist required (steht in OUTPUT_CONTRACT), `planned_steps` wird nach Fix A dort verankert → Policy-Block kann stark gekürzt werden
- [ ] **C1c** DOCS ANSWER POLICY: Prüfen ob relevant für Selector oder nur für Sync — ggf. aus Selector-Prompt entfernen

### C2 — Redundanzen zwischen SYSTEM und OUTPUT_CONTRACT

Mehrere Regeln werden doppelt genannt (einmal in SYSTEM, einmal in OUTPUT_CONTRACT). Das ist teilweise sinnvoll (Reinforcement), aber teilweise widersprüchlich (wenn sie leicht unterschiedlich formuliert sind).

- [ ] **C2a** SYSTEM enthält: "For task_call, commands MUST contain exactly one command object..." → auch im OUTPUT_CONTRACT. Formulierungen angleichen (exakt gleicher Wortlaut = Reinforcement, unterschiedlicher Wortlaut = Verwirrung)
- [ ] **C2b** Routing decision order (1–5) nur im SYSTEM, nicht im OUTPUT_CONTRACT → gut so, beibehalten
- [ ] **C2c** OUTPUT_CONTRACT entfernt alles was nur SYSTEM-Level ist, enthält nur: required fields, format rules, phase-spezifische contracts

### C3 — Decision Order: Multi-Step explizit adressieren

- [ ] **C3a** Routing-Reihenfolge ergänzen um expliziten Multi-Step-Fall:
  ```
  6) multi-step request with no pending planned steps in context
     → response_type=task_call + planned_steps=[future steps]
  ```
- [ ] **C3b** Klarstellen: `planned_steps` enthält KEINE Parameter, nur Intent-Strings

### C4 — Trigger-Format vereinfachen

Aktuell: `message_triggers` als Array von `{id, description, examples[]}` Objekten (JSON in JSON).

- [ ] **C4a** In Plain-Text-Katalog (nach Fix B): Trigger als einfache Stichwort-Liste
- [ ] **C4b** Trigger-IDs weglassen (sind backend-intern, LLM braucht sie nicht zur Selektion)
- [ ] **C4c** `used_triggers` Policy bleibt unverändert (Trigger-IDs sind für Backend-Signale)

---

## D — Task-Abdeckung verbessern

### D1 — `update_option_trainer` zuverlässig im Katalog

Das Embedding für Multi-Step-Anfragen findet `update_option_trainer` oft nicht, weil semantisch "Veranstaltung erstellen" dominiert.

- [ ] **D1a** `update_option_trainer` Trigger-Beschreibung verbessern: explizit Keywords "Trainer", "Referent", "Kursleiter", "zuweisen", "setzen" aufnehmen
- [ ] **D1b** Prüfen ob `update_option_trainer` und `book_users` als "core downstream tasks" immer inkludiert werden sollen (unabhängig vom Embedding-Ergebnis) — ähnlich wie `CORESET` im Flowchart für Basis-Tasks
- [ ] **D1c** Falls D1b: In `adaptive_task_catalog_service.php::get_mandatory_tasks()` — `update_option_trainer` und `book_users` als immer-inkludierte Tasks registrieren

### D2 — `planned_steps` als Discovery-Signal nutzen

- [ ] **D2a** In der Discovery-Phase: wenn `planned_steps` aus Thread-Metadata vorhanden, Intent-Strings als zusätzliche Embedding-Query-Augmentierung verwenden (ergänzt bestehenden `next_step_intent`-Mechanismus)
- [ ] **D2b** Verhindern dass bei vorhandenen Placeholders der nächste Selector nur Tasks für den ersten Placeholder findet — Embedding-Query soll alle pending Intents berücksichtigen

---

## E — Constructor-Prompt absichern

### Kontext
Thread 226, ID 1920: Constructor gibt `"next_step_intent": null` aus — null statt String. Das bricht die `next_step_intent`-Persistenz.

- [ ] **E1** Constructor OUTPUT_CONTRACT: `next_step_intent` muss String sein (nie null, leerer String erlaubt)
- [ ] **E2** Constructor darf `planned_steps` nicht ausgeben (ist nur Selector-Phase) — explizit verbieten
- [ ] **E3** `message_persistence_service`: null-Guard für `next_step_intent` — `null` → `''` konvertieren

---

## F — Synchronizer-Prompt absichern

### Kontext
Sync sagt in Threads 223–227 wiederholt: "Diese Aufgaben können manuell über die Buchungsseiten erledigt werden." — Der Sync halluziniert über Agent-Fähigkeiten.

- [ ] **F1** Sync-Prompt: explizite Regel hinzufügen: "Never suggest manual steps as fallback unless the planner explicitly returned an error or clarification for those steps. If the planner has planned_steps or next_step_intent for pending actions, do NOT say they must be done manually."
- [ ] **F2** Sync-Prompt: "Noch ausstehend"-Formulierungen OHNE "bitte manuell" — stattdessen neutral: "wird im nächsten Schritt fortgesetzt"
- [ ] **F3** Sync erhält `planned_steps` aus dem Planner-Result als Kontext — damit er weiß was noch aussteht

---

## G — Flowchart aktualisieren

- [ ] **G1** Flowchart: `TSEL`-Node um `planned_steps` als required Output für Multi-Step updaten (war bisher "optional")
- [ ] **G2** Flowchart: Sync-Node (`SYNC_RUN`) — Notiz ergänzen: "darf keine manuellen Fallbacks vorschlagen wenn planned_steps vorhanden"
- [ ] **G3** Flowchart: Catalog-Rendering-Schritt in Discovery/Selection explizit als "plain text rendering" kennzeichnen (nach Fix B)

---

## Priorisierung und Reihenfolge

| Prio | Paket | Impact | Aufwand | Abhängigkeit |
|------|-------|--------|---------|--------------|
| P0 | **A** — `planned_steps` im OUTPUT_CONTRACT | Hoch: Multi-Step zuverlässig | Klein | — |
| P0 | **E** — Constructor null-Guard | Hoch: verhindert Datenverlust | Klein | — |
| P1 | **F** — Sync "manuell"-Halluzination | Hoch: User-Experience | Mittel | — |
| P1 | **B** — Katalog Plain Text | Sehr hoch: Token, Zuverlässigkeit | Groß | — |
| P2 | **C** — Prompt-Struktur bereinigen | Mittel: Konsistenz | Mittel | B |
| P2 | **D** — Task-Abdeckung | Mittel: Recall | Mittel | B |
| P3 | **G** — Flowchart | Dokumentation | Klein | A,B,F |

---

## Definition of Done

- [ ] Multi-Step-Anfrage (≥3 Schritte) läuft ohne User-Interaktion ("mach weiter") durch
- [ ] Selector gibt `planned_steps` in >95% der Multi-Step-Fälle korrekt aus
- [ ] `update_option_trainer` erscheint zuverlässig im Katalog wenn Trainer-Intent erkennbar
- [ ] Token-Count des Selector-Prompts um ≥40% reduziert (messbar via LLM Debug Log)
- [ ] Sync sagt nie mehr "bitte manuell erledigen" für Aktionen die der Agent ausführen kann
- [ ] Flowchart ist aktuell
