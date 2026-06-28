# Auskopplung `bookingextension_agent` → `local_wizard` — aktualisierter Planungs-Blueprint

**Datum:** 2026-06-28
**Status:** Planung (keine Code-Änderung heute)
**Ersetzt/aktualisiert:** `obsolet/todo/wizard_local_plugin_extraction_plan_2026-06-08.md` (Status quo dort teils überholt)
**Vorbedingung:** **pre-production** — es gibt keine zu erhaltenden Produktivdaten. Keine Daten-Migration nötig.

---

## 0. Entscheidungen (Georg, 2026-06-28)

1. **Opt-out reversibel (B+):** `local_wizard` wird die aktive Engine; `bookingextension_agent` stellt sich **zur Laufzeit** still, sobald `local_wizard` installiert ist, und **erwacht wieder**, wenn `local_wizard` deinstalliert wird. Reine Feature-Detection, kein Datenumzug.
2. **Tabellen-Koexistenz durch Rename, NICHT durch Migration:** Zwei Plugins dürfen dieselben Tabellen nicht in `install.xml` deklarieren. Lösung: das Subplugin benennt seine Tabellen `local_wizard_*` → **`bx_agent_*`** um; `local_wizard` besitzt künftig die `local_wizard_*`-Namen. **Keine Datenkopie** (pre-prod): einfacher `install.xml`-Wechsel, Neuinstallation genügt.
3. **Booking-Skills via Thin Bridge-Provider:** Wenn der Agent inert ist, exponiert er seine Booking-Skills über einen Adapter, der `local_wizard`s `skill_interface` implementiert und an den echten Skill forwardet (SDK-frei, lädt nur bei vorhandenem `local_wizard`).

> **Heute NICHT zu tun:** der eigentliche Engine-Cut (Ordner kopieren, Search-Replace). Dieser Blueprint plant ihn, führt ihn nicht aus.

---

## 1. Status quo (verifiziert 2026-06-28) — was sich seit 2026-06-08 geändert hat

| Bereich | Stand |
|---|---|
| **Kontext-Kopplung** | **Erledigt.** Tabellen nutzen `contextid` (module/course/system), nicht mehr `bookingid`. Die §3.6-Hauptkopplung des alten Blueprints ist aufgelöst (cmid-Loslösung abgeschlossen). |
| **DB-Tabellen** | **9**, alle Präfix `local_wizard_`: `_ai_threads`, `_ai_messages`, `_ai_runs`, `_ai_llm_debug`, `_user_memory`, `_benchmark_runs/_scenarios/_baselines/_metrics`. |
| **Tabellennamen im Code** | 18 Dateien referenzieren `local_wizard_*`-Strings (ai_threads 42×, ai_messages 24×, ai_llm_debug 22×, …). Das ist der Rename-Umfang (Workstream A). |
| **Interner Namespace** | `bookingextension_agent\local\wizard\*` (bleibt — komponentenpräfix-isoliert, kollidiert nicht mit `local_wizard\*`). |
| **Booking-Skills** | in `mod_booking\local\wizard\*` (~246 Treffer), gebunden an Agent-Typen → Bridge (Workstream D). |
| **Zentraler Verfügbarkeits-Check** | existiert: `authorization_service::is_agent_extension_installed()` + `aiready`. Idealer Einhängepunkt für die Opt-out-Weiche. |
| **Settings** | `admin_settingpage 'bookingextension_agent_aisettings'` → Kategorie `modbookingfolder`; geladen von `mod/booking/settings.php` (Extension-Loop, **außerhalb** des PRO-Gates). Master-Gate `agent_enabled`. 18 Config-Keys. |
| **uninstall.php** | **fehlt** → Moodle droppt die install.xml-Tabellen beim Deinstallieren automatisch (relevant für die Eigentums-/Reversibilitäts-Logik). |

**„local_wizard_"-Naming = Absicht:** Tabellen (`local_wizard_*`), interner Namespace (`…\local\wizard`) und Skill-Namespace (`mod_booking\local\wizard`) wurden vorausschauend so benannt, um den nahtlosen Übergang zu ermöglichen. Genau das adressiert Workstream A neu (Tabellen → `bx_agent_*`, damit `local_wizard` die `local_wizard_*`-Namen frei bekommt).

---

## 2. Workstream A — Tabellen-Koexistenz (Rename, keine Migration)

**Ziel:** Agent und `local_wizard` gleichzeitig installierbar, ohne XML-Tabellenkollision.

- [ ] Agent: in `db/install.xml` alle 9 Tabellen `local_wizard_*` → `bx_agent_*` umbenennen.
- [ ] Agent: alle 18 Code-Dateien mit `local_wizard_*`-Tabellennamen-Strings anpassen (gezielter Search-Replace **nur der Tabellennamen-Strings**, nicht des Namespace).
- [ ] **Pre-prod-Vereinfachung:** kein datenerhaltendes `rename_table` in `db/upgrade.php` nötig. Optionen:
  - (empfohlen, am saubersten) ein simpler `upgrade.php`-Schritt mit `$dbman->rename_table()` je Tabelle — verliert keine Dev-Daten, ist trivial; **oder**
  - schlicht `install.xml` ändern + Plugin neu installieren (pre-prod legitim).
- [ ] `local_wizard` (beim späteren Cut) deklariert/besitzt die `local_wizard_*`-Namen in **seiner** `install.xml`. Da der Agent sie geräumt hat, keine Kollision, **keine Adopt-Guards, keine Kopie**.
- [ ] Reversibilität ist hier geschenkt: Agent behält dauerhaft seine eigenen `bx_agent_*`-Tabellen; `local_wizard` hat eigene `local_wizard_*`. Deinstalliert man `local_wizard`, droppt Moodle nur dessen Tabellen; die `bx_agent_*` des Agents bleiben unberührt → Agent erwacht mit intakten (eigenen) Daten.

> **Konsequenz B+:** Reversibel heißt hier „Agent läuft mit seinem eigenen Datenstand weiter". Da pre-prod und getrennte Tabellen, gibt es bewusst **kein** Zusammenführen der während `local_wizard`-Betrieb entstandenen Daten zurück in den Agent. Das ist akzeptiert (kein Produktivdatenverlust möglich).

---

## 3. Workstream B — Reversibles Opt-out (Laufzeit-Weiche)

**Ziel:** Sobald `local_wizard` installiert ist, tut `bookingextension_agent` nichts mehr (delegiert/inert); wird `local_wizard` entfernt, lebt der Agent automatisch wieder.

### B.1 Zentrale Weiche
- [ ] Neue Methode in `authorization_service` (oder einem schlanken `delegation_gate`): `local_wizard_is_active(): bool` → `class_exists('\\local_wizard\\…\\agent_runtime')` bzw. `core_plugin_manager`-Check (`get_plugin_info('local_wizard')->is_installed_and_upgraded()`).
- [ ] Ein **einziger** Helper, überall konsumiert. Reine Feature-Detection = automatisch reversibel.

### B.2 Chokepoints (jeder bekommt einen frühen Guard `if (local_wizard_is_active()) { … inert … }`)
- [ ] **13 Webservices** (`db/services.php`): `ai_send_message`, `ai_privacy_precheck`, `ai_confirm_run`, `ai_discard_pending`, `ai_poll_thread`, `ai_get_thread_debug_logs`, `ai_get_doc_content`, `request_trial_key`, `activate_trial_context`, `configure_provider_from_existing`, `ai_upload_attachment`, `store_provider_apikey`, `set_debug_mode` → graceful „delegated to local_wizard" (kein Throw; siehe `check_use_readiness`-Muster).
- [ ] **Head-Injection-Hook** (`db/hooks.php` → `page_injection::extend_head`): früher `return`.
- [ ] **Fragment** `bookingextension_agent_output_fragment_aipanel` (`lib.php`): leer zurück.
- [ ] **Shortcode** (`classes/shortcodes.php`): an `local_wizard` delegieren / leer.
- [ ] **2 Tasks** (`db/tasks.php`: `cleanup_attachment_temp_files_adhoc`, `cleanup_old_benchmark_runs_task`): `execute()` früh raus (`mtrace` + return).
- [ ] **`aiready`**: bereits zentral — `is_agent_extension_installed()` um `&& !local_wizard_is_active()` ergänzen (oder die Weiche dort konsumieren).
- [ ] **Skill-Provider** (`skill_provider::get_skills()`): bei aktivem `local_wizard` **nicht** die eigenen Engine-Skills doppelt registrieren → siehe Workstream D (Bridge übernimmt die Booking-Skills).

---

## 4. Workstream C — Settings ausblenden + Hinweis

**Ziel:** Bei installiertem `local_wizard` zeigt der Agent **keine** Settings mehr, sondern einen Hinweis „nicht verfügbar / von local_wizard übernommen".

- [ ] In `bookingextension/agent/settings.php`: am Anfang `local_wizard_is_active()` prüfen.
  - Wenn aktiv: **nur** eine `admin_setting_heading` mit Lang-String `local_wizard_has_taken_over` registrieren (Titel + Erklärtext), **keine** der bestehenden Seiten/Felder.
  - Sonst: bestehende Registrierung unverändert.
- [ ] Neuer Lang-String `local_wizard_has_taken_over` in `lang/en/bookingextension_agent.php`.
- [ ] `mod/booking/settings.php`-Extension-Loop bleibt unverändert (er ruft nur `load_settings`; die Weiche sitzt im Agent-`settings.php`). Damit erscheint die Kategorie weiterhin, aber leer bis auf den Hinweis.

---

## 5. Workstream D — Thin Bridge-Provider für Booking-Skills

**Problem:** Die Booking-Skills (`mod_booking\local\wizard\*`: create_option, update_option, search_options …) sind an `bookingextension_agent`-Typen (`skill_interface`, DTOs, `base_skill`) gebunden. Wird der Agent inert und `local_wizard` aktiv, würde ein Bestandskunde sonst seine Booking-KI verlieren.

**Lösung (Georg-Entscheidung):** Der Agent stellt nur seine **Engine** still, exponiert die Booking-Skills aber über einen **Bridge-Provider**:
- [ ] Adapter, der `local_wizard\…\skill_interface` implementiert und an den echten `mod_booking`-Skill (gebunden an Agent-Typen) forwardet. Wegen Struktur-/Codegen-Gleichheit ~generischer Forwarder.
- [ ] Lädt nur bei vorhandenem `local_wizard` (optionale Kopplung; `class_exists`-Guard).
- [ ] Verhindert die Doppel-Registrierung aus Workstream B.2: der Agent registriert seine **Engine-Skills** nicht in `local_wizard`s Registry; nur die **Booking-Skills** werden via Bridge sichtbar.
- [ ] **Voraussetzung = Contract-Hygiene (§4 alt-Blueprint):** die 5 Engine-Internal-Leaks der Skills (`privacy_anonymizer`, `conversation_store`, `skill_discovery`, `skill_registry_factory`, `attachment_token_service`) invertieren, damit die Skills nur an die stabile Contract-Surface (Interfaces + DTOs + `base_skill`) hängen. Das macht die Bridge mechanisch.

---

## 5b. Engine-agnostischer Skill-Contract — DTO-frei + bedingtes `extends` (BEWIESEN 2026-06-28)

**Anlass (Georg):** Es darf **kein Extra-Plugin** installiert werden müssen, und ein Skill (auch für eine Fremd-Activity) muss laufen, egal ob nur `local_wizard`, nur der Agent, oder beide installiert sind. Die Bridge (WS-D) löst das nicht für `local_wizard`-only (kein Agent → keine Agent-`base_skill`). Ein geteiltes Contract-Plugin wäre das „Extra-Plugin", das vermieden werden soll.

**Blocker (verifiziert):** ~20 konkrete Skills überschreiben `preflight(): preflight_result_v2` (einige `get_prompt_contract(): skill_prompt_contract`) und **konstruieren** den Engine-DTO im Körper. Dieser Rückgabetyp ist statisch an *einen* Engine-Namespace gebunden → ein bedingtes `extends` allein reicht nicht, die Skill-eigene Signatur kettet ihn weiter an eine Engine.

**Lösung (zwei Teile):**
1. **DTO-freier Override-Surface:** `preflight()` / `get_prompt_contract()` werden **`final` in `base_skill`**; der konkrete Skill implementiert nur primitiv-typisierte Template-Methoden (`validate_input(array): array`, `build_prompt_contract(): array`, …). Die engine-eigene `base_skill` wickelt das Array in ihren DTO. (Konsequente Fortsetzung des bereits eingeführten Preview-Daten-Contracts.)
2. **Bedingtes `extends` über einen Body-Trait:**
```php
trait my_skill_body { /* gesamte Logik, nur array/string/bool-Signaturen */ }

if (class_exists('\local_wizard\local\wizard\base_skill')) {
    class my_skill extends \local_wizard\local\wizard\base_skill { use my_skill_body; }
} else {
    class my_skill extends \bookingextension_agent\local\wizard\base_skill { use my_skill_body; }
}
```

**Machbarkeit BEWIESEN** (Mini-Harness, beide Modi):

| Aktive Engine | Skill-Elternklasse | `preflight()`-DTO |
|---|---|---|
| local_wizard | `…local_wizard…\base_skill` | `local_wizard\…\preflight_result_v2` |
| nur Agent | `…bookingextension_agent…\base_skill` | `bookingextension_agent\…\preflight_result_v2` |

Derselbe Trait-Body lief unter beiden Eltern; der Skill nennt **keinen** Engine-Typ. Der Moodle-Autoloader `require`t die Datei, der `if/else` definiert die Klasse gegen die vorhandene Engine.

**Folgen:**
- **Ersetzt die Bridge (WS-D) als Standard-Weg** für engine-agnostische Skills. Die Bridge bleibt höchstens Fallback für nicht-konvertierte Altskills.
- **Autoren sehen die Boilerplate nie:** das vorhandene `agent.scaffold_skill` (Skill-Template-Generator) emittiert das `trait` + den `if/else`-Block; der Autor füllt nur Body-Methoden mit primitiven Signaturen.
- **Voraussetzung = die Leak-Inversionen (WS-D/§5) + der DTO-freie Refactor** der ~20 `preflight()`-Overrides. Beides zusammen ist Phase 0 und unabhängig vom Cut committbar.

**Scaffold-Vorlage (was der Generator ausgibt):**
```php
namespace <component>\local\wizard\skills;

trait <skill>_body {
    public function get_name(): string { return '<skill>'; }
    public function get_schema(): array { return [ /* … */ ]; }
    public function check_structure(array $input): array { return []; }
    protected function validate_input(array $input): array { /* Skill-Logik, reine Daten */ return []; }
    public function execute(array $prepared, int $contextid, int $userid): array { /* … */ return []; }
    public function is_read_only(): bool { return true; }
}

if (class_exists('\\local_wizard\\local\\wizard\\base_skill')) {
    class <skill>_skill extends \local_wizard\local\wizard\base_skill { use <skill>_body; }
} else {
    class <skill>_skill extends \bookingextension_agent\local\wizard\base_skill { use <skill>_body; }
}
```

---

## 6. Workstream E — Der eigentliche Engine-Cut (späteres Folgeprojekt)

Unverändert gültig aus dem alten Blueprint (§2–§4, §6, §8), hier nur referenziert:
- Ordner-Kopie `…/agent` → `local/wizard/`; Search-Replace **Lauf 1 Slash** (`bookingextension/agent`→`local/wizard`) **vor Lauf 2 Underscore** (`bookingextension_agent`→`local_wizard`).
- `version.php`: `component='local_wizard'`, `dependencies` ohne `mod_booking`.
- `classes/agent.php` (`extends bookingextension`) **nicht** ins local-Plugin (bleibt Booking-Shim).
- Contract-Surface sauber schneiden (Interfaces/DTOs/`base_skill`), `preflight_result_v2`/`skill_prompt_contract` aus `services\` nach `dto\`/`contract\`.
- AMD neu bauen (`grunt amd`), PHPUnit-Reinit (`local_wizard_testsuite`), Caches, WS-Registrierung, Behat-Tags.

---

## 7. Empfohlene Reihenfolge

- **Phase 0 (jetzt machbar, risikoarm, im bestehenden Plugin):**
  - Contract-Hygiene / 5 Leak-Inversionen (§5-Voraussetzung) — Tests grün halten.
- **Phase 1 — Koexistenz-Vorbereitung im Agent (vor local_wizard):**
  - Workstream A (Tabellen → `bx_agent_*`).
  - Workstream B.1 (zentrale `local_wizard_is_active()`-Weiche) + B.2-Guards (no-op, solange `local_wizard` nicht existiert → unschädlich).
  - Workstream C (Settings-Hinweis-Pfad; inaktiv bis `local_wizard` da).
- **Phase 2 — local_wizard-Cut (Folgeprojekt):**
  - Workstream E (Engine auskoppeln) + D (Bridge) + Parallel-Smoke (beide installiert → genau eine Engine, Booking-KI lebt via Bridge, Settings zeigen nur Hinweis).

---

## 8. Offene Punkte / Risiken

1. **Skill-Provider-Doppelregistrierung** bei Parallelbetrieb — die Bridge muss klar trennen: Engine-Skills (gehören local_wizard) vs. Booking-Skills (via Bridge). Verifikation per Registry-Test.
2. **Capability-Migration** beim späteren Cut: neue `local/wizard:*`-Capabilities sind auf Bestandsrollen nicht zugewiesen → Default-Archetypes setzen.
3. **`bookingextension_interface`-Schnitt** sauber halten, damit die Engine kein Booking-Wissen mitnimmt (`agent.php`-Hooks sind heute alle no-op/leer — günstig).
4. **Reversibilität ist „getrennte Datenstände"** (Abschnitt 2-Konsequenz): bewusst kein Rückfluss von `local_wizard`-Daten in den Agent. Pre-prod akzeptiert.
5. **Search-Replace-Disziplin** beim Cut: `bookingextension` (ohne `_agent`) ist der mod_booking-Subplugin-**Typ** und darf nicht angefasst werden.
6. **Flowchart-Policy:** `AGENT_IMPLEMENTATION_FLOWCHART.mmd` ist die primäre Architekturdoku — Diskrepanzen Code↔Flowchart vor Umsetzung mit Georg klären, nicht eigenständig angleichen.

---

## 9. Referenz-Schnellzugriff (Status quo 2026-06-28)

- Tabellen: `bookingextension/agent/db/install.xml` (9× `local_wizard_*` → Ziel `bx_agent_*`)
- Verfügbarkeits-Check: `…/classes/local/wizard/services/security/authorization_service.php::is_agent_extension_installed()`, `…/classes/local/wizard/aiready.php`
- Settings: `…/settings.php` (`bookingextension_agent_aisettings`, Master-Gate `agent_enabled`), geladen in `mod/booking/settings.php` (Extension-Loop, außerhalb PRO-Gate)
- WS: `…/db/services.php` (13 Funktionen), Hook: `…/db/hooks.php`, Fragment: `…/lib.php`, Shortcode: `…/classes/shortcodes.php`, Tasks: `…/db/tasks.php`
- Booking-Hooks (no-op): `…/classes/agent.php`
- Skill-Provider: `…/classes/local/wizard/skill_provider.php`
- Booking-Skills (Bridge-Ziel): `mod/booking/classes/local/wizard/**`
