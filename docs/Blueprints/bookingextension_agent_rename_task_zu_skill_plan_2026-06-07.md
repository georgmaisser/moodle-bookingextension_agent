# Plan: Umbenennung "Task" → "Skill" im Agent-Subsystem

> **Datum:** 2026-06-07
> **Anlass:** Der Begriff "Task" kollidiert mit Moodles eigenem Task-Konzept (`\core\task\adhoc_task`, `\core\task\scheduled_task`, Cron-System) und ist daher für die Agent-Domäne unglücklich gewählt. Zielbegriff: **"Skill"**.
> **Charakter dieses Dokuments:** Planungsgrundlage zur Diskussion — **keine Umsetzung**. Enthält Scope-Inventur, Risikoeinschätzung pro Schicht, Phasenvorschlag und offene Entscheidungsfragen.

---

## ⚠️ Update 2026-06-07: Vorproduktiv-Status ändert die Empfehlung grundlegend

**Georgs Vorgabe:** *"Wir sind noch nicht produktiv, daher benötigen wir keine Migration. Wir können das noch vor Release bereinigen. Es spricht viel dafür, das radikal und vollständig durchzuführen."*

Das ändert die Risikolage massiv, weil der **gesamte Abschnitt 4 (Schicht B — Migrationspflicht)** entfällt:

- **Keine produktiven Daten** → DB-Felder (`task_set`, `task_expected`, `task_selected`), Capabilities (`bookingextension/agent:task_*`) und Settings (`aitaskenabled` etc.) können **direkt in `install.xml`/`access.php`/`settings.php` umbenannt** werden — kein `db/upgrade.php`-Migrationsschritt, kein Capability-Copy-Pattern, kein `set_config`/`unset_config`. Einfach: alten Namen durch neuen ersetzen, fertig.
- **Keine laufenden Konversationen mit Bestandsdaten** → auch Schicht C (LLM-Wire-Format `"task"`/`"task_call"`) verliert ihren größten Risikofaktor (Inkompatibilität mit "in-flight"-Threads). Was bleibt, ist "nur" der Prompt-Engineering-/Testaufwand (Schema, Prompt-Texte, Interpreter, Real-LLM-Tests neu verifizieren) — kein Daten-Kompatibilitätsproblem mehr.
- Das spricht in der Tat für die von Georg vorgeschlagene **radikale, vollständige Variante**: alle fünf Schichten inkl. Wire-Format in einem zusammenhängenden Vorhaben umbenennen, statt halbe Sachen zu machen und dauerhaft mit der Inkonsistenz "intern Skill, extern/im Protokoll Task" zu leben.

**Die folgenden Abschnitte 1–10 sind die ursprüngliche, vorsichtigere Analyse — sie bleibt als Risiko-Inventur und Nachschlagewerk wertvoll** (sie zeigt z. B. genau, welche Dateien betroffen sind und wo die fachlichen Fallstricke liegen). **Abschnitt 11 wurde durch den finalen, radikalen Umsetzungsplan ersetzt** (s. u.) — das ist jetzt der maßgebliche Abschnitt für die Umsetzung.

---

## 1. Executive Summary

Eine Grep-Hochrechnung über `bookingextension/agent/` und `mod_booking/classes/local/wbagent/` zeigt **~230 Dateien** mit Treffern für "task" (case-insensitive), davon **~60 Dateien**, deren Dateiname selbst "task" enthält. Der Begriff ist auf **fünf unterschiedlichen Schichten** verankert:

| Schicht | Beispiel | Aufwandscharakter |
|---|---|---|
| A — Internes PHP (Klassen, Namespaces, Interfaces, DTOs, Methoden) | `task_registry`, `task_interface`, `base_task` | mechanisch, automatisierbar |
| B — Identifier in Schema/Config (DB-Felder, Capabilities, Settings, Registry-Strings) | `task_set`, `bookingextension/agent:task_*`, `aitaskenabled` | mechanisch — **vorproduktiv ohne Migration direkt änderbar** (s. Update-Box oben) |
| C — LLM-Vertragsebene / Wire-Format | `"task": "booking.create_option"`, `response_type: "task_call"`, Prompt-Texte | Prompt-/Schema-/Interpreter-Anpassung + Real-LLM-Testlauf nötig — **kein Daten-Kompatibilitätsrisiko mehr** (vorproduktiv) |
| D — User-facing Strings | Lang-Keys, UI-Labels, Capability-Beschreibungen | Fleißarbeit, zweisprachig (DE/EN) |
| E — Doku/Tests/Blueprints | Inventur, Flowchart, 60+ Test-Dateien | mechanisch, folgt aus A/C |

**Kernaussage (revidiert):** Da noch keine Produktivdaten existieren, entfällt der ursprüngliche Hauptgrund gegen eine vollständige Umbenennung (Migrationspflicht für Schicht B, Datenkompatibilität für Schicht C). Eine **radikale, vollständige Umbenennung in einem zusammenhängenden Vorhaben ist jetzt die empfohlene Variante** — siehe finalen Plan in Abschnitt 11. Abschnitte 2–10 bleiben als detaillierte Fundstellen-Inventur und Risikobeschreibung pro Schicht erhalten (sie beschreiben weiterhin korrekt, *was* sich wo ändert — nur die Empfehlung "schrittweise mit Migration" ist durch "radikal in einem Zug, ohne Migration" ersetzt).

---

## 2. Begriffsklärung: Wo "Task" bleiben MUSS

Nicht jedes Vorkommen von "task" ist ein Umbenennungskandidat. Drei Dateien implementieren **echte Moodle-Core-Task-Klassen** (Cron-Subsystem) — diese MÜSSEN "task" heißen, weil sie von `\core\task\adhoc_task` / `\core\task\scheduled_task` erben und über das Verzeichnis `classes/task/` von Moodles Cron-Autodiscovery gefunden werden:

- `bookingextension/agent/classes/task/execute_ai_run_adhoc.php` (extends `adhoc_task`)
- `bookingextension/agent/classes/task/rebuild_task_catalog_embeddings_adhoc.php` (extends `\core\task\adhoc_task`)
- `bookingextension/agent/classes/task/cleanup_old_benchmark_runs_task.php` (extends `scheduled_task`)

→ **Diese drei Dateien, ihr Verzeichnis `classes/task/` und alle Moodle-Cron-Registrierungen (`db/tasks.php`, falls vorhanden) bleiben unverändert.** Genau diese Doppelbelegung ("Moodle-Task" vs. "Agent-Task") ist ja der Auslöser für die Umbenennung — die Abgrenzung muss im Code (und in der Doku) klar sichtbar bleiben: *"Skill = was der Agent tun kann"* vs. *"Task = Moodles Cron-Job"*.

**Alles andere**, was sich auf das fachliche Konzept "vom Agenten ausführbare Fähigkeit/Aktion" bezieht (`task_interface`, `base_task`, `task_registry`, `booking_task_*`, `*_task.php` in `options/tasks/` und `core/tasks/`, `task_risk_class`, `response_type: task_call`, etc.), ist Umbenennungskandidat → **"Skill"**.

---

## 3. Schicht A — Internes PHP (Klassen, Namespaces, Interfaces, DTOs, Methoden)

### Umfang
~60 Dateien mit "task" im Namen, u. a.:

- **Kern-Infrastruktur**: `task_registry.php`, `task_registry_factory.php`, `task_discovery.php`, `task_provider.php`, `base_task.php`, `task_executability_evaluator.php`, `task_contract_validator.php`
- **Interfaces** (`interfaces/`): `task_interface.php`, `task_provider_interface.php`, `task_input_normalizer_interface.php`, `task_input_normalizer_provider_interface.php`, `task_trigger_provider_interface.php`, `task_result_summary_provider_interface.php`, `task_input_normalizer_provider_interface.php`
- **DTOs** (`dto/`): `task_risk_class.php`, `task_selection_result.php`
- **Contracts**: `task_family_contract.php`
- **Services**: `task_version_policy.php`, `task_prompt_contract.php`, `task_governance_service.php`, `task_selector.php`, `lazy_task_loader.php`, `task_selection_overlap_policy.php`, `adaptive_task_catalog_service.php`, `task_selection_debug_service.php`
- **Konkrete Skills** (Tasks): `options/tasks/*.php` (19 Dateien: `create_option_task.php`, `update_option_task.php`, `book_users_task.php` etc.), `core/tasks/*.php` (6 Dateien), `booking_task_base.php`, `core_task_base.php`
- **Booking-Provider-Schicht**: `booking_task_provider.php`, `booking_task_support.php`, `booking_task_mutation_execute_service.php`, `provider_task_input_normalizer.php`

### Charakter der Änderung
Mechanisches Rename: Dateinamen, Klassennamen, Namespace-Segmente (`...\options\tasks\` → `...\options\skills\`, `...\core\tasks\` → `...\core\skills\`), Methodennamen (`get_task_names()` → `get_skill_names()`, `get_task()` → `get_skill()`, `is_task_active()` → `is_skill_active()`, `task_registry` → `skill_registry` usw.), Property-/Variablennamen, PHPDoc.

### Risiko
**Niedrig** — reines Compile-Time-Refactoring, von PHP-Tooling (IDE-Rename, `grep`+`sed`) weitgehend automatisierbar und durch die 36 Contract-Tests + 15 Scenario-Tests sofort verifizierbar. Kein Einfluss auf Laufzeitverhalten oder persistierte Daten, **solange** die in Schicht B/C beschriebenen String-Identifier separat behandelt werden (s. u. — Klassennamen ≠ Registry-Namen!).

### Wichtige Falle
`task_registry::get_task_names()` liefert **String-Identifier** (z. B. `"booking.create_option"`), die in Prompts an die LLM gehen und in der DB persistiert werden (`commandsjson`, Queue-Items). Diese Strings sind **nicht** automatisch von einer Klassenumbenennung betroffen — sie gehören zu Schicht C/B und müssen separat entschieden werden (s. Abschnitt 5).

---

## 4. Schicht B — Identifier in Schema/Config (DB-Felder, Capabilities, Settings)

> **⚠️ Hinweis:** Der folgende Abschnitt beschreibt, *welche* Stellen betroffen sind und *wie* eine Migration aussähe — **diese Migrationsschritte entfallen jedoch komplett**, weil noch keine Produktivinstallation existiert (s. Update-Box am Dokumentenanfang). Die Inhalte bleiben als Fundstellen-Liste relevant: alle hier genannten Bezeichner werden direkt in `install.xml`/`access.php`/`settings.php` umbenannt, ohne `db/upgrade.php`-Schritt, ohne Capability-Copy-Pattern, ohne `set_config`/`unset_config`.

Ursprüngliche Einschätzung (gilt für eine produktive Installation, **hier nicht anwendbar**): Diese Schicht erfordert **Moodle-Upgrade-Steps** (`db/upgrade.php` + Versionsbump), weil die Werte bereits in produktiven Datenbanken liegen können.

### 4.1 DB-Schema-Felder (`db/install.xml`)
Drei Felder in den Benchmark-Tabellen:
```
local_wbagent_benchmark_runs.task_set
local_wbagent_benchmark_scenarios.task_expected
local_wbagent_benchmark_scenarios.task_selected
```
→ Migration via `$dbman->rename_field()` in `db/upgrade.php`, neuer Upgrade-Step mit `upgrade_plugin_savepoint()`. Risiko: gering (reine Spaltenumbenennung, keine Datenkonvertierung), aber **muss** als Upgrade-Step erfolgen, sonst brechen produktive Installationen.

### 4.2 Capabilities (`db/access.php`)
```
bookingextension/agent:task_<taskname>     (z. B. task_booking_create_option, ~50 Stück, generiert aus $teachertasks/$managertasks/$adminonlytasks)
bookingextension/agent:debugtaskselection
```
**Wichtig:** Capability-Namen werden bei Plugin-Installation in `mdl_capabilities` und bei Rollenzuweisungen in `mdl_role_capabilities` persistiert. Moodle bietet **keine native "rename capability"-API** — der Standardweg ist:
1. Neue Capability unter neuem Namen in `access.php` deklarieren
2. In `db/upgrade.php` die Rollenzuweisungen von alt → neu kopieren (`$DB->get_records('role_capabilities', ['capability' => $old])`, dann Insert unter neuem Namen)
3. Alte Capability-Einträge entfernen (`$DB->delete_records('role_capabilities', ...)`, `$DB->delete_records('capabilities', ...)`)

Dies ist ein **nicht-triviales, fehleranfälliges Upgrade-Pattern** — Fehler hier führen dazu, dass Lehrende/Admins nach dem Update plötzlich keine Berechtigung mehr für Agent-Skills haben.

### 4.3 Settings (`settings.php`, gespeichert in `config_plugins`)
Beispiele: `aitaskenabled`, `aitaskenableall`, `aitaskgovernanceheading`, `aitaskgovernanceunavailable`, `taskselectiondebug`, `benchmark_threshold_task_hit_rate`, sowie die generierten Per-Skill-Toggle-Settings (`task_registry::get_task_toggle_setting_name()`).

→ Migration via `set_config()`/`unset_config()` in `db/upgrade.php`, um bestehende Admin-Konfigurationen (z. B. welche Skills aktiviert sind) zu erhalten. Auch hier: **wenn vergessen, verlieren bestehende Installationen ihre Skill-Aktivierungs-Konfiguration beim Update.**

### 4.4 Registry-Namen / persistierte Skill-Identifier
`task_registry::get_task_names()` liefert Strings wie `"booking.create_option"`, `"core.search_users"` — diese werden:
- in `commandsjson` (`local_wbagent_ai_runs`) und `structuredjson` (`local_wbagent_ai_messages`) als JSON persistiert
- in Queue-Items (`queue_manager::enqueue_command()` → Feld `'task' => $taskname`) gespeichert
- im `command_schema.json` validiert (Property-Key `"task"`)

→ Diese Identifier sind primär **Namespace-Präfixe + fachliche Aktionsnamen** (`booking.create_option`), nicht das Wort "task" selbst — eine Umbenennung des *Konzepts* "Task" zu "Skill" erfordert hier **nicht zwingend** eine Änderung dieser Strings. Eine Änderung des Envelope-Schlüssels `"task"` → `"skill"` im JSON-Format wäre dagegen Schicht C (s. u.) und deutlich risikoreicher.

---

## 5. Schicht C — LLM-Vertragsebene / Wire-Format (höchstes Risiko)

Das ist die kritischste Schicht, weil sie das **Protokoll zwischen Server und LLM** sowie **bereits laufende Konversationen** betrifft.

### Betroffene Artefakte
1. **`config/command_schema.json`**: JSON-Schema definiert den Envelope-Schlüssel `"task"` als Pflichtfeld für jedes Command (`{"task": "booking.create_option", "version": 1, "input": {...}}`)
2. **`prompts/initial_system_prompt.md`**: Definiert `response_type` Enum-Wert `"task_call"` als Vertragswert, den die LLM zurückgeben muss; enthält Beispiel-JSON mit `"task": "booking.create_option"`
3. **`interpreter.php`**: Parst und validiert genau diese Schlüssel/Werte (`normalize_commands_payload()`, `enforce_phase_contract()`)
4. **`finalization_classifier.php`**: Klassifiziert u. a. nach `response_type === 'task_call'` (siehe Flowchart `LG_MATRIX`, Regel 2 + 6)
5. **Persistierte Konversationen**: `commandsjson`/`structuredjson` in laufenden/historischen Threads enthalten den alten Envelope (`"task": ...`, `"response_type": "task_call"`)

### Warum ursprünglich als riskant eingestuft? (Kontext für die Einschätzung)
- **Lebende LLM-Verträge**: Eine Änderung des JSON-Schlüssels `"task"` → `"skill"` oder des Enum-Werts `"task_call"` → `"skill_call"` bedeutet, dass **alle Prompts neu formuliert**, das **JSON-Schema geändert**, der **Interpreter angepasst** und die **gesamte Real-LLM-Testsuite** (7 Dateien, benötigt Live-Provider) neu verifiziert werden müssen — das ist Prompt-Engineering-Arbeit mit empirischem Charakter, nicht reines Refactoring. *(Dieser Arbeitsaufwand bleibt bestehen — nur das zusätzliche Daten-Kompatibilitätsrisiko entfällt, s. u.)*
- ~~**Kompatibilität mit laufenden Threads**: Threads, die mitten in einem mehrstufigen Run stecken (`planner_trace_history`, `phase_trace`, gespeicherte `next_step_intent`), enthalten u. U. den alten Envelope. Der Interpreter müsste beide Formate parsen können.~~ → **entfällt vorproduktiv**: keine laufenden Threads mit alten Envelopes vorhanden.
- **Benchmark-Baselines**: `local_wbagent_benchmark_baselines` und Scenario-Fixtures referenzieren `response_type_expected = 'task_call'` — diese werden im Zuge des Renames **mit aktualisiert** (Teil des Vorhabens, kein externes Kompatibilitätsproblem, da es sich um eigene Test-Fixtures handelt, nicht um Produktivdaten).

### Empfehlung (revidiert durch Update-Box)
Die ursprüngliche Empfehlung *"Schicht C zunächst nicht anfassen"* basierte auf dem Risiko **"laufende Konversationen mit Bestandsdaten brechen"**. Da wir **vorproduktiv** sind, entfällt genau dieses Risiko — es gibt keine "in-flight"-Threads, die durch ein geändertes Wire-Format kollabieren könnten. Damit bleibt von Schicht C nur noch der **Arbeitsaufwand** übrig (Prompt neu formulieren, Schema ändern, Interpreter anpassen, Real-LLM-Tests neu verifizieren) — kein strukturelles Risiko mehr. Das ist nicht trivial, aber planbar und gehört nach Georgs Vorgabe explizit mit ins radikale Gesamtvorhaben (s. Abschnitt 11). Wichtig bleibt: **Real-LLM-Tests müssen nach der Umbenennung vollständig neu laufen**, da sich das Vertragsformat ändert, auf das die LLM reagiert.

---

## 6. Schicht D — User-facing Strings (Lang-Files, UI)

### Umfang
66+ Lang-String-Keys in `lang/en/` und `lang/de/` (je ~73/77 KB) mit "task" im Key oder Wert, u. a.:
- `agent_booking_unknown_task`, `agent_decision_command_missing_task`, `agent_executor_task_not_registered`
- `ai_status_taskcall_*` (10+ Strings — UI-Anzeige "Aktion wird ausgeführt: ...")
- `ai_action_explain_task_schema`, `ai_action_recreate_task_catalog`
- `aitaskenabled_label`, `aitaskenableall`, `aitaskgovernanceheading` (Settings-Beschriftungen)
- `taskselectiondebug` (Debug-Tool-Titel)

### Charakter
Mischung aus:
- **Key-Renames** (folgen aus Schicht B/Settings-Renames — z. B. `aitaskenabled` → `aiskillenabled`)
- **Label-/Text-Änderungen** ("Task" → "Skill" in der sichtbaren Beschriftung, z. B. "Task Selection Debug" → "Skill Selection Debug")
- **Übersetzungsarbeit**: DE-Datei muss konsistent mitgepflegt werden (kein automatisches Mapping zwischen EN/DE-Key und -Text)

### Risiko
**Mittel** — kein technisches Risiko, aber Aufwand durch Volumen + Zweisprachigkeit + Gefahr von "lost in translation" bei Strings, die sowohl die Schicht-B-Rename-Folgen als auch reine Wortlaut-Änderungen mischen.

---

## 7. Schicht E — Dokumentation, Tests, Blueprints

- **Tests**: 7 Dateien mit "task" im Namen (`*_task_*test.php`), zusätzlich Testklassen wie `abstract_llm_task_matrix_testcase.php`, `llm_task_matrix_scenario_provider.php`, `r3_task_e2e_test.php` — folgen mechanisch aus Schicht-A-Renames
- **Blueprint**: `AGENT_IMPLEMENTATION_FLOWCHART.mmd` referenziert `TR` (`task_registry`), `TI` (`task_interface`), `TPC`, `TRC`, `TCV`, `BTASK`, `CTASK`, `BT` — alle Knoten-Labels und Kommentare müssten nachgezogen werden
- **Inventur-Dokumente**: alle bisherigen Inventuren (`bookingextension_agent_inventur_*.md`) sind Snapshots und müssen NICHT rückwirkend angepasst werden — sie dokumentieren den Stand zum jeweiligen Datum

### Risiko
**Niedrig** — reine Dokumentationspflege, kann zeitlich entkoppelt vom Code-Rename erfolgen (Doku darf kurzzeitig hinterherhinken, sollte aber nicht dauerhaft divergieren — siehe `[[feedback_flowchart_policy]]`: Diskrepanzen Code↔Flowchart sind mit dir zu klären).

---

## 8. (Verworfen) Ursprünglicher gestaffelter Phasenplan

> Der ursprüngliche Vorschlag, Schicht B per Migration und Schicht C gar nicht oder separat zu behandeln, ist durch Georgs Vorgabe **obsolet**. Er ist hier nur noch der Vollständigkeit halber als Kontrast zum finalen Plan (Abschnitt 11) referenziert — die Reihenfolge der Schichten A→E bleibt im finalen Plan im Kern erhalten, nur Schicht B verliert ihren Migrationscharakter und Schicht C wird Teil des Hauptvorhabens statt ausgeklammert.

---

## 9. (Erledigt) Ursprünglich offene Entscheidungen — jetzt durch Georgs Vorgabe beantwortet

| Frage (aus der ursprünglichen Analyse) | Antwort durch Georgs Vorgabe vom 2026-06-07 |
|---|---|
| Soll Schicht C (Wire-Format) angefasst werden? | **Ja** — radikal und vollständig, da kein Datenkompatibilitätsrisiko mehr besteht |
| Sollen Wire-IDs wie `booking.create_option` umbenannt werden? | Bleibt **fachlich unverändert empfohlen** — es sind Aktionsnamen (Namespace.Aktion), keine "Task"-Wortverwendungen; eine Umbenennung hier wäre eine andere Baustelle (Aktionsnamen-Konvention) und nicht Teil des "Task→Skill"-Anliegens. Siehe Hinweis in Abschnitt 11, Schritt 5.
| Soll die Migrationsschicht (B) ins Vorhaben? | **Ja, aber ohne Migration** — direkte Umbenennung in `install.xml`/`access.php`/`settings.php`, da vorproduktiv |
| Versionsstrategie? | Entfällt — kein Upgrade-Step nötig, da keine Bestandsdaten zu konvertieren sind. Ein normaler Versionsbump (`version.php`) zur Kennzeichnung des Release reicht aus. |

---

## 10. (Ersetzt) Ursprüngliche Aufwandsschätzung

> Ersetzt durch die konsolidierte Schätzung in Abschnitt 11 — die ursprüngliche Schätzung ging von einer separaten, migrationsbehafteten Phase 4 und einer ausgeklammerten Phase 5 aus, was beides entfällt.

---

## 11. ✅ Finaler Umsetzungsplan: Radikale Komplettumbenennung (vorproduktiv, ohne Migration)

**Leitentscheidung (Georg, 2026-06-07):** *"Wir sind noch nicht produktiv, daher benötigen wir keine Migration. Wir können das noch vor Release bereinigen. Es spricht viel dafür, das radikal und vollständig durchzuführen."*

Damit lautet der Auftrag: **"Task" verschwindet als Agent-Domänenbegriff vollständig — in Code, Doku, UI, Schema, Settings, Capabilities UND im LLM-Wire-Format — und wird konsequent durch "Skill" ersetzt.** Einzige bleibende Ausnahme: die drei echten Moodle-Core-Task-Klassen in `classes/task/` (s. Abschnitt 2) — diese Abgrenzung wird durch die Umbenennung sogar **deutlicher und selbsterklärender**, weil "Skill" und "Task" dann zwei klar getrennte Konzepte im selben Plugin sind.

### Schritt-für-Schritt-Reihenfolge

```
Schritt 1 — Vorbereitung
            • Branch/Worktree anlegen (radikaler Rename = große, gut isolierbare Änderung)
            • Vollständigen Testlauf VOR dem Rename als Baseline dokumentieren
              (36 Contract-Tests, 15 Scenario-Tests, 7 Real-LLM-Tests — Ausgangszustand sichern)

Schritt 2 — Schicht A: Internes PHP-Rename
            ├─ 2a: Interfaces + DTOs + Contracts (interfaces/, dto/, contracts/)
            ├─ 2b: Kern-Infrastruktur (task_registry → skill_registry, base_task → base_skill,
            │       task_discovery → skill_discovery, task_executability_evaluator → ...,
            │       task_contract_validator → ...)
            ├─ 2c: Konkrete Skills — Verzeichnisse + Klassen
            │       (options/tasks/ → options/skills/, core/tasks/ → core/skills/,
            │        *_task.php → *_skill.php, z.B. create_option_task → create_option_skill)
            └─ 2d: Booking-Provider-Schicht (booking_task_* → booking_skill_*)
            Nach jeder Unterphase: Contract-Test-Lauf gegen Baseline

Schritt 3 — Schicht C: LLM-Wire-Format (jetzt TEIL des Hauptvorhabens, nicht ausgeklammert)
            • config/command_schema.json: Envelope-Schlüssel "task" → "skill"
            • prompts/initial_system_prompt.md: response_type-Wert "task_call" → "skill_call",
              alle Beispiel-JSONs und Erläuterungstexte aktualisieren
            • interpreter.php: Parsing/Validierung auf neue Schlüssel/Werte umstellen
              (normalize_commands_payload, enforce_phase_contract)
            • finalization_classifier.php: Klassifikationsregeln auf "skill_call" umstellen
              (Flowchart LG_MATRIX Regel 2 + 6)
            • Benchmark-Fixtures/-Baselines: response_type_expected/actual-Werte aktualisieren
            ⚠️ Danach: VOLLSTÄNDIGER Real-LLM-Testlauf (7 Dateien, Live-Provider) — dies ist
               der einzige Schritt mit empirischem statt mechanischem Charakter; ggf. mehrere
               Iterationen nötig, falls die LLM auf den neuen Vertrag anders reagiert als erwartet

Schritt 4 — Schicht B: Schema/Config — DIREKT umbenennen, KEINE Migration
            • db/install.xml: task_set → skill_set, task_expected → skill_expected,
              task_selected → skill_selected (+ ggf. Tabellennamen-Suffixe prüfen)
            • db/access.php: bookingextension/agent:task_* → bookingextension/agent:skill_*,
              debugtaskselection → debugskillselection, $teachertasks/$managertasks/
              $adminonlytasks-Arrays + $buildtaskcapability-Helper umbenennen
            • settings.php: aitaskenabled → aiskillenabled, aitaskenableall → aiskillenableall,
              aitaskgovernanceheading → aiskillgovernanceheading, taskselectiondebug → ...,
              benchmark_threshold_task_hit_rate → benchmark_threshold_skill_hit_rate
            • task_registry::get_task_toggle_setting_name() → get_skill_toggle_setting_name()
              (erzeugt die Settings-Namen — muss konsistent mit obigen Renames sein)
            • version.php: Versionsbump (reines Kennzeichnen des Release, kein Upgrade-Step
              mit Datenkonvertierung nötig — ggf. Tabellen können sogar neu angelegt werden,
              falls das einfacher ist als rename_field, s. Hinweis unten)

Schritt 5 — Persistierte Skill-Identifier-Strings ("booking.create_option" etc.)
            • EMPFEHLUNG: NICHT umbenennen — das sind fachliche Aktionsnamen
              (Namespace.Aktion-Konvention), keine Verwendungen des Wortes "Task".
              Eine Änderung hier wäre ein separates Anliegen (Aktionsnamen-Konvention)
              und würde den Rename unnötig aufblähen und vermischen.
            • Falls Georg das anders sieht: an dieser Stelle gilt dasselbe
              "vorproduktiv = kein Risiko"-Argument — technisch ebenfalls ohne Migration
              möglich, sollte dann aber als bewusst separat benannter Entscheidungspunkt
              markiert werden, um die beiden Anliegen (Begriffsklarheit vs.
              Namenskonvention für Aktionen) nicht zu vermischen.

Schritt 6 — Schicht D: User-facing Strings (Lang-Files)
            • lang/en/bookingextension_agent.php: alle ~66 Keys/Werte mit "task" →
              "skill"-Äquivalente (Key-Renames UND Wortlaut-Änderungen, z.B.
              "Task Selection Debug" → "Skill Selection Debug")
            • lang/de/bookingextension_agent.php: parallel und konsistent nachziehen
              (Übersetzungsarbeit — z.B. "Aufgabe" vs. "Fähigkeit"/"Skill" als DE-Begriff
              klären — siehe offene Frage unten)
            • amd/src/aiinstructions.js + ggf. weitere AMD-Module: String-Referenzen
              und ggf. CSS-Klassen/Datenattribute mit "task" prüfen

Schritt 7 — Schicht E: Tests, Blueprint, Doku
            • Testdateinamen + Klassennamen (7 Dateien direkt, weitere folgen aus Schritt 2)
            • AGENT_IMPLEMENTATION_FLOWCHART.mmd: Knoten-Labels TR/TI/TPC/TRC/TCV/BTASK/
              CTASK/BT auf Skill-Terminologie umstellen (in Abstimmung mit Georg gem.
              [[feedback_flowchart_policy]] — Flowchart ist primäre Architekturdoku)
            • Bestehende Inventur-Dokumente NICHT rückwirkend ändern (Snapshots);
              ggf. nach Abschluss eine neue Inventur erstellen, die den Skill-Stand zeigt

Schritt 8 — Abschlussverifikation
            • Vollständiger Testlauf (Contract + Scenario + Real-LLM) gegen Baseline aus Schritt 1
            • Grep-Restprüfung: `grep -ril "task" ...` im Agent-Verzeichnis — verbleibende
              Treffer müssen sich auf die drei legitimen Moodle-Core-Task-Dateien
              (s. Abschnitt 2) sowie auf evtl. bewusst belassene Wire-IDs (Schritt 5)
              reduzieren lassen; alles andere ist ein vergessener Rename-Kandidat
```

### Wichtige Hinweise zur radikalen Variante

1. **DB-Felder: `rename_field` vs. Neuanlage.** Da `db/install.xml` ohnehin direkt geändert wird (kein Upgrade-Step), kann bei den drei Benchmark-Feldern (`task_set`, `task_expected`, `task_selected`) abgewogen werden: entweder die Felder in `install.xml` umbenennen (sauberer für Neuinstallationen) oder — falls bereits Testinstallationen mit Daten existieren, an denen jemand hängt — ein einmaliger, undramatischer `rename_field`-Schritt in `upgrade.php`. Das ist die einzige Stelle, an der eine "Mini-Migration" sinnvoll sein könnte — aber als technische Aufräumarbeit, nicht als Produktivdaten-Schutz.

2. **Reihenfolge A vor C**: Auch wenn Schritt 3 (Wire-Format) jetzt Teil des Hauptvorhabens ist, sollte er **nach** dem PHP-Rename (Schritt 2) erfolgen — der Interpreter und die Klassifikatoren, die das neue Wire-Format parsen, existieren ja erst nach Schritt 2 unter ihren neuen Namen. Eine Vertauschung würde unnötige Zwischenstände erzeugen.

3. **Real-LLM-Tests sind der Taktgeber**: Schritt 3 ist der einzige Schritt, der nicht rein mechanisch ist. Plant hierfür Pufferzeit ein — die LLM könnte auf den neuen Vertrag (`"skill_call"` statt `"task_call"`, `"skill"`-Schlüssel statt `"task"`-Schlüssel im Envelope) zunächst unerwartet reagieren, was iterative Prompt-Korrekturen erfordern kann.

4. **Offene Detailfrage für Schritt 6 (DE-Übersetzung)**: "Skill" ist im Deutschen nicht eindeutig übersetzbar — Optionen wären, den englischen Begriff "Skill" direkt zu übernehmen (wie z. B. "Task" bisher auch unübersetzt blieb), oder einen deutschen Begriff wie "Fähigkeit" zu verwenden. Empfehlung: **"Skill" auch im Deutschen beibehalten** (konsistent mit der Quellcode-Terminologie, vermeidet Mapping-Verwirrung zwischen Code-Begriff und UI-Text) — aber das ist Geschmackssache und sollte kurz mit Georg abgestimmt werden, bevor Schritt 6 beginnt.

### Konsolidierte Aufwandsschätzung (radikale Variante)

| Schritt | Aufwand | Bemerkung |
|---|---|---|
| 1 (Vorbereitung/Baseline) | 0,5 Tag | — |
| 2 (PHP-Rename, alle Unterschritte) | 2–3 Tage | mechanisch, IDE-Rename + grep/sed-gestützt |
| 3 (Wire-Format + Real-LLM-Verifikation) | 1–2 Tage | **Taktgeber** — empirischer Anteil, ggf. Iterationen |
| 4 (Schema/Config-Renames, ohne Migration) | 0,5–1 Tag | rein mechanisch dank Vorproduktiv-Status |
| 5 (Wire-IDs) | 0 Tage | nicht umbenennen (Empfehlung) |
| 6 (Lang-Strings EN+DE) | 1 Tag | inkl. Abstimmung DE-Begriff |
| 7 (Tests/Blueprint/Doku) | 0,5–1 Tag | folgt mechanisch aus Schritt 2 |
| 8 (Abschlussverifikation) | 0,5 Tag | vollständiger Testlauf + Grep-Restprüfung |

**Gesamt: ca. 6–9 Tage** für die vollständige, radikale Umbenennung — das liegt nahe an der ursprünglichen "nur Phasen 1–4"-Schätzung (5–7 Tage), weil der Wegfall der Migrationsarbeit (Schritt 4 wird trivial) den Mehraufwand für das Wire-Format (Schritt 3) weitgehend kompensiert. **Das bestätigt Georgs Einschätzung**, dass es viel dafür spricht, es jetzt radikal und vollständig zu machen: der Aufwandsunterschied zur "vorsichtigen Teil-Variante" ist gering, der Nutzen (vollständige Konsistenz, keine dauerhafte Diskrepanz "intern Skill / extern Task") aber groß.

---

## 12. Zusammenfassung

Der Rename-Wunsch ist fachlich gut begründet (Namenskollision mit Moodle-Cron-Tasks erschwert das Verständnis der Architektur). Die ursprüngliche Analyse identifizierte drei Risikoklassen unter dem Begriff "Task": reines internes Naming (risikolos), persistierte Konfigurationsdaten (migrationspflichtig in Produktion) und LLM-Live-Vertrag (verhaltens- und datenrelevant in Produktion). **Da das Plugin noch nicht produktiv ist, entfallen die beiden letzten Risikofaktoren vollständig** — es bleibt nur noch der Arbeitsaufwand, kein strukturelles Risiko. Damit ist die von Georg vorgeschlagene **radikale, vollständige Umbenennung in einem zusammenhängenden Vorhaben (Abschnitt 11) die richtige Wahl**: sie vermeidet eine dauerhafte Inkonsistenz zwischen interner Architektursprache ("Skill") und externem Protokoll/Schema/Settings ("Task"), kostet dabei kaum mehr als die ursprünglich vorsichtigere Teil-Variante, und lässt sich — weil vorproduktiv — ohne Migrations- und Kompatibilitätsrisiko in einem Zug durchziehen.
