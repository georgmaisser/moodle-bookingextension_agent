# wizard → eigenständiges `local`-Plugin & Context-Unabhängigkeit — Vorbereitungsanalyse

**Datum:** 2026-06-08
**Status:** Reine Analyse (keine Code-Änderung)
**Ziel der Analyse:** Bestandsaufnahme, wie der wizard aktuell `contextid` vs. `cmid` behandelt, wo noch harte Abhängigkeiten von `mod_booking` bestehen, und wo die Context-Unabhängigkeit (z. B. Course-Ebene, Nav-Bar, beliebige Moodle-Kontexte) heute **nicht** gegeben ist.

---

## 0. Kernaussage (TL;DR)

- **Kann der Agent heute auf Course-Ebene laufen? → NEIN.** Er ist an *einer* Stelle hart auf einen **Booking-Modul-Context** gegated: `authorization_service::require_booking_module_context()` verlangt `context_module` **und** `get_coursemodule_from_id('booking', …)`. Jeder Nicht-Booking-Modul-Context (Course, System, andere Module) wird mit `invalidcontext`/`invalidcoursemodule` abgewiesen. Dazu ist die Capability auf `CONTEXT_MODULE` definiert.
- **Gute Nachricht:** Das **Persistenz-/Scope-Modell ist bereits context-basiert** (`contextid` ist der Schlüssel für Threads/Runs/Messages/Debug), die DB-Tabellen heißen schon `local_wizard_ai_*`, und die **Skill-Discovery ist bereits komponenten-agnostisch** (Provider-Modell, scannt *alle* Plugins). Es gibt sogar schon einen Nicht-Booking-Provider: **`local_entities`**.
- **Schlechte Nachricht:** Trotz context-basierter Persistenz wird `contextid` **an jedem Entry-Point sofort zu `cmid` → Booking-Modul aufgelöst** und der gesamte Prompt-/Readiness-Aufbau nimmt ein Booking-Modul an. Außerdem ist der Agent **strukturell ein Booking-Subplugin** (`bookingextension_agent extends bookingextension`), mit Hard-Dependency auf `mod_booking`, und mehrere Framework-Services hardcoden `mod_booking.*`-Skillnamen.

**Migrationskern:** Die *Architektur* (Provider, contextid-Scope, generische DB) ist bereits weitgehend agnostisch. Zu entkoppeln sind primär (a) der **Auth-Gate**, (b) die **`get_coursemodule_from_id('booking')`-Auflösung an den Entry-Points/Orchestrator**, (c) die **Plugin-Struktur** (Subplugin → `local`), (d) einige **hardcodierte Skillnamen** in Framework-Services, (e) die **`threads.bookingid`-Spalte** und (f) die **Capability-Ebene**.

---

## 1. Context vs. cmid — wie es aktuell implementiert ist

### 1.1 Scope-Key ist `contextid` (bereits agnostisch)
Persistenz hängt durchgängig an `contextid`, nicht an `cmid`:

| Tabelle (`db/install.xml`) | Scope-Spalten | Befund |
|---|---|---|
| `local_wizard_ai_threads` | `contextid` (Z. 11) **+ `bookingid` NOT NULL** (Z. 12) | context-basiert, aber booking-Spalte |
| `local_wizard_ai_messages` | `threadid` (Z. 38, FK) | agnostisch |
| `local_wizard_ai_runs` | `threadid`, `contextid` (Z. 50) | agnostisch |
| `local_wizard_ai_llm_debug` | `threadid`, `contextid` (Z. 73) | agnostisch |

- `conversation_store::get_active_thread(userid, contextid)` und `get_or_create_thread(userid, contextid, bookingid)` → Thread-Lookup per **`contextid`** (gut), aber Erstellung verlangt eine **`bookingid`** (Kopplung).
  - Datei: `classes/local/wizard/conversation_store.php:64, 84, 119`.

### 1.2 …aber `contextid` wird sofort zu `cmid`→Booking aufgelöst
An praktisch jedem Eingang wird der Context als Booking-Modul-Context interpretiert:

- `agent_runtime::resolve_cmid_from_contextid()` — wirft `coding_exception('Invalid module context id.')`, wenn der Context **kein** `context_module` ist; gibt sonst `$ctx->instanceid` (cmid) zurück.
  - `classes/local/wizard/agent_runtime.php:261-271`.
- `orchestrator::process()` / `run_*_phase()` arbeiten durchgehend mit `cmid` und `context_module::instance($cmid)`.
- `executor::execute_commands()` löst `context::instance_by_id($contextid)` und erzwingt `context_module` (`classes/local/wizard/executor.php:97-103`).

**Fazit:** Der Code nutzt `contextid` als Schlüssel, **nimmt aber überall an, dass dahinter ein Booking-Modul steht.** Das ist der eigentliche „Context-Unabhängigkeit nicht 100 %"-Punkt.

---

## 2. Booking-Kopplungen — vollständige Inventur (Files + Methoden + Zeilen)

### 2.1 Struktureller Plugin-Typ (größte Kopplung)
- **Der Agent ist ein Booking-Subplugin.**
  `classes/agent.php:29` → `class agent extends bookingextension implements bookingextension_interface`
  (`use mod_booking\plugininfo\bookingextension;`, `…\bookingextension_interface;` — Z. 19-20).
  Implementiert booking-spezifische Hooks: `contains_option_fields()` (Z. 44), `get_option_fields_info_array()` (Z. 53), `load_data_for_settings_singleton()` (Z. 82), `set_template_data_for_optionview()` (Z. 92), `add_options_to_col_actions()` (Z. 103), `get_allowedruleeventkeys()` (Z. 112), `get_booking_history_description()` (Z. 123).
- **Hard-Dependency** auf mod_booking: `version.php:29-32` → `component = 'bookingextension_agent'`, `dependencies = ['mod_booking' => 2026020300]`.
- **Plugin-Pfad** liegt unter `mod/booking/bookingextension/agent/` (Subplugin-Tree), Namespace `bookingextension_agent\*`.

### 2.2 Autorisierungs-Gate (harter Booking-Modul-Zwang) — **der zentrale Blocker**
- `authorization_service::require_booking_module_context(int $contextid): context_module`
  `classes/local/wizard/services/security/authorization_service.php:65-75`
  - verlangt `context instanceof context_module` (sonst `moodle_exception('invalidcontext')`),
  - verlangt `get_coursemodule_from_id('booking', $context->instanceid)` (sonst `invalidcoursemodule`).
- Genutzt von `require_use_capability()` (Z. 84-92), `can_use()` (Z. 101-112), `require_valid_context()` (Z. 120-122).
- **Konsequenz:** Course-/System-/Nicht-Booking-Modul-Contexts werden grundsätzlich abgelehnt.

### 2.3 Capability ist Modul-eben
- `db/access.php:29-34` → `'bookingextension/agent:useaiinstructions'` mit `contextlevel => CONTEXT_MODULE`, archetype `editingteacher`.
- Für context-übergreifende Nutzung müsste die Capability auf eine breitere Ebene (z. B. `CONTEXT_COURSE`/`CONTEXT_SYSTEM` bzw. mehrere Levels) und unter `local/wizard:*` neu definiert werden.

### 2.4 Entry-Points hardcoden das Booking-Modul
Alle rufen `get_coursemodule_from_id('booking', $cmid, …)`:

| Datei | Zeile |
|---|---|
| `classes/external/ai_send_message.php` | 160 (+ `$cmid = $context->instanceid` Z. 110, `get_or_create_thread(…, $cm->instance)` Z. 224) |
| `classes/external/ai_poll_thread.php` | 96 |
| `classes/external/ai_privacy_precheck.php` | 112 |
| `classes/external/activate_trial_context.php` | 106 |
| `classes/local/wizard/services/security/authorization_service.php` | 70 |
| `classes/local/wizard/aiready.php` | 113 |
| `classes/local/wizard/orchestrator.php` | 2174 (`build_runtime_context_block`) |

### 2.5 Orchestrator / Prompt-Aufbau nimmt ein Booking-Modul an
- `orchestrator::build_runtime_context_block()` → `$cm = get_coursemodule_from_id('booking', $cmid); $bookingname = format_string($cm->name)` und rendert `booking_name:` in den `SYSTEM_RUNTIME`-Block.
  `classes/local/wizard/orchestrator.php:2174, 2133, 2137`.
- `get_runtime_provider_status(int $cmid)` arbeitet mit `context_module::instance($cmid)` und `ai_manager::get_ai_fields_from_course_module()` — **core_ai**-Kopplung (nicht booking-spezifisch), aber **Modul-Context-Annahme**.

### 2.6 Readiness-Check ist booking-spezifisch
- `aiready` nutzt `mod_booking\singleton_service`:
  `classes/local/wizard/aiready.php:32` (use), `113` (`get_coursemodule_from_id('booking', …)`), `344` (`get_instance_of_booking_by_bookingid`), `361` (`get_instance_of_booking_option_settings`), `363` (`get_instance_of_booking_answers`).

### 2.7 Framework-Services mit hardcodierten `mod_booking.*`-Skillnamen
Diese liegen **im Framework** (nicht im Booking-Provider) und sind damit echte Kopplungen:

| Datei | Skillname(n) | Zeile |
|---|---|---|
| `classes/local/wizard/services/lookup/option_lookup_service.php` | `mod_booking.search_options`, `mod_booking.update_option` | 67, 95 |
| `classes/local/wizard/services/catalog/adaptive_skill_catalog_service.php` | `mod_booking.book_users`, `mod_booking.update_option_trainer` (ALWAYS_INCLUDE) | — |
| `classes/local/wizard/services/phase_prompt_bundle_builder.php` | `mod_booking.create_option` | — |
| `classes/local/wizard/orchestrator.php` | `mod_booking.create_option_canonical_fallback` | — |
| `classes/local/wizard/benchmark/**` (Test-/Bench-Fixtures) | diverse `mod_booking.*` | — |

`skill_contract_validator.php:39` reserviert zudem `RESERVED_NAMESPACES = ['booking', 'core']`.

### 2.8 DB-Kopplung
- `local_wizard_ai_threads.bookingid` ist `NOT NULL DEFAULT 0` (`db/install.xml:12`). Für ein booking-freies Plugin müsste die Spalte entfallen, nullable werden oder generisch (z. B. „domain scope id") umgedeutet werden.

---

## 2.9 Umgekehrte Kopplung: Skills → Engine (Dependency-Inversion nötig)

Nicht nur das Framework hängt an Booking — auch die **Skills hängen an der Engine**. Die Skill-Provider (`mod_booking/classes/local/wizard` = 29 Dateien/246 Treffer; `local_entities` = 5 Dateien) importieren `bookingextension_agent\…`. Aufschlüsselung:

- **Contract-Surface (legitim, gehört in ein SDK):** `interfaces\skill_interface`, `…\skill_trigger_provider_interface` (14×), `…\queue_identity_provider_interface` (5×), `…\skill_provider_interface`, `…\skill_input_normalizer_interface`, `…\skill_preview_*_interface`, `…\issue_code_provider_interface`, `dto\skill_risk_class` (8×), `services\preflight_result_v2` (18×, eigentlich DTO), `services\skill_prompt_contract` (DTO), `base_skill`.
- **Engine-Internal-Leaks (Fehlkopplung, muss invertiert werden):**
  - `privacy_anonymizer` → `update_option_skill`, `update_option_trainer_skill`, `bulk_update_options_skill`
  - `conversation_store` → `booking_skill_support`
  - `skill_discovery` → `skill_provider`, `booking_skill_support`
  - `skill_registry_factory` → `list_option_properties_skill`
  - `services\attachment\attachment_token_service` → `booking_skill_mutation_execute_service` *(aus dem Header-Bild-Feature)*

**Konsequenz für die Auskopplung (revidiert 2026-06-09):** Kein separates SDK-Plugin. Die Skills leiten von `bookingextension_agent` ab (= die zuerst ausgelieferte Engine), denn das ist der einfachste Weg, mit Booking direkt in Bestandsinstallationen zu kommen. Die Skills dürfen nur an die **Contract-Surface** der Engine (Interfaces + DTOs + `base_skill`) hängen, **nicht** an deren Internas — die Inversion der 5 Leaks bleibt also sinnvoll, der Rest von Block (I) ist legitime Ableitung. Detail-Plan + offene Weiche „beide installiert": siehe [Extraction-Plan §4 und §10.8](wizard_local_plugin_extraction_plan_2026-06-08.md).

---

## 3. Was bereits context-agnostisch / entkoppelt ist (die gute Basis)

- **Provider-Modell (komponenten-agnostisch):** `skill_registry::make_default()` iteriert `core_component::get_component_names()` und lädt aus *jeder* Komponente `\{component}\local\wizard\skill_provider`.
  `classes/local/wizard/skill_registry.php` (make_default). Beleg: es existieren **drei** Provider:
  - `mod/booking/classes/local/wizard/skill_provider.php`
  - `mod/booking/bookingextension/agent/classes/local/wizard/skill_provider.php`
  - **`local/entities/classes/local/wizard/skill_provider.php`** ← ein `local`-Plugin liefert bereits Skills.
- **Skill-Discovery:** `skill_discovery::get_skill_instances($component)` scannt `{plugindir}/classes/local/wizard` generisch (`classes/local/wizard/skill_discovery.php:44, 119-160`).
- **DB-Tabellen** heißen bereits `local_wizard_ai_*` (kein `bookingextension_*`-Rename nötig).
- **Persistenz/Scope** an `contextid` (Threads/Runs/Messages/Debug).
- **core_ai-Anbindung** (Provider-Status) nutzt `core_ai\manager`, nicht Booking.
- Flowchart-Prinzipien bestätigen die Zielrichtung: „Context authority: Runtime uses Moodle contextid as the scope key" und „Framework agnostic by contract" (siehe `docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd`, LG_CTX/LG_AGN).

---

## 4. Konkret: Was bricht bei Course-Level (oder beliebigem Context) heute?

1. **Auth:** `require_booking_module_context()` → `invalidcontext`/`invalidcoursemodule` (2.2). **Sofortiger Stopp.**
2. **Capability:** `useaiinstructions` ist nur auf `CONTEXT_MODULE` zuweisbar (2.3).
3. **Entry-Points:** `get_coursemodule_from_id('booking', …)` schlägt fehl, sobald kein Booking-cm dahinter steht (2.4).
4. **Thread-Erstellung:** `get_or_create_thread()` braucht eine `bookingid`; auf Course-Ebene existiert keine (2.1, 2.8).
5. **Prompt-Kontext:** `booking_name` aus `cm->name` nicht auflösbar (2.5).
6. **Readiness:** `aiready` fragt Booking-Instanz/Optionen ab (2.6).
7. **Skill-Set:** Ohne Booking-Modul ergeben die Booking-Skills fachlich keinen Sinn; es bräuchte context-passende Skill-Familien (Discovery liefert sie schon generisch, aber die o. g. hardcodierten `mod_booking.*`-Referenzen in Framework-Services laufen ins Leere) (2.7).

Es gibt aktuell **keinen** Nav-Bar-/globalen Einstiegspunkt; die UI wird ausschließlich aus `mod/booking/view.php` heraus eingebunden (Booking-Modul-Seite).

---

## 5. Migrations-Checkliste (Fakten, keine Umsetzung)

**A. Plugin-Identität**
- Neuer Typ `local_wizard` (Pfad `local/wizard/`), Component `local_wizard`, Namespace `local_wizard\*` (mechanischer Massen-Rename von `bookingextension_agent\*`).
- `version.php`: `dependencies` auf `mod_booking` entfernen; mod_booking wird zum *optionalen* Skill-Provider.
- `classes/agent.php`: die `bookingextension`-Interface-Implementierung (Option-Fields/Rules/History-Hooks) gehört **nicht** ins generische Plugin → in einen mod_booking-seitigen Adapter verschieben.

**B. Context-Generalisierung**
- `authorization_service`: `require_booking_module_context()` → generischer `require_valid_context()` (akzeptiert konfigurierte Contextlevels; keine `'booking'`-cm-Pflicht).
- Entry-Points (2.4): `get_coursemodule_from_id('booking', …)` entfernen; nur `context::instance_by_id($contextid)` nutzen.
- `agent_runtime::resolve_cmid_from_contextid` / `executor`: Modul-Context-Zwang lockern (cmid optional; context-typ-agnostisch).
- `orchestrator`: `booking_name` → generischer `context_name` (z. B. `$context->get_context_name()`); kein `get_coursemodule_from_id('booking')`.

**C. Capability**
- Neue Capability `local/wizard:useaiinstructions` mit passender/mehreren Contextlevels statt `CONTEXT_MODULE`.

**D. Datenmodell**
- `local_wizard_ai_threads.bookingid` entfernen/nullable/umdeuten; `get_or_create_thread()`-Signatur entkoppeln (ohne `bookingid`).

**E. Framework-Services entkoppeln (2.7)**
- `option_lookup_service`, `adaptive_skill_catalog_service` (ALWAYS_INCLUDE), `phase_prompt_bundle_builder`, `orchestrator`-Fallback: hardcodierte `mod_booking.*`-Skillnamen → über Provider-/Family-Contracts deklarieren statt im Framework zu nennen.

**F. UI / Navigation**
- Neuer globaler Einstieg (Nav-Bar / `*_extend_navigation`-Hook), context-abhängig sichtbar; Entkopplung von `mod/booking/view.php`.

**G. Readiness**
- `aiready` von `singleton_service`/Booking-Instanz lösen → generischer Provider-/core_ai-Readiness-Check.

---

## 6. Offene Fragen / Risiken

1. **Welche Contextlevels** soll der Agent unterstützen (Course, Module, Category, System, User)? Das bestimmt Capability-Definition, Auth-Logik und Skill-Familien-Gating.
2. **Booking-spezifische `bookingextension_interface`-Hooks** (Option-Fields, Rules, col_actions, History): Diese bleiben mod_booking-Funktionalität. Brauchen einen sauberen Schnitt — der generische Agent darf sie nicht kennen.
3. **`threads.bookingid`-Migration:** Bestehende Daten? (DB-Policy im Projekt: „new schema via install.xml only, no upgrade.php" — siehe Flowchart LG_DB. Das kollidiert mit einer Spalten-Migration und ist mit Georg zu klären.)
4. **Skill-Relevanz pro Context:** Die Discovery ist generisch, aber Familien-/Context-Prior-Mapping (`family_registry_service`, context_prior) muss für Nicht-Booking-Contexts sinnvoll greifen.
5. **`local_entities` als Vorbild:** dessen `skill_provider` zeigt das Zielmuster für context-agnostische Skills — als Referenz für den Schnitt nutzen.

---

## 7. Wichtigste Referenzen (Datei → Methode → Zeile)

- `classes/agent.php:29` — Subplugin-Struktur (`extends bookingextension`).
- `version.php:29-32` — Component + mod_booking-Dependency.
- `classes/local/wizard/services/security/authorization_service.php:65-122` — Booking-Modul-Gate (zentraler Blocker).
- `db/access.php:29-34` — Capability `CONTEXT_MODULE`.
- `classes/external/ai_send_message.php:108-110, 160, 224` — Context→cmid→booking + Thread-Erstellung.
- `classes/local/wizard/agent_runtime.php:261-271` — `resolve_cmid_from_contextid` (Modul-Zwang).
- `classes/local/wizard/orchestrator.php:2174` — `booking_name` via `get_coursemodule_from_id('booking')`.
- `classes/local/wizard/aiready.php:113, 344-363` — Booking-Readiness via `singleton_service`.
- `classes/local/wizard/conversation_store.php:64, 84, 119` — Thread-Keying (contextid + bookingid).
- `db/install.xml:7-25` — `local_wizard_ai_threads` inkl. `bookingid` (Z. 12).
- `classes/local/wizard/skill_registry.php` — `make_default()` (komponenten-agnostische Provider-Discovery).
- `classes/local/wizard/skill_discovery.php:44, 119-160` — generischer `local/wizard`-Scan.
- `classes/local/wizard/services/lookup/option_lookup_service.php:67, 95` — hardcodierte `mod_booking.*`-Skills im Framework.
- Provider-Beleg: `mod/booking/…/skill_provider.php`, `…/bookingextension/agent/…/skill_provider.php`, **`local/entities/…/skill_provider.php`**.
