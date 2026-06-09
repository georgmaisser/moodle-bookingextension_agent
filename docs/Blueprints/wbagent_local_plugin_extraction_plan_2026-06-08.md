# Auskopplung wbagent → eigenständiges `local_wbagent`-Plugin — Migrations-Blueprint

**Datum:** 2026-06-08
**Status:** Planung/Analyse (keine Code-Änderung)
**Vorgänger:** [wbagent_local_plugin_context_decoupling_analysis_2026-06-08.md](wbagent_local_plugin_context_decoupling_analysis_2026-06-08.md) (Context/Booking-Kopplungen)
**Ziel:** Den Agenten aus dem Booking-Subplugin `bookingextension_agent` in ein eigenständiges `local_wbagent` überführen — per Search-Replace als Basis, mit klarer Liste aller manuellen Eingriffe, Parallelbetrieb-Strategie und Sync-Konzept.

---

## 0. Kernempfehlung (TL;DR)

1. **Ziel-Architektur: EIN Engine + EIN dünner Shim — nicht zwei Engines.**
   - `local_wbagent` = die **Engine** (Runtime, Orchestrator, Skills-Framework, WS, Tabellen).
   - `bookingextension_agent` bleibt als **dünnes Booking-Subplugin** (nur die `bookingextension_interface`-Hooks: Option-Fields, Rules, col_actions, History) und **delegiert** an `local_wbagent`. Kein Engine-Code mehr.
   - Damit ist „wenn beide installiert sind, wird nur wbagent verwendet" **automatisch** erfüllt: es gibt nur eine Engine. Das ist der zentrale Gegenvorschlag zu „zwei vollständige Kopien synchron halten" (siehe §6/§7).
2. **Search-Replace ist die richtige Basis NUR für die Engine**, deckt aber nur ~80 % ab. Die kritischen 20 % (Plugin-Typ, Subplugin-Interface, Tabellen-Ownership, Capability-Slash-Form, externe Consumer, AMD-Rebuild) müssen manuell erfolgen — Liste in §3.
3. **Kein separates SDK-Plugin. Eine Engine, die Skills leiten von `bookingextension_agent` ab** (Stand 2026-06-09, Georgs Entscheidung). Da `bookingextension_agent` zuerst und mit Booking ausgeliefert wird, ist es die einzige Engine; alle Skills (mod_booking, local_entities) implementieren dessen Interfaces / extenden dessen `base_skill`. Die Discovery erledigt das „nur die jeweils vorhandenen Skills" automatisch: ein Provider, dessen Parent-Engine nicht installiert ist, lädt nicht. Die einzige Disziplin: die **Contract-Surface** (Interfaces + DTOs + `base_skill`) als saubere, self-contained Sub-Namespace *innerhalb* der Engine halten und die wenigen Engine-Internal-Leaks invertieren (Details §4) — kein eigenes Plugin, nur Hygiene, damit ein späteres Teilen der Typen mechanisch bleibt. Siehe auch §10.8 (offene Weiche „beide installiert").
4. **Gleiche Tabellen für Parallelbetrieb ist machbar** (sie heißen bereits `local_wbagent_ai_*`), erfordert aber eine bewusste **Tabellen-Ownership-Lösung** (nur ein Plugin deklariert sie; Adopt-Guards via `db/install.php`) — §5.

---

## 1. Ausgangslage (harte Zahlen)

| Bereich | Befund |
|---|---|
| Token `bookingextension_agent` (Plugin) | **267 Dateien, 3950 Treffer** |
| Token `bookingextension/agent` (Slash/Capability-Form) | **93 Treffer** (settings.php, benchmark_report.php, skill_selection_debug.php, viele Docs) |
| Subplugin-Registrierung | `mod/booking/db/subplugins.json` → Typ `bookingextension` unter `mod/booking/bookingextension` |
| Plugin-Struktur | `classes/agent.php` `extends bookingextension implements bookingextension_interface`; `version.php` `dependencies=['mod_booking']` |
| DB-Tabellen (eigene install.xml) | **8 Tabellen, alle `local_wbagent_*`**: `_ai_threads/_ai_messages/_ai_runs/_ai_llm_debug` + 4× `_benchmark_*` |
| **Externe Consumer des Namespace** | `mod_booking/classes/local/wbagent` (**29 Dateien / 246 Treffer**), **`local_entities`** (5 Dateien), `mod/booking/view.php` (UI-Einbindung) |
| AMD/JS | `amd/src/aiinstructions.js` referenziert WS-Funktionsnamen `bookingextension_agent_ai_*`; `amd/build/*` muss neu kompiliert werden |
| db/ | access.php, caches.php, install.xml, services.php, tasks.php, **upgrade.php** |

**Wichtig:** Der Namespace wird **nicht nur im Plugin selbst** verwendet, sondern auch von `mod_booking` (die Booking-Skills, die du zuletzt bearbeitet hast — `update_option_skill`, `option_input_verification`, `booking_skill_support` …) und von `local_entities`. Eine reine Umbenennung bricht diese Consumer, **wenn** keine Backward-Compat-Schicht (class_alias) bereitsteht — siehe §4.

---

## 2. Was Search-Replace zuverlässig erledigt

Auf einer **Kopie** des Ordners nach `local/wbagent/`:

1. **Namespace + Component-String:** `bookingextension_agent` → `local_wbagent`
   (PHP-Namespaces, `get_string(..., 'local_wbagent')`, `cache::make('local_wbagent', …)`, WS-Funktionsnamen `local_wbagent_ai_*`, Cache-Component, Event-Component).
2. **Capability/Slash-Form** (separater Lauf!): `bookingextension/agent` → `local/wbagent`
   (Capabilities `local/wbagent:useaiinstructions`, `require_capability`-Strings, Settings-Pfade).
3. **Lang-Dateien:** Inhalt der `$string[...]`-Keys bleibt; nur die **Dateinamen** `lang/en/bookingextension_agent.php` → `lang/en/local_wbagent.php` (manuell), und alle `get_string`-Component-Args werden durch Lauf 1 erfasst.

**Reihenfolge:** zuerst Slash-Form (`bookingextension/agent`→`local/wbagent`), dann Underscore-Form (`bookingextension_agent`→`local_wbagent`). Sonst würde der zweite Lauf `bookingextension/agent` in `local_wbagent/agent`-Artefakte zerlegen.

**Scope:** `*.php, *.js (nur amd/src!), *.xml, *.json, *.mustache, *.csv (Fixtures), settings.php, lang/*`. **Nicht** `amd/build/*` (wird neu generiert), **nicht** `vendor/`/`node_modules`.

---

## 3. Was Search-Replace NICHT erledigt — manuelle Pflichtliste

### 3.1 Plugin-Identität / Struktur
- [ ] **Ordner verschieben** `mod/booking/bookingextension/agent/` → `local/wbagent/`.
- [ ] `version.php`: `$plugin->component = 'local_wbagent'`; **`dependencies` auf `mod_booking` entfernen** (mod_booking wird optionaler Provider). `$plugin->maturity`/`requires` prüfen.
- [ ] **`classes/agent.php` (das `extends bookingextension`) gehört NICHT ins local-Plugin.** Es ist booking-spezifisch (Option-Fields/Rules/History). → bleibt im Booking-Subplugin-Shim (siehe §6). Im local-Plugin **löschen**.
- [ ] Hardcodierte Pfade: `require($CFG->dirroot . '/mod/booking/bookingextension/agent/settings.php')` → `/local/wbagent/settings.php` (in `agent.php`/Shim).

### 3.2 Datenbank / Tabellen-Ownership (Parallelbetrieb-kritisch — §5)
- [ ] Entscheiden, **wer die Tabellen besitzt**. Empfehlung: `local_wbagent`. Dann muss der Booking-Shim die Tabellen aus *seiner* install.xml entfernen.
- [ ] `db/install.php` im local-Plugin mit `if (!$dbman->table_exists(...))`-Adopt-Guards, damit bestehende (vom alten Subplugin erzeugte) Tabellen **nicht neu erstellt** werden.
- [ ] `db/upgrade.php` existiert (entgegen der „install.xml only"-Policy aus dem Flowchart LG_DB) — Inhalt prüfen und ggf. mitnehmen/bereinigen.
- [ ] **Uninstall-Schutz:** der zu retirende Plugin darf die geteilten Tabellen **nicht** droppen (`db/uninstall.php`/keine `XMLDB drop`).

### 3.3 Capability / Access
- [ ] `db/access.php`: `local/wbagent:useaiinstructions` mit passendem `contextlevel` (nicht zwingend `CONTEXT_MODULE` — für course-/system-weite Nutzung breiter; siehe Context-Doc). Archetypes neu bewerten.
- [ ] `db/access.php`: `debugskillselection` (war `CONTEXT_SYSTEM`) übernehmen.
- [ ] Roles/Capability-Zuweisungen bestehender Installs: neue Capability ist initial nicht zugewiesen → Default-Archetypes setzen.

### 3.4 Externe Consumer (zwei weitere Repos!) — NICHT pauschal umbenennen
- [ ] **`mod_booking`** (29 Dateien/246 Treffer) und **`local_entities`** (5 Dateien): das sind **Skill-Provider**. Sie dürfen die Engine nicht referenzieren → Behandlung gemäß **§4** (Contract-Surface + Leak-Inversion), **nicht** per Search-Replace auf `local_wbagent\…`.
- [ ] **`mod/booking/view.php`**: UI-Einbindung + WS-Funktionsname `bookingextension_agent_ai_*` → auf Shim oder `local_wbagent_*` zeigen.
- [ ] Repo-übergreifender Scan vor dem Cut: `grep -rl bookingextension_agent` über `mod/booking`, `local/*`, `blocks/*`.

### 3.5 Frontend / AMD
- [ ] `amd/src/*.js`: WS-Namen (Lauf 1 erfasst Strings) — danach **`grunt amd`** neu bauen; alte `amd/build/*` löschen/überschreiben.
- [ ] Template-IDs/CSS-Klassen mit `bookingextension_agent`-Präfix prüfen (Mustache).

### 3.6 Booking-Kopplungen, die NICHT durch Rename verschwinden (aus Context-Doc)
- [ ] `authorization_service::require_booking_module_context()` → generischer Context-Gate.
- [ ] Entry-Points `get_coursemodule_from_id('booking', …)` (ai_send_message:160, ai_poll_thread:96, ai_privacy_precheck:112, activate_trial_context:106, orchestrator:2174, aiready:113).
- [ ] `threads.bookingid` NOT NULL (§5).
- [ ] Hardcodierte `mod_booking.*`-Skillnamen in Framework-Services (option_lookup_service:67/95, adaptive_skill_catalog_service, phase_prompt_bundle_builder, orchestrator-Fallback) → über Provider/Family-Contracts deklarieren.
- [ ] `aiready` / `singleton_service`-Nutzung → booking-seitig kapseln.

> Diese 3.6-Punkte sind für die **bloße Auskopplung** (Plugin läuft als local, weiterhin nur in Booking-Contexts) **nicht zwingend**. Sie sind nötig für das Folgeziel „context-unabhängig überall" und können danach erfolgen.

---

## 4. Contract-Surface sauber halten (KERNSTÜCK) — kein SDK-Plugin

**Vorgabe (revidiert 2026-06-09):** Es gibt **kein separates SDK-Plugin**. Die Skills leiten von `bookingextension_agent` ab (= die zuerst ausgelieferte Engine). Was bleibt, ist eine reine **Hygiene-Aufgabe**: die Skills dürfen nur an die **stabile Contract-Surface** der Engine (Interfaces + DTOs + `base_skill`) hängen, **nicht** an deren Internas (Orchestrator, Executor, Stores, Services). Reines Umbenennen `bookingextension_agent`→`local_wbagent` ist kein Thema mehr — die Skills bleiben auf `bookingextension_agent`. Der Grund, die Contract-Surface trotzdem sauber zu schneiden: falls die Skill-Typen später geteilt werden müssen (siehe §10.8, Weg B), ist das dann mechanisch statt einer Entwirrung.

### 4.1 Bestandsaufnahme: was die Skills heute aus dem Framework ziehen
Analyse der `use bookingextension_agent\…`-Imports in `mod/booking/classes/local/wbagent` (246 Treffer) + `local/entities` (5 Dateien) ergibt **zwei** klar trennbare Klassen von Abhängigkeiten:

**(I) Contract-Surface — legitim, gehört in ein stabiles SDK** (Skills *dürfen* darauf bauen):

| Typ | Verwendung (≈) |
|---|---|
| `interfaces\skill_interface` | Kern-Contract |
| `interfaces\skill_trigger_provider_interface` | 14 |
| `interfaces\queue_identity_provider_interface` | 5 |
| `interfaces\skill_provider_interface` | mehrfach |
| `interfaces\skill_input_normalizer_interface` / `…_provider_interface` | 3 |
| `interfaces\skill_preview_renderer_interface` / `…_provider_interface` | 2 |
| `interfaces\issue_code_provider_interface` | local_entities |
| `dto\skill_risk_class` | 8 |
| `services\preflight_result_v2` *(DTO, falsch unter `services\`)* | **18** |
| `services\skill_prompt_contract` *(DTO, falsch unter `services\`)* | 1 |
| `base_skill` *(abstrakt, hängt nur an obigen Contracts)* | Basisklasse vieler Skills |

→ Das ist faktisch bereits ein **SDK**. `base_skill` importiert ausschließlich `skill_risk_class`, `skill_interface`, `preflight_result_v2`, `skill_prompt_contract` (verifiziert) — also reine Contracts.

**(II) Engine-Internal-Leaks — echte Fehlkopplung, muss *invertiert* (nicht umbenannt) werden:**

| Engine-Klasse | Geleakt in (Datei) | Inversions-Idee |
|---|---|---|
| `privacy_anonymizer` | `update_option_skill`, `update_option_trainer_skill`, `bulk_update_options_skill` | Engine (de)anonymisiert **um** den Skill herum; der Executor deanonymisiert Command-Input ohnehin (Flowchart: `deanonymize_command_input`). Direkt-Nutzung im Skill streichen. |
| `conversation_store` | `booking_skill_support` | Benötigte Daten (Preview-Option-IDs, Thread-Metadaten) über **Input/Context-Contract** hineinreichen, statt den Store direkt zu lesen. |
| `skill_discovery` | `skill_provider`, `booking_skill_support` | Provider darf seine eigenen Skills über einen Contract-Helfer auflisten; `support` bekommt den Katalog injiziert statt zu discovern. |
| `skill_registry_factory` | `list_option_properties_skill` | Ein Skill darf nicht in die Registry greifen; benötigte Infos via Contract/Input. |
| `attachment\attachment_token_service` | `booking_skill_mutation_execute_service` | **Die Engine** löst das Attachment auf und übergibt dem Skill einen **Datei-Pfad/Handle** (z. B. via Input-Normalizer); der Skill kennt kein Token-Service. *(Anmerkung: diese Kopplung stammt aus dem zuletzt gebauten Header-Bild-Feature — sie ist hier der konkrete Beleg, warum die Entflechtung nötig ist.)* |

### 4.2 Ziel: saubere Contract-Surface *innerhalb* der Engine (kein eigenes Plugin)
- Die Contract-Surface bleibt ein **Sub-Namespace innerhalb von `bookingextension_agent`** (z. B. `…\interfaces\*`, `…\dto\*`, `base_skill`). Kein `local_wbagentsdk`-Plugin. Skills hängen an dieser Surface, nicht an Engine-Services.
- Konkrete Maßnahmen (reine Hygiene, kein Strukturbruch):
  1. `preflight_result_v2` und `skill_prompt_contract` aus `services\` nach `dto\`/`contract\` verschieben (es sind DTOs, liegen falsch).
  2. `interfaces\*` + `dto\*` + `base_skill` als Contract-Surface dokumentieren (das ist die Oberfläche, die ein evtl. späterer Schnitt erbt).
  3. Die 5 Engine-Leaks gemäß Tabelle 4.1-II auflösen (Inversion) — das ist der eigentliche Wert dieses Abschnitts: Skills sollen nicht auf `privacy_anonymizer`, `conversation_store`, `attachment_token_service` etc. zugreifen.
  4. Skills importieren danach nur noch aus der Contract-Surface (+ ihrem eigenen Provider-Plugin).

### 4.2a Previews: Daten-Contract statt Engine-Interface (weitere Entkopplung)
Heute muss ein Skill für eine Vorschau **zwei Engine-Interfaces** implementieren (`skill_preview_renderer_interface::render(): string`, `skill_preview_provider_interface::get_preview_descriptor(): {type, renderer-FQCN, js_module, description}`). Der konkrete Booking-Renderer ruft sogar mod_booking-View-Internals (`booking_option_preview_renderer::render()` → `get_rendered_showonlyone_table`).

**Besser (Vorschlag):** Der Skill liefert die Vorschau als **reine Daten** in seinem Result, z. B. `preview => { html: string, js_module: string|null, payload: array }`. Die Engine/WS reicht das unverändert an die GUI durch.
- **Gewinn:** Zwei Interfaces fallen aus der Contract-Surface; der Skill „kann alles ausgeben", ohne Engine-Typen zu kennen. Booking-View-Wissen bleibt komplett im Booking-Skill — die Engine kennt keine Booking-Views mehr (stärkt die Auskopplung).
- **Leitplanke 1 — kein Inline-JS:** `js_module` bleibt ein **AMD-Modulname** (im Plugin des Skills unter `amd/src/`), kein roher `<script>`-String. CSP-/XSS-konform; die GUI lädt das Modul mit `payload` über den Standard-Loader.
- **Leitplanke 2 — Trust-Boundary:** `html` ist server-autorisierter Plugin-Output (Durchreichen via `PARAM_RAW` ok), aber **kein un-escapetes User-/DB-Content**.
- **Netto:** Verhaltens-Interface-Kopplung → kleiner **Daten-Contract** `{html, js_module, payload}`. `skill_preview_renderer_interface` + `skill_preview_provider_interface` aus dem SDK entfernen.

### 4.3 Search-Replace-Konsequenz
- **Für jetzt entfällt das Thema:** Skill-Provider bleiben auf `bookingextension_agent` — kein Rename, kein Alias. Es passiert gar kein Engine-Cut, solange nur `bookingextension_agent` ausgeliefert wird.
- Die einzige Arbeit hier sind die **Leak-Inversionen** (§4.1-II) — sie sind Design-Fehler, kein Namensthema, und für eine spätere Wahl von Weg A oder B (§10.8) ohnehin sinnvoll.

---

## 5. Parallelbetrieb mit geteilten Tabellen

**Dein Ziel:** beide installiert, gleiche Tabellen, faktisch nur wbagent aktiv. **Bewertung: sinnvoll — mit einer Präzisierung.**

### 5.1 Tabellen-Ownership (der eigentliche Knackpunkt)
- Zwei Plugins dürfen **nicht beide** dieselben Tabellen in `install.xml` deklarieren (Install-Kollision).
- Lösung: **`local_wbagent` besitzt** `local_wbagent_ai_*` (+ benchmark). Der Booking-Shim entfernt sie aus seiner install.xml.
- Für **Bestandsinstallationen** (Tabellen existieren bereits, vom alten Subplugin): `local_wbagent/db/install.php` mit `table_exists()`-Adopt-Guards → keine Neuanlage, kein Datenverlust.
- **Uninstall:** der Shim darf die geteilten Tabellen nie droppen.

### 5.2 Runtime-Delegation („nur wbagent wird verwendet")
- Mit dem **Engine+Shim-Modell ist das geschenkt**: der Shim *enthält* keine Engine, er ruft `local_wbagent`. Es gibt nur einen Pfad.
- Falls (Übergang) der Shim doch noch eigenen Engine-Code hätte: einfache Feature-Detection `if (class_exists('\\local_wbagent\\local\\wbagent\\agent_runtime')) { delegiere } else { lokal }`. Das ist genau dein „automatisches Umstellen im Hintergrund" — aber es ist nur nötig, wenn man (unnötigerweise) zwei Engines hält.

### 5.3 Warum ich „zwei vollwertige Engines parallel" abrate
- Doppelte Wartung, Divergenz-Risiko, doppelte WS-Registrierung, doppelte Skill-Provider (beide würden Skills registrieren → Namens-/Verhaltenskonflikte im `skill_registry`), Tabellen-Ownership-Streit.
- Der gewünschte Effekt („wbagent ist die Engine") wird durch **Engine + dünner Shim** ohne diese Nachteile erreicht.

---

## 6. Ziel-Schnitt: was wohin gehört

| Komponente | Zielort |
|---|---|
| Runtime, Orchestrator, Interpreter, Executor, Queue, Preflight, Synchronizer, Discovery/Embeddings, Core-Skills, WS-Endpoints, Tabellen, Caches, Tasks, AMD-UI | **`local_wbagent`** |
| `bookingextension_interface`-Hooks (`contains_option_fields`, `get_option_fields_info_array`, `set_template_data_for_optionview`, `add_options_to_col_actions`, `get_allowedruleeventkeys`, `get_booking_history_description`) | **`bookingextension_agent`-Shim** (bleibt Booking-Subplugin), delegiert an `local_wbagent` |
| Booking-Skills (`mod_booking.create_option`, `update_option`, `search_options`, …) | bleiben in **`mod_booking/classes/local/wbagent`**, referenzieren künftig `local_wbagent\…` (oder via Alias) |
| `aiready`/Booking-Readiness, `singleton_service`-Nutzung | **booking-seitig** (Shim oder mod_booking), nicht in der Engine |

---

## 7. Codebase synchron halten — Optionen (deine letzte Frage)

**Primärempfehlung: das Problem auflösen, nicht verwalten.** Mit Engine+Shim gibt es **nur eine** Quelle (Engine). Kein Sync nötig. Das ist die mit Abstand robusteste Antwort.

Falls dennoch **zwei eigenständige Codebasen** gewünscht sind (z. B. der Booking-Subplugin soll auch **ohne** local_wbagent voll funktionieren):

1. **Codegen/Mirror-Script + CI-Gate (am praktikabelsten bei Duplikat-Zwang):**
   - Kanonische Quelle = `local_wbagent`.
   - Ein deterministisches Transform-Script erzeugt den Booking-Klon (reverse Search-Replace `local_wbagent`→`bookingextension_agent`, Entfernen engine-fremder Teile).
   - CI-Job führt das Script aus und schlägt fehl, wenn das committete Klon-Verzeichnis vom generierten abweicht (`git diff --exit-code`). So kann Drift nicht unbemerkt entstehen.
2. **Git subtree** des Engine-Kerns in beide Plugins. Problem: die feste Namespace-Differenz (`local_wbagent\` vs `bookingextension_agent\`) macht denselben Quelltext nicht teilbar, ohne Build-Zeit-Rewriting → unschön.
3. **Shared Library (Composer-Package/`vendor`) mit stabilem Namespace**, das beide Plugins dünn umhüllen. Sauber im Prinzip, aber in Moodle (kein per-Plugin-Composer-Autoload-Standard) schwergewichtig.
4. **Symlink** (Dev-only): `mod/booking/bookingextension/agent` → `local/wbagent`. Nur lokal/dev, nicht deploybar, Namespace-Problem bleibt.

**Fazit:** Option 1 nur falls echte Doppel-Eigenständigkeit Pflicht ist; ansonsten Engine+Shim (= kein Sync).

---

## 8. Test-/Verifikations-Plan nach dem Cut

1. **PHPUnit-Reinit:** `php public/admin/tool/phpunit/cli/init.php` (neuer Component-Testsuite-Name `local_wbagent_testsuite`).
2. Test-Namespaces/`@covers`/`use`-Statements werden durch Search-Replace erfasst; Fixtures (CSV-Embeddings, Benchmark-Seeds) prüfen.
3. Gezielt: skill-registry-/contract-/integration-Tests, Postcondition-/Verifikations-Tests, der neue Construction-Guidance-Test.
4. **WS-Registrierung:** `php admin/cli/upgrade.php`; prüfen, dass `local_wbagent_ai_*`-WS existieren und JS sie aufruft.
5. **AMD:** `grunt amd` (oder `grunt`), `amd/build` neu; Browser-Smoke-Test (Drop-Zone, Paperclip, Senden).
6. **Caches:** `purge_caches.php`.
7. **Behat:** Feature-Files mit Component-Tags/Pfaden prüfen.
8. **Cross-Plugin:** mod_booking-Skills + local_entities-Skills laden (registry baut ohne Contract-Diagnostics).
9. **Parallel-Smoke:** beide Plugins installiert → genau eine Engine aktiv, keine doppelten WS-/Skill-Registrierungen, Tabellen unverändert/adoptiert.

---

## 9. Empfohlene Reihenfolge (de-risked)

**Phase 0 — Skills entflechten (VOR jedem Rename, im bestehenden Code):**
1. Contract-Surface festziehen (§4.2): `preflight_result_v2`/`skill_prompt_contract` nach `dto\`/`contract\`; Interfaces+DTOs+`base_skill` als öffentliche API markieren.
2. Die 5 Engine-Leaks (§4.1-II) invertieren — privacy_anonymizer/conversation_store/skill_discovery/skill_registry_factory/attachment_token_service aus den Skills entfernen. Tests grün halten.
3. Skill-Provider importieren danach **nur noch** aus dem Contract-Namespace (Verifikation per `grep`: kein `…\\wbagent\\(services|orchestrator|executor|conversation_store|privacy_anonymizer)` mehr in Skills).

**Phase 1 — Engine auskoppeln:**
4. **Branch + Kopie** des Ordners nach `local/wbagent/` (Original vorerst belassen).
5. Search-Replace Lauf 1 (Slash) + Lauf 2 (Underscore) auf der Kopie; Lang-Dateien umbenennen.
6. Manuelle §3.1–§3.3 (Identität, Tabellen-Ownership/install.php, access.php). `agent.php` löschen.
7. **Compat-Aliase** nur für Contract-Typen (§4.3) → Provider laufen unverändert.
8. Install/Upgrade + PHPUnit-Reinit + `grunt amd` + Caches; Tests grün machen (§8).

**Phase 2 — Booking-Shim + Parallelbetrieb:**
9. **Booking-Shim** umbauen: `bookingextension_agent` zu dünnem Forwarder (nur Interface-Hooks, delegiert an `local_wbagent`); Tabellen aus dessen install.xml entfernen; Uninstall-Schutz; **keinen** eigenen skill_provider mehr registrieren (§10.2).
10. Parallel-Smoke-Test (§8.9).

**Phase 3 — optionale Folgeprojekte:**
11. Context-Generalisierung (§3.6) + Nav-Bar-Einstieg.
12. Contract-Aliase entfernen, Provider final auf den Contract-Namespace ziehen.

---

## 10. Risiken & offene Entscheidungen

1. **Tabellen-Ownership bei Bestandsinstallationen** (§5.1) — die heikelste Stelle; `db/install.php`-Adopt-Guards zwingend testen (frisch **und** Upgrade-Pfad).
2. **Doppelte Skill-Provider-Registrierung** bei Parallelbetrieb: wenn der Shim weiterhin einen `skill_provider` mitbringt, könnte `skill_registry::make_default()` Skills doppelt sehen. Der Shim sollte **keinen** eigenen Provider registrieren (Engine + mod_booking + local_entities reichen).
3. **Capability-Migration:** neue `local/wbagent:*` ist auf Bestandsrollen nicht zugewiesen → bewusst Default-Archetypes setzen, sonst „permission denied".
4. **DB-Policy-Konflikt:** Projekt sagt „install.xml only, no upgrade.php" (Flowchart LG_DB), aber es existiert eine `upgrade.php` und die Tabellen-Adoption braucht ggf. Logik → mit dir klären.
5. **`bookingextension_interface`-Schnitt:** sauber trennen, damit die Engine kein Booking-Wissen mitnimmt (sonst war die Auskopplung umsonst).
6. **3950 + 246 + N Treffer**: Search-Replace muss case-/wortgrenzen-bewusst sein; `bookingextension` (ohne `_agent`) **nicht** anfassen (das ist der mod_booking-Subplugin-Typ).
7. **Skill-Entflechtung ist Voraussetzung, kein Nachgang (§4):** Solange Skills Engine-Services (privacy_anonymizer, conversation_store, attachment_token_service …) direkt aufrufen, ist die Auskopplung nicht „sauber" — sie würde die Engine wieder mitziehen. Phase 0 zuerst. Konkreter aktueller Beleg: das Header-Bild-Feature koppelt `booking_skill_mutation_execute_service` an `attachment_token_service` — exemplarisch zu invertieren.

8. **Gewählte Richtung „beide Plugins installiert": reversibler Cutover (B+)** (Georg, 2026-06-09 — umzusetzen erst, wenn local_wbagent kommt, NICHT jetzt). Skill-Klassen sind an *einen* Engine-Namespace gebunden (`bookingextension_agent\…\skill_interface` ≠ `local_wbagent\…\skill_interface`, verschiedene PHP-Typen). Solange nur `bookingextension_agent` ausgeliefert wird, ist das irrelevant — bei „nur Extension" bzw. „nur local_wbagent" sind die jeweils anderen Skills schlicht nicht vorhanden (gewünschtes Verhalten). Relevant ist allein **beide gleichzeitig** (Upgrade-Pfad eines Booking-Kunden).
   - **Mechanik (Georgs Wunsch):** local_wbagent wird *die* aktive Engine; `bookingextension_agent` stellt seine **Engine still** (Feature-Detection-Weiche: `class_exists('\local_wbagent\…\agent_runtime')` → delegieren, selbst keine Aktion) + einmalige **Daten-Migration** in local_wbagents getrennte Tables (§5). **Reversibel:** wird local_wbagent deinstalliert, fällt die Weiche zurück und `bookingextension_agent` „erwacht" wieder. Solange wbagent installiert ist, führt bookingextension keinerlei eigene Aktion aus.
   - **Konsequenz (unausweichlich):** Wenn Booking inaktiv ist, muss local_wbagent die **Booking-Skills ausführen** (sonst verliert der Bestandskunde beim Install seine Booking-KI). Die Booking-Skills sind aber auf `bookingextension_agent`-Typen gebunden. Auflösung — zwei Optionen, Entscheidung fällt erst beim local_wbagent-Cut:
     - **(empfohlen) Thin Bridge-Provider in `bookingextension_agent`:** stellt nur die *Engine* still, exponiert die Booking-Skills aber via Adapter, der `local_wbagent\…\skill_interface` implementiert und an den echten Booking-Skill forwardet. Lädt nur bei vorhandenem local_wbagent (optionale Kopplung). Wegen Codegen-Strukturgleichheit ~generischer Forwarder. SDK-frei, passt zu „bookingextension delegiert".
     - **(Alternative) Geteilte Contract-Typen:** die Skill-Contract-Surface (Interfaces + DTOs + base_skill) in eine von beiden Engines unabhängige Komponente ziehen, sodass eine einzige Typdefinition existiert. Das ist faktisch das zuvor verworfene SDK-Paket — nur dann erforderlich, wenn die Bridge nicht reicht.
   - **Jetzt zu tun:** nichts an der Auslieferung; nur die Contract-Surface sauber halten (§4.2), damit Bridge ODER geteilter Contract später mechanisch bleibt.

---

## 11. Referenz-Schnellzugriff

- Subplugin-Typ: `mod/booking/db/subplugins.json`
- Plugin-Identität: `…/agent/version.php`, `…/agent/classes/agent.php`
- Tabellen: `…/agent/db/install.xml` (8× `local_wbagent_*`)
- WS: `…/agent/db/services.php`; AMD-Consumer: `…/agent/amd/src/aiinstructions.js`
- Capability: `…/agent/db/access.php` (`bookingextension/agent:*`)
- Externe Consumer: `mod/booking/classes/local/wbagent/**` (246), `local/entities/classes/local/wbagent/**` (5), `mod/booking/view.php`
- Booking-Kopplungen (Detail): siehe [Context-Decoupling-Doc](wbagent_local_plugin_context_decoupling_analysis_2026-06-08.md)
