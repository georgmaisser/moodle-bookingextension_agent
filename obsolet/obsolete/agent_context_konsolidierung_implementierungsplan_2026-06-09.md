# Kontext-Konsolidierung & Context-Agnostik — Implementierungsplan

**Datum:** 2026-06-09
**Status:** **Implementierungsplan. NOCH KEINE Umsetzung.** Vorbereitung gem. Auftrag.
**Voraussetzung (vom Auftraggeber bestätigt):** Plugin ist **noch nicht produktiv** → **kein
`upgrade.php`, keine Datenmigration**. `db/install.xml` ist frei änderbar (Schema kann sauber
umgebaut werden). Daraus folgt: wir machen die **echte Konsolidierung** sofort mit, statt
rückwärtskompatible Optional-Parameter zu stapeln.

> Grundlage: [`agent_cmid_context_decoupling_und_kontextwechsel_pruefung_2026-06-09.md`](agent_cmid_context_decoupling_und_kontextwechsel_pruefung_2026-06-09.md)
> (Ist-Analyse + vollständige Datei/Methoden-Inventur). Plugin-Auskopplung: separat in
> [`wizard_local_plugin_extraction_plan_2026-06-08.md`](wizard_local_plugin_extraction_plan_2026-06-08.md).

---

## 1. Ziel & Leitprinzipien

1. **Eine konsolidierte Kontext-Repräsentation** statt verstreuter `contextid`/`cmid`/`bookingid`-Ints
   und wiederholter `get_coursemodule_from_id('booking', …)`-Aufrufe. → Neues Value-Object
   **`agent_context`**, **einmal** am Entry-Point erzeugt und durch die gesamte Pipeline gereicht.
2. **Context-agnostisch:** Agent läuft in Modul-, Kurs- und System-Kontext.
3. **Laufzeit-Kontextwechsel:** ein einzelner Skill darf gegen einen aufgelösten, breiteren
   *Operating-Kontext* laufen (re-autorisiert, **keine** Eskalation).
4. **Booking verhält sich identisch.** Keine Verhaltensänderung an bestehenden Booking-Flows.
5. **Kein DB-Upgrade.** `threads.bookingid` wird **sauber entfernt** (nicht nur nullable).
6. **Flowchart bleibt Source of Truth.** Die interne `cmid`-Durchreichung widerspricht heute dem
   Flowchart (`process(threadid, contextid, …)`); die Konsolidierung **richtet den Code am Flowchart
   aus**. Diskrepanz ist hier dokumentiert (Flowchart-Policy: nicht still angleichen → hiermit
   explizit benannt und als Implementierungsziel beschlossen).

---

## 2. Gelockte Kernentscheidungen (für diese Umsetzung)

| # | Entscheidung |
|---|---|
| E1 | Neues DTO `agent_context` wird die **einzige** kontexttragende Struktur durch Runtime/Orchestrator/Executor/Decision. Rohes `int $cmid` verschwindet aus den internen Signaturen. |
| E2 | `cmid`/`courseid`/`modname` werden **optionale, lazy aufgelöste Attribute** von `agent_context` (null außerhalb von Modul-/Kurs-Kontext). |
| E3 | `authorization_service` wird generisch (`require_valid_context(contextid, allowedlevels)`); Booking-Modul-Zwang entfällt. |
| E4 | `threads.bookingid` wird **entfernt** (install.xml). `conversation_store`-Signaturen verlieren den Parameter. |
| E5 | `booking_name` im Prompt → generisches `context_name` (`agent_context::display_name()`). |
| E6 | **Zwei-Gate-Autorisierung (verbindlich).** Die per-Skill-Agent-Caps `…:skill_*` **bleiben erhalten** und werden auf Multi-Level gehoben (Modul + Kurs + System) → Admins behalten die granulare Kontrolle „dieser User darf Skill X in Kursbereich A, aber nicht in B" + Skill-Enable-Toggles. **Zusätzlich** prüft **jeder** mutierende Skill in `preflight()` die **native Moodle-Capability** der zugrunde liegenden Aktion am **Target-/Operating-Kontext**. **Beide Gates müssen passen.** Keine Konsolidierung auf eine Sammel-Cap (Option B verworfen — würde Admin-Granularität nehmen). |
| E7 | Laufzeit-Kontextwechsel via neuem `context_resolver` + Skill-`get_required_context_level()`; Default `CONTEXT_MODULE` (bestehende Skills unverändert). |
| E8 | Erster Konsument des Kontextwechsels: `core.generate_questions` (PDF→Kurs-Fragebank). |
| E9 | **Anti-Bypass-Grundsatz:** Der Agent darf **nie** ein Recht verschaffen, das der User ohne Agent nicht hätte. Das wird über E6-Gate-2 (native Cap im Target-Kontext) garantiert, **unabhängig** von der Agent-Skill-Cap. |

---

## 3. Zielarchitektur

### 3.1 `agent_context` — das zentrale Value-Object (NEU)
Datei: `classes/local/wizard/dto/agent_context.php`

```
final class agent_context {
    private \context $context;
    private int $contextid;
    private int $contextlevel;
    private ?int $cmid = null;       // lazy; nur bei context_module
    private ?int $courseid = null;   // lazy
    private ?string $modname = null; // lazy; z.B. 'booking' | null

    public static function from_contextid(int $contextid): self;   // context::instance_by_id, MUST_EXIST
    public static function from_context(\context $context): self;

    public function id(): int;
    public function level(): int;
    public function moodle_context(): \context;
    public function display_name(): string;     // $context->get_context_name() → ersetzt booking_name
    public function is_module(?string $modname = null): bool;
    public function cmid(): ?int;               // null wenn kein Modul
    public function courseid(): ?int;           // aus context->get_course_context() ableitbar
    public function modname(): ?string;
    public function with_context(\context $c): self;  // für Operating-Kontext (Kontextwechsel)
}
```

**Begründung der Konsolidierung:** Heute wird `get_coursemodule_from_id('booking', …)` an ≥7 Stellen
wiederholt aufgerufen; `cmid` wird am Runtime-Boundary aufgelöst und als nackter int durch 5
Methodenebenen gereicht. `agent_context` löst **einmal** auf, cached lazy, und macht `cmid` zu einem
*optionalen Detail* statt zur tragenden Achse.

### 3.2 Generalisierte Autorisierung
`classes/local/wizard/services/security/authorization_service.php` +
`interfaces/agent_authorization_service.php`:
- `require_valid_context(int $contextid, array $allowedlevels = [CONTEXT_MODULE, CONTEXT_COURSE, CONTEXT_SYSTEM]): agent_context`
  (gibt direkt das Value-Object zurück → Entry-Points bekommen den Kontext fertig validiert).
- `require_use_capability(int $userid, agent_context $ctx): void` (Cap am echten Kontext).
- `can_use(int $userid, agent_context $ctx): bool`.
- **NEU** `require_capability_at(int $userid, \context $operatingcontext, string $capability): void`
  (Kontextwechsel-Re-Check).
- `require_booking_module_context()` **entfällt**.

### 3.3 Kontextwechsel (Operating-Kontext)
- **NEU** `classes/local/wizard/services/security/context_resolver.php`
  - `resolve(agent_context $ambient, int $requiredlevel): agent_context` — läuft die echte
    Context-Hierarchie des Ambient-Kontexts hinauf (Modul → Kurs → Kategorie → System) bis zum
    geforderten Level; liefert ein `agent_context` mit dem Operating-`\context`.
- **Skill-Vertrag** (`interfaces/skill_interface.php` + `base_skill`): neue Methode
  `get_required_context_level(): int` (Default `CONTEXT_MODULE`).
- **Executor** ruft pro Command: `context_resolver::resolve()` →
  `authorization_service::require_capability_at()` → Skill-`execute()` mit dem **Operating**-Kontext.

### 3.4 Persistenz ohne `bookingid`
- `db/install.xml`: Feld `bookingid` aus `local_wizard_ai_threads` **entfernen** (Z. 12).
- `conversation_store::get_or_create_thread(int $userid, int $contextid)` /
  `create_fresh_thread(int $userid, int $contextid)` — `bookingid`-Parameter entfernen; keine
  `$record->bookingid`-Zuweisung mehr.

### 3.5 Readiness generisch
- `aiready` entkoppeln (s. §4.7); Booking-spezifische Statistik als optionaler mod_booking-Hook.

### 3.6 Zwei-Gate-Autorisierung (verbindlicher Skill-Contract)
Jeder Skill-Aufruf passiert **zwei voneinander unabhängige** Tore — **beide** müssen zustimmen:

- **Gate 1 — Agent-Exposure (admin-konfigurierbar).** Die per-Skill-Agent-Cap `…:skill_<name>`
  (multi-level) + der Skill-Enable-Toggle. Beantwortet: *„Darf dieser User diesen Skill in diesem
  Kontext überhaupt anbieten/auslösen?"* Das ist die **Stellschraube für Admins** — sie können einen
  Skill in Kursbereich A erlauben und in B verbieten, ohne dass wir die Entscheidung für sie treffen.
  Geprüft in `skill_executability_evaluator::has_required_capabilities()` (heute schon) — künftig am
  **Operating-Kontext** (s. §6).
- **Gate 2 — Native Moodle-Autorisierung (nicht umgehbar, im Skill).** Jeder **mutierende** Skill prüft
  in `preflight()` die **native Moodle-Capability der zugrunde liegenden Kernaktion** am
  **Target-/Operating-Kontext** (z. B. `mod/booking:updatebooking` für Option-Update,
  `moodle/question:add` für Fragenerzeugung). Beantwortet: *„Hätte der User dieses Recht auch ohne
  Agent?"* **Der Agent darf nie ein Recht verschaffen, das der User nativ nicht hat** (E9).

> **Befund (Audit nötig):** Native Checks existieren heute **uneinheitlich** —
> `configure_booking_instance_skill.php:295` (`mod/booking:updatebooking`),
> `add_price_category_skill.php:173,244` (`moodle/site:config`),
> `diagnose_*_skill` (`mod/booking:bookforothers`) prüfen nativ; andere mutierende Skills
> (z. B. `update_option_skill`) **nicht sichtbar** → genau die Bypass-Lücke. Gate 2 wird damit zur
> **verbindlichen Vertragspflicht** für alle mutierenden Skills (s. §4.8), inkl. Audit/Nachrüsten.

---

## 4. Konkrete Umbauten je Datei (Vorher → Nachher)

> Reihenfolge = empfohlene Implementierungsreihenfolge (Bottom-up: erst DTO/Auth, dann Runtime,
> dann Entry-Points, dann Persistenz/Readiness/Framework, zuletzt Kontextwechsel).

### 4.1 NEU: DTO + Resolver
- `dto/agent_context.php` (NEU, §3.1).
- `dto/context_requirement.php` (NEU, optional — kapselt `{minlevel}`; alternativ reicht der int).
- `services/security/context_resolver.php` (NEU, §3.3).

### 4.2 Autorisierung
- `services/security/authorization_service.php` (Z. 65-122): `require_booking_module_context()` löschen;
  `require_valid_context`/`require_use_capability`/`can_use` auf `agent_context` + `allowedlevels`
  umstellen; `require_capability_at()` ergänzen.
- `interfaces/agent_authorization_service.php`: Signaturen angleichen (+ `require_capability_at`).
- `db/access.php`: `useaiinstructions` (Z. 29-35) und die per-Skill-`buildskillcapability()` (Z. 114-148)
  von `CONTEXT_MODULE` auf `[CONTEXT_MODULE, CONTEXT_COURSE, CONTEXT_SYSTEM]` heben.

### 4.3 Runtime-Kern (das Herz der Konsolidierung)
- `agent_runtime.php`
  - `run(int $threadid, int $contextid, int $userid)` (Z. 153) und
    `run_loop(… int $contextid …)` (Z. 168): statt `resolve_cmid_from_contextid()` →
    **`$ctx = agent_context::from_contextid($contextid)`** einmal bauen.
  - `resolve_cmid_from_contextid()` (Z. 261-271): **entfernen** (durch `agent_context::cmid()` ersetzt).
  - `run_internal(int $threadid, agent_context $ctx, int $userid, array $observations, ?agent_state)`
    (Z. 850): Parameter `int $cmid` → `agent_context $ctx`.
  - `call_orchestrator_step(...)` (Z. 859): `cmid` → `$ctx`.
  - `decisionsvc->process(result, threadid, $ctx, userid, …)` (Z. 876-883): `cmid` → `$ctx`.
- `orchestrator.php`
  - `process(int $threadid, agent_context $ctx, int $userid, …)` (Z. 348-354): `int $cmid` → `agent_context $ctx`.
    Z. 355 `context_module::instance($cmid)` → `$ctx->moodle_context()` (kein Modul-Zwang).
  - `run_discovery_phase`/`run_selection_phase`/`run_construction_phase` (Z. 555/965/1191): `cmid` → `$ctx`.
  - `build_runtime_context_block(agent_context $ctx, …)` (Z. 2251-2281): Z. 2273
    `get_coursemodule_from_id('booking')` **entfernen**; `booking_name` → `$ctx->display_name()`
    (Booking-Name ergibt sich automatisch, wenn `$ctx->is_module('booking')`).
  - `get_runtime_provider_status(agent_context $ctx)` (Z. 181): `context_module::instance($cmid)` →
    `$ctx->moodle_context()` (core_ai-Status ist nicht modulgebunden).
  - Z. 2010 Kommentar/Trigger-Strip: rein kosmetisch anpassen.
- `agent_decision_service.php`: `process(... int $cmid ...)` → `process(... agent_context $ctx ...)`;
  alle internen Weiterreichungen an Executor/Preflight auf `$ctx` umstellen.
- `executor.php`: `execute_commands(array $commands, agent_context $ctx, int $userid, …)` (Z. 95):
  Modul-Zwang (Z. 97-99) entfernen; `$cmid`-Ableitung (Z. 104) → `$ctx`. **Kontextwechsel-Andockpunkt**
  (§6).
- `skill_executability_evaluator.php`: Aufrufe an `authorization_service` auf `agent_context`-Signatur
  anpassen.

### 4.4 Entry-Points (`classes/external/*`)
Einheitliches Muster: `$ctx = $authz->require_valid_context($contextid);` (liefert validiertes
`agent_context`); danach `$authz->require_use_capability($USER->id, $ctx);`. **Alle**
`get_coursemodule_from_id('booking', …)` und `context_module::instance()`-Zwänge entfernen.

| Datei | Aktuell zu entfernen/ändern |
|---|---|
| `ai_send_message.php` | Z. 159 `get_coursemodule_from_id('booking')`; Z. 225 `get_or_create_thread(…, $cm->instance)` → ohne bookingid; `run($threadid, $ctx->id(), …)`. Optionaler neuer Param `target_context_id`. |
| `ai_poll_thread.php` | Z. 96 entfernen; + `require_sesskey()`. |
| `ai_privacy_precheck.php` | Z. 112 entfernen; Thread-Erstellung ohne bookingid. |
| `activate_trial_context.php` | Z. 106 entfernen/konditional (course-Update nur bei Modul); `moodle/site:config` bleibt. |
| `ai_confirm_run.php`, `ai_discard_pending.php`, `ai_get_thread_debug_logs.php` | nur generisches Gate; `require_sesskey()` bei Debug-Logs ergänzen. |
| `ai_get_doc_content.php` | nur generisches Gate; + `require_sesskey()`. |
| `ai_upload_attachment.php` | nur generisches Gate; + `require_sesskey()` (Write!). |
| `db/services.php` | Capability-Einträge ggf. (bei Plugin-Migration) auf `local/wizard:*`. |

### 4.5 Persistenz / Schema
- `db/install.xml`: `local_wizard_ai_threads.bookingid` (Z. 12) **entfernen**. (Kein upgrade.php nötig.)
- `conversation_store.php`: `get_or_create_thread()` (Z. 84-109), `create_fresh_thread()` (Z. 119-148):
  `bookingid`-Parameter + Zuweisungen (Z. 101, 140) entfernen.

### 4.6 Readiness
- `aiready.php`: `use mod_booking\singleton_service` (Z. 32) entfernen; Constructor →
  `__construct(agent_context $ctx, int $userid)` (statt `cmid`+`bookingid`); `export_for_template()`
  (Z. 113) generisch; `get_booking_statistics()` (Z. 350, 367-369) → optionaler mod_booking-Hook
  (booking-seitig).
- Aufrufer `mod/booking/view.php:203-211`: neue Signatur
  (`new aiready(agent_context::from_context($context), $USER->id)`).

### 4.7 Framework-Skillname-Entkopplung
- `services/lookup/option_lookup_service.php` (Z. 67, 95, 97): `mod_booking.*` → Family-/Intent-Lookup
  über Provider-Contract.
- `services/catalog/adaptive_skill_catalog_service.php` (Z. 61-63): `ALWAYS_INCLUDE_SKILL_NAMES` →
  Family-basierte Inclusion.
- `services/phase_prompt_bundle_builder.php` (Z. 145, 308, 311): `{{bookingname}}` → `{{contextname}}`;
  Prompt-Beispiele provider-agnostisch.
- `skill_contract_validator.php` (Z. 39, 302-315): `RESERVED_NAMESPACES` bei Migration aktualisieren.

### 4.8 Skill-Contract: native Capability-Pflicht im preflight (Gate 2)
- `interfaces/skill_interface.php` + `base_skill`: die Pflicht zu Gate 2 vertraglich verankern. Zwei
  Optionen (in P-Entscheidung festzulegen):
  - **(empfohlen, deklarativ + erzwingbar)** Neue Methode `get_required_native_capabilities(): array`
    (z. B. `['mod/booking:updatebooking']`). `base_skill` bietet einen Helper
    `require_native_capabilities(\context $operatingcontext, int $userid): void`, den `preflight()`
    aufruft. `skill_contract_validator` kann dann **erzwingen**, dass jeder **nicht-readonly** Skill
    mindestens eine native Cap deklariert (CI-Gate, analog `aigovernancestrictmode`).
  - **(minimal)** reine Konvention „jeder mutierende Skill prüft im preflight selbst" — fragil, keine
    Erzwingung. Nicht empfohlen.
- **Audit + Nachrüsten** (alle mutierenden Skills, Provider `mod_booking` + künftige): native Cap
  ergänzen, wo sie fehlt. Bekannt vorhanden: `configure_booking_instance_skill.php:295`,
  `add_price_category_skill.php:173,244`, `diagnose_*`. Bekannt zu prüfen/ergänzen: `update_option_skill`,
  `bulk_update_options_skill`, `update_rule_from_template_skill`, `create_selflearning_option_skill`,
  `update_option_trainer`, `book_users`, `create_user` u. a.
- **Operating-Kontext:** die native Prüfung erfolgt am vom `context_resolver` aufgelösten Target-Kontext
  (§6), nicht am Ambient-Kontext.

---

## 5. Caller-Update-Matrix (weil Signaturen sich ändern)

| Geänderte Signatur | Aufrufer, die mitziehen müssen |
|---|---|
| `agent_runtime::run/run_loop(contextid)` baut jetzt `agent_context` | interne; Entry-Points bleiben bei `contextid`-Übergabe → `$ctx->id()` |
| `run_internal(agent_context)` | `agent_runtime::run` Z. 155, `run_loop` Z. 175 |
| `orchestrator::process(agent_context)` | `agent_runtime::call_orchestrator_step` Z. 961 |
| `decisionsvc->process(agent_context)` | `agent_runtime::run_internal` Z. 876 |
| `build_runtime_context_block(agent_context)` | orchestrator Z. 456, 860, 1010, 1250 |
| `run_*_phase(agent_context)` | orchestrator `process()` Z. 358 ff. |
| `executor::execute_commands(agent_context)` | `agent_decision_service` (Command-Routing) |
| `authorization_service::require_*(agent_context)` | alle `classes/external/*` (12+), `executor.php`, `skill_executability_evaluator.php`, `aiready.php` |
| `conversation_store::get_or_create_thread/create_fresh_thread` (kein bookingid) | `ai_send_message.php:225`, `ai_privacy_precheck.php`, alle Thread-Ersteller |
| `aiready::__construct(agent_context, userid)` | `mod/booking/view.php:203-211` |

---

## 6. Laufzeit-Kontextwechsel — Detaildesign

**Ablauf je Skill-Command im `executor`:**
1. `$required = $skill->get_required_context_level();` (Default `CONTEXT_MODULE`).
2. `$opctx = $required === $ctx->level() ? $ctx : $contextresolver->resolve($ctx, $required);`
3. **Gate 1** (Agent-Exposure): `skill_executability_evaluator`/`require_capability_at($userid,
   $opctx->moodle_context(), $skillagentcap)` — die per-Skill-Agent-Cap am Operating-Kontext.
4. **Gate 2** (native, im Skill-`preflight()`): `require_native_capabilities($opctx->moodle_context(),
   $userid)` — die native Moodle-Cap der Kernaktion am Operating-Kontext (E6/E9; keine Eskalation).
5. `$skill->execute($input, $opctx->id(), $userid);` — Skill läuft gegen den Operating-Kontext.
6. **Confirm/Preview** (`agent_decision_service`): wenn `$opctx->id() !== $ctx->id()`, im Preview
   transparent anzeigen („schreibt in Kurs ‚X' / systemweit"). Risk-Gate (R2/R3) greift zusätzlich.

**Thread-Scoping:** Der **Ambient-Thread bleibt** (Konversation zusammenhängend). Der Operating-Kontext
ist nur Ausführungs-/Autorisierungs-Scope der Operation; im Run-/Debug-Log wird der Operating-`contextid`
mitgeschrieben (`local_wizard_ai_runs.contextid` kann das aufnehmen — prüfen, ob separates Feld nötig).

**Skill-Vertrag/Discovery:** `get_required_context_level()` fließt optional in die Selection-Metadaten
(damit der Planner weiß, dass ein Skill „nach oben" wirkt). Discovery/Embeddings-Pipeline bleibt sonst
unverändert; nach Hinzufügen neuer Skills wie gehabt **Embeddings-Rebuild**.

**Erst-Konsument:** `core.generate_questions` deklariert `CONTEXT_COURSE`, schreibt via Moodle-Import-API
in die Kurs-Fragebank (siehe [`neue_skills_und_pdf_fragegenerierung_analyse_2026-06-09.md`](neue_skills_und_pdf_fragegenerierung_analyse_2026-06-09.md)).

---

## 7. Teststrategie

- **Regression (Booking unverändert):** bestehende Agent-Contract-/Option-Field-Tests müssen grün
  bleiben; Skill-Auswahl für die heutigen `mod_booking.*`-Skills identisch (Snapshot vor/nach dem
  Family-Lookup-Umbau).
- **Unit:** `agent_context` (Factory aus Modul-/Kurs-/System-Context; `cmid()` null außerhalb Modul;
  `display_name()`); `context_resolver` (Baum-Aufstieg, korrektes Ziel-Level); `authorization_service`
  (akzeptiert konfigurierte Levels, lehnt fremde ab).
- **Integration:** Entry-Point mit Kurs-Context (kein Booking-cm) → Thread wird erstellt, kein
  `invalidcoursemodule`. System-Context analog.
- **Kontextwechsel:** Skill mit `CONTEXT_COURSE` aus Modul-Ambient → Operating-Kontext = Kurs;
  User **ohne** Kurs-Capability → `require_capability_at` wirft; User **mit** → Ausführung + Preview
  zeigt Zielkontext.
- **Schema:** frische Installation (install.xml ohne `bookingid`) → Threads/Runs funktionieren.

---

## 8. Folgeänderungen an Doku & Flowchart (nach der Umsetzung)

- **Flowchart** (`Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd`): `CS1`
  `get_or_create_thread(userid, contextid, bookingid)` → ohne `bookingid`; `ORC`-Knoten bestätigt
  `process(threadid, contextid, …)` (Code zieht nach → Diskrepanz aufgelöst); `build_runtime_context_block`
  → `context_name`; AUTHZ-Knoten generisch. Neuer Knoten `context_resolver` im Executor-Zweig.
- **architecture/02-authorization-and-context.md**, **…/01-entry-and-web-services.md**,
  **…/11-executor.md**, **…/14-skill-layer.md**, **operations/configuration.md** (Context-Levels),
  **developer-guides/writing-a-skill.md** (`get_required_context_level` **+ verpflichtende native-Cap-Prüfung
im preflight / `get_required_native_capabilities()`**), **architecture/15-risk-classes.md** (Verhältnis
Risk-Class ↔ Zwei-Gate-Autorisierung), **README.md** (Topic-Note).
- Diese Liste ist Teil der Umsetzung, **nicht** dieses Plans (hier nur vorgemerkt).

---

## 9. Phasen & Akzeptanzkriterien

| Phase | Inhalt | Akzeptanzkriterium |
|---|---|---|
| **P1 — Fundament** | `agent_context` DTO + generalisierte `authorization_service`/Interface + `db/access.php` Multi-Level | Unit-Tests grün; Booking-Entry-Points über neue Auth unverändert nutzbar |
| **P2 — Runtime-Konsolidierung** | `cmid`-Threading in `agent_runtime`/`orchestrator`/`decision`/`executor` → `agent_context`; `build_runtime_context_block` → `context_name` | Booking-Regression grün; `resolve_cmid_from_contextid` entfernt; kein internes `int $cmid` mehr |
| **P3 — Entry-Points + Persistenz** | alle `classes/external/*` entkoppeln; `bookingid` aus install.xml + `conversation_store`; `sesskey`-Lücken schließen | Kurs-/System-Context-Integrationstest grün; frische Installation ohne `bookingid` |
| **P4 — Readiness + Framework-Skillnamen** | `aiready` entkoppeln (+ Booking-Hook); `option_lookup`/`adaptive_catalog`/`phase_prompt` Family-Lookup | Skill-Auswahl-Snapshot identisch; Readiness rendert im Kurs-Context |
| **P4b — Gate 2: native Cap-Pflicht** | Skill-Contract `get_required_native_capabilities()` + `base_skill`-Helper + `skill_contract_validator`-Enforcement; **Audit + Nachrüsten** aller mutierenden Skills (§4.8) | jeder nicht-readonly Skill deklariert ≥1 native Cap (CI-Gate); Bypass-Test: User ohne native Cap wird trotz Agent-Cap abgewiesen |
| **P5 — Kontextwechsel** | `context_resolver`, Skill-`get_required_context_level()`, Executor-/Confirm-Integration (Gate 1 + Gate 2 am Operating-Kontext) | Kontextwechsel-Tests grün; Preview zeigt Zielkontext |
| **P6 — Doku/Flowchart** | §8 abarbeiten | Flowchart + architecture-Docs konsistent |

---

## 10. Offene Detailfragen (vor/while Umsetzung zu klären)

1. **DI-Verdrahtung:** `agent_context` per `di`-Container injizieren oder als reines Argument durchreichen?
   (Empfehlung: reines Argument — explizit, testbar; `di` nur für Services wie `context_resolver`.)
2. **Operating-Kontext-Mehrdeutigkeit:** Modul→Kurs ist eindeutig. Falls ein Skill „Kategorie" mit
   mehreren Kursen braucht → Disambiguierung nötig (vorerst out of scope; nur eindeutige Aufstiege).
3. **`runs.contextid` für Operating-Kontext:** reicht das bestehende Feld, oder braucht es ein separates
   `operating_contextid`? (Für Audit/Debug; klären, sobald `generate_questions` gebaut wird.)
4. **Capability-Granularität: ENTSCHIEDEN (2026-06-09).** Zwei-Gate-Modell (E6/E9): per-Skill-Agent-Caps
   **bleiben** (multi-level, Admin-Granularität) **+** verbindliche native-Cap-Prüfung im Skill-`preflight()`
   am Target-Kontext. Sammel-Cap (Option B) verworfen. Offen bleibt nur die *Form* der Vertragspflicht
   (deklarativ `get_required_native_capabilities()` + CI-Gate vs. reine Konvention — Empfehlung: deklarativ, §4.8).
5. **Welche Context-Levels im MVP:** Empfehlung Modul + Kurs + System (Kategorie/User später).
6. **`classes/agent.php`-Booking-Hooks** bleiben mod_booking-seitig — beim späteren Plugin-Cut sauber
   schneiden (nicht Teil dieser Konsolidierung, aber nicht entgegenarbeiten).

---

## 11. Referenzen (verifiziert 2026-06-09)

**Runtime-Signaturen:** `agent_runtime.php:153(run),168(run_loop),261-271(resolve_cmid),850(run_internal),
154/169/358(resolve→cmid),155/175(run_internal-Aufruf),961(process-Aufruf),876(decision-Aufruf)`;
`orchestrator.php:181(provider_status),348-354(process: int $cmid),355(context_module::instance),
555/965/1191(phasen),456/860/1010/1250(ctx-block-Aufrufe),2251-2281(build_runtime_context_block),2273(booking-cm)`;
`executor.php:95(execute_commands: int $contextid),97-99(Modul-Zwang),104(cmid)`.
**Auth:** `authorization_service.php:65-122`; `interfaces/agent_authorization_service.php`; `db/access.php:29-35,114-148`.
**Persistenz:** `conversation_store.php:84-109,119-148`; `db/install.xml:11-12,22`.
**Readiness:** `aiready.php:32,70-73,113,350,367-369`; Aufrufer `mod/booking/view.php:203-211`.
**Framework-Hardcodings:** `services/lookup/option_lookup_service.php:67,95,97`;
`services/catalog/adaptive_skill_catalog_service.php:61-63`; `services/phase_prompt_bundle_builder.php:145,308,311`;
`skill_contract_validator.php:39,302-315`.
**Entry-Points:** `classes/external/{ai_send_message,ai_poll_thread,ai_privacy_precheck,activate_trial_context,
ai_confirm_run,ai_discard_pending,ai_get_thread_debug_logs,ai_get_doc_content,ai_upload_attachment}.php`;
`db/services.php`.
**Verwandte Docs:** Ist-Analyse (oben verlinkt), Extraction-Plan, `neue_skills_und_pdf_fragegenerierung_analyse_2026-06-09.md`.
**Memory:** project-wizard-local-plugin-extraction, project-agent-skill-discovery-visibility,
project-agent-guidance-injection, feedback-flowchart-policy.
