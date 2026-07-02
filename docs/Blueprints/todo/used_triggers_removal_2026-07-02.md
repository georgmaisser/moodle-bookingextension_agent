# Blueprint · `used_triggers` abbauen — von 5 Triggern auf (fast) null

> **Status:** TEMPORÄR — Planungsdokument, nicht in Production. Datum: 2026-07-02.
> **Auslöser:** Frage „bekommen wir mit used_triggers wirklich etwas, das wir sonst nicht hätten?"
> **Scope:** `bookingextension_agent` (Engine/Prompt/Decision/Benchmark/Docs). Berührt Flowchart-Knoten → Flowchart-Policy.

---

## 1. Ist-Zustand: 5 Core-Trigger, real geprüft

`message_trigger_registry.php:55-77` (`CORE_TRIGGERS`). `used_triggers` ist ein **zweiter Kontroll-Kanal neben `response_type`** — die Architektur (Docs 08/14/16 + Flowchart `MTRIG`) sagt selbst: „signals about what the turn is, NOT routing".

Konsumiert wird ausschließlich in `agent_decision_service` über `trigger_result_util::has_trigger()`. Nach Trace jedes einzelnen:

| Trigger | Konsument | **Realer Status** |
|---|---|---|
| `core.is_lookup_request` | decision:225 (Lookup-Safety-Guard) | **TOT** — kein Producer. Docs+Flowchart behaupten „server derives", aber `message_trigger_registry` hat nur `normalize_*`, kein Setter; Prompt verbietet dem LLM es zu emittieren (`prompt_policy_builder:209`). Guard feuert nie. |
| `core.force_new_duplicate_option` | — | **TOT** — in `CORE_TRIGGERS` definiert/beworben, **kein Konsument** im Code. |
| `core.is_preview_request` | decision:183 (Preview-Shortcut) | **TOT in der Praxis** — Guard verlangt `$previewoptionid > 0`, aber der einzige Aufrufer (`agent_runtime.php:1086`) übergibt **hart `0`**. Zudem: Previews werden ohnehin automatisch gerendert (`preview_passthrough::resolve_preview_json` in `ai_send_message.php:291`), der Shortcut ist auch inhaltlich redundant. |
| `core.is_confirmation_message` | decision:298 (`should_block…` → nicht blocken) | **REDUNDANT** — `should_block_new_intent_while_pending()` gibt schon bei `response_type==confirm_pending` (decision:303) `false` zurück; das LLM emittiert beide zusammen (`initial_system_prompt.md:54`). |
| `core.discard_pending_confirmation` | decision:207 (Pending-Intent löschen) | **LEBENDIG, aber (a) mit deterministischem WS-Zwilling `ai_discard_pending` (UI-Button) und (b) selbst fehlerhaft** — siehe Bug 2. |

**Ergebnis: 3 tot, 1 redundant, 1 lebendig-aber-imperfekt.** Der einzige echte Nutzen des ganzen Kanals ist die NL-Discard-Absicht — und die hat einen deterministischen Zwilling.

---

## 2. Zwei Bugs, unabhängig vom Abbau (sollte George kennen)

- **Bug 1 (Doku-Lüge + toter Guard):** `core.is_lookup_request` ist in vier Docs (`08:40`, `14:87`, `16:58`) + Flowchart `MTRIG` als *„server derives"* dokumentiert — **nirgends implementiert**. Der Lookup-Safety-Guard (decision:223-243) ist damit toter Code.
- **Bug 2 (asymmetrischer Discard):** Der NL-Discard-Pfad (decision:207) ruft nur `pendingintentsvc->clear()` — er räumt **nicht** die Queue-Items ab. Der WS-Pfad (`discard_pending_service::discard()`, Z.57-98) markiert dagegen alle aktionablen mutierenden Items als `skipped` (`USER_DISCARDED_PENDING_CONFIRMATION`). → Der NL-Discard kann **verwaiste, weiterhin aktionable Queue-Items** hinterlassen. Der „lebendige" Trigger ist also der halb-kaputte Pfad.

---

## 3. Designentscheidung: KEINE neuen response_types

Naheliegend wäre, `preview`/`discard` als neue `response_type`-Werte zu führen. **Verworfen:** `response_type` wird an **drei** redundanten Allow-Lists geführt (`message_trigger_registry:41`, `agent_runtime` `ALLOWED_FINAL_RESPONSE_TYPES`, `interpreter` `ALLOWED_RESPONSE_TYPES`) und in ~11 if/in_array-Ketten geprüft (Decision, Interpreter, Runtime, finalization_classifier, Prompt-Policy ×4). Ein neuer Wert = 11+ Dateien, hohes Silent-Fail-Risiko. Da 4 von 5 Triggern tot/redundant sind, ist der richtige Weg **Abbau**, nicht Umlagerung.

---

## 4. Der Umbau — drei unabhängig commit-/testbare Stufen

### Stufe 1 — 3 tote Trigger + Guards entfernen (NULL Verhaltensänderung)
Reiner Dead-Code-Abbau. `used_triggers`-Feld bleibt vorerst (Stufe 2/3 leben noch).

1. **`message_trigger_registry.php:55-77`**: `is_lookup_request`, `force_new_duplicate_option`, `is_preview_request` aus `CORE_TRIGGERS` streichen (bleiben: `is_confirmation_message`, `discard_pending_confirmation`).
2. **`agent_decision_service.php`**: Preview-Guard (182-201) und Lookup-Guard (223-243) löschen; `int $previewoptionid` aus `process()`-Signatur (171) entfernen; PHPDoc (162) anpassen.
3. **`agent_runtime.php:1080-1087`**: 6. Argument `0` beim `process()`-Aufruf entfernen.
4. **Docs/Flowchart (Flowchart-Policy, mit George):** `D_PREVIEW` (172), `D_LOOKUP_GUARD` (176) aus Flowchart; `MTRIG` (280) „server derives" streichen; `08-decision-service.md:34/36/40`, `14:87`, `16:58` bereinigen. **Behebt Bug 1.**
- **Bricht nichts:** keiner der drei Guards feuerte je. Previews laufen weiter über den Auto-Pfad (`preview_passthrough`). Verifikation: `route_*`/create-Szenarien im Benchmark unverändert grün.

### Stufe 2 — `is_confirmation_message` fallenlassen (redundant)
Einzige Wahrheitsquelle für „User bestätigt" wird `response_type == confirm_pending`.

1. **`message_trigger_registry.php`**: `is_confirmation_message` aus `CORE_TRIGGERS`.
2. **`agent_decision_service.php:298`**: den `has_trigger(is_confirmation_message)`-Zweig in `should_block_new_intent_while_pending()` löschen — Z.303 (`confirm_pending → return false`) deckt den Bestätigungsfall bereits ab.
3. **Prompt:** `prompt_policy_builder.php:210` + `initial_system_prompt.md:54` (Beispiel auf `used_triggers: []` bzw. reines `confirm_pending`).
4. **Tests:** `integration_agent_framework_test.php:929` (assert `['core.is_confirmation_message']`) anpassen.
- **Risiko: niedrig.** Einziger verlorener Fall: LLM setzt den Trigger, aber **nicht** `confirm_pending` (inkonsistenter Output) — dann würde ein neuer Intent bei pending korrekt geblockt (das ist die gewollte Step-8-Semantik). Verifikation: `short_confirm_ja` / `short_confirm_weiter` Benchmark-Szenarien + gezielter Unit-Test „ja bei pending confirmation".

### Stufe 3 — der letzte Konsument (`discard`) → ENTSCHEIDUNG
Nach Stufe 1+2 ist `discard_pending_confirmation` der **einzige** verbleibende `used_triggers`-Konsument. Zwei Wege:

- **3A — used_triggers KOMPLETT eliminieren (empfohlen):** NL-Discard streichen; Discard läuft nur noch über den deterministischen WS/UI-Button (`ai_discard_pending`, `aiinstructions.js:2422`). Damit fällt der **gesamte** Kanal weg: Feld aus Prompt (`prompt_policy_builder:101-212`, `initial_system_prompt` Beispiele, `orchestrator:631`), `extract_used_triggers` (`interpreter:1043`) + alle ~13 `'used_triggers'=>…`-Producer (interpreter/decision/discovery_phase/planner_phase/agent_runtime), `normalize_used_triggers` (registry:136), `trigger_result_util.php` (ganze Klasse), `message_persistence:59`, Benchmark-Contract (`benchmark_result_collector:175/182`) + Szenario-Stubs, Tests (`integration_agent_framework_test:922/929/982/1002`). **Behebt zugleich Bug 2** (der halb-kaputte NL-Pfad verschwindet). *Kosten:* die NL-Affordanz „vergiss das und mach stattdessen X" in **einer** Nachricht entfällt — der User klickt „Verwerfen" oder sagt es in zwei Schritten.
- **3B — minimalen Kanal für Discard behalten:** `used_triggers` bleibt, aber nur mit `discard_pending_confirmation`. Dann sollte der NL-Pfad zusätzlich `discard_pending_service::discard()` aufrufen (Bug 2 fixen), statt nur `clear()`. *Kosten:* der ganze Kanal (Prompt-Zeilen, Normalisierung, Persistenz, Benchmark-Feld) bleibt für **einen** Trigger stehen.

**Empfehlung:** **3A.** Der einzige „echte" Nutzen ist ein NL-Convenience mit deterministischem UI-Zwilling und eigenem Bug — das rechtfertigt keinen permanenten Zweit-Kanal, den das LLM in jeder Antwort korrekt befüllen muss.

---

## 5. „Bricht nichts" — Persistenz & Benchmark im Detail
- **DB:** `used_triggers` ist **keine Spalte** — es liegt im `structuredjson`-Blob (`message_persistence:59`). Key entfernen → neue Nachrichten ohne, alte Blobs behalten ihn (Leser nutzen `?? []`). **Kein Schema-Change, kein upgrade.php.**
- **Benchmark:** `used_triggers` aus dem `$required`-Contract (`benchmark_result_collector:175`) nehmen (analog zum bereits entfernten `lang`); Szenario-Stubs dürfen das Feld behalten (wird ignoriert) oder werden mitgeräumt.
- **Reihenfolge:** Stufe 1 → PHPUnit + Benchmark grün → committen; dann Stufe 2 → verifizieren → committen; dann Stufe 3A/3B nach George-Entscheid. Jede Stufe ist eigenständig revertierbar.

---

## 6. Offene Entscheidung für George
1. **Stufe 3A (voll eliminieren) oder 3B (Mini-Kanal für Discard)?**
2. Bug 1 (Doku ↔ Code Lookup-Guard) und Bug 2 (asymmetrischer NL-Discard) — im selben Zug beheben (3A erledigt beide) oder separat?
3. Flowchart/Docs: ich ziehe `D_PREVIEW`/`D_LOOKUP_GUARD`/`MTRIG` + Kapitel 08/14/16 im selben Schritt mit (Flowchart-Policy) — ok?
