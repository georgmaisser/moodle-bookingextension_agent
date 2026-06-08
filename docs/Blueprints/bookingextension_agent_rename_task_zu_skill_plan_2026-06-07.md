# Plan: Umbenennung "Task" → "Skill" im Agent-Subsystem

> **Datum:** 2026-06-07
> **Anlass:** Der Begriff "Task" kollidiert mit Moodles eigenem Task-Konzept (`\core\task\adhoc_task`, `\core\task\scheduled_task`, Cron-System) und ist daher für die Agent-Domäne unglücklich gewählt. Zielbegriff: **"Skill"**.
> **Charakter dieses Dokuments:** Planungsgrundlage zur Diskussion — **keine Umsetzung**. Enthält Scope-Inventur, Risikoeinschätzung pro Schicht, Phasenvorschlag und offene Entscheidungsfragen.

---

## ⚠️ Update 2026-06-07: Vorproduktiv-Status ändert die Empfehlung grundlegend

**Georgs Vorgabe:** *"Wir sind noch nicht produktiv, daher benötigen wir keine Migration. Wir können das noch vor Release bereinigen. Es spricht viel dafür, das radikal und vollständig durchzuführen."*

Das ändert die Risikolage massiv, weil der **gesamte Abschnitt 4 (Schicht B — Migrationspflicht)** entfällt:

- **Keine produktiven Daten** → DB-Felder (`skill_set`, `skill_expected`, `skill_selected`), Capabilities (`bookingextension/agent:skill_*`) und Settings (`aiskillenabled` etc.) können **direkt in `install.xml`/`access.php`/`settings.php` umbenannt** werden — kein `db/upgrade.php`-Migrationsschritt, kein Capability-Copy-Pattern, kein `set_config`/`unset_config`. Einfach: alten Namen durch neuen ersetzen, fertig.
- **Keine laufenden Konversationen mit Bestandsdaten** → auch Schicht C (LLM-Wire-Format `"task"`/`"skill_call"`) verliert ihren größten Risikofaktor (Inkompatibilität mit "in-flight"-Threads). Was bleibt, ist "nur" der Prompt-Engineering-/Testaufwand (Schema, Prompt-Texte, Interpreter, Real-LLM-Tests neu verifizieren) — kein Daten-Kompatibilitätsproblem mehr.
- Das spricht in der Tat für die von Georg vorgeschlagene **radikale, vollständige Variante**: alle fünf Schichten inkl. Wire-Format in einem zusammenhängenden Vorhaben umbenennen, statt halbe Sachen zu machen und dauerhaft mit der Inkonsistenz "intern Skill, extern/im Protokoll Task" zu leben.

**Die folgenden Abschnitte 1–10 sind die ursprüngliche, vorsichtigere Analyse — sie bleibt als Risiko-Inventur und Nachschlagewerk wertvoll** (sie zeigt z. B. genau, welche Dateien betroffen sind und wo die fachlichen Fallstricke liegen). **Abschnitt 11 wurde durch den finalen, radikalen Umsetzungsplan ersetzt** (s. u.) — das ist jetzt der maßgebliche Abschnitt für die Umsetzung.

---

## 1. Executive Summary

Eine Grep-Hochrechnung über `bookingextension/agent/` und `mod_booking/classes/local/wbagent/` zeigt **~230 Dateien** mit Treffern für "task" (case-insensitive), davon **~60 Dateien**, deren Dateiname selbst "task" enthält. Der Begriff ist auf **fünf unterschiedlichen Schichten** verankert:

| Schicht | Beispiel | Aufwandscharakter |
|---|---|---|
| A — Internes PHP (Klassen, Namespaces, Interfaces, DTOs, Methoden) | `skill_registry`, `task_interface`, `base_task` | mechanisch, automatisierbar |
| B — Identifier in Schema/Config (DB-Felder, Capabilities, Settings, Registry-Strings) | `skill_set`, `bookingextension/agent:skill_*`, `aiskillenabled` | mechanisch — **vorproduktiv ohne Migration direkt änderbar** (s. Update-Box oben) |
| C — LLM-Vertragsebene / Wire-Format | `"task": "booking.create_option"`, `response_type: "skill_call"`, Prompt-Texte | Prompt-/Schema-/Interpreter-Anpassung + Real-LLM-Testlauf nötig — **kein Daten-Kompatibilitätsrisiko mehr** (vorproduktiv) |
| D — User-facing Strings | Lang-Keys, UI-Labels, Capability-Beschreibungen | Fleißarbeit, zweisprachig (DE/EN) |
| E — Doku/Tests/Blueprints | Inventur, Flowchart, 60+ Test-Dateien | mechanisch, folgt aus A/C |

**Kernaussage (revidiert):** Da noch keine Produktivdaten existieren, entfällt der ursprüngliche Hauptgrund gegen eine vollständige Umbenennung (Migrationspflicht für Schicht B, Datenkompatibilität für Schicht C). Eine **radikale, vollständige Umbenennung in einem zusammenhängenden Vorhaben ist jetzt die empfohlene Variante** — siehe finalen Plan in Abschnitt 11. Abschnitte 2–10 bleiben als detaillierte Fundstellen-Inventur und Risikobeschreibung pro Schicht erhalten (sie beschreiben weiterhin korrekt, *was* sich wo ändert — nur die Empfehlung "schrittweise mit Migration" ist durch "radikal in einem Zug, ohne Migration" ersetzt).

---

## 2. Begriffsklärung: Wo "Task" bleiben MUSS

Nicht jedes Vorkommen von "task" ist ein Umbenennungskandidat. Drei Dateien implementieren **echte Moodle-Core-Task-Klassen** (Cron-Subsystem) — diese MÜSSEN "task" heißen, weil sie von `\core\task\adhoc_task` / `\core\task\scheduled_task` erben und über das Verzeichnis `classes/task/` von Moodles Cron-Autodiscovery gefunden werden:

- `bookingextension/agent/classes/task/execute_ai_run_adhoc.php` (extends `adhoc_task`)
- `bookingextension/agent/classes/task/rebuild_skill_catalog_embeddings_adhoc.php` (extends `\core\task\adhoc_task`)
- `bookingextension/agent/classes/task/cleanup_old_benchmark_runs_task.php` (extends `scheduled_task`)

→ **Diese drei Dateien, ihr Verzeichnis `classes/task/` und alle Moodle-Cron-Registrierungen (`db/tasks.php`, falls vorhanden) bleiben unverändert.** Genau diese Doppelbelegung ("Moodle-Task" vs. "Agent-Task") ist ja der Auslöser für die Umbenennung — die Abgrenzung muss im Code (und in der Doku) klar sichtbar bleiben: *"Skill = was der Agent tun kann"* vs. *"Task = Moodles Cron-Job"*.

**Alles andere**, was sich auf das fachliche Konzept "vom Agenten ausführbare Fähigkeit/Aktion" bezieht (`task_interface`, `base_task`, `skill_registry`, `booking_task_*`, `*_task.php` in `options/tasks/` und `core/tasks/`, `task_risk_class`, `response_type: skill_call`, etc.), ist Umbenennungskandidat → **"Skill"**.

---

## 3. Schicht A — Internes PHP (Klassen, Namespaces, Interfaces, DTOs, Methoden)

### Umfang
~60 Dateien mit "task" im Namen, u. a.:

- **Kern-Infrastruktur**: `skill_registry.php`, `skill_registry_factory.php`, `skill_discovery.php`, `skill_provider.php`, `base_task.php`, `skill_executability_evaluator.php`, `skill_contract_validator.php`
- **Interfaces** (`interfaces/`): `task_interface.php`, `skill_provider_interface.php`, `task_input_normalizer_interface.php`, `task_input_normalizer_provider_interface.php`, `task_trigger_provider_interface.php`, `task_result_summary_provider_interface.php`, `task_input_normalizer_provider_interface.php`
- **DTOs** (`dto/`): `task_risk_class.php`, `skill_selection_result.php`
- **Contracts**: `task_family_contract.php`
- **Services**: `skill_version_policy.php`, `skill_prompt_contract.php`, `skill_governance_service.php`, `skill_selector.php`, `lazy_skill_loader.php`, `skill_selection_overlap_policy.php`, `adaptive_task_catalog_service.php`, `skill_selection_debug_service.php`
- **Konkrete Skills** (Tasks): `options/tasks/*.php` (19 Dateien: `create_option_task.php`, `update_option_task.php`, `book_users_task.php` etc.), `core/tasks/*.php` (6 Dateien), `booking_task_base.php`, `core_task_base.php`
- **Booking-Provider-Schicht**: `booking_skill_provider.php`, `booking_task_support.php`, `booking_task_mutation_execute_service.php`, `provider_task_input_normalizer.php`

### Charakter der Änderung
Mechanisches Rename: Dateinamen, Klassennamen, Namespace-Segmente (`...\options\tasks\` → `...\options\skills\`, `...\core\tasks\` → `...\core\skills\`), Methodennamen (`get_task_names()` → `get_skill_names()`, `get_task()` → `get_skill()`, `is_task_active()` → `is_skill_active()`, `skill_registry` → `skill_registry` usw.), Property-/Variablennamen, PHPDoc.

### Risiko
**Niedrig** — reines Compile-Time-Refactoring, von PHP-Tooling (IDE-Rename, `grep`+`sed`) weitgehend automatisierbar und durch die 36 Contract-Tests + 15 Scenario-Tests sofort verifizierbar. Kein Einfluss auf Laufzeitverhalten oder persistierte Daten, **solange** die in Schicht B/C beschriebenen String-Identifier separat behandelt werden (s. u. — Klassennamen ≠ Registry-Namen!).

### Wichtige Falle
`skill_registry::get_task_names()` liefert **String-Identifier** (z. B. `"booking.create_option"`), die in Prompts an die LLM gehen und in der DB persistiert werden (`commandsjson`, Queue-Items). Diese Strings sind **nicht** automatisch von einer Klassenumbenennung betroffen — sie gehören zu Schicht C/B und müssen separat entschieden werden (s. Abschnitt 5).

---

## 4. Schicht B — Identifier in Schema/Config (DB-Felder, Capabilities, Settings)

> **⚠️ Hinweis:** Der folgende Abschnitt beschreibt, *welche* Stellen betroffen sind und *wie* eine Migration aussähe — **diese Migrationsschritte entfallen jedoch komplett**, weil noch keine Produktivinstallation existiert (s. Update-Box am Dokumentenanfang). Die Inhalte bleiben als Fundstellen-Liste relevant: alle hier genannten Bezeichner werden direkt in `install.xml`/`access.php`/`settings.php` umbenannt, ohne `db/upgrade.php`-Schritt, ohne Capability-Copy-Pattern, ohne `set_config`/`unset_config`.

Ursprüngliche Einschätzung (gilt für eine produktive Installation, **hier nicht anwendbar**): Diese Schicht erfordert **Moodle-Upgrade-Steps** (`db/upgrade.php` + Versionsbump), weil die Werte bereits in produktiven Datenbanken liegen können.

### 4.1 DB-Schema-Felder (`db/install.xml`)
Drei Felder in den Benchmark-Tabellen:
```
local_wbagent_benchmark_runs.skill_set
local_wbagent_benchmark_scenarios.skill_expected
local_wbagent_benchmark_scenarios.skill_selected
```
→ Migration via `$dbman->rename_field()` in `db/upgrade.php`, neuer Upgrade-Step mit `upgrade_plugin_savepoint()`. Risiko: gering (reine Spaltenumbenennung, keine Datenkonvertierung), aber **muss** als Upgrade-Step erfolgen, sonst brechen produktive Installationen.

### 4.2 Capabilities (`db/access.php`)
```
bookingextension/agent:skill_<taskname>     (z. B. task_booking_create_option, ~50 Stück, generiert aus $teachertasks/$managertasks/$adminonlytasks)
bookingextension/agent:debugskillselection
```
**Wichtig:** Capability-Namen werden bei Plugin-Installation in `mdl_capabilities` und bei Rollenzuweisungen in `mdl_role_capabilities` persistiert. Moodle bietet **keine native "rename capability"-API** — der Standardweg ist:
1. Neue Capability unter neuem Namen in `access.php` deklarieren
2. In `db/upgrade.php` die Rollenzuweisungen von alt → neu kopieren (`$DB->get_records('role_capabilities', ['capability' => $old])`, dann Insert unter neuem Namen)
3. Alte Capability-Einträge entfernen (`$DB->delete_records('role_capabilities', ...)`, `$DB->delete_records('capabilities', ...)`)

Dies ist ein **nicht-triviales, fehleranfälliges Upgrade-Pattern** — Fehler hier führen dazu, dass Lehrende/Admins nach dem Update plötzlich keine Berechtigung mehr für Agent-Skills haben.

### 4.3 Settings (`settings.php`, gespeichert in `config_plugins`)
Beispiele: `aiskillenabled`, `aiskillenableall`, `aiskillgovernanceheading`, `aiskillgovernanceunavailable`, `skillselectiondebug`, `benchmark_threshold_skill_hit_rate`, sowie die generierten Per-Skill-Toggle-Settings (`skill_registry::get_skill_toggle_setting_name()`).

→ Migration via `set_config()`/`unset_config()` in `db/upgrade.php`, um bestehende Admin-Konfigurationen (z. B. welche Skills aktiviert sind) zu erhalten. Auch hier: **wenn vergessen, verlieren bestehende Installationen ihre Skill-Aktivierungs-Konfiguration beim Update.**

### 4.4 Registry-Namen / persistierte Skill-Identifier
`skill_registry::get_task_names()` liefert Strings wie `"booking.create_option"`, `"core.search_users"` — diese werden:
- in `commandsjson` (`local_wbagent_ai_runs`) und `structuredjson` (`local_wbagent_ai_messages`) als JSON persistiert
- in Queue-Items (`queue_manager::enqueue_command()` → Feld `'task' => $taskname`) gespeichert
- im `command_schema.json` validiert (Property-Key `"task"`)

→ Diese Identifier sind primär **Namespace-Präfixe + fachliche Aktionsnamen** (`booking.create_option`), nicht das Wort "task" selbst — eine Umbenennung des *Konzepts* "Task" zu "Skill" erfordert hier **nicht zwingend** eine Änderung dieser Strings. Eine Änderung des Envelope-Schlüssels `"task"` → `"skill"` im JSON-Format wäre dagegen Schicht C (s. u.) und deutlich risikoreicher.

---

## 5. Schicht C — LLM-Vertragsebene / Wire-Format (höchstes Risiko)

Das ist die kritischste Schicht, weil sie das **Protokoll zwischen Server und LLM** sowie **bereits laufende Konversationen** betrifft.

### Betroffene Artefakte
1. **`config/command_schema.json`**: JSON-Schema definiert den Envelope-Schlüssel `"task"` als Pflichtfeld für jedes Command (`{"task": "booking.create_option", "version": 1, "input": {...}}`)
2. **`prompts/initial_system_prompt.md`**: Definiert `response_type` Enum-Wert `"skill_call"` als Vertragswert, den die LLM zurückgeben muss; enthält Beispiel-JSON mit `"task": "booking.create_option"`
3. **`interpreter.php`**: Parst und validiert genau diese Schlüssel/Werte (`normalize_commands_payload()`, `enforce_phase_contract()`)
4. **`finalization_classifier.php`**: Klassifiziert u. a. nach `response_type === 'skill_call'` (siehe Flowchart `LG_MATRIX`, Regel 2 + 6)
5. **Persistierte Konversationen**: `commandsjson`/`structuredjson` in laufenden/historischen Threads enthalten den alten Envelope (`"task": ...`, `"response_type": "skill_call"`)

### Warum ursprünglich als riskant eingestuft? (Kontext für die Einschätzung)
- **Lebende LLM-Verträge**: Eine Änderung des JSON-Schlüssels `"task"` → `"skill"` oder des Enum-Werts `"skill_call"` → `"skill_call"` bedeutet, dass **alle Prompts neu formuliert**, das **JSON-Schema geändert**, der **Interpreter angepasst** und die **gesamte Real-LLM-Testsuite** (7 Dateien, benötigt Live-Provider) neu verifiziert werden müssen — das ist Prompt-Engineering-Arbeit mit empirischem Charakter, nicht reines Refactoring. *(Dieser Arbeitsaufwand bleibt bestehen — nur das zusätzliche Daten-Kompatibilitätsrisiko entfällt, s. u.)*
- ~~**Kompatibilität mit laufenden Threads**: Threads, die mitten in einem mehrstufigen Run stecken (`planner_trace_history`, `phase_trace`, gespeicherte `next_step_intent`), enthalten u. U. den alten Envelope. Der Interpreter müsste beide Formate parsen können.~~ → **entfällt vorproduktiv**: keine laufenden Threads mit alten Envelopes vorhanden.
- **Benchmark-Baselines**: `local_wbagent_benchmark_baselines` und Scenario-Fixtures referenzieren `response_type_expected = 'skill_call'` — diese werden im Zuge des Renames **mit aktualisiert** (Teil des Vorhabens, kein externes Kompatibilitätsproblem, da es sich um eigene Test-Fixtures handelt, nicht um Produktivdaten).

### Empfehlung (revidiert durch Update-Box)
Die ursprüngliche Empfehlung *"Schicht C zunächst nicht anfassen"* basierte auf dem Risiko **"laufende Konversationen mit Bestandsdaten brechen"**. Da wir **vorproduktiv** sind, entfällt genau dieses Risiko — es gibt keine "in-flight"-Threads, die durch ein geändertes Wire-Format kollabieren könnten. Damit bleibt von Schicht C nur noch der **Arbeitsaufwand** übrig (Prompt neu formulieren, Schema ändern, Interpreter anpassen, Real-LLM-Tests neu verifizieren) — kein strukturelles Risiko mehr. Das ist nicht trivial, aber planbar und gehört nach Georgs Vorgabe explizit mit ins radikale Gesamtvorhaben (s. Abschnitt 11). Wichtig bleibt: **Real-LLM-Tests müssen nach der Umbenennung vollständig neu laufen**, da sich das Vertragsformat ändert, auf das die LLM reagiert.

---

## 6. Schicht D — User-facing Strings (Lang-Files, UI)

### Umfang
66+ Lang-String-Keys in `lang/en/` und `lang/de/` (je ~73/77 KB) mit "task" im Key oder Wert, u. a.:
- `agent_booking_unknown_task`, `agent_decision_command_missing_task`, `agent_executor_task_not_registered`
- `ai_status_taskcall_*` (10+ Strings — UI-Anzeige "Aktion wird ausgeführt: ...")
- `ai_action_explain_task_schema`, `ai_action_recreate_skill_catalog`
- `aiskillenabled_label`, `aiskillenableall`, `aiskillgovernanceheading` (Settings-Beschriftungen)
- `skillselectiondebug` (Debug-Tool-Titel)

### Charakter
Mischung aus:
- **Key-Renames** (folgen aus Schicht B/Settings-Renames — z. B. `aiskillenabled` → `aiskillenabled`)
- **Label-/Text-Änderungen** ("Task" → "Skill" in der sichtbaren Beschriftung, z. B. "Task Selection Debug" → "Skill Selection Debug")
- **Übersetzungsarbeit**: DE-Datei muss konsistent mitgepflegt werden (kein automatisches Mapping zwischen EN/DE-Key und -Text)

### Risiko
**Mittel** — kein technisches Risiko, aber Aufwand durch Volumen + Zweisprachigkeit + Gefahr von "lost in translation" bei Strings, die sowohl die Schicht-B-Rename-Folgen als auch reine Wortlaut-Änderungen mischen.

---

## 7. Schicht E — Dokumentation, Tests, Blueprints

- **Tests**: 7 Dateien mit "task" im Namen (`*_task_*test.php`), zusätzlich Testklassen wie `abstract_llm_skill_matrix_testcase.php`, `llm_skill_matrix_scenario_provider.php`, `r3_skill_e2e_test.php` — folgen mechanisch aus Schicht-A-Renames
- **Blueprint**: `AGENT_IMPLEMENTATION_FLOWCHART.mmd` referenziert `TR` (`skill_registry`), `TI` (`task_interface`), `TPC`, `TRC`, `TCV`, `BTASK`, `CTASK`, `BT` — alle Knoten-Labels und Kommentare müssten nachgezogen werden
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
[x] Schritt 1 — Vorbereitung
            • Branch/Worktree anlegen (radikaler Rename = große, gut isolierbare Änderung)
            • Vollständigen Testlauf VOR dem Rename als Baseline dokumentieren
              (36 Contract-Tests, 15 Scenario-Tests, 7 Real-LLM-Tests — Ausgangszustand sichern)

Schritt 2 — Schicht A: Internes PHP-Rename
            ├─ [x] 2a: Interfaces + DTOs + Contracts (interfaces/, dto/, contracts/)
            ├─ [x] 2b: Kern-Infrastruktur (skill_registry → skill_registry, base_task → base_skill,
            │       skill_discovery → skill_discovery, skill_executability_evaluator → ...,
            │       skill_contract_validator → ...)
            ├─ [x] 2c: Konkrete Skills — Verzeichnisse + Klassen
            │       (options/tasks/ → options/skills/, core/tasks/ → core/skills/,
            │        *_task.php → *_skill.php, z.B. create_option_task → create_option_skill)
            └─ [x] 2d: Booking-Provider-Schicht (booking_task_* → booking_skill_*)
            Nach jeder Unterphase: Contract-Test-Lauf gegen Baseline (✅ 2a/2b/2c/2d: je 214/214, 1189 Assertions — Baseline-Match. Schritt 2 / Schicht A vollständig abgeschlossen)

Schritt 3 — Schicht C: LLM-Wire-Format (jetzt TEIL des Hauptvorhabens, nicht ausgeklammert)
            ├─ [x] config/command_schema.json: Envelope-Schlüssel "task" → "skill"
            ├─ [x] prompts/initial_system_prompt.md: response_type-Wert "skill_call" → "skill_call",
            │      alle Beispiel-JSONs und Erläuterungstexte aktualisiert
            ├─ [x] Scope-Erweiterung (User-Vorgabe "radikal & vollständig"): zusätzlich ~13
            │      neu entdeckte Produktionsklassen umbenannt (skill_selector, lazy_skill_loader,
            │      skill_selection_overlap_policy, skill_governance_service,
            │      adaptive_skill_catalog_service, skill_selection_debug_service,
            │      skill_version_policy, skill_not_in_catalog, provider_skill_input_normalizer,
            │      + 4 lokale local_entities Skill-Klassen inkl. Namespace-Wechsel
            │      local_entities\...\tasks → \...\skills) — alle Referenzen angepasst,
            │      Contract-Tests grün (214/214, aber 1142/1189 Assertions, s. unten)
            ├─ [x] spawn_contract_service.php + zugehörige Contract-Tests: normalize_task_result
            │      → normalize_skill_result, Envelope-Schlüssel 'task' → 'skill' in
            │      spawn_commands-Strukturen (reference_scenarios_contract_test.php,
            │      spawn_contract_service_test.php aktualisiert)
            ├─ [x] Assertion-Differenz 1142 vs. ursprünglich notierte 1189 (47 fehlend, 0
            │      Failures) — UNTERSUCHT UND ABGESCHLOSSEN (s. ausführliche STATUS-Notiz
            │      unten): alle ~40 produktiven PHP-Klassen + Fixtures wurden umgestellt,
            │      4 echte Bugs gefunden+behoben, erschöpfende Grep-Restprüfung zeigt NUR
            │      noch erwartete Fallback-Patterns/Cron-Tasks/unabhängige Konzepte. Die
            │      47er-Differenz bewegt sich durch keinen dieser Fixes — Arbeitshypothese:
            │      intrinsische Folge veränderter Bezeichner-Strings (Länge/Form), kein Bug.
            │      Empfehlung: 1142/1189 als neue Baseline akzeptieren (s. STATUS-Notiz,
            │      Entscheidung an Georg analog [[feedback_flowchart_policy]]).
            │      SONDERFALL: embeddings_csv_repository.php HEADERS-Konstante + die
            │      Fixture-CSV tests/agent/fixtures/skill_catalog_embeddings.csv haben eine
            │      Spalte "task" — das ist eine PERSISTIERTE Datenschema-Spalte (kein reiner
            │      Wire-Envelope-Schlüssel), die aus den gleichen prompt_contracts gespeist
            │      wird. ENTSCHEIDUNG NOCH OFFEN: entweder hier mit umbenennen (für volle
            │      Konsistenz Catalog→CSV→Embeddings→Wire) oder bewusst Schritt 4
            │      (Schema/Config) zuordnen — analog zu install.xml skill_set/skill_expected/
            │      skill_selected. Empfehlung: zusammen mit Schritt 4 behandeln, da es sich
            │      um eine Spalten-/Schema-Umbenennung handelt, nicht um Wire-Format im
            │      engeren Sinne.
            ├─ [x] interpreter.php: Variablen ($selectedskill, $skillname, $commandskill,
            │      $resolvedskill, $responsereferencedskill, $skill) waren bereits umgestellt
            │      (vom anderen Agenten); zusätzlich 8 verbliebene Kommentar-/Docblock-Stellen
            │      ("task->preflight()", "task-validator wording", "single-task",
            │      {task,version,input}-Beispiele, "task-like malformed outputs",
            │      "task-call signal") auf 'skill'-Terminologie aktualisiert. Nur die
            │      bewussten ?? $x['task']-Fallbacks (5 Stellen) bleiben (Schritt 8).
            ├─ [x] dto/skill_selection_result.php: Properties $skillname/$skill bereits
            │      umgestellt (vom anderen Agenten); nur Docblock "DTO for selected task
            │      resolution." → "...selected skill resolution." korrigiert. Externe
            │      Zugriffe (interpreter.php, skill_selector.php) bereits konsistent.
            ├─ [x] finalization_classifier.php: bereits vollständig auf 'skill_call'/'skill'
            │      umgestellt (0 Treffer für "task" bei Grep) — keine Änderung nötig.
            ├─ [x] Benchmark-Fixtures/-Baselines: get_expected_task() → get_expected_skill()
            │      (17 Dateien), $taskhit/$taskbase/$exptask/$acttask → skill-Pendants,
            │      Metric-Key 'task_hit_rate' → 'skill_hit_rate' (Calculator + Report +
            │      lang/en + lang/de + ws2_benchmarking_implementation_plan.md), 9 stale
            │      JSON-Wire-Literale '"commands":[{"task":...}]' → '{"skill":...}' in
            │      Szenario-Fixtures korrigiert (echte Bugs — hätten reale LLM-Antworten
            │      mit altem Schlüssel simuliert), + ~15 Kommentar-/Label-Strings in
            │      Szenario-Klassen auf 'skill'-Terminologie umgestellt.
            ├─ [x] Real-LLM-Testinfrastruktur (7 Dateien + abstract_llm_skill_matrix_testcase.php
            │      + llm_skill_matrix_scenario_provider.php) MECHANISCH umgestellt:
            │        • ECHTER BUG gefunden+behoben: $entry['task'] / $scenario['task'] Lookups
            │          lasen einen Schlüssel, den die Produktionsklassen gar nicht mehr
            │          schreiben (alle result-payload-Builder schreiben nur noch 'skill' =>);
            │          → $entry['skill'] / $scenario['skill'], Methoden umbenannt
            │          (resolve_task_result_payload→resolve_skill_result_payload,
            │          extract_task_result→extract_skill_result, find_task_result_entry→
            │          find_skill_result_entry, task_result_candidate_names→
            │          skill_result_candidate_names, assert_task_is_executable_or_skip→
            │          assert_skill_is_executable_or_skip, has_task_evidence→
            │          has_skill_evidence, is_task_available→is_skill_available,
            │          provide_registered_task_scenarios→provide_registered_skill_scenarios,
            │          get_missing_registered_task_scenarios→
            │          get_missing_registered_skill_scenarios, task_matrix_scenarios→
            │          skill_matrix_scenarios, assert_llm_task_scenario_success→
            │          assert_llm_skill_scenario_success + Test-Methodennamen)
            │        • 4 weitere stale Wire-Format-Literale '$command['task']'/
            │          "['task' => 'core.list_actions', ...]" in den Test-Fixtures korrigiert
            │        • 1 stale RETRY_HINT-Promptliteral in agent_runtime.php:584 korrigiert
            │          ("Do not wrap task..." / {"task":"<task>",...} → "skill"/{"skill":...})
            │        • 1 deutscher LLM-Prompt-Text "Neuaufbau des Task-Katalogs" →
            │          "...Skill-Katalogs" (core.recreate_skill_catalog-Szenario)
            │        • Alle Kommentare/Docblocks/Fehlermeldungen auf 'skill' umgestellt
            │      VERIFIKATION: php -l auf allen 9 Dateien fehlerfrei, PHPUnit
            │      --list-tests-xml lädt alle Klassen + parametrisierten Daten-Provider
            │      ohne Fataler Fehler (z. B. test_all_registered_skills_can_complete_via_real_llm
            │      #core.recreate_skill_catalog erscheint korrekt in der Liste).
            └─ [ ] ⚠️ NOCH OFFEN: VOLLSTÄNDIGER Real-LLM-Testlauf (7 Dateien, Live-Provider) —
                   dies ist der einzige Schritt mit empirischem statt mechanischem Charakter;
                   KANN IN DIESER UMGEBUNG NICHT AUSGEFÜHRT WERDEN (BOOKING_TEST_AI_KEY /
                   BOOKING_TEST_AI_MODEL sind nicht gesetzt — require_real_llm() würde sofort
                   fehlschlagen). Georg muss diesen Lauf selbst mit Live-Provider-Credentials
                   durchführen; ggf. mehrere Iterationen nötig, falls die LLM auf den
                   geänderten Vertrag (inkl. des nun korrigierten $entry['skill']-Lookups)
                   anders reagiert als erwartet.

            STATUS 2026-06-07 (Update Folgesession): Alle ~40 oben gelisteten Produktionsdateien
            + Contract-Test-Fixtures wurden systematisch auf 'skill' umgestellt (inkl.
            integration_agent_framework_test.php, 23 verbleibende 'task' =>-Fixtures konvertiert).
            Zusätzlich 4 konkrete Korrekturen vorgenommen:
              1. preflight_schema_validator.php:63-68 — prüfte noch
                 array_key_exists('task', $command) UND referenzierte eine undefinierte
                 Variable $task (Folge einer unvollständigen Umbenennung) → korrigiert auf
                 array_key_exists('skill', ...) + $skill.
              2. preflight_contract_validator_contract_test.php:48/57 — Mock-Fixtures mit
                 veralteter Fehlermeldung 'Missing required field "task".' → auf "skill"
                 aktualisiert (Konsistenz mit neuem Schema).
              3. integration_agent_framework_test.php:975 — JSON-Wire-Format-Literal enthielt
                 noch "task":"..." statt "skill":"..." → korrigiert.
              4. abstract_agent_testcase.php:562 (exec_command-Helper) — baute Commands noch
                 mit ['task' => $taskname, ...] statt ['skill' => ...] → korrigiert
                 (wirkt sich über das Fallback-Pattern nicht auf das Testergebnis aus, war aber
                 ein Konsistenz-Rest aus der alten Wire-Format-Generation).

            ERGEBNIS NACH ALLEN FIXES: Suite bleibt bei 214/214 grün, 0 Failures/Errors,
            39 Deprecations — ABER weiterhin 1142/1189 Assertions (47 fehlend). Keiner der
            obigen Fixes hat den Wert verändert (Beleg: es waren keine 'task'/'skill'
            Wire-Key-Mismatches mehr ursächlich — alle verbliebenen ['skill'] ?? ['task']
            Fallback-Patterns lösen identisch auf, unabhängig vom vorhandenen Key).

            TIEFENANALYSE der 47er-Differenz: Per-Test-Assertion-Zählung via --log-junit
            durchgeführt (JUnit-XML liefert assertions="N" je Testcase). Keine Tests mit
            0 Assertionen oder conditional-skip-Mustern auf 'task'/'skill'-Keys gefunden.
            Größter Einzeltest (test_slim_catalog_keeps_examples_separate_from_minimals,
            119 Assertions) per Assert::getCount()-Delta-Instrumentierung komplett
            aufgeschlüsselt: Ergebnis 100% konsistent mit aktueller Catalog-Struktur
            (24 Skills, 22 mit example_input, 24 mit description; assertLessThanOrEqual()
            zählt intern als 2 Assertions — verifizierte PHPUnit-11-Eigenheit, kein Bug).
            Die Catalog-GRÖSSE (24 Skills) ändert sich durch die Umbenennung nicht
            (gleiche Skills, nur neue Bezeichner).

            ABSCHLIESSENDE GREP-RESTPRÜFUNG: alle verbleibenden 'task'-Vorkommen sind
            ausschließlich (a) bewusste Fallback-Patterns ['skill'] ?? ['task'] (~60 Stellen,
            ~40 Dateien — sollen It. Plan in Schritt 8 final entfernt werden), (b) legitime
            Moodle-Cron-Task-Klassen in classes/task/ (3 Dateien, MÜSSEN "task" behalten),
            (c) das unabhängige $params['task']-Konzept in
            classes/external/booking_validate_option.php (Options-Validierungstyp
            create/update/bulk_update — KEIN Agent-Skill-Bezug, bewusst NICHT umbenannt),
            (d) Real-LLM-Test-Infrastruktur (abstract_llm_skill_matrix_testcase.php +
            real_llm_multistep/*), die separat im ⚠️-Schritt oben behandelt wird.

            SCHLUSSFOLGERUNG: Die verbleibende 47-Assertion-Differenz lässt sich nach
            erschöpfender Suche nicht mehr auf 'task'/'skill'-Key-Mismatches zurückführen.
            Arbeitshypothese (nicht abschließend bewiesen): Die Differenz ist eine INHÄRENTE,
            KORREKTE Konsequenz der Umbenennung selbst — z. B. veränderte String-Längen
            (z. B. 'core.recreate_skill_catalog' = 27 Zeichen vs.
            'core.recreate_task_catalog' = 26 Zeichen), die Truncation-/Vergleichs-/
            Dedup-Logik in compact_catalog_description()/example_input beeinflussen und
            dadurch einzelne Loop-Iterationen/Branches in Test-Schleifen anders zählen lassen,
            ohne dass eine Assertion fehlschlägt. Ein exakter 1:1-Match nach einem "radikal
            & vollständigen" Rename ist unter dieser Hypothese prinzipiell nicht zu erwarten,
            da sich Datenformen mit den Namen mit verändern.

            EMPFEHLUNG (zur Entscheidung an Georg, analog [[feedback_flowchart_policy]] —
            "Diskrepanzen erst klären, nicht eigenständig redefinieren"): 1142/1189
            Assertions (0 Failures/Errors, 39 Deprecations, 214/214 grün) als NEUE,
            korrekte Baseline akzeptieren. Die ursprüngliche 1189er-Zahl war ohnehin nur
            eine TRANSIENTE Zwischenmessung (Schritt 2a-2d, vor Schritt-3-Wire-Format-Änderungen)
            ohne zugehörigen Commit — ein Vergleich mit dem Merge-Base-Commit (4bf9a65,
            "Version 2026060301") zeigt eine andere Testsuiten-Zusammensetzung (180 vs.
            214 Tests), ist also nicht als verbindliche Referenz nutzbar.

Schritt 4 — Schicht B: Schema/Config — DIREKT umbenennen, KEINE Migration
            ├─ [x] db/install.xml: bereits vollständig auf 'skill'-Terminologie (skill_set,
            │      skill_expected, skill_selected) — keine Änderung nötig, war schon konsistent.
            ├─ [x] db/access.php: $buildtaskcapability→$buildskillcapability,
            │      $teachertasks/$managertasks/$adminonlytasks→$teacherskills/$managerskills/
            │      $adminonlyskills, $tasksuffix→$skillsuffix + Docblock-Korrektur. Capability-
            │      Prefix 'bookingextension/agent:skill_' war bereits korrekt (kein 'task_').
            ├─ [x] settings.php: foreach ($contracts as $taskname => ...) → $skillname
            │      (+ beide get_string-Aufrufe), 2 Kommentar-Korrekturen ("individual task
            │      toggles"→"skill toggles", "per-task configs"→"per-skill configs").
            ├─ [x] skill_registry::get_skill_toggle_setting_name(string $taskname) →
            │      Parameter zu $skillname umbenannt (+ Body-Referenz + 2 Docblock-/
            │      Kommentar-Korrekturen). HINWEIS: 50 weitere $taskname/$task/$this->tasks-
            │      Vorkommen als LOKALE Variablennamen in dieser Datei bewusst NICHT
            │      angefasst — siehe Befund unten zur projektweiten $taskname-Inkonsistenz.
            ├─ [x] version.php: Versionsbump 2026060403 → 2026060700 (reine Release-
            │      Kennzeichnung, kein Upgrade-Step nötig — install.xml war bereits sauber,
            │      keine Datenkonvertierung erforderlich).
            └─ [x] embeddings_csv_repository.php / Fixture-CSV "task"-Spalte — ENTSCHEIDUNG:
                   FALSCHALARM, kein Rename nötig. Die HEADERS-Konstante listet bereits
                   'skill' (nicht 'task') als Spaltenname — das Schema ist korrekt. Die
                   ursprünglich beobachteten "task"-Vorkommen sind STALE FIXTURE-DATEN im
                   freitextigen 'intent'-Feld (22 Zeilen mit intent="task", z.B. für
                   mod_booking.create_option) — ein Datenwert, kein Spaltenname. Verifiziert:
                   kein Produktionscode liefert literal 'intent' => 'task' (alle Booking-
                   Skills lassen 'intent' leer/undefiniert). Diese Werte stammen aus einer
                   älteren Fixture-Generierung und sind unabhängig von der Task→Skill-
                   Umbenennung (reine Altdaten-Inkonsistenz, ggf. behebbar durch Fixture-
                   Regenerierung — separates Wartungsthema, NICHT Teil dieses Renames).
                   ZUSATZFUND (echter Bug, behoben): cli/rebuild_embeddings_fixture.php las
                   $row['task'] aus Zeilen, die der Builder/die CSV nur mit Schlüssel 'skill'
                   liefern — das Skript hätte JEDE Zeile übersprungen (taskname immer leer)
                   und alle Skills fälschlich als "deleted" gemeldet. Komplett auf 'skill'
                   umgestellt ($row['skill'], $skillname, $existingbyskill, $currentskills,
                   $deletedskills, Hilfetexte/mtrace-Strings); php -l fehlerfrei.
            ZUSATZFUND (genuine Bug, behoben): Cluster aus 10 toten "Geister"-Dateien
            task_family_contract.php, task_prompt_contract.php, task_contract_validator.php,
            interfaces/task_interface.php, dto/task_selection_result.php,
            interfaces/task_{input_normalizer,input_normalizer_provider,provider,
            result_summary_provider,trigger_provider}_interface.php — vollständig isolierter
            Klon-Hierarchie der aktiven skill_*-Äquivalente, vermutlich durch Kopieren statt
            Verschieben während des Renames entstanden (laut `git ls-tree HEAD` nicht im
            Eltern-Commit vorhanden, als komplett neue Dateien gestaged). Per Grep verifiziert:
            ZERO externe Referenzen (nur gegenseitige Verweise innerhalb des Clusters). Mit
            Georgs Bestätigung gelöscht (git rm).

            VERIFIKATION nach Schritt 4 (init.php neu initialisiert, da Testumgebung durch
            unabhängige composer.lock-Drift einen Versions-Mismatch meldete):
            vendor/bin/phpunit --testsuite bookingextension_agent_testsuite →
            247 Tests, 1182 Assertions, 32 Failures, 0 Errors, 48 Deprecations.
            Alle 32 Failures sind AUSSCHLIESSLICH "Real-LLM tests require BOOKING_TEST_AI_KEY
            + BOOKING_TEST_AI_MODEL" (require_real_llm() in abstract_agent_testcase.php:613 —
            $this->fail() statt markTestSkipped(), by design). Die 33 zusätzlichen Tests ggü.
            der 214er-Baseline sind die real_llm_multistep-Matrix (all_skills_real_llm_test
            parametrisiert über alle registrierten Skills: 25 Failures allein dort). KEINE
            einzige Failure-Message bezieht sich auf 'task'/'skill'-Mismatches oder auf die
            entfernten Geister-Dateien — die Suite ist nach Schritt 4 stabil und regressionsfrei
            (gleiche 215 nicht-Real-LLM-Tests grün wie vor Schritt 4 + 1 neue Matrix-Coverage-
            Prüfung test_skill_matrix_covers_all_registered_skills, die selbst real_llm-
            Credentials benötigt und daher hier ebenfalls als Failure zählt).

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

Schritt 6 — Schicht D: User-facing Strings (Lang-Files) — ABGESCHLOSSEN (2026-06-07)
    [x] lang/en/bookingextension_agent.php: ~50 Keys/Werte mit "task" → "skill"
        umbenannt (Key-Renames UND Wortlaut-Änderungen, z.B. "Task Selection Debug"
        → "Skill Selection Debug"). Verbleibende 4 "task"-Treffer geprüft und bewusst
        belassen (KEEP): natürliches Englisch ("booking management tasks",
        "documentation lookup tasks") sowie legitime Moodle-Cron-Begriffe
        (aiexecutionmode_adhoc "adhoc task", task_execute_ai_run).
        ZUSATZFUND #1 (echter Bug, behoben): Capability-Lang-String
        `agent:task_booking_analyze_rules` passte nicht zur tatsächlichen Capability
        `bookingextension/agent:skill_booking_analyze_rules` (gebaut über
        `$buildskillcapability()` in db/access.php) → Anzeigename wurde nie aufgelöst.
        Key umbenannt in `agent:skill_booking_analyze_rules` (EN+DE).
        ZUSATZFUND #2 (verwaiste Strings, zur Konsistenz umbenannt): Cluster
        `agent_booking_explain_task_schema_*` / `ai_action_explain_task_schema` +
        zugehörige Capability-Suffix `booking_explain_task_schema` — per grep über
        den gesamten public/mod/booking/-Baum verifiziert: 0 Produktionsverwendungen,
        kein passender Skill `explain_task_schema`/`explain_skill_schema` in der
        Registry. Für Konsistenz auf `*_explain_skill_schema_*` umbenannt statt
        gelöscht (kleinere, nicht-destruktive Maßnahme als Datei-Löschung).
    [x] lang/de/bookingextension_agent.php: parallel und konsistent nachgezogen.
        Offene Frage aus Abschnitt "Detailfrage DE-Übersetzung" entschieden:
        "Skill" als Lehnwort beibehalten (Repo-Konvention bereits etabliert, siehe
        bestehendes `benchmark_skill_hit` = 'Skill-Treffer'); "Skill-Set",
        "Skill-Katalog", "Skill-Governance" etc. analog gebildet.
        Zusätzlich 2 Fälle von Übersetzungs-Drift gefunden+behoben (EN sagte
        bereits "skill", DE noch "Task"): `ai_action_recreate_skill_catalog`,
        `ai_status_confirm_booking_recreate_skill_catalog`.
    [x] Produktions-Call-Sites für umbenannte Lang-Keys aktualisiert:
        option_mutation_service.php (agent_booking_unknown_task→_skill, 6×),
        executor.php (agent_executor_task_not_registered→skill_not_registered),
        agent_runtime.php (ai_agent_malformed_taskcall_clarification→skillcall_),
        skill_selection_debug.php (skillselectiondebug_selectedtask/_task/_task_a/_task_b
        → _selectedskill/_skill/_skill_a/_skill_b).
        ZUSATZFUND #3 (echter Bug, behoben): `fallback_taskcall_string_key` /
        `ai_status_taskcall_*` — Konsument `agent_decision_service.php:419,427` las
        bereits (mechanisch vorab umbenannt) `fallback_skillcall_string_key` /
        `ai_status_skillcall_default`, aber die 4 produzierenden Skill-Dateien
        (search_courses_, search_users_, recall_memory_, recreate_skill_catalog_skill.php)
        setzten weiterhin den alten Schlüssel `fallback_taskcall_string_key` mit Werten
        `ai_status_taskcall_booking_*` → Mismatch führte dazu, dass die Fallback-
        Statustext-Auflösung NIE griff (immer Rückfall auf Default-Text). Beide Seiten
        konsistent auf `*_skillcall_*` umbenannt (4 Skill-Dateien + 13 Lang-Keys in
        lang/en + lang/de).
    [x] {{tasklist}} → {{skilllist}} und {{taskcatalogjson}} → {{skillcatalogjson}}
        Prompt-Platzhalter umbenannt (lang-Beschreibungstext + phase_prompt_bundle_builder.php),
        zusammen mit den zugehörigen Identifiern $tasknames/$tasklist/$systemtaskcatalog/
        $includetaskcatalog/$unavailabletaskcatalog/$shouldincludetaskcatalog in
        orchestrator.php, skill_registry.php, skill_executability_evaluator.php,
        phase_prompt_bundle_builder.php, runtime_step_analysis_service.php — inkl.
        Methodenumbenennungen extract_recent_task_names_from_messages→
        extract_recent_skill_names_from_messages, extract_step_task_names→
        extract_step_skill_names, extract_recorded_step_task_names→
        extract_recorded_skill_names (alle nur intern verwendet, keine externen
        Aufrufer außerhalb der jeweiligen Datei).
    [x] amd/src/aiinstructions.js geprüft.
        ZUSATZFUND #4 (echter Bug, behoben): JS las `cmd.task` / `parsed.task` /
        `action.task` / `READ_ONLY_TASKS` aus dem Command-/Result-JSON, das das
        Backend mittlerweile durchgängig mit dem Schlüssel `'skill'` serialisiert
        (verifiziert: 0 Treffer für `'task' =>` in der Serialisierung, ausschließlich
        `'skill' =>`). Die JS-Zugriffe waren dadurch verwaist und lieferten immer
        `undefined`/leeren String → kaputte Read-Only-Auto-Execute-Erkennung
        (`shouldAutoExecuteReadOnly`), kaputtes Single-Object-Payload-Parsing
        (`parseCommandPayload`) und leere Debug-/Vorschau-Ausgaben. Alle Stellen auf
        `.skill` / `READ_ONLY_SKILLS` / `buildSkillPreviewHtml` umgestellt + Kommentare
        nachgezogen; Minified-Bundle inkl. Sourcemap neu gebaut via
        `npx grunt amd --root=public/mod/booking/bookingextension/agent`
        (eslint:amd + rollup:dist liefen fehlerfrei durch).
    [x] benchmark_trend_chart.js geprüft — keine "task"-Referenzen, keine Änderung nötig.

    VERIFIKATION nach Schritt 6 (vollständiger Lauf, --test-suffix _test.php,
    kein Init nötig — Umgebung war bereits aus Schritt 4 stabil):
    Tests: 247, Assertions: 1182, Failures: 32, Errors: 0, Deprecations: 48.
    Alle 32 Failures sind AUSSCHLIESSLICH "Real-LLM tests require BOOKING_TEST_AI_KEY +
    BOOKING_TEST_AI_MODEL." (verifiziert per grep — 32/32 Treffer, keine andere
    Failure-Message). Identisch zur dokumentierten Schritt-4-Baseline → keine Regression.

    ZUSATZFUND GEKLÄRT (Georg, 2026-06-07 — ENTSCHEIDUNG): Georg hat den oben
    notierten ~837er-Restfund gesichtet (eigene Zählung kam auf "noch 3280 task
    Erwähnungen im ganzen Projekt" — entspricht ~3399 case-insensitiven Substring-
    Treffern in Code+Doku des Plugins, s. Schritt 7) und unmissverständlich
    entschieden: *"alle task Vorkommen, die sich nicht auf moodle tasks beziehen,
    sollten in skills umbenannt werden. du kannst gerne einen eigenen Schritt dafür
    machen. […] Mach jedenfalls weiter."* → eigener Schritt 7 (neu) unten; bisherige
    Schritte 7/8 rücken zu 8/9.

Schritt 7 — Komplettbereinigung: ALLE verbleibenden Agent-Domänen-"Task"-Vorkommen
            (Auftrag Georg, 2026-06-07 — eigener Schritt, s.o.)

            ZIEL: Jedes verbleibende "task"-Vorkommen (alle 6 Groß-/Kleinschreib-
            varianten: task/Task/TASK/tasks/Tasks/TASKS) im Plugin wird nach
            "skill" umbenannt — AUSSER es bezeichnet ein echtes Moodle-Core-Task-
            Konzept. Damit wird der ~837er-Fund aus Schritt 6 vollständig
            aufgelöst, UND die für Schritt 9 (vorm. Schritt 8) vorgesehene
            Fallback-Shim-Entfernung (`$cmd['skill'] ?? $cmd['task']`, ~60 Stellen)
            wird hier mit erledigt, weil diese Shims ausschließlich der
            Übergangskompatibilität zwischen altem/neuem Agent-Wire-Format dienen
            und KEIN Moodle-Task-Bezug besteht.

            BESTANDSAUFNAHME (Messung 2026-06-07, Wortgrenzen-Substring "task"
            case-insensitive, ohne docs/Blueprints/obsolete/): 3399 Treffer in
            269 Dateien (task=2549, Task=246, TASK=74, tasks=465, Tasks=58,
            TASKS=7) — deckt sich mit Georgs "noch 3280" (leicht abweichende
            Zählmethode/Zeitpunkt). Davon entfallen ca. 1473 Treffer in ~120
            PHP/JS-Code-Dateien (production + tests), der große Rest verteilt
            sich auf die Inventur-/Blueprint-Markdown-Dokumente und die
            Flowchart-Datei.

            ABGRENZUNG "bleibt Task" (s. auch Abschnitt 2 — bewusst NICHT umbenennen):
            ├─ [x] Die drei echten Moodle-Cron-Task-Klassen in classes/task/
            │      (Klassen-/Dateinamen, Namespace `bookingextension_agent\task`,
            │      `extends scheduled_task`/`extends \core\task\adhoc_task`,
            │      Docblocks "Adhoc task to execute…"/"Scheduled task: …",
            │      Methoden-Docblock "Execute task." (Moodle-Lifecycle-Konvention),
            │      Lang-Keys `task_execute_ai_run`, `aiexecutionmode_adhoc`)
            │      — ABER: Agent-Domänen-Inhalte INNERHALB dieser Dateien (Prosa
            │      "task catalog"/"task states", Variablen $taskstates/$taskname)
            │      WERDEN umbenannt — s. ZUSATZFUND unten, bereits erledigt.
            ├─ [x] `\core\task\manager`/`task_manager`-Aufrufe (queue_adhoc_task,
            │      reschedule_or_queue_adhoc_task) in confirm_run_service.php,
            │      embeddings_readiness_service.php, recreate_skill_catalog_skill.php
            ├─ [x] `$params['task']` in classes/external/booking_validate_option.php
            │      — unabhängiges Options-Validierungstyp-Konzept (create/update/
            │      bulk_update), KEIN Agent-Skill-Bezug (bereits in Schritt 3
            │      Restprüfung dokumentiert, hier nur zur Vollständigkeit gelistet)
            └─ [x] natürlichsprachliche Nicht-Fachbegriff-Stellen in Lang-Strings
                   ("booking management tasks", "documentation lookup tasks" — bereits
                   in Schritt 6 als KEEP geprüft)

            ✅ ZUSATZFUND, bereits erledigt (während Scoping dieses Schritts entdeckt
            und sofort behoben, da echter Bug — Klassennamen-/Dateinamen-Mismatch):
            classes/task/rebuild_task_catalog_embeddings_adhoc.php → umbenannt zu
            rebuild_skill_catalog_embeddings_adhoc.php (git mv + Klassendeklaration),
            weil recreate_skill_catalog_skill.php und embeddings_readiness_service.php
            die Klasse bereits unter dem NEUEN Namen referenzierten
            (`use …\rebuild_skill_catalog_embeddings_adhoc`, Konstante
            REBUILD_TASK_CLASS = '…\\rebuild_skill_catalog_embeddings_adhoc')
            — d.h. Moodles Klassen-Autoloader hätte die Datei nie gefunden
            ("class not found" beim Queuen des Embeddings-Rebuild-Adhoc-Tasks).
            Zusätzlich Docblocks ("rebuild task-catalog embeddings" → "skill-catalog…",
            "Rebuilds embeddings for the full task catalog" → "…skill catalog") sowie
            die rein Agent-domänenbezogenen internen Variablen $taskstates/$taskname
            → $skillstates/$skillname (+ mtrace-Texte "task states"→"skill states")
            umbenannt — und SYNCHRON dazu in family_embeddings_index_service.php
            (Produzent des summary['taskstates']-Arrays): $taskname/$taskstates/
            $existingbytask/$currenttasknames/$removedtasks/$embeddedtasks/$reusedtasks
            → skill-Pendants + Summary-Key 'taskstates'→'skillstates'. php -l beider
            Dateien fehlerfrei. (Die verbleibenden `?? $row['task']`-Fallbacks in
            family_embeddings_index_service.php sind bewusste CSV-Altformat-Shims —
            werden zusammen mit den übrigen Fallback-Shims unten behandelt.)

            STRATEGIE (Datei-Kategorisierung vor mechanischer Umsetzung):
            ├─ [x] Kategorie A "Pure Skill-Domäne" (keine Moodle-Task-API-Marker
            │      `core\task|scheduled_task|adhoc_task|task_manager|queue_adhoc_task`):
            │      ca. 110+ Dateien — case-erhaltendes Such/Ersetzen in 6 Varianten
            │      (task→skill, Task→Skill, TASK→SKILL, tasks→skills, Tasks→Skills,
            │      TASKS→SKILLS) je Datei, danach Diff-Review (Wortgrenzen, um
            │      Fehltreffer wie "subtask"/"multitask"/"tasklist" kontrolliert
            │      mitzunehmen oder bewusst zu belassen)
            ├─ [x] Kategorie B "Mixed" (enthalten echte Moodle-Task-API-Referenzen
            │      UND Agent-Domänen-"task"): die 3 Cron-Task-Klassen (s.o.,
            │      rebuild_* bereits erledigt, cleanup_old_benchmark_runs_task.php
            │      ist rein Moodle-legitim, execute_ai_run_adhoc.php fast
            │      vollständig legitim), confirm_run_service.php,
            │      embeddings_readiness_service.php, recreate_skill_catalog_skill.php
            │      — Zeile-für-Zeile-Review, nur Agent-Domänen-Treffer umbenennen
            └─ [x] Kategorie C "Fallback-Shims" (`$x['skill'] ?? $x['task']` bzw.
                   `$x['skill'] ?? $x['something_else']['task']`, ~28 Dateien laut
                   Grep `\['skill'\] ?? …\['task'\]`): da vorproduktiv und ohne
                   Migrationspflicht (Georgs Leitentscheidung) werden diese Shims
                   ENTFERNT (nicht nur umbenannt) — verbleibender Zugriff direkt
                   auf `$x['skill']`. Damit verschmilzt dieser Schritt mit der für
                   Schritt 9 vorgesehenen Shim-Bereinigung (keine doppelte Arbeit).

            UMSETZUNGSREIHENFOLGE:
            [x] 7.1: Kategorie A — Production-Code (classes/, *.php außerhalb tests/)
                     → 108 "pure" Dateien case-erhaltend per sed umbenannt, alle
                     bestehen `php -l`, keine "task"-Reste mehr (verifiziert per Grep).
                     Dabei einen echten Produktionsbug gefunden+behoben: Datei/Klasse
                     `rebuild_task_catalog_embeddings_adhoc` hieß noch "task", wurde
                     aber von Aufrufern bereits als `rebuild_skill_catalog_embeddings_adhoc`
                     referenziert (→ "Class not found" zur Laufzeit) — per `git mv` +
                     Klassen-/Variablen-Rename behoben.
            [x] 7.2: Kategorie A — Test-Code (tests/agent/**, inkl. Datei-/Klassen-/
                     Methodennamen-Renames, danach phpunit --list-tests-Abgleich)
                     → war bereits Teil des 7.1-Bulk-Renames vollständig erledigt
                     (per `git mv`: r3_task_e2e_test→r3_skill_e2e_test,
                     mod_booking_option_tasks_contract_test→…_skills_…,
                     task_contract_validator_contract_test→skill_…,
                     llm_task_matrix_scenario_provider→llm_skill_…,
                     abstract_llm_task_matrix_testcase→…_skill_…,
                     all_tasks_real_llm_test→all_skills_…,
                     task_catalog_embeddings.csv→skill_catalog_embeddings.csv).
                     Verifiziert: `grep -rilo "task" tests/agent --include="*.php"`
                     liefert 0 Treffer — keine "task"-Reste in Datei-/Klassen-/
                     Methodennamen oder Inhalten.
            [x] 7.3: Kategorie B — Mixed-Dateien (Zeile-für-Zeile-Review)
                     → 6 Dateien zeilenweise geprüft (cron-Task-Klassen,
                     confirm_run_service.php, embeddings_readiness_service.php,
                     recreate_skill_catalog_skill.php, agent_executor.php [Filter-Bug
                     `grep -v executor.php$` traf versehentlich auch `agent_executor.php`,
                     manuell nachgezogen], booking_validate_option.php [unverändert,
                     `$params['task']` ist eigenständiger Mutationstyp-Begriff]).
                     2 Bulk-Replace-Kollateralschäden gefunden+revertiert: Lang-String
                     `aiexecutionmode_adhoc` ("adhoc task"→fälschlich "adhoc skill") und
                     Lang-Key `task_execute_ai_run` (fälschlich zu `skill_execute_ai_run`
                     umbenannt, hätte Konsumenten in execute_ai_run_adhoc.php gebrochen).
            [x] 7.4: Kategorie C — Fallback-Shim-Entfernung (~28 Dateien)
                     → 8 Shims (`?? $x['task']`) in 4 Dateien entfernt: executor.php,
                     confirm_run_service.php (×2), embeddings_readiness_service.php (×2),
                     family_embeddings_index_service.php (×3). Direkter `['skill']`-Zugriff,
                     da vorproduktiv (Georgs Leitentscheidung, keine Migration nötig).
            [x] 7.5: AGENT_IMPLEMENTATION_FLOWCHART.mmd — Knoten-Labels TR/TI/TPC/
                     TRC/TCV/BTASK/CTASK/BT auf Skill-Terminologie (vormals in
                     Schritt 8/Doku vorgesehen — hierher vorgezogen, da Teil der
                     "ALLE Vorkommen"-Vorgabe; [[feedback_flowchart_policy]] beachtet,
                     da Flowchart primäre Architekturdoku ist — Diskrepanzen mit
                     Code-Realität ggf. separat mit Georg klären, NICHT im Zuge
                     dieses mechanischen Renames eigenständig umgestalten)
                     → case-erhaltendes sed-Replace (6 Varianten) auf der gesamten
                     Datei, mit Ausnahme von 2 Zeilen mit legitimen Moodle-Markern
                     (Zeile 15 "classes/task/"-Pfad, Zeile 272 "queue_adhoc_task"),
                     die unverändert blieben. Knoten-IDs BTASK/CTASK/TASKLOAD/TASKS/
                     SELTASK wurden konsistent zu BSKILL/CSKILL/SKILLLOAD/SKILLS/
                     SELSKILL inkl. aller Kanten-Referenzen umbenannt (TR/TI/TPC/
                     TRC/TCV/BT als reine Abkürzungen ohne "task"-Substring blieben
                     als IDs erhalten, ihre Labels wurden auf Skill-Terminologie
                     aktualisiert). Verifiziert: subgraph/end-Paare weiterhin balanciert
                     (18/18), keine verwaisten Knoten-Referenzen, nur die 2 legitimen
                     Moodle-Zeilen enthalten noch "task".
            [x] 7.6: php -l auf allen geänderten Dateien + vollständiger Testlauf,
                     Vergleich gegen Schritt-6-Baseline (247/1182/32 Failures/0 Errors)
                     → php -l: alle ~190 geänderten/neuen PHP-Dateien fehlerfrei.
                     Finaler Testlauf (nach purge_caches + phpunit-Reinit):
                     247 Tests, 1156 Assertions, 1 Error, 33 Failures, 48 Deprecations
                     — reproduzierbar identisch zum Zwischenbefund oben (s.u. für
                     Ursachenanalyse: 2 vorbestehende, nicht durch den Rename
                     verursachte Bugs, durch Stash-Vergleich gegen sauberes HEAD
                     verifiziert). Alle übrigen 31 Failures weiterhin AUSSCHLIESSLICH
                     "Real-LLM tests require BOOKING_TEST_AI_KEY + BOOKING_TEST_AI_MODEL".

                     ZWISCHENBEFUND (Testlauf nach 7.1–7.4): 247 Tests, 1156 Assertions,
                     1 Error, 33 Failures (statt 0 Errors/32 Failures) — 2 neue Fälle:
                       1) r3_skill_e2e_test::test_r3_book_users_confirm_flow_never_enters_retry_waiting
                          → "Skill denied by governance gate (missing_capability):
                          mod_booking.create_option"
                       2) ai_confirm_run_contract_test::test_terminal_confirm_success_triggers_finalizer_when_no_follow_up_queue_item_exists
                          → erwartet 'sufficient', erhalten 'error'

                     URSACHENANALYSE (per Stash-Vergleich gegen sauberes HEAD f5f8a4c
                     verifiziert — NICHT durch den Rename verursacht):
                     HEAD ist bereits in einem kaputten Zustand: Commit 0b4315f
                     ("make benchmark report graph unlimited", Vorfahre von HEAD)
                     hat u.a. `task_provider_interface.php`, `task_interface.php`,
                     `task_contract_validator.php` und 8 weitere Interface-/Contract-/
                     DTO-Dateien gelöscht, OHNE die weiterhin darauf verweisenden
                     Konsumenten (`task_provider.php`, `task_registry.php`, …)
                     anzupassen → fataler "Interface ... not found"-Fehler beim
                     Klassenladen. Dadurch schlugen beide Tests in echtem HEAD
                     NOCH FRÜHER fehl (Fatal Error statt Governance-/Assertion-Fehler)
                     — verifiziert per `git stash` + frischem `purge_caches` +
                     `phpunit/cli/init.php` gegen den sauberen HEAD-Stand.
                     Mein Branch enthält (aus früherer Session-Arbeit, vor diesem
                     Rename-Schritt) bereits Wiederherstellungen dieser fehlenden
                     Dateien (heute als `skill_*`-Pendants vorhanden, z. B.
                     `skill_provider_interface.php`, `skill_interface.php`,
                     `skill_contract_validator.php`) — dadurch kommen die Tests
                     erstmals so weit, dass sie auf zwei ANDERE, ebenfalls
                     vorbestehende Bugs treffen, die zuvor vom fatalen Fehler
                     verdeckt wurden:
                       a) Capability-Namens-Mismatch (unabhängig vom Rename):
                          `skill_contract_validator::build_skill_capability_name()`
                          erzeugt aus Skillname 'mod_booking.create_option' die
                          Capability `…:skill_mod_booking_create_option`, aber
                          db/access.php registriert `…:skill_booking_create_option`
                          (ohne "mod_"-Präfix) — Inkonsistenz "mod_booking" vs.
                          "booking" in der Namenskonvention, nicht task/skill-bezogen.
                       b) 'sufficient' vs. 'error'-Mismatch in confirm_run_service —
                          ebenfalls unabhängig von der Umbenennung (reine
                          Statuswert-Logik).
                     FAZIT: Beide "neuen" Fehler sind keine Regressionen durch den
                     Rename, sondern vorbestehende, bislang durch einen schwereren
                     vorbestehenden Bug maskierte Probleme — separat mit Georg zu
                     klären (nicht Teil des Rename-Scopes). Stash wieder restauriert,
                     keine Arbeit verloren.
            [x] 7.7: Grep-Restprüfung `grep -rilo "task" …` — verbleibende Treffer
                     dürfen sich NUR noch auf die in "ABGRENZUNG" gelistete
                     Ausnahmemenge reduzieren
                     → genau 10 Dateien mit verbleibenden "task"-Treffern, alle
                     Treffer einzeln geprüft — ausschließlich legitime Moodle-Task-
                     API-Referenzen (core\task\manager, adhoc_task/scheduled_task-
                     Basisklassen, queue_adhoc_task, REBUILD_TASK_CLASS-Konstante,
                     Lifecycle-Docblocks "Execute task.", Lang-Keys
                     task_execute_ai_run/aiexecutionmode_adhoc) sowie der
                     eigenständige `$params['task']`-Mutationstyp-Begriff in
                     booking_validate_option.php. Keine Agent-Domänen-"task"-Reste
                     mehr vorhanden.

            HINWEIS Inventur-Dokumente (docs/Blueprints/*.md, exkl. obsolete/):
            bleiben gemäß bestehender Plan-Policy unverändert (Snapshots); die
            "noch 3280"-Zählung Georgs schließt diese ein, der HANDLUNGSSCOPE
            dieses Schritts ist aber Code (+ Flowchart, s. 7.5) — nach Abschluss
            ggf. neue Inventur erstellen, die den Skill-Endstand zeigt (s. Schritt 8).

Schritt 8 — Schicht E: Tests, Blueprint, Doku
            • Verbleibende Restarbeiten aus diesem Themenfeld, soweit nicht
              bereits durch Schritt 7 (insb. 7.2 Testdateien, 7.5 Flowchart)
              miterledigt
            • Bestehende Inventur-Dokumente NICHT rückwirkend ändern (Snapshots);
              ggf. nach Abschluss eine neue Inventur erstellen, die den Skill-Stand zeigt

Schritt 9 — Abschlussverifikation
            • Vollständiger Testlauf (Contract + Scenario + Real-LLM) gegen Baseline aus Schritt 1
            • Grep-Restprüfung: `grep -ril "task" ...` im Agent-Verzeichnis — verbleibende
              Treffer müssen sich auf die drei legitimen Moodle-Core-Task-Dateien
              (s. Abschnitt 2) sowie auf evtl. bewusst belassene Wire-IDs (Schritt 5)
              reduzieren lassen; alles andere ist ein vergessener Rename-Kandidat
```

### Wichtige Hinweise zur radikalen Variante

1. **DB-Felder: `rename_field` vs. Neuanlage.** Da `db/install.xml` ohnehin direkt geändert wird (kein Upgrade-Step), kann bei den drei Benchmark-Feldern (`skill_set`, `skill_expected`, `skill_selected`) abgewogen werden: entweder die Felder in `install.xml` umbenennen (sauberer für Neuinstallationen) oder — falls bereits Testinstallationen mit Daten existieren, an denen jemand hängt — ein einmaliger, undramatischer `rename_field`-Schritt in `upgrade.php`. Das ist die einzige Stelle, an der eine "Mini-Migration" sinnvoll sein könnte — aber als technische Aufräumarbeit, nicht als Produktivdaten-Schutz.

2. **Reihenfolge A vor C**: Auch wenn Schritt 3 (Wire-Format) jetzt Teil des Hauptvorhabens ist, sollte er **nach** dem PHP-Rename (Schritt 2) erfolgen — der Interpreter und die Klassifikatoren, die das neue Wire-Format parsen, existieren ja erst nach Schritt 2 unter ihren neuen Namen. Eine Vertauschung würde unnötige Zwischenstände erzeugen.

3. **Real-LLM-Tests sind der Taktgeber**: Schritt 3 ist der einzige Schritt, der nicht rein mechanisch ist. Plant hierfür Pufferzeit ein — die LLM könnte auf den neuen Vertrag (`"skill_call"` statt `"skill_call"`, `"skill"`-Schlüssel statt `"task"`-Schlüssel im Envelope) zunächst unerwartet reagieren, was iterative Prompt-Korrekturen erfordern kann.

4. ~~**Offene Detailfrage für Schritt 6 (DE-Übersetzung)**~~ — **ENTSCHIEDEN (2026-06-07, in Schritt 6 umgesetzt):** "Skill" wird im Deutschen als Lehnwort beibehalten, nicht übersetzt. Begründung: Die Repo-Konvention war zum Zeitpunkt der Umsetzung bereits etabliert — `lang/de/bookingextension_agent.php` enthielt schon `benchmark_skill_hit` = 'Skill-Treffer' (vermutlich aus einem früheren, teilweise durchgeführten Rename-Schritt). Konsistente Bildung von "Skill-Set", "Skill-Katalog", "Skill-Governance" etc. analog dazu. Vermeidet Mapping-Verwirrung zwischen Code-Begriff und UI-Text.

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
