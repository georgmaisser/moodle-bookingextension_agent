# Release-Audit — `bookingextension_agent`

**Plugin:** `mod/booking/bookingextension/agent` · Komponente `bookingextension_agent` · Version `2026061704`
**Geprüft am:** 2026-06-18 · **Grundlage:** `docs/Blueprints/testprotokoll.md` (20-Punkte-Checkliste)
**Methode:** Statische Vollprüfung des Quellcodes (7 parallele Audit-Durchläufe über alle 20 Sektionen),
mit `file:line`-Belegen. **Nicht** geprüft wurden Laufzeit-Checks, die ein PHP-Runtime brauchen
(siehe Umgebungs-Caveat).

> **Laufzeit-Checks (nachgeholt via SSH auf `user@10.111.0.2`, Moodle 5.1.1+, PHP 8.3.28, MariaDB 10.11).**
> Die lokale Audit-Sandbox hat kein PHP — die statische Prüfung lief lokal, die drei Laufzeit-Punkte
> (Lint, Codechecker, PHPUnit) wurden auf dem Dev-Container ausgeführt:
> - **PHP-Lint** (`php -l`, gesamtes Plugin ohne thirdparty/.claude): **0 Syntaxfehler** ✅
> - **PHPUnit** (`--testsuite bookingextension_agent_testsuite`, ohne Key): **445 Tests, 2184 Assertions, 0 Failures/Errors**, 50 skipped (Real-LLM ohne Key), 89 PHPUnit-Deprecation-Notices (Framework) ✅
> - **phpcs** (`--standard=moodle`): **399 Violations (327 Errors + 72 Warnings) in 39 Dateien**, davon 342 auto-fixbar ⚠️ — Details in Sektion 3.
>
> Die Real-LLM-Tests wurden **nicht** erzwungen und der API-Key **nie** gesetzt (Vorgabe eingehalten).

---

## Gesamtergebnis

**☑ Mit Auflagen** — Architektur, Codestandards, DB/Upgrade, Webservices, Caching und CI sind solide
bis stark. Vor einem (kommerziellen) Release **müssen** drei kritische Befunde adressiert werden:

### 🔴 Blocker (kritisch)

| # | Bereich | Befund | Beleg |
|---|---------|--------|-------|
| B1 | Sicherheit | **IDOR — Cross-User-Thread-Read.** `threadid` vom Client wird ohne Eigentümer-Prüfung gefetcht; ein Nutzer kann fremde Konversationen / rohe LLM-Debug-Logs lesen (nur `useaiinstructions` am Kontext wird geprüft, nicht `thread.userid == $USER->id`). Entspricht dem bekannten SEC-01..03-Cluster, weiterhin offen. | `classes/external/ai_poll_thread.php:85-94`, `classes/external/ai_get_thread_debug_logs.php:91-93` |
| B2 | Datenschutz/DSGVO | **Privacy-Provider deckt nur 1 von 5 personenbezogenen Tabellen ab.** `local_wbagent_ai_threads`, `_ai_messages` (roher Gesprächstext), `_ai_runs`, `_ai_llm_debug` (rohe Prompt/Response-Strings) fehlen in `get_metadata()` **und** in allen Export-/Lösch-Pfaden → Auskunfts-/Löschbegehren übersehen alle KI-Konversationsdaten. Zudem **keine `external_location`** für die LLM-Übermittlung deklariert. | `classes/privacy/provider.php:43,51-65` vs. `db/install.xml:7-88` |
| B3 | Backup/Reset | **Kein `backup/moodle2/` und kein Kurs-Reset-Cleanup.** Kontextgebundene Thread-/Message-/Run-Daten reisen nicht im Kursbackup mit und werden bei „Kurs zurücksetzen" nicht entfernt (Orphan-Daten). *Hinweis: Für ein bookingextension-Subplugin ggf. bewusst dem Parent überlassen — mit Georg zu klären, ob Eigen-Backup gewünscht ist.* | kein `backup/`-Verzeichnis; `db/hooks.php` ohne `after_reset_course_data` |

### 🟠 Wichtig (vor Release beheben, kein harter Blocker)

- **66 Capabilities ohne Sprachstring** (64 `skill_*` + `agent:viewbenchmarks` + `agent:managebenchmarks`) → erscheinen als `[[agent:skill_…]]` auf der Rollen-Seite. (`lang/en/bookingextension_agent.php:25-40`)
- **`cleanup_old_benchmark_runs_task` nicht in `db/tasks.php` registriert** → Scheduled Task läuft nie, Benchmark-Retention greift nicht; zudem fehlt der `task_*`-Sprachstring. (`classes/task/cleanup_old_benchmark_runs_task.php:32` vs. `db/tasks.php:27-38`)
- **`version.php` ohne `$plugin->maturity` und `$plugin->release`** — beide sollten gesetzt sein. (`version.php`)
- **Privacy-API-Provider ungetestet** — es gibt Anonymizer-Tests, aber keinen `provider_testcase` für Export/Delete/Metadata. (`tests/` — kein `provider_testcase`)
- **37 DE-Strings fehlen** gegenüber EN (Skill-Caps + UI). (`lang/de/bookingextension_agent.php`)
- **phpcs `moodle` nicht clean: 399 Violations** (Container-Lauf). 342 via `phpcbf` auto-fixbar (PSR2-Call-Signaturen, Lang-Sortierung, Zeilenlänge, Kommentar-Großschreibung). Manuell zu prüfen: 6× `global $PAGE` in `question_preview_renderer.php` (Renderer soll `$this->page`/Output-API nutzen), 2 leere catch-Blöcke (Exceptions verschluckt: `activity_creation_service.php:127`, `agent_decision_service.php:447`), auskommentierter Code (`question_generation_service.php:151`).

### 🟡 Geringfügig / informativ

- `agent:managebenchmarks` deklariert, aber nirgends geprüft (tote Capability).
- Benchmark-Cap-Inkonsistenz: Menülink nutzt `viewbenchmarks`, die Seiten verlangen hart `moodle/site:config` → sichtbarer aber 403-Link für Nicht-Admins. (`settings.php:274` vs. `benchmark_*.php:28`)
- Pauschale `RISK_DATALOSS|RISK_XSS` auf allen `skill_*`-Caps (auch reine Read-Skills überzeichnet); 4 explizite + 2 Benchmark-Caps ganz ohne riskbitmask.
- **Keine Behat-Tests**, kein `tests/generator/`.
- **Kein `CHANGELOG.md`**; „Known Limitations" nur verstreut.
- `styles.css` theme-naiv (hardcodierte Light-Theme-Hex, ein `!important` auf `.text-muted`), wenn auch sauber unter `#booking-ai-wrapper` gescoped.
- Chat-Textarea ohne `<label>`/`aria-label` (nur Placeholder). (`templates/aiinstructions.mustache:98`)
- Copyright-Jahr-Drift (2025/2026 gemischt).

---

## Sektion 1 — Plugin-Struktur & Metadaten

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Verzeichnisstruktur passt zum Plugin-Typ | ✅ | Pfad korrekt unter `mod/booking/bookingextension/agent` | bookingextension-Subplugin |
| `version.php` vollständig | ⚠️ | `version.php` | `maturity` + `release` fehlen |
| `$plugin->component` korrekt | ✅ | `version.php:29` `= 'bookingextension_agent'` | exakt = Verzeichnis |
| `$plugin->version` Datumsformat & höher | ✅ | `version.php:27` `2026061704` | monoton |
| `$plugin->requires` gesetzt | ✅ | `version.php:28` `2024100700` | |
| `$plugin->maturity` korrekt | ❌ | nicht deklariert | z. B. `MATURITY_STABLE` ergänzen |
| `$plugin->release` lesbar | ❌ | nicht deklariert | menschenlesbare Version ergänzen |
| `$plugin->dependencies` deklariert | ✅ | `version.php:31-33` `mod_booking` | |
| Keine überflüssigen/Temp-Dateien | ⚠️ | `benchmark_*.php`, `skill_selection_debug.php`, `trial_challenge.php` im Root; `.claude/` | alle mit GPL-Header + Access-Guards; klären ob Admin-Tools mitausliefern |
| Komponentenname kollisionsfrei | ✅ | tree-weit eindeutig | |

## Sektion 2 — Lizenz & rechtliche Grundlagen

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| GPL v3 | ✅ | alle Plugin-Dateien | |
| GPL-Header je PHP-Datei | ✅ | 330 Plugin-Dateien mit Header | 46 ohne Header = `thirdparty/pdfparser/` (legitim ausgenommen) |
| `@copyright/@license/@package` korrekt | ⚠️ | Stichproben ok | Copyright-Jahr 2025/2026 gemischt |
| Bibliotheken mit kompatibler Lizenz dokumentiert | ✅ | `thirdpartylibs.xml` (Smalot PdfParser 2.12.5, LGPL-3) | |
| Keine fremden Markenrechte | ✅ | best-effort | |

## Sektion 3 — Coding Standards & Code-Qualität

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Moodle Coding Guidelines (phpcs `moodle`) | ⚠️ | Container-Lauf: 399 Violations / 39 Dateien | ~96 % kosmetisch & auto-fixbar (s. u.) |
| `phpcs` fehlerfrei | ⚠️ | 327 Errors + 72 Warnings; phpcbf fixt 342 | substanziell nur: 6× `global $PAGE` in Renderer (`question_preview_renderer.php:72,100-102,148,154`), 2 leere catch (`activity_creation_service.php:127`, `agent_decision_service.php:447`), auskommentierter Code (`question_generation_service.php:151`), 10 „forbidden strings". Rest: PSR2-Call-Signaturen 299×, Lang-Sortierung 25×, Zeilenlänge 21×, Kommentar-Großschr. 13× |
| PHPDoc für Klassen/Methoden | ✅ | Stichprobe: `orchestrator.php` 54/54, `agent.php` 9/9, `provider.php` 7/7 | durchgängig (phpcs: 1 fehlende Docblock-Desc, 1 fehlende var-Comment) |
| Keine deprecated Core-Funktionen | ✅ | grep `print_error/add_to_log/get_context_instance/…` → 0 | |
| Keine DB-Zugriffe an der API vorbei | ✅ | (Sek. 4/5) nur `$DB`-API | |
| Konsistente Frankenstyle/Namespaces | ✅ | 221/221 Namespace↔Verzeichnis korrekt | |
| Keine Code-Leichen / `var_dump`/`error_log` | ✅ | `var_dump`/`print_r`/`error_log`/`console.*` = 0; nur 3 bewusste Scaffold-`TODO`s | `skill_template_generator.php:289,309,323` |
| PHP-Lint ohne Syntaxfehler | ✅ | Container `php -l` gesamtes Plugin → 0 Fehler | PHP 8.3.28 |

## Sektion 4 — Sicherheit

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| SQL-Injection | ✅ | `benchmark_*.php`, tree-weit | nur `$DB`-API mit Platzhaltern, keine Konkatenation |
| XSS | ✅ | `benchmark_report.php:256-264`, `skill_selection_debug.php:92,178` | `s()`/`htmlspecialchars()` auf Ausgaben |
| CSRF | ✅ | `benchmark_report.php:45`, `skill_selection_debug.php:59` | `confirm_sesskey()` / hidden sesskey |
| `require_login` an Einstiegen | ✅ | alle Root-Skripte + `cli/*` (`CLI_SCRIPT`) | `trial_challenge.php` bewusst public (Token-Back-Channel) |
| Kontext-/Capability-Prüfung | ⚠️ | `benchmark_*.php:28` | Cap-Inkonsistenz `viewbenchmarks` vs. `site:config` |
| Eingaben via optional/required_param | ✅ | typisierte PARAM-Konstanten durchgängig | |
| File-API statt Pfadzugriff | ✅ | `pdf_text_extractor.php:165,171` (`escapeshellarg`) | |
| Keine sensiblen Daten im Frontend | ⚠️ | `ai_get_thread_debug_logs.php:90-119` | nur im Debug-Mode, aber siehe IDOR B1 |
| Keine Ausführung von Eingaben | ✅ | nur `escapeshellarg`/statischer `shell_exec` | kein `eval`/Input-Include |
| **Objekt-Eigentümerschaft (IDOR)** | ❌ | `ai_poll_thread.php:85-94`, `ai_get_thread_debug_logs.php:91-93` | **Blocker B1** |

## Sektion 5 — Datenbank (XMLDB & Upgrades)

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| `install.xml` wohlgeformt | ✅ | `db/install.xml:1-201` (9 Tabellen) | PKs/FKs/Indizes vorhanden |
| Namenskonventionen | ✅ | durchgängig lowercase | Stil-Mix `metadatajson` vs. `run_uuid` (legal) |
| Sinnvolle Indizes/Schlüssel | ✅ | `useridcontextid`, Unique auf `idempotencykey`/`run_uuid` | |
| `upgrade.php` deckt Schema ab | ✅ | `db/upgrade.php:66-241` | `field_exists`/`table_exists`-Guards |
| Savepoints, monoton, = version.php | ✅ | `db/upgrade.php` … `2026061704` | letzter Savepoint = `version.php:27` |
| `uninstall.php` falls nötig | ➖ | nicht nötig | Core droppt Plugin-Tabellen |
| MySQL/MariaDB + PostgreSQL | ⚠️ | Spalte `…_ai_llm_debug.source` (`install.xml:73`) | Soft-Reserved-Word, via DML gequotet → sicher |

## Sektion 6 — Capabilities & Berechtigungen

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| `db/access.php` definiert Caps | ✅ | `db/access.php:28-217` (4 + 75 `skill_*` + 2) | |
| Namensschema `…/…:aktion` | ✅ | `db/access.php:29,184` | konsistent mit Runtime-Cap-Bau |
| riskbitmask korrekt | ⚠️ | `db/access.php:159` | pauschal auf allen Skills; 4+2 Caps ganz ohne |
| Archetypes sinnvoll | ✅ | `db/access.php:164-216` | |
| Sprachstring je Capability | ❌ | `lang/en/…:25-40` (nur 11 von 75 + 2 Benchmark fehlen) | **66 fehlend** (Wichtig) |
| Caps tatsächlich geprüft | ⚠️ | `skill_executability_evaluator.php:182-204` | `managebenchmarks` tote Cap |

## Sektion 7 — Datenschutz / DSGVO

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Privacy API implementiert | ✅ | `classes/privacy/provider.php:38-41` | metadata + request + userlist provider |
| Request-Provider statt null_provider | ✅ | `provider.php:38-41` | |
| Metadata beschreibt ALLE Daten | ❌ | `provider.php:51-65` vs. `install.xml:7-88` | **Blocker B2** — 4 Tabellen fehlen |
| Externe LLM-Übermittlung deklariert | ❌ | `grep external_location` → 0 | **Blocker B2** — keine `external_location` |
| Export implementiert | ⚠️ | `provider.php:109-139` | nur `user_memory` |
| Löschung implementiert | ⚠️ | `provider.php:147-193` | nur `user_memory` |
| Trial-Consent-Flow (extern) | ✅ | `request_trial_key.php:85-99` + `event/trial_consent_given.php` | Laufzeit-Consent, ersetzt aber nicht die Metadata |
| Privacy-Unit-Tests | ❌ | nur Anonymizer-Tests | kein `provider_testcase` |
| Anonymizer-Einordnung | ➖ | `privacy_anonymizer.php` | Datenminimierung (Mitigation), kein Ersatz für Provider/`external_location` |

## Sektion 8 — Sprache & i18n

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Strings in `lang/en/…` | ✅ | 886 Zeilen | |
| Keine hartcodierten sichtbaren Texte | ✅ | grep `echo '…'` in classes/ = 0; Templates `{{#str}}` | |
| Ausgaben via `get_string`/`lang_string` | ✅ | Templates durchgängig | |
| Platzhalter `{$a}` | ✅ | `lang/en:48` `{$a->count}` | |
| Pflichtstring `pluginname` | ✅ | EN & DE `'Booking Agent'` | |
| EN/DE in sync | ⚠️ | EN ~862 / DE ~825 | 37 DE fehlen |

## Sektion 9 — Barrierefreiheit (statisch)

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| ARIA-Rollen/-Labels | ✅ | `aiinstructions.mustache:79,90,176` | Splitter/Toggle/Spinner |
| Formularfelder mit Labels | ⚠️ | `aiinstructions.mustache:98` | Chat-Textarea nur Placeholder |
| Alt-Texte / dekorative Icons | ✅ | `ai_upload_attachment.php:285`; FA-Icons `aria-hidden` | |
| Info nicht nur über Farbe | ✅ | `diagnostic_checklist_preview.php:40` (✓/✗/⚠) | |
| Tastaturbedienbarkeit | ✅ | native `<button>`/`<a>`; Enter-Handler `aiinstructions.js:2889` | |
| WCAG 2.1 AA / Screenreader | ➖ N/A | statisch nicht verifizierbar | manuell/AT-Test nötig |

## Sektion 10 — UI / UX & Templates

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Mustache statt HTML-in-PHP | ✅ | `templates/*.mustache` (3) via `$OUTPUT->render_from_template` | |
| renderer.php / Output-API | ⚠️ | kein `classes/output/`/`renderer.php` | nutzt globales `$OUTPUT` |
| Responsive | ✅ | `styles.css:167` `@media`, Mobile-Toggle | |
| Boost-kompatibel | ⚠️ | `styles.css` hardcodierte Hex, ein `!important` | theme-naiv, aber gescoped |
| Moodle-UI-Konventionen | ✅ | `core/notification`, `core/modal`, Bootstrap | |
| Keine Inline-Styles | ⚠️ | `aiinstructions.mustache:45,175,194` | wenige, meist Sizing |
| JS als AMD (src→build) | ✅ | 3 src / 3 frische builds (+maps) | nicht stale |
| (HTML-in-PHP für Chat-Previews) | ⚠️ | 31 `html_writer`-Stellen | In-Chat-Fragmente, kein Page-UI |

## Sektion 11 — Automatisierte Tests

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| PHPUnit-Tests vorhanden | ✅ | 82 `*_test.php` (Skills/Services/Anonymizer/Memory/Contracts) | breite Abdeckung |
| Behat-Tests | ➖/❌ | kein `tests/behat/` | keine E2E-UI-Tests |
| Tests laufen (Container-Lauf) | ✅ | 445 Tests, 2184 Assertions, **0 Failures/Errors**, 50 skipped, 89 Deprecation-Notices, exit 0 | ohne Key; Real-LLM-Tests skippen |
| Coverage der Geschäftslogik | ✅ | thematisch breit | |
| Generators | ❌ | kein `tests/generator/` | ggf. mod_booking-Generator genutzt |
| Privacy-Provider-Tests | ❌ | kein `provider_testcase` | nur Anonymizer getestet |
| Real-LLM-Isolation | ✅ | `abstract_agent_testcase.php:653` `markTestSkipped` | keyloser Lauf sicher |

## Sektion 12 — Backup, Restore & Reset

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| `backup/moodle2/` | ❌ | nicht vorhanden | **Blocker B3** (bzw. bewusst dem Parent überlassen — klären) |
| Per-Kontext-Daten reisen mit | ❌ | `ai_threads/_messages/_runs` an `contextid` | nicht im Kursbackup |
| Kurs-Reset | ❌ | kein `after_reset_course_data` | Orphan-Daten |
| Deinstallation sauber | ➖ | Core droppt Tabellen | ok |

## Sektion 13 — Events, Logging & Bewertungen

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Event-Klassen | ✅ | `event/trial_consent_given.php:38-65` | |
| Events ausgelöst | ✅ | `request_trial_key.php:96-99` | KI-Aktionen via `ai_runs`/`ai_llm_debug` statt Events (ok) |
| Scheduled Tasks via Task-API | ⚠️ | `db/tasks.php:27-38`; `cleanup_old_benchmark_runs_task.php:32` | **Task nicht registriert** (Wichtig) |
| Keine Legacy-Cron | ✅ | nur `\core\task\*` | kein dangling `execute_ai_run_adhoc` |
| Gradebook/Kalender/Completion | ➖ N/A | KI-Agent-Subplugin | |

## Sektion 14 — Funktions- & Integrationstests (statisch)

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Saubere Neuinstallation | ✅ | `db/install.xml` | |
| Upgrade von Vorversion | ✅ | `db/upgrade.php` | |
| Deinstallation ohne Reste | ✅ | Core-Drop | |
| Edge Cases / Fehlermeldungen | ✅ | `ai_error_classifier.php`, übersetzte Error-Strings | |
| Behat für Kern-Workflows | ❌ | keine `.feature` | |

## Sektion 15 — Kompatibilität

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Moodle-Versionen | ✅ | `version.php:30` `[500,501]`, `requires 2024100700` | |
| PHP-Versionen | ✅ | CI `min_php 8.2` (5.0/5.1), 8.1 (4.05) | |
| DB-Engines | ✅ | portable XMLDB-Typen | CI deckt PG+MySQL |
| `db/mobile.php` | ➖ N/A | Backend-Agent | korrekt N/A |
| AMD/CSS-Builds | ✅ | `amd/build/*` aktuell | |

## Sektion 16 — Performance & Skalierung

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| MUC-Caching | ✅ | `db/caches.php:27-59` (5 Caches) | sinnvolle Modes/TTLs |
| Caches sinnvoll genutzt | ✅ | `privacy_anonymizer.php:1001-1070` (Namens-Index) | |
| Keine N+1 | ✅ | Stichproben ok | |
| Effiziente Abfragen/Indizes | ✅ | `install.xml`-Indizes | |
| Recordsets für Bulk | ✅ | `upgrade.php:41-52` | |

## Sektion 17 — Web Services / Externe APIs

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Funktionen in `db/services.php`/`external/` | ✅ | `db/services.php:28-128` (10) | |
| Strenge Param/Return-Validierung | ✅ | `*_parameters()`/`*_returns()` durchgängig | |
| Capability je WS-Funktion | ✅ | `capabilities`-Key + `validate_context` | 2 Trial-Endpunkte mit `require_capability` |
| Dokumentiert | ✅ | `description` je Eintrag + phpdoc | |
| `check_use_readiness` an allen 10 Eingängen | ✅ | alle 10 `external/*` | graceful agent_unavailable/context_invalid/permission_denied |

## Sektion 18 — Dokumentation

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| README | ✅ | `docs/README.md` (umfangreich) | 16 Architektur-Kapitel etc. |
| CHANGELOG | ❌ | fehlt | ergänzen |
| Installation/Konfiguration | ✅ | `docs/operations/configuration.md` | |
| Nutzung/Entwickler | ✅ | `docs/developer-guides/`, `docs/reference/` | |
| Known Limitations / Support | ⚠️ | verstreut | zentralisieren |

## Sektion 19 — CI/CD & Build

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| moodle-plugin-ci | ✅ | `.github/workflows/moodle-plugin-ci.yml` | |
| Pipeline-Gates | ✅ | phpunit, phpcs (0 warn), phpdoc, phpmd, phpcpd, mustache, grunt | |
| AMD/CSS-Build reproduzierbar | ✅ | CI pre-build (NVM/grunt) | umgeht Stale-Detection |
| Versionsmatrix | ✅ | Moodle 5.0/5.1 + 4.05, PHP 8.1/8.2 | |

## Sektion 20 — Plugin Directory

| Punkt | Status | Beleg | Anmerkung |
|---|---|---|---|
| Directory-Anforderungen | ⚠️/➖ | `wb_license.php`, `trial_*` | **kommerzielles Lizenz-Plugin** — Publikation vermutlich N/A |
| Öffentliches Repo | ✅ | `.git` vorhanden | |
| Unterstützte Versionen | ✅ | `version.php:30` | |
| Prechecks | ✅ | CI deckt ab | |

---

## Konsolidierte Maßnahmenliste

**Blocker (vor Release):**
1. B1 — Thread-Eigentümer-Gate an allen `threadid`-Eingängen (mind. `ai_poll_thread`, `ai_get_thread_debug_logs`; Cluster SEC-01..03 vollständig schließen).
2. B2 — Privacy-Provider um `ai_threads/_messages/_runs/_llm_debug` erweitern (metadata + export + delete) und `external_location` für die LLM-Übermittlung deklarieren; Provider-Test ergänzen.
3. B3 — Backup/Restore + Kurs-Reset klären/implementieren (oder bewusste N/A-Entscheidung mit Georg dokumentieren).

**Wichtig:** 66 Cap-Sprachstrings; `cleanup_old_benchmark_runs_task` registrieren (+ `task_*`-String); `maturity`/`release` in version.php; 37 DE-Strings; `phpcbf` laufen lassen (fixt 342 Violations) + die ~57 manuellen phpcs-Befunde prüfen (v. a. `global $PAGE` im Renderer, leere catch-Blöcke).

**Geringfügig:** tote `managebenchmarks`-Cap; Benchmark-Cap-Inkonsistenz; riskbitmask differenzieren; Behat-Minimalset; CHANGELOG; styles.css theme-fest machen; Chat-Textarea-Label; Copyright-Jahr vereinheitlichen.

> **Laufzeit-Bestätigung:** Lint (0 Fehler) und PHPUnit (445 Tests grün, 0 Failures) wurden auf dem
> Dev-Container `user@10.111.0.2` (Moodle 5.1.1+, PHP 8.3.28, MariaDB 10.11) ausgeführt; phpcs dort
> mit 399 (überwiegend auto-fixbaren) Violations. Real-LLM-Tests blieben ungesetzt/geskippt.
