# Bereinigungskonzept: bookingextension_agent

> **Datum:** 2026-06-07
> **Basis:** `bookingextension_agent_inventur_2026-06-06.md`, `flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd`
> **Status:** Punkte 2 + 3 der Inventur-Empfehlungen bereits umgesetzt (siehe Abschnitt 0). Dieses Dokument plant die verbleibenden Punkte (4, 5) sowie die Diskussionspunkte aus Kapitel 6 der Inventur.

---

## 0. Bereits umgesetzt (heute)

| # | Maßnahme | Status |
|---|---|---|
| 2 | `services/planning/` (leeres Verzeichnis) gelöscht | ✅ erledigt |
| 3 | Legacy-WS-Endpoints `external/booking_create_option.php`, `booking_update_option.php`, `booking_bulk_update_options.php`, `booking_validate_option.php` gelöscht | ✅ erledigt |

**Befund zu Punkt 3:** Alle vier Dateien waren **nicht** in `db/services.php` registriert (keine WS-Funktion `bookingextension_agent_booking_*`) und wurden außerhalb ihrer eigenen Klassen von keinem aktiven Code referenziert — eindeutig totes Code.

⚠️ **Korrektur zur Inventur-Annahme:** Die zugehörigen DTOs `create_option_input_dto`, `update_option_input_dto`, `bulk_update_options_input_dto`, `create_entity_input_dto` habe ich **bewusst NICHT gelöscht**. Sie sind keine Legacy-Reste, sondern werden aktiv von `services/mutation/option_mutation_service.php` und `services/mutation/entity_mutation_service.php` verwendet (`validate_create()`, `validate_update()`, `validate_bulk_update()`, `create_entity()` etc.) — also Teil der lebendigen Mutation-Architektur. Die Inventur hatte hier eine falsche Abhängigkeitsannahme (⚠️ "Abhängig von Legacy-WS").

`wunderbyte_trial_endpoint.py` bleibt nach deinem Wunsch vorerst liegen (dazu mehr in Abschnitt 4).

---

## 1. Verbleibende Maßnahmen aus der Inventur — Übersicht

| # | Maßnahme | Aufwand | Risiko | Empfehlung |
|---|---|---|---|---|
| 4 | `booking_task_support.php` (2895 Zeilen / 102 KB) in kleinere Services aufteilen | hoch | mittel–hoch | Geplant in Abschnitt 2 — **mehrstufiges Refactoring**, nicht in einem Rutsch |
| 5 | `embedding_query_builder` als eigenes Objekt extrahieren | mittel | niedrig | Geplant in Abschnitt 3 — **mechanisches Extract-Class-Refactoring** |
| 6.1 | `orchestrator.php` (2397 Zeilen / 96 KB) generell verkleinern | hoch | mittel | Folgt aus Abschnitt 3 (EMB_QUERY-Extraktion ist der erste Schritt) |
| 6.3 | `agent_decision_service.php` (1567 Zeilen / 62 KB) aufteilen | mittel | mittel | Siehe Abschnitt 5 |
| 6.4 | `ai_privacy_precheck.php` Eigenständigkeit diskutieren | niedrig | niedrig | Siehe Abschnitt 6 — **Empfehlung: behalten wie es ist** |
| 6.5 | Trial-System in `trial/`-Verzeichnis organisieren | niedrig | niedrig | Siehe Abschnitt 4 |
| 6.8 | `embeddings_csv_repository.php` nach `services/embeddings/` verschieben | sehr niedrig | sehr niedrig | Siehe Abschnitt 7 — **Quick-Win, sofort machbar** |
| — | `wunderbyte_trial_endpoint.py` | niedrig | niedrig | Siehe Abschnitt 4 (auf Wunsch zurückgestellt) |
| — | `ws_message_formatter.php` (1.5 KB) inline? | sehr niedrig | sehr niedrig | Siehe Abschnitt 7 — **Empfehlung: so lassen** |

---

## 2. `booking_task_support.php` aufteilen (Punkt 4 — mehr Planung gewünscht)

### Ist-Zustand
2895 Zeilen / 102 KB, Klasse `mod_booking\local\wbagent\booking\booking_task_support`. Mischt mindestens vier Verantwortlichkeiten:

1. **Task-Registry-Funktionen**: `get_task_names()`, `get_contextual_prompt_packs()`, `get_task_instances()`, `has_task_name()`
2. **Schema-Access**: `get_task_schema()`, `check_structure()`
3. **Execute-Delegation**: `execute()` → delegiert an `booking_task_mutation_execute_service`
4. **Resolution-Logik** (größter Block): `resolve_single_option()`, `resolve_single_user()`, `resolve_single_course()`, `find_existing_options_by_exact_title()`, `search_option_candidates_for_preview()`, `search_user_candidates_for_preview()`, `search_course_candidates_for_preview()`, `resolve_courses_for_restriction()` + diverse private Helfer
5. **Datetime-Normalisierung**: `parse_datetime()`, `normalize_identity_datetime()`, `normalize_temporal_input()`, `extract_optiondates()`
6. **Permission-Validation**: `validate_update_field_permissions()`

Die Inventur schlägt vor: `booking_resolution_service`, `booking_datetime_service`, `booking_schema_service`.

### Vorgeschlagene Zielstruktur

```
mod_booking/classes/local/wbagent/booking/
├── booking_skill_provider.php              (bleibt — dünner Provider-Entry)
├── booking_task_support.php                (bleibt — schrumpft auf Registry/Execute-Delegation)
├── support/
│   ├── booking_resolution_service.php       (NEU: alle resolve_*/search_*_candidates_*)
│   ├── booking_datetime_service.php         (NEU: parse_datetime/normalize_*/extract_optiondates)
│   ├── booking_schema_service.php           (NEU: get_task_schema/check_structure)
│   ├── booking_mutation_validation.php       (bereits vorhanden — bleibt)
│   ├── booking_rules_agent_service.php        (bereits vorhanden — bleibt)
│   └── slot_booking_normalizer.php             (bereits vorhanden — bleibt)
```

Alle drei neuen Klassen werden als **statische Service-Klassen** ausgelegt (analog zu den bestehenden `support/`-Klassen, die bereits statische Methoden mit `static`-Signaturen verwenden — siehe `booking_mutation_validation::validate_common()`), damit der Umstieg ohne DI-Container-Änderungen erfolgt.

### Phasenplan (kein Big-Bang!)

**Phase 1 — Datetime-Service extrahieren (geringstes Risiko, klar abgegrenzt)**
- Verschiebe `parse_datetime()`, `normalize_identity_datetime()`, `normalize_temporal_input()`, `extract_optiondates()` (alle bereits `static`) 1:1 nach `booking_datetime_service`
- In `booking_task_support` als dünne Pass-Through-Aliase belassen ODER alle Aufrufer direkt umstellen (Empfehlung: Aufrufer umstellen, Aliase vermeiden — siehe Hinweis unten zu Code-Hygiene)
- Tests: bestehende Contract-Tests für Datetime-Normalisierung laufen lassen, keine neuen Tests nötig (reine Verschiebung)

**Phase 2 — Resolution-Service extrahieren (größter Brocken, höchster Nutzen)**
- Verschiebe alle `resolve_*`/`search_*_candidates_*`/`find_existing_options_by_exact_title` (alle `static`) nach `booking_resolution_service`
- Das ist der Block mit dem in der Inventur vermerkten Duplikat-Verdacht (`search_user_candidates()` vs. `search_user_candidates_for_preview()`) — **bei der Verschiebung gleich bereinigen**: prüfen ob beide Methoden wirklich unterschiedliche Kontexte brauchen oder ob eine die andere mit einem Parameter ersetzen kann
- Tests: Resolution wird in mehreren Task-Klassen verwendet (`create_option_task`, `update_option_task`, `book_users_task` etc.) — Contract-Tests + Real-LLM-Tests für diese Tasks erneut laufen lassen

**Phase 3 — Schema-Service extrahieren (kleinster Block, am Ende)**
- `get_task_schema()`, `check_structure()` nach `booking_schema_service`
- Geringes Risiko, da klar gekapselt

**Phase 4 — Aufräumen**
- `booking_task_support` behält nur noch: `get_task_names()`, `get_contextual_prompt_packs()`, `execute()` (Delegation), `validate_update_field_permissions()`, `get_task_instances()`/`has_task_name()` (Registry-nah)
- Erwartete Restgröße: ~400-600 Zeilen statt 2895

### Wichtiger Hinweis zur Vorgehensweise
Bei jeder Phase: **Aufrufer direkt umstellen**, keine Re-Export-Aliase oder Kompatibilitäts-Shims hinterlassen (`booking_task_support::parse_datetime()` als Wrapper um `booking_datetime_service::parse_datetime()` wäre technische Schuld, die später wieder aufgeräumt werden müsste). Da alle betroffenen Methoden `static` sind, ist ein direktes Suchen/Ersetzen der vollqualifizierten Aufrufe (`booking_task_support::parse_datetime(` → `booking_datetime_service::parse_datetime(`) risikoarm und vollständig grep-bar.

### Geschätzter Aufwand
3 Phasen à ca. 0,5–1 Tag (inkl. Testlauf), Phase 2 eher 1–1,5 Tage wegen Umfang und Duplikat-Bereinigung.

---

## 3. `embedding_query_builder` als eigenes Objekt extrahieren (Punkt 5)

### Ist-Zustand
Laut Blueprint (`EMB_QUERY` in `AGENT_IMPLEMENTATION_FLOWCHART.mmd:83`) soll es ein eigenständiges Objekt geben mit der Aufgabe:

> query = latest user message + next_step_intent (from thread metadata, if set), always appended when present → prevents wrong clarification on short confirmations ("ja", "ok"); update_option_trainer + book_users: always-include downstream tasks (ALWAYS_INCLUDE_TASK_NAMES in adaptive_task_catalog_service)

Aktuell ist diese Logik **inline im `orchestrator.php`** (96 KB / 2397 Zeilen) verstreut.

### Vorgeschlagene Extraktion

Neue Klasse: `services/discovery/embedding_query_builder.php`

```
namespace bookingextension_agent\local\wbagent\services\discovery;

class embedding_query_builder {
    public static function build(
        string $latest_user_message,
        ?string $next_step_intent,
        array $context
    ): string { ... }
}
```

### Vorgehen
1. **Identifikation**: Im Orchestrator nach der EMB_QUERY-Logik suchen — vermutlich in `run_discovery_phase()` oder einer der Catalog-Hilfsmethoden (`slim_prompt_catalog_for_planner`, `filter_catalog_by_selected_families`). Der Flowchart-Hinweis "CS15 → read at discovery start → EMB_QUERY" (Zeile 519) gibt den Ankerpunkt: Suche nach `get_thread_metadata_value` mit Key `next_step_intent` im Discovery-Kontext.
2. **Extraktion**: Logik 1:1 in die neue Klasse verschieben, Methode `build()` mit den nötigen Parametern (Message, next_step_intent, ggf. Context-Signale)
3. **Wiring**: Orchestrator ruft `embedding_query_builder::build(...)` auf statt die Logik inline auszuführen
4. **Test**: Neuer Contract-Test `embedding_query_builder_test.php` analog zu den bestehenden 36 Contract-Tests — testet insbesondere das in der Blueprint-Notiz beschriebene Verhalten ("verhindert falsche Rückfrage bei kurzen Bestätigungen wie 'ja', 'ok'")

### Nutzen
- Direkter Beitrag zu Diskussionspunkt 6.1 (Orchestrator-Größe) — jede saubere Extraktion verkleinert die 2397-Zeilen-Datei
- Bessere Testbarkeit dieser sicherheitsrelevanten Logik (verhindert Fehlinterpretationen bei Kurzantworten)
- Bringt den Code in Blueprint-Konformität (aktuell als ⚠️ "kein eigenes Objekt" markiert)

### Geschätzter Aufwand
0,5–1 Tag — mechanisches Extract-Class-Refactoring mit überschaubarem Umfang, sofern die Logik tatsächlich lokal zusammenhängend ist (zu verifizieren beim Lesen des Codes).

### Reihenfolge-Empfehlung
**Vor** Punkt 4 (booking_task_support) angehen — kleinerer, klar abgegrenzter Schnitt, schafft Routine für die größeren Refactorings und liefert schnell sichtbaren Fortschritt bei der Orchestrator-Verkleinerung.

---

## 4. Trial-System bündeln + Python-Datei (Diskussionspunkt 6.5, zurückgestellt: Punkt 1)

### Ist-Zustand
Vier Dateien bilden ein eigenständiges, nicht im Blueprint vorkommendes Sub-System:
- `classes/external/activate_trial_context.php`
- `classes/external/request_trial_key.php`
- `trial_challenge.php` (Root, 1.5 KB)
- `classes/local/wbagent/wunderbyte_trial_endpoint.py` (Python-Referenzimplementierung — **bleibt vorerst, auf deinen Wunsch**)

### Vorschlag (für später, wenn ihr die Python-Datei verschiebt)
```
bookingextension/agent/classes/local/wbagent/trial/
├── (ggf. PHP-Hilfsklassen, falls welche entstehen)
docs/reference/
└── wunderbyte_trial_endpoint.py    (Python-Referenzimpl. gehört in Doku, nicht ins PHP-Classes-Verzeichnis)
```

Die beiden `external/`-Endpunkte (`activate_trial_context`, `request_trial_key`) sind in `db/services.php` korrekt registriert (Zeilen 101–116) — sie sind **kein** totes Gewebe, sondern aktive WS-Funktionen. Eine Verschiebung in einen eigenen Namespace `bookingextension_agent\external\trial\...` würde die `classname`-Pfade in `services.php` und ggf. Frontend-JS-Aufrufe berühren — **das ist kein Quick-Win**, sondern ein eigener kleiner Migrationsschritt mit Update von `db/services.php`, Versions-Bump und Cache-Reset.

### Empfehlung
Diesen Punkt zusammen mit der Python-Datei behandeln, sobald ihr entscheidet, wohin `wunderbyte_trial_endpoint.py` soll (Doku-Repo? eigenes Referenz-Repo? `docs/`-Ordner dieses Plugins?). Eine Bündelung *nur* der PHP-Dateien ohne die Python-Datei würde wenig Mehrwert bringen, da die WS-Endpoints bereits klar als Trial-System erkennbar benannt sind (`*_trial_*`). **Aktuell kein Handlungsbedarf — auf Rückmeldung von dir warten, wo die Python-Datei letztlich landen soll.**

---

## 5. `agent_decision_service.php` aufteilen (Diskussionspunkt 6.3)

### Ist-Zustand
1567 Zeilen / 62 KB. Die Inventur schlägt vor, Confirm-Pending-Logik und Command-Routing-Logik in eigene Services auszulagern.

### Einschätzung
Im Gegensatz zu `booking_task_support.php` ist diese Klasse bereits **klar in benannte private Methoden strukturiert** (`handle_confirm_pending()`, `handle_command_routing()`, `handle_preflight()`, `apply_execution_guard_tokens()`, `persist_pending_intent_pointer()`, `execute_readonly_commands()`, `split_commands_by_risk_class()`, `split_commands_by_mutability()`, `inject_risk_class_into_commands()`). Das ist **keine Gottklasse im klassischen Sinn**, sondern eine große, aber wohlstrukturierte Orchestrierungsklasse, die die zentrale `D_PREVIEW → D_PENDING → D_LOOKUP_GUARD → D_PROMOTE → D_ROUTE`-Pipeline aus dem Blueprint 1:1 abbildet.

### Empfehlung
**Niedrigere Priorität als `booking_task_support`.** Falls eine Aufteilung gewünscht ist, sind die natürlichen Schnittkandidaten:
- `confirm_pending_handler` (← `handle_confirm_pending`, `persist_pending_intent_pointer` für den Confirm-Pfad)
- `command_routing_service` (← `handle_command_routing`, `split_commands_by_risk_class`, `split_commands_by_mutability`, `inject_risk_class_into_commands`)

Beide würden vom `agent_decision_service::process()` als Collaborator injiziert. Das ist machbar, aber der Nutzen ist geringer als bei `booking_task_support` (dort sind die Verantwortlichkeiten fachlich völlig verschieden — Datetime vs. Resolution vs. Schema; hier sind sie Teil derselben Routing-Pipeline und eng verzahnt). **Empfehlung: zurückstellen, bis Punkt 4 abgeschlossen ist und sich zeigt, ob das Muster sich bewährt.**

---

## 6. `ai_privacy_precheck.php` (Diskussionspunkt 6.4)

### Ist-Zustand
Eigenständiger WS-Endpoint (`bookingextension_agent_ai_privacy_precheck`, korrekt in `db/services.php` registriert), der vom Frontend als Pre-Step vor `ai_send_message` aufgerufen wird, um die User-Nachricht auf PII zu prüfen, bevor sie überhaupt gesendet wird.

### Einschätzung
Eine Integration in `ai_send_message` würde bedeuten, dass der Privacy-Check **serverseitig nach** dem Senden passiert — das widerspricht dem UX-Zweck (Nutzer soll **vor** dem Absenden gewarnt werden, z. B. um die Nachricht noch zu redigieren). Die Eigenständigkeit ist hier **funktional begründet**, nicht historisch gewachsen.

### Empfehlung
**So lassen.** Die Diskussion in der Inventur war als offene Frage markiert — die Antwort ergibt sich aus dem UX-Flow: ein separater Read-Type-Endpoint vor dem eigentlichen Senden ist die richtige Architektur, kein Cleanup-Kandidat.

---

## 7. Quick-Wins (sofort umsetzbar, sehr geringes Risiko)

### 7.1 `embeddings_csv_repository.php` verschieben
- **Von:** `classes/local/wbagent/embeddings_csv_repository.php`
- **Nach:** `classes/local/wbagent/services/embeddings/embeddings_csv_repository.php`
- Reine Namespace-/Pfad-Verschiebung (Moodle-Autoloading via PSR-4 erfordert Pfad = Namespace), alle `use`-Statements der Aufrufer müssen mitgezogen werden (`grep -rl embeddings_csv_repository`)
- Aufwand: < 1 Stunde inkl. Grep-Verifikation

### 7.2 `ws_message_formatter.php` (1.5 KB)
- Inventur fragt, ob diese Klasse (nur `format()` als statische Methode) inline sein könnte
- **Empfehlung: behalten.** Eine eigene, benannte Formatter-Klasse mit einer einzigen statischen Methode ist ein etabliertes, leicht testbares Muster (Single-Responsibility) und wird vermutlich von mehreren `external/*`-Klassen verwendet — Inline würde zu Duplikation führen. Verifizieren mit `grep -rl ws_message_formatter`, falls tatsächlich nur ein Aufrufer existiert, dann neu bewerten.

---

## 8. Empfohlene Reihenfolge (Gesamt-Roadmap)

```
1. ✅ services/planning/ löschen                          [erledigt]
2. ✅ Legacy-WS-Endpoints löschen                          [erledigt]
3. 🔜 Quick-Wins (Abschnitt 7): embeddings_csv_repository  [< 1 Std.]
4. 🔜 embedding_query_builder extrahieren (Abschnitt 3)    [0,5-1 Tag]
5. 🔜 booking_task_support aufteilen, Phase 1-4 (Abschnitt 2) [3-4 Tage gesamt]
6. ⏸  agent_decision_service evaluieren (Abschnitt 5)      [zurückgestellt, nach 5.]
7. ⏸  Trial-System + Python-Datei (Abschnitt 4)            [wartet auf deine Entscheidung zum Zielort]
```

`ai_privacy_precheck` (Abschnitt 6) erfordert keine Aktion — Diskussionspunkt geklärt, Architektur ist korrekt.

---

## 9. Zusammenfassung der Korrekturen zur Inventur

Beim Umsetzen sind zwei Punkte aufgefallen, die die Inventur vom 2026-06-06 anders einschätzte als der tatsächliche Code-Zustand:

1. **DTOs nicht an Legacy-WS gebunden**: `create_option_input_dto`, `update_option_input_dto`, `bulk_update_options_input_dto`, `create_entity_input_dto` sind aktive Bestandteile der `mutation/`-Services, nicht Legacy-Reste. Sie wurden **nicht** gelöscht.
2. **`agent_decision_service.php` ist keine Gottklasse**: Trotz 1567 Zeilen ist sie klar in benannte, fachlich zusammengehörige private Methoden strukturiert und bildet die Blueprint-Pipeline 1:1 ab — eine Aufteilung hätte geringeren Nutzen als bei `booking_task_support.php`.

Diese Korrekturen sind in den jeweiligen Abschnitten oben dokumentiert, damit zukünftige Inventuren diese Annahmen nicht wiederholen.
