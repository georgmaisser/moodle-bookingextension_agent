# Audit-Follow-up — Worklog (2026-06-23)

**Bearbeiter:** Claude (Opus 4.8), zweiter Agent parallel zum Docs-Corpus/Embeddings-Refactor.
**Quelle:** `full_audit_2026-06-23.md`
**Abgrenzung:** Der parallele Agent bearbeitet `docs_corpus_embeddings_refactor_2026-06-23.md`
(Docs-Corpus + beide Embeddings-Stacks). Dieser Worklog deckt **ausschließlich isolierte
Sicherheits-/Korrektheits-Fixes**, die jenen Bereich **nicht** berühren.

## Bewusst ausgelassen (Überschneidung / Policy)
- **Bug 1** (`_similarity`→`score`) → ist deren **D1**.
- Embeddings-CSV-Basisklasse / RFC-4180, `cosine_similarity`-Klone, Readiness/Retrieval/Index,
  `family_*`, `docs_lookup_service`, `explain_docs_skill`, `search_skills`/`list_skills`,
  `settings.php aidocsroot`, `docs_provider` → deren D2/F/A.
- **§7 D1–D6** (Flowchart-Diskrepanzen) → laut `feedback_flowchart_policy` nur Klärung mit Georg,
  nicht eigenmächtig angleichen. **Nicht angefasst.**
- **Bug 2** (`user_input_lang`/`last_output_lang`-Writer) → enthält einen Flowchart-Klärungsanteil
  (CS14/LANG). **Offen gelassen** für Entscheidung mit Georg.

---

## Durchgeführte Änderungen (alle verifiziert, keine Überschneidung)

### 1. Bug 3 — `require_sesskey()` beim Upload-Write-Endpoint
- **Datei:** `classes/external/ai_upload_attachment.php`
- **Vorher:** `execute()` (write: schreibt Temp-File + mintet Token) ohne `require_sesskey()`,
  als einziger Write-Endpoint. Alle übrigen Writes (`ai_send_message`, `ai_confirm_run`,
  `ai_discard_pending`, `ai_privacy_precheck`, …) rufen es auf.
- **Änderung:** `require_sesskey()` direkt nach `global $USER;` ergänzt (idiomatisch wie
  `ai_confirm_run.php:84`). Schließt CSRF-Lücke.
- **Risiko:** Niedrig — Aufruf erfolgt über dieselbe `core/ajax`-Transportschicht wie die anderen
  Writes (sesskey vorhanden).

### 2. Bug 3 (Teil 2) — `ai_poll_thread` Politik dokumentiert
- **Datei:** `classes/external/ai_poll_thread.php`
- **Entscheidung:** Read-only Poll → **kein** `require_sesskey()` (konsistent mit allen anderen
  Read-Endpoints; nur Writes erzwingen sesskey). IDOR-Schutz bleibt über
  `thread_belongs_to_user`. Statt Verhaltensänderung: **Politik als Kommentar dokumentiert**
  (Audit-Empfehlung: „ergänzen oder dokumentieren"). Kein Verhaltenswechsel.

### 3. §5.F — Token-Entropie gehärtet
- **Datei:** `classes/local/wbagent/services/attachment/attachment_token_service.php`
- **Vorher:** `sha1($userid.':'.$contextid.':'.$tmppath.':'.microtime(true).':'.random_int(...))`.
- **Nachher:** `bin2hex(random_bytes(32))` (256 Bit, krypto-stark, unguessable).
- **Kompatibilität geprüft:** Token ist reiner Cache-Key; `resolve()`/`invalidate()` machen nur
  `cache->get($token)` — **keine** Längen-/Format-Annahme. Return-Typ `PARAM_ALPHANUMEXT` bleibt
  erfüllt (Hex). Kein Konsument parst das Format.

### 4. §5.F — Toter, unerreichbarer `else if`-Zweig entfernt
- **Datei:** `classes/local/wbagent/services/preflight_version_validator.php`
  (`resolve_requested_version`)
- **Vorher:** zwei identische `else if (array_key_exists('skill_version', $command))` —
  der zweite Zweig unerreichbar (Copy-paste).
- **Nachher:** Duplikat entfernt. Reine Code-Hygiene, **kein** Verhaltenswechsel.

### 5. §5.F / §6-P0.4 — Geschluckte Exception in `try_mark_running` geloggt
- **Datei:** `classes/local/wbagent/queue/queue_manager.php`
- **Vorher:** `catch (\Throwable $e) { return false; }` — ein echter DB-Fehler war nicht von
  „Slot bereits belegt" unterscheidbar → konnte die Queue still blockieren.
- **Nachher:** `debugging('try_mark_running failed: '.$e->getMessage(), DEBUG_DEVELOPER)` vor
  `return false`. Verhalten (Rückgabe `false`) unverändert, nur Diagnose ergänzt.

---

### 6. §5.E — Verifizierbar toter Code entfernt (jeweils 0 Caller in ganz `mod/booking`)
Vor jeder Entfernung per grep über das gesamte `mod/booking` (inkl. `tests/`, `db/tasks.php`)
geprüft; nach jeder Entfernung Gegen-grep auf Restreferenzen (alle leer). Plugin steht unter
Git → wiederherstellbar.

**Ganze Dateien (`git rm`):**
- `classes/local/wbagent/loop_finalizer.php` (~250 LOC) — kein Caller, keine Task-Registrierung.
- `classes/local/wbagent/services/runtime_step_analysis_service.php` (~171 LOC) — kein Caller,
  dupliziert zudem den Signatur-Normalizer.

**Methoden (in geteilten Dateien, je 0 Caller):**
- `agent_state.php`: `make_resumed`, `extract_observed_command_signatures` + dessen einziger
  Helfer `normalize_command_input`.
- `prompt_policy_builder.php`: `build_trigger_policy` (nur `build_trigger_policy_compact` wird
  genutzt).
- `interpreter.php`: die tote Kopie `hydrate_question_field` (die aktive Kopie lebt in
  `parameter_constructor`) und `normalize_ambiguity_options`.
- `privacy_anonymizer.php`: `scope_identity_key_for_type` (No-op, Docblock log) und
  `get_distinct_name_index` (Subset von `get_user_name_match_index`); zugehörige nun verwaiste
  Konstante `NAME_INDEX_CACHE_KEY` mitentfernt.
- `orchestrator.php`: `build_construction_allowed_skills`, `availability_from_deny_reason`,
  `sanitize_unavailable_skill_catalog`, `build_skill_description_index`,
  `augment_catalog_with_recent_executable_skills`.

**Bewusst NICHT entfernt (Überschneidung mit Embeddings-Agent):**
`embeddings_csv_repository::count_unreadable_rows` und `explain_docs_skill::PLANNER_DIRECT_DOC_SCORE`
liegen im Docs/Embeddings-Bereich → dem Parallel-Agenten überlassen.

### 7. §6-P0.5 — `preflight_audit_logger` komplett entfernt
**Entscheidung Georg (2026-06-23):** Das Preflight-Audit-Logging wurde **bewusst** stillgelegt
(Setting entfernt) → der Logger soll weg, nicht reaktiviert. Damit aufgelöst:
- **Klasse gelöscht:** `services/preflight_audit_logger.php` (`git rm`). `get_events`/
  `summarize_reason_codes`/Setting `preflight_audit_enabled`/Metadatenschlüssel `_preflight_audit_log`
  waren vorab als referenzlos verifiziert.
- **`confirm_run_service.php`:** Konstruktion + alle **5** `append()`-No-op-Aufrufe entfernt (die
  bei jedem Confirm verworfene Payloads bauten); der `try_mark_running`-`if/else` sauber zu
  `if (!…) { to_ready }` invertiert (Seiteneffekt erhalten); nun toter Helfer
  `build_queue_audit_context` entfernt.
- **`preflight_pipeline.php`:** Feld + Ctor-Zuweisung + **2** `append()`-Aufrufe entfernt; nun tote
  Helfer `build_audit_command_context` und `resolve_preflight_reason_code` entfernt. `$batchriskclass`/
  `$errorclass` bleiben (anderweitig genutzt → kein Orphan).
- **Tests nachgezogen:** `tests/agent/contracts/preflight_audit_logger_contract_test.php` gelöscht
  (testete nur die entfernte Klasse); in `integration_agent_framework_test.php` die Methode
  `test_preflight_audit_log_format_contains_reconstruction_fields` entfernt (prüfte nur das
  Logger-Payload-Format).
- **Verifiziert:** Working-Tree-weiter Gegen-grep auf alle Logger-Symbole = leer. Treffer in
  `.claude/worktrees/*` sind **separate Git-Worktrees (andere Branches)** und bewusst unberührt.

### 8. §5.F — `get_history_limit_for_phase`-No-op entschlackt (verhaltenserhaltend)
- **Datei:** `services/orchestrator_prompt_profile_service.php` + `orchestrator.php`
- **Vorher:** Methode berechnete die Phase, verwarf sie (`$ignored = $normalizedphase;`) und gab
  immer `PHP_INT_MAX` zurück; Docblock („history depth per explicit planner phase") log.
  Konstante `orchestrator::MAX_HISTORY_MESSAGES = 12` dadurch komplett ungenutzt.
- **Nachher:** Methodenkörper auf `return PHP_INT_MAX;` reduziert, Docblock ehrlich gefasst
  (phasen-unabhängig & uncapped; Seam bleibt für eine künftige Cap-Wiedereinführung). Tote
  Konstante `MAX_HISTORY_MESSAGES` entfernt.
- **Sloppy-Code-Bereinigung (§5.F)** war der erste Schritt; **Folgeentscheidung Georg (2026-06-23):**
  der History-Cap soll real wieder greifen — siehe Abschnitt 9.

### 9. History-Window-Cap real eingeführt („letzte N + erste User-Message")
Auf Nachfrage verifiziert: es gab **gar keinen** wirksamen Cap — `get_messages()` lieferte die volle
Thread-Historie, `array_slice(-PHP_INT_MAX)` schnitt nichts ab → komplette Historie bei jedem LLM-Call.
Entscheidung: Cap wieder einführen, aber Clarification-sicher (erste User-Anfrage erhalten).
- `orchestrator_prompt_profile_service`: Konstante `HISTORY_TAIL_LIMIT = 14`;
  `get_history_limit_for_phase()` liefert wieder ein echtes Limit; neu
  `select_history_messages()` = **letzte N + erste User-Message** (dedup, max N+1, Tail-only-Fallbacks).
- `phase_prompt_bundle_builder` nutzt die neue Selektion; 3 `$historycount`-Telemetrie-Stellen im
  Orchestrator nachgezogen.
- Tests: Limit-Assertion aktualisiert + 4 neue Fälle für `select_history_messages`.
- Tool-Observations/Planner-Traces unberührt (separat angehängt) → Windowing trifft nur alte
  Gesprächsturns. **Blueprint:** `history_window_cap_2026-06-23.md`.

## Verifikation
- Jeder Befund vor der Änderung am aktuellen Code gegengeprüft (Zeilen/Idiom bestätigt).
- **PHPUnit auf der VM (`user@10.111.0.2`, PHP 8.3.28) ausgeführt** — volle Suite
  `bookingextension_agent_testsuite` nach `init.php`:
  **494 Tests grün** (0 Errors/Failures), 51 Skips (Real-LLM ohne Key — normal),
  105 bekannte `@covers`-Deprecations (ignoriert lt. Playbook). Laufzeit ~2:25.
- **Ein Fund dabei behoben:** der entfernte (produktiv 0-Caller-)Helfer
  `orchestrator::build_construction_allowed_skills` hatte noch einen Reflection-Test
  (`integration_agent_framework_test::test_construction_allow_list_…`). Da produktiv-tot
  (Audit-bestätigt), Test auf die noch lebende `extract_selected_skill_from_selection_phase_output`
  reduziert (Live-Coverage erhalten, vestigiale Assertion entfernt). Kein anderer entfernter
  Symbolname wird in `tests/` referenziert (geprüft).

## Offen / nicht angefasst (für Georg)
- **Bug 2** (lang-Writer) — Flowchart-Klärungsanteil.
- Alle **§7-Diskrepanzen** D1–D6.
- Größere Strukturpunkte (§5.A God-Classes, §5.B Risk-Resolver, §5.E Totcode-Dateien) — bewusst
  nicht im Alleingang; teils Berührung mit dem parallelen Embeddings-Task.

---

*Erstellt 2026-06-23. Fünf isolierte Edits, kein Embeddings-/Docs-Code berührt.*
