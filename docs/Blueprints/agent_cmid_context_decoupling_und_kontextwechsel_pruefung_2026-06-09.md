# Loslösung vom Booking-cmid-Kontext + Laufzeit-Kontextwechsel — Prüfung & vollständige Änderungsliste

**Datum:** 2026-06-09
**Status:** Reine Prüfung/Analyse. **Keine Code-Änderung.**
**Auftrag:** Prüfen, was nötig ist, um den Agent **vollständig vom Booking-cmid-Kontext zu lösen**,
sodass er (1) **in jedem Moodle-Kontext** läuft (Modul, Kurs, Kategorie, System), (2) **alles
weiter wie bisher** funktioniert (Booking-Modul unverändert) und (3) ein **Laufzeit-Kontextwechsel**
möglich ist — der Agent kann für eine Operation auf einen breiteren Kontext (Kurs/System) gehen,
um dort die nötigen Berechtigungen zu nutzen (z. B. Fragen in die Kurs-Fragebank schreiben).
**Mit Benennung aller betroffenen Dateien/Klassen/Methoden** (Stand verifiziert 2026-06-09).

> Baut auf [`wbagent_local_plugin_context_decoupling_analysis_2026-06-08.md`](wbagent_local_plugin_context_decoupling_analysis_2026-06-08.md)
> auf und **aktualisiert** dessen Inventar (neue Entry-Points, neue Zeilennummern) sowie ergänzt das
> dort fehlende Thema **Laufzeit-Kontextwechsel/Elevation**. Migration des Plugins selbst (Subplugin →
> `local_wbagent`): siehe [`wbagent_local_plugin_extraction_plan_2026-06-08.md`](wbagent_local_plugin_extraction_plan_2026-06-08.md).

---

## 0. Kernaussage (TL;DR)

- **Zwei getrennte Ziele.** (A) **Hosting-Agnostik**: der Agent darf in einem beliebigen Kontext
  *leben*. (B) **Laufzeit-Kontextwechsel**: ein einzelner Skill darf für seine Operation in einem
  *verwandten, breiteren* Kontext *ausgeführt* werden (z. B. Kurs des Moduls). Beide sind unabhängig,
  beide brauchen denselben Unterbau (generische Auth + Context-Resolver).
- **Gute Nachricht – der Engpass ist heute zentralisiert.** Fast alle Entry-Points laufen über
  **`authorization_service::require_valid_context()`**, das intern auf
  `require_booking_module_context()` zeigt. **Eine** generalisierte Auth-Methode hebelt damit den
  Großteil der Kopplung. Persistenz/Scope hängt bereits an `contextid`; Skill-Discovery ist bereits
  komponenten-agnostisch (Provider-Modell). Das **Interface** `agent_authorization_service` ist im
  Docblock bereits generisch formuliert — nur die Implementierung ist booking-spezifisch.
- **Schlechte Nachricht – die Booking-Annahme sitzt an vielen Rändern.** An 7 Stellen wird
  `get_coursemodule_from_id('booking', …)` direkt aufgerufen; `aiready`, `conversation_store`
  (+ `threads.bookingid NOT NULL`), der Prompt-Block (`booking_name`) und vier Framework-Services
  mit hartcodierten `mod_booking.*`-Skillnamen nehmen ein Booking-Modul an.
- **Kontextwechsel ist KEINE Rechte-Eskalation.** Der Agent „erhält" keine Rechte. Beim Wechsel wird
  der **Zielkontext aufgelöst** und die **Capability des handelnden Users am Zielkontext erneut
  geprüft** (`has_capability` am Zielkontext). Der Agent operiert nur innerhalb der ohnehin
  vorhandenen Rechte des Users — nur eben am breiteren Kontext.
- **Backward-Compatibility ist billig zu halten:** neue optionale Parameter mit Default = heutiges
  Verhalten (`bookingid` nullable, `allowedlevels` Default `[CONTEXT_MODULE]`, `cmid` optional). Das
  Booking-Modul bleibt unverändert funktionsfähig.

---

## 1. Begriffsmodell für den Kontextwechsel

| Begriff | Bedeutung |
|---|---|
| **Ambient-Kontext** | Der Kontext, in dem der Chat/Thread *lebt* (heute immer ein Booking-Modul-Context). Scope-Key der Persistenz (`contextid`). |
| **Operating-Kontext** | Der Kontext, gegen den eine *einzelne* Skill-Operation läuft. Heute identisch mit dem Ambient-Kontext. Künftig: ein vom Skill geforderter, **aufgelöster** verwandter Kontext (z. B. der Kurs des Moduls, oder System). |
| **Context-Resolver** | Neue Komponente, die aus dem Ambient-Kontext + dem vom Skill geforderten Level den Operating-Kontext bestimmt (Baum hoch: Modul → Kurs → Kategorie → System) und die **User-Capability dort** prüft. |
| **Required Context Level (Skill)** | Neue Skill-Vertragsangabe: welches Kontext-Level der Skill mindestens braucht (z. B. `core.generate_questions` → `CONTEXT_COURSE`). |

**Sicherheitsregel:** Operating-Kontext ⊇ Ambient-Kontext nur entlang der echten Moodle-Context-Hierarchie
des Ambient-Kontexts, und **immer** mit `has_capability($cap, $operatingcontext, $userid)`-Re-Check.
Niemals ein beliebiger fremder Kontext.

---

## 2. Verifizierter Ist-Zustand (2026-06-09)

### 2.1 Die gute Basis (bereits agnostisch)
- **Scope-Key = `contextid`** in allen Tabellen (`db/install.xml`): `local_wbagent_ai_threads.contextid`
  (Z. 11), `…_runs`/`…_messages`/`…_llm_debug` hängen an `threadid`/`contextid`. Index `useridcontextid`
  (Z. 22) → Thread-Lookup ist rein context-basiert.
- **Provider-Discovery** komponenten-agnostisch: `skill_registry::make_default()` scannt alle Plugins
  nach `\{component}\local\wbagent\skill_provider`. Drei Provider existieren (`mod_booking`,
  `bookingextension_agent`, **`local_entities`**).
- **Generisches Auth-Interface** vorhanden: `classes/local/wbagent/interfaces/agent_authorization_service.php`
  (Docblock bereits context-generisch; nur die Impl ist booking-spezifisch).
- **WS-/JS-Schicht führt `contextid`**, nicht `cmid` (alle AJAX-Calls in `amd/src/aiinstructions.js`
  übergeben `contextid`).

### 2.2 Der zentrale Engpass (positiv: zentralisiert)
`classes/local/wbagent/services/security/authorization_service.php`:
- `require_booking_module_context(int $contextid): context_module` **(private, Z. 65-75)** — wirft
  `moodle_exception('invalidcontext')` wenn nicht `context_module`; wirft `invalidcoursemodule` wenn
  `get_coursemodule_from_id('booking', $context->instanceid)` (Z. 70) leer ist.
- Aufgerufen von: `require_use_capability()` (Z. 84-92, prüft Cap `bookingextension/agent:useaiinstructions`),
  `can_use()` (Z. 101-112), `require_valid_context()` (Z. 120-122, reiner Delegat).
- **Fast alle Entry-Points + `executor` gehen über `require_valid_context()`/`require_use_capability()`/`can_use()`.**
  → Generalisiert man diese drei (bzw. die private Gate-Methode), fällt der Großteil der Sperre.

### 2.3 Direkte `get_coursemodule_from_id('booking', …)` — vollständige Liste (aktuell)
| Datei | Zeile | Methode |
|---|---|---|
| `classes/local/wbagent/services/security/authorization_service.php` | 70 | `require_booking_module_context()` |
| `classes/external/ai_send_message.php` | 159 | `execute()` (+ `get_or_create_thread(…, $cm->instance)` Z. 225) |
| `classes/external/ai_poll_thread.php` | 96 | `execute()` |
| `classes/external/ai_privacy_precheck.php` | 112 | `execute()` |
| `classes/external/activate_trial_context.php` | 106 | `execute()` |
| `classes/local/wbagent/orchestrator.php` | 2273 | `build_runtime_context_block()` |
| `classes/local/wbagent/aiready.php` | 113 | `export_for_template()` |

### 2.4 Capabilities (alle modul-/system-gebunden)
`db/access.php`:
- `bookingextension/agent:useaiinstructions` → **`CONTEXT_MODULE`** (Z. 29-35), archetype `editingteacher`.
- Auto-generierte **Per-Skill-Caps** `bookingextension/agent:skill_*` via `buildskillcapability()`
  (Z. 114-148) — **alle `CONTEXT_MODULE`** (Z. 118, 123, 130); 27 Teacher- + 10 Manager- + 1 Admin-Skill.
- System-Level-Caps: `:debugskillselection`, `:viewbenchmarks`, `:managebenchmarks` (CONTEXT_SYSTEM).

### 2.5 Runtime-Kern erzwingt Modul-Context
- `agent_runtime::resolve_cmid_from_contextid(int): int` (Z. 261-271) — `coding_exception('Invalid module context id.')`
  bei Nicht-`context_module`; gibt sonst `instanceid` (cmid). Aufgerufen in `run()`/`run_loop()` (Z. 154/169).
- `executor::execute_commands(array, int $contextid, …)` (Z. 95-107) — erzwingt `context_module`
  (Z. 97-99), `$cmid = $context->instanceid` (Z. 104), dann `require_use_capability()`/`require_valid_context()`.
- `orchestrator`: `get_runtime_provider_status()`/`process()` mit `context_module::instance($cmid)` (Z. 201/355);
  `build_runtime_context_block(int $cmid, …)` (Z. 2251-2281) baut `booking_name` aus
  `get_coursemodule_from_id('booking', $cmid)` (Z. 2273-2278) → `SYSTEM_RUNTIME`-Block. Kein `context_name`.

### 2.6 Persistenz koppelt an `bookingid`
- `conversation_store::get_or_create_thread(int $userid, int $contextid, int $bookingid)` (Z. 84-109,
  schreibt `bookingid` Z. 101) und `create_fresh_thread(… , int $bookingid)` (Z. 119-148, Z. 140).
  `get_active_thread(userid, contextid)` (Z. 64-74) ist bereits bookingid-frei.
- `db/install.xml:12` — `local_wbagent_ai_threads.bookingid` `TYPE="int" NOTNULL="true" DEFAULT="0"`.

### 2.7 Readiness ist booking-spezifisch
`classes/local/wbagent/aiready.php`:
- `use mod_booking\singleton_service;` (Z. 32); Constructor `__construct(int $cmid, int $userid, int $bookingid)`
  (Z. 70-73, speichert `$this->bookingid`).
- `export_for_template()` (Z. 113 `get_coursemodule_from_id('booking')`); `get_booking_statistics()`
  nutzt `singleton_service::get_instance_of_booking_by_bookingid()` (Z. 350) und
  `…get_instance_of_booking_option_settings()/…_answers()` (Z. 367-369).

### 2.8 Framework-Services mit hartcodierten `mod_booking.*`
| Datei | Zeile | Hardcodierung |
|---|---|---|
| `services/lookup/option_lookup_service.php` | 67, 95, 97 | `mod_booking.search_options`, `mod_booking.update_option` (+ Fehlertext) |
| `services/catalog/adaptive_skill_catalog_service.php` | 61-63 | `ALWAYS_INCLUDE_SKILL_NAMES`: `mod_booking.update_option_trainer`, `mod_booking.book_users` |
| `services/phase_prompt_bundle_builder.php` | 145, 308, 311 | Placeholder `{{bookingname}}`, Prompt-Beispiele `mod_booking.create_option` |
| `orchestrator.php` | 2010 | Kommentar/Trigger-Strip `…create_option_canonical_fallback` (Logik generisch) |
| `skill_contract_validator.php` | 39 | `RESERVED_NAMESPACES = ['booking', 'core']` (+ `component_may_register_namespace()` Z. 302-315) |

Bereits generisch (keine Änderung nötig): `services/discovery/context_prior_builder.php`,
`services/discovery/family_registry_service.php`.

### 2.9 UI/Navigation
- Einziger Einstieg: `mod/booking/view.php:203-211` rendert `bookingextension_agent/aiinstructions`
  via `new aiready($cmid, $USER->id, $cm->instance)`. `bookingextension/agent/lib.php` ist leer
  (kein `*_extend_navigation*`). Kein globaler/Nav-Bar-Einstieg.
- `templates/aiinstructions.mustache` setzt `data-contextid`/`data-threadid`/`data-sesskey` (Z. 29);
  `amd/src/aiinstructions.js::init()` liest `currentContextId` aus `data-contextid` (Z. ~2832/2850)
  und übergibt `contextid` an alle WS. → **UI ist bereits contextid-zentrisch.**
- `version.php`: `component = 'bookingextension_agent'`, `dependencies = ['mod_booking' => …]`.

> **Abweichungen ggü. 2026-06-08:** Inventar bestätigt; zusätzlich **neue Entry-Points** gefunden, die
> ebenfalls über das Booking-Gate laufen: `ai_get_doc_content`, `ai_upload_attachment`,
> `ai_get_thread_debug_logs`, `ai_discard_pending`, `request_trial_key`, sowie die Booking-Write-WS
> `booking_create_option`/`booking_validate_option`/`booking_update_option`/`booking_bulk_update_options`.
> Orchestrator-Zeile wanderte von 2174 → **2273**. `aiready` ist tiefer gekoppelt als dokumentiert
> (Constructor `bookingid`, `singleton_service`-Statistiken).
> **Nebenbefund (sicherheitsrelevant, unabhängig):** `ai_poll_thread`, `ai_get_thread_debug_logs`,
> `ai_get_doc_content`, `ai_upload_attachment` rufen **kein** `require_sesskey()` auf — beim Umbau
> mitnehmen.

---

## 3. Vollständige Änderungsliste (Datei → Klasse → Methode)

### 3.1 Auth-Schicht (Fundament beider Ziele)
- **`classes/local/wbagent/services/security/authorization_service.php`**
  - `require_booking_module_context()` (Z. 65-75) → **ersetzen/umbenennen** durch generisches
    `require_valid_context(int $contextid, array $allowedlevels = [CONTEXT_MODULE]): \context`.
    Default = heutiges Verhalten (nur Modul); Booking-`cm`-Pflicht entfällt.
  - `require_use_capability(int $userid, int $contextid, array $allowedlevels = [CONTEXT_MODULE])`
    (Z. 84-92) → `has_capability` am **übergebenen** Context (statt erzwungenem Modul-Context).
  - `can_use()` (Z. 101-112), `require_valid_context()` (Z. 120-122) → an die generische Gate-Methode anpassen.
  - **NEU:** `require_capability_at(int $userid, int $contextid, string $capability): \context` — für den
    Kontextwechsel (Re-Check am Zielkontext, s. §4).
- **`classes/local/wbagent/interfaces/agent_authorization_service.php`** — Signaturen der o. g. Methoden
  generisch fassen (Docblock ist bereits generisch); neue `require_capability_at()` deklarieren.
- **`db/access.php`** — `useaiinstructions` und die per-Skill-`skill_*`-Caps von `CONTEXT_MODULE` auf
  mehrere Levels heben (z. B. `CONTEXT_MODULE, CONTEXT_COURSE, CONTEXT_SYSTEM`). Bei Plugin-Migration:
  unter `local/wbagent:*` neu definieren. **Backward-compat:** Modul-Level bleibt enthalten.

### 3.2 Entry-Points (`classes/external/*`)
Gemeinsamer Umbau: `context::instance_by_id($contextid)` nutzen, **kein** erzwungenes
`context_module::instance()` mehr; `get_coursemodule_from_id('booking', …)` entfernen oder nur
`if ($context instanceof context_module && Modulname==='booking')` ausführen; `cmid` optional.

| Datei | Methode | Konkrete Änderung |
|---|---|---|
| `ai_send_message.php` | `execute()` | Z. 159 `get_coursemodule_from_id('booking')` entfernen; Z. 110 `$cmid` optional; Z. 225 `get_or_create_thread(userid, contextid)` ohne `bookingid`. Optionaler neuer Param `target_context_id` (Kontextwechsel). |
| `ai_poll_thread.php` | `execute()` | Z. 96 entfernen; `cmid` optional. + `require_sesskey()`. |
| `ai_privacy_precheck.php` | `execute()` | Z. 112 entfernen; Thread-Erstellung ohne `bookingid`. |
| `activate_trial_context.php` | `execute()` | Z. 106 entfernen/konditional; `course`-Update nur bei Modul-Context. `moodle/site:config` bleibt. |
| `ai_confirm_run.php`, `ai_discard_pending.php`, `ai_get_thread_debug_logs.php` | `execute()` | Nur generisches Gate (kein eigener cm-Lookup). Fehlendes `require_sesskey()` ergänzen (Debug-Logs). |
| `ai_get_doc_content.php` | `execute()` | Nur generisches Gate; `cmid` optional. + `require_sesskey()`. (Read-only, context-logisch unabhängig.) |
| `ai_upload_attachment.php` | `execute()` | Nur generisches Gate (nutzt schon `contextid` für Token). + `require_sesskey()`. |
| `db/services.php` | — | Capability-Einträge ggf. auf neue `local/wbagent:*` umstellen (bei Migration). |

### 3.3 Runtime-Kern
- **`classes/local/wbagent/agent_runtime.php`** — `resolve_cmid_from_contextid()` (Z. 261-271) →
  `?int` zurückgeben (null wenn kein Modul), **keine** Exception; `run()`/`run_loop()` (Z. 154/169)
  führen `cmid` nur noch optional weiter.
- **`classes/local/wbagent/orchestrator.php`**
  - `build_runtime_context_block()` (Z. 2251-2281): `get_coursemodule_from_id('booking')` (Z. 2273) →
    `context::instance_by_id($contextid)->get_context_name()`; `booking_name` → generisches
    `context_name` (Booking-Name nur als Spezialfall, wenn Modul = booking).
  - `get_runtime_provider_status()`/`process()` (Z. 201/355): `context_module::instance($cmid)`-Zwang
    lockern (core_ai-Status ist nicht modulgebunden).
  - Z. 2010 Kommentar/Trigger-Strip: rein kosmetisch.
- **`classes/local/wbagent/executor.php`** — `execute_commands()` (Z. 95-107): Modul-Zwang (Z. 97-99)
  ersetzen durch Context-Level-Whitelist; `$cmid` optional. **Hier andockt der Kontextwechsel** (§4):
  pro Command den Operating-Kontext auflösen + autorisieren.

### 3.4 Persistenz / Datenmodell
- **`classes/local/wbagent/conversation_store.php`** — `get_or_create_thread()` (Z. 84-109) und
  `create_fresh_thread()` (Z. 119-148): Signatur `…, ?int $bookingid = null`; `bookingid` nur setzen,
  wenn vorhanden.
- **`db/install.xml`** — `local_wbagent_ai_threads.bookingid` (Z. 12) auf `NOTNULL="false"`
  (oder entfernen/umdeuten als generische „domain scope id"). **⚠ DB-Policy** „new schema via
  install.xml only, no upgrade.php" (Flowchart LG_DB) kollidiert mit einer Spaltenänderung an
  Bestandsinstallationen → **mit Georg klären** (Default `0`/nullable als verträglichster Weg).

### 3.5 Readiness
- **`classes/local/wbagent/aiready.php`** — `use mod_booking\singleton_service` (Z. 32) entfernen;
  Constructor → `__construct(int $contextid, int $userid)`; `export_for_template()` (Z. 113) generisch;
  `get_booking_statistics()` (Z. 350, 367-369) als **optionalen, booking-seitigen Hook** auslagern
  (generischer Core-AI-/Provider-Readiness bleibt im Plugin). Aufrufer `mod/booking/view.php:203-211`
  anpassen (neue Signatur).

### 3.6 Framework-Skillname-Entkopplung
- **`services/lookup/option_lookup_service.php`** (Z. 67, 95, 97) — `mod_booking.*` → Family-/Intent-
  Lookup über Provider-Contract statt hartem Namen.
- **`services/catalog/adaptive_skill_catalog_service.php`** (Z. 61-63) — `ALWAYS_INCLUDE_SKILL_NAMES`
  → Family-basierte Inclusion (z. B. „mutations"-Familie), nicht namentlich.
- **`services/phase_prompt_bundle_builder.php`** (Z. 145, 308, 311) — `{{bookingname}}` →
  `{{contextname}}`; Prompt-Beispiele provider-agnostisch.
- **`skill_contract_validator.php`** (Z. 39, 302-315) — `RESERVED_NAMESPACES`/`component_may_register_namespace()`
  bei Migration auf `['core', 'local_wbagent']` aktualisieren.

### 3.7 UI / Navigation (für Hosting in beliebigem Kontext)
- **`bookingextension/agent/lib.php`** (heute leer) — Navigation-Hook(s) ergänzen
  (`*_extend_navigation`/`*_extend_settings_navigation`) für einen context-abhängigen, ggf. globalen
  Einstieg; Entkopplung von `mod/booking/view.php`.
- **`templates/aiinstructions.mustache`** + **`amd/src/aiinstructions.js`** — bereits contextid-zentrisch;
  für UI-seitigen Kontextwechsel: `setContextId(newContextId)` exponieren + `data-contextid` dynamisch
  aktualisierbar.
- **`settings.php`** — `aidocsroot`/`aidocsentry` sind global; bei Multi-Context ggf. pro-Context
  konfigurierbar (optional, kein Blocker).
- **`version.php`** — bei Plugin-Migration `component`/`dependencies` (mod_booking optional).

---

## 4. NEU: Der Laufzeit-Kontextwechsel (Operating-Context-Resolution)

**Ziel:** Ein Skill, der mehr Reichweite braucht als der Ambient-Kontext (z. B.
`core.generate_questions` → Kurs-Fragebank), läuft gegen einen **aufgelösten, breiteren** Kontext —
re-autorisiert, nicht eskaliert.

### 4.1 Neue Komponenten (zu erstellen)
- **`classes/local/wbagent/dto/context_requirement.php`** (DTO) — `{minlevel, prefer}` o. ä.
- **Skill-Vertrag erweitern:** Methode `get_required_context_level(): int` (Default `CONTEXT_MODULE`)
  in `interfaces/skill_interface.php` + `base_skill` (Default-Impl). Damit deklariert ein Skill seinen
  Bedarf. Fließt in den Embeddings-/Selection-Katalog ein (Discovery bleibt unverändert nutzbar).
- **`classes/local/wbagent/services/security/context_resolver.php`** (NEU) —
  `resolve(int $ambientcontextid, int $requiredlevel): \context` läuft den Context-Baum des
  Ambient-Kontexts hinauf (Modul → Kurs → Kategorie → System) bis zum geforderten Level;
  `authorize(int $userid, \context $operatingcontext, string $capability): void` ruft
  `authorization_service::require_capability_at()`.

### 4.2 Integrationspunkte (zu ändern)
- **`executor::execute_commands()`** — pro Command: Skill-`get_required_context_level()` lesen →
  `context_resolver::resolve()` → `authorize()` → Skill mit dem **Operating-`contextid`** ausführen
  (`execute($input, $operatingcontextid, $userid)`), statt pauschal mit dem Ambient-`contextid`.
- **`agent_decision_service` / Confirm-Flow** — wenn der Operating-Kontext ≠ Ambient-Kontext ist, im
  Confirm-Preview **transparent anzeigen** („Diese Aktion schreibt in Kurs ‚X' / systemweit"). Risk-Gate
  (R2/R3) greift ohnehin.
- **`orchestrator::build_runtime_context_block()`** — den Ambient-`context_name` zeigen; optional die
  *möglichen* Operating-Kontexte (Kurs/System) als Hinweis, damit der Planner weiß, dass ein Skill
  „nach oben" wirken kann.
- **`preflight()` der betroffenen Skills** — Capability am Operating-Kontext prüfen (nicht am Ambient).

### 4.3 Thread-Scoping beim Wechsel (Entscheidung nötig)
- Threads sind an `contextid` (Ambient) gescoped (Index `useridcontextid`). Ein **Wechsel des
  Ambient-Kontexts** ergibt natürlich einen **separaten Thread** — kein Sonderfall nötig.
- Ein **Operating-Context-Wechsel innerhalb einer Operation** soll den **Ambient-Thread NICHT**
  wechseln (Konversation bleibt zusammenhängend). Der Operating-Kontext ist nur Ausführungs-/
  Autorisierungs-Scope der einzelnen Skill-Operation. **Empfehlung:** Ambient-Thread beibehalten,
  Operating-Kontext nur an `executor`/Skill durchreichen; im Run/Debug-Log den Operating-`contextid`
  mitschreiben (`…_runs.contextid` kann das aufnehmen).

---

## 5. Backward-Compatibility (alles läuft weiter wie bisher)

- **Auth:** `require_valid_context($id, $allowedlevels = [CONTEXT_MODULE])` — Default = heutiges
  Verhalten. Booking-Modul-Context erfüllt es weiterhin.
- **cmid:** `resolve_cmid_from_contextid()` liefert für Booking-Module weiterhin den cmid; nur
  Nicht-Module liefern `null` statt Exception.
- **bookingid:** Spalte bleibt (nullable/Default 0); Booking-Flows setzen sie weiter, generische nicht.
- **Skillnamen:** Family-Lookup muss die heutigen `mod_booking.*`-Skills exakt gleich auflösen
  (Regressionstest gegen die bestehende Auswahl).
- **Prompt:** Für Booking-Module ist `context_name` faktisch der Booking-Name → identische Ausgabe.
- **UI:** `mod/booking/view.php`-Tab bleibt; der neue Nav-Einstieg ist additiv.
- **Required context level:** Default `CONTEXT_MODULE` → bestehende Skills unverändert; nur neue Skills
  (z. B. `core.generate_questions`) fordern explizit mehr.

---

## 6. Offene Fragen / Risiken

1. **Unterstützte Context-Levels?** (Modul/Kurs/Kategorie/System/User) — bestimmt Capability-Def,
   Resolver-Logik und Familien-Gating. (Empfehlung MVP: Modul + Kurs + System.)
2. **DB-Policy vs. `bookingid`-Änderung:** „install.xml only, kein upgrade.php" (Flowchart LG_DB) ↔
   Spaltenänderung an Bestandsdaten. Mit Georg klären (nullable als verträglichster Weg).
3. **Per-Skill-Capability-Fläche:** Die `skill_*`-Caps sind heute alle CONTEXT_MODULE. Auf mehrere
   Levels heben oder auf ein einziges `local/wbagent:execute_skill` + per-Skill-Risk konsolidieren?
4. **Operating-Context-Auswahl:** Wer entscheidet den Zielkontext — Skill-Required-Level + Resolver
   (automatisch nach oben) genügt meist; bei Mehrdeutigkeit (mehrere Kurse?) braucht es eine
   Disambiguierung. Für Modul→Kurs eindeutig.
5. **`sesskey`-Lücken** (poll/debug/doc/upload) beim Umbau schließen.
6. **Booking-`bookingextension_interface`-Hooks** (`classes/agent.php`) bleiben mod_booking-seitig —
   der generische Agent darf sie nicht kennen (Schnitt beim Plugin-Cut, s. Extraction-Plan).

---

## 7. Vorgeschlagene Phasen (risikoarm zuerst)

- **Phase 0 — Audit fixieren:** dieses Dokument; Regressions-Baseline der heutigen Skill-Auswahl.
- **Phase 1 — Auth + Runtime generalisieren (nicht-brechend):** generisches `require_valid_context`
  (Default Modul), `resolve_cmid_from_contextid` → optional, `build_runtime_context_block` →
  `context_name`, Entry-Points entkoppeln. Booking unverändert.
- **Phase 2 — Persistenz + Readiness:** `bookingid` optional (Store-Signaturen + install.xml),
  `aiready` entkoppeln (Booking-Stats als Hook).
- **Phase 3 — Framework-Skillnamen + Capability-Levels:** Family-Lookup statt `mod_booking.*`;
  Caps auf Multi-Level.
- **Phase 4 — Kontextwechsel:** `context_requirement`-DTO, Skill-`get_required_context_level()`,
  `context_resolver`, `executor`/`preflight`/Confirm-Integration. Erst-Konsument: `core.generate_questions`.
- **Phase 5 — UI/Nav + Plugin-Migration** (`local_wbagent`): additiver Nav-Einstieg; Rename gem.
  Extraction-Plan.

---

## 8. Referenzen (Datei → Methode → Zeile)

**Auth:** `services/security/authorization_service.php:65-75` (Gate), `:84-92`/`:101-112`/`:120-122`;
`interfaces/agent_authorization_service.php`; `db/access.php:29-35, 114-148`.
**Entry:** `classes/external/{ai_send_message:159,225 · ai_poll_thread:96 · ai_privacy_precheck:112 ·
activate_trial_context:106 · ai_confirm_run · ai_discard_pending · ai_get_thread_debug_logs ·
ai_get_doc_content · ai_upload_attachment}`; `db/services.php`.
**Runtime:** `agent_runtime.php:154,169,261-271`; `orchestrator.php:201,355,2010,2251-2281(2273)`;
`executor.php:95-107`.
**Persistenz:** `conversation_store.php:64-74,84-109,119-148`; `db/install.xml:11-12,22`.
**Readiness:** `aiready.php:32,70-73,113,350,367-369`; Aufrufer `mod/booking/view.php:203-211`.
**Framework-Hardcodings:** `services/lookup/option_lookup_service.php:67,95,97`;
`services/catalog/adaptive_skill_catalog_service.php:61-63`;
`services/phase_prompt_bundle_builder.php:145,308,311`; `skill_contract_validator.php:39,302-315`.
**Bereits generisch:** `services/discovery/context_prior_builder.php`, `…/family_registry_service.php`;
`skill_registry.php::make_default()`; `skill_discovery.php`.
**UI:** `lib.php` (leer), `templates/aiinstructions.mustache:29`, `amd/src/aiinstructions.js` (init/WS-Args),
`settings.php` (`aidocsroot`/`aidocsentry`), `version.php`.

**Verwandte Blueprints:** [`wbagent_local_plugin_context_decoupling_analysis_2026-06-08.md`](wbagent_local_plugin_context_decoupling_analysis_2026-06-08.md),
[`wbagent_local_plugin_extraction_plan_2026-06-08.md`](wbagent_local_plugin_extraction_plan_2026-06-08.md),
[`neue_skills_und_pdf_fragegenerierung_analyse_2026-06-09.md`](neue_skills_und_pdf_fragegenerierung_analyse_2026-06-09.md)
(Erst-Konsument des Kontextwechsels: Fragen → Kurs-Fragebank).
**Memory:** project-wbagent-local-plugin-extraction, project-agent-skill-discovery-visibility,
project-agent-guidance-injection, feedback-flowchart-policy.
