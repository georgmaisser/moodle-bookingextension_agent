# bookingextension_agent — Konkreter Refactoring-Plan (2026-06-23)

**Grundlage:** `full_audit_2026-06-23.md` (selber Ordner)
**Autoritative Quelle:** `flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd`
**Status:** PLAN — noch nichts umgesetzt. Jede Phase ist unabhängig shippbar, Suite muss nach jeder Phase grün bleiben.

## Leitprinzipien für diesen Plan
1. **Verhaltenserhaltend, sofern nicht explizit markiert.** Reine Struktur-/Hygiene-Änderungen ändern keine Observables (gleiche Outputs, gleiche DB-Effekte).
2. **§7-Diskrepanzen sind AUSGESCHLOSSEN.** Die 6 Code↔Flowchart-Abweichungen (Audit §7, D1–D6) sind **nicht** Teil dieses Plans — sie brauchen erst Georgs Entscheidung (Memory `feedback_flowchart_policy`). Sie stehen unten unter „🚧 Blockiert".
3. **Tests vor Cut-over.** Bei Extraktionen zuerst Service + Unit-Tests, dann Callsites migrieren, dann Altcode entfernen — nie im selben Schritt.
4. **Keine Real-LLM-Läufe ohne Georgs Go** (Memory `feedback_real_llm_tests_require_ask`). Verhaltenssensible Items (1.5) sind entsprechend markiert.

Aufwandsskala: S ≈ <1h · M ≈ 1–3h · L ≈ halber Tag · XL ≈ mehrtägig.

---

## Phase 0 — Bugfixes & Sicherheit (P0, niedriger Aufwand, echte Wirkung)

### 0.1 — Semantic doc-search reanimieren `[S]`
- **Problem (Audit §4-Bug-1):** `docs_lookup_service.php:151` liest `$hit['_similarity']`; `embeddings_retrieval_service::search_top_k` schreibt nur `'score'` (`:57,69`). Score ist immer 0.0, `SEMANTIC_MIN_SCORE=0.30` verwirft alles → semantische Suche tot.
- **Genaue Stelle:** `classes/local/wizard/services/lookup/docs_lookup_service.php:151`.
- **Vorgehen:** `'_similarity'` → `'score'` ändern. Skalierung prüfen: `search_top_k` liefert Cosine ∈ [0,1]; `:169` macht `round($score*1000)` — sicherstellen, dass `SEMANTIC_MIN_SCORE` (0.30, roh) **vor** der *1000-Skalierung greift (tut es, Filter ist bei `:152`). Keine weitere Anpassung nötig, nur Key-Fix.
- [x] Key `_similarity`→`score` in `docs_lookup_service.php:151`
- [ ] Unit-Test: `search_semantic()` mit gemocktem `search_top_k`-Rückgabewert (score 0.5) liefert ≥1 Treffer über Schwelle
- [ ] Regressionstest: Treffer unter 0.30 wird weiterhin gefiltert

### 0.2 — Turn-Sprache persistieren (`user_input_lang`/`last_output_lang`) `[M]`
- **Problem (Audit §4-Bug-2):** Beide Keys werden gelesen (`language_policy_service.php:62,66`, `confirm_run_service.php:268`), **nie** geschrieben. Kandidat #1/#5 der Policy permanent leer. Bei Planner-Turns fängt Kandidat #2 (`result['user_lang']`) die Sprache; aber **Confirm-/Error-/Template-Pfade ohne Planner-Call** fallen auf `current_language()`→`en` → verletzt „Synchronizer antwortet in Nutzersprache".
- **Genaue Stellen:**
  - Writer für `user_input_lang`: in `agent_runtime::finalize_and_persist_result` bzw. direkt nach erfolgreicher Sprachauflösung — sobald `resolve_output_language()` eine nicht-Fallback-Sprache liefert, via `store->set_thread_metadata_value($threadid, 'last_output_lang', $lang)`.
  - `user_input_lang`: beim Message-Ingest in `classes/external/ai_send_message.php:265` (nach `add_message`). Quelle = die vom Planner deklarierte `user_lang` ist erst NACH dem Loop bekannt → pragmatisch: nach dem Loop, wenn `result['user_lang']` vorliegt, in `user_input_lang` zurückschreiben (persistiert die zuletzt erkannte Nutzersprache für Folge-Turns ohne Planner).
- **Vorgehen:** Eine kleine Methode in `language_policy_service`: `persist_resolved_language(store, threadid, result, resolvedlang)` — schreibt `user_input_lang` aus `result['user_lang']` falls vorhanden, und `last_output_lang` aus `$resolvedlang`. Aufruf aus dem Finalisierungspfad (`agent_runtime`) **und** `confirm_run_service`.
- **Verhalten:** verbessert nur Fälle, die heute auf `en` fallen; Planner-Turns unverändert (Kandidat #2 deckte sie schon).
- [ ] `language_policy_service::persist_resolved_language(...)` ergänzen
- [ ] Aufruf in `agent_runtime` Finalisierung (nach `resolve_output_language`)
- [ ] Aufruf in `confirm_run_service` (nutzt `last_output_lang` Read bei `:268`)
- [ ] Unit-Test: Turn mit `result['user_lang']='de'` → Metadata gesetzt → Folge-Confirm-Turn ohne Planner antwortet `de` statt `en`

### 0.3 — `require_sesskey()` bei Write-Endpoint ergänzen `[S]`
- **Problem (Audit §4-Bug-3):** `ai_upload_attachment.php:91` (registriert als `type=write` in `db/services.php:113`, schreibt Temp-File + mintet Token) ohne `require_sesskey()`; `ai_poll_thread.php:67` ebenfalls ohne.
- **Genaue Stellen:** `classes/external/ai_upload_attachment.php` (Beginn von `execute`, vor Token-Mint), `classes/external/ai_poll_thread.php`.
- **Vorgehen:** `require_sesskey()` als ersten Aufruf nach `validate_parameters()` in `ai_upload_attachment`. Bei `ai_poll_thread` (read, lock-free) entweder ergänzen oder die sesskey-freie Politik im Klassen-Docblock explizit begründen — **Entscheidung an Georg**, da Polling-Frequenz betroffen.
- [x] `require_sesskey()` in `ai_upload_attachment::execute`
- [x] poll: sesskey ergänzen ODER Docblock-Begründung (Georg fragen)
- [ ] Behat/WS-Test: Upload ohne sesskey → Exception

### 0.4 — Stille Queue-Blockade verhindern `[S]`
- **Problem (Audit §5.F):** `queue_manager::try_mark_running` (`:446`) fängt `\Throwable` und gibt `false` zurück ohne Log → ein DB-Fehler ist ununterscheidbar von „Slot belegt" und kann die Queue still stehenlassen.
- **Genaue Stelle:** `classes/local/wizard/queue/queue_manager.php:446–449`.
- **Vorgehen:** `debugging('try_mark_running failed: '.$e->getMessage(), DEBUG_DEVELOPER)` im catch ergänzen (Rückgabe `false` bleibt).
- [x] `debugging()` in `try_mark_running`-catch
- [ ] gleiche Behandlung für `build_input_signature_details` (`:761`) prüfen

### 0.5 — Totes `preflight_audit_logger` entfernen `[M]`
- **Problem (Audit §5.E):** `append()` (`:57`) returnt früh (Setting entfernt); trotzdem bauen alle Callsites große Kontext-Arrays, die verworfen werden — verschwendete Arbeit bei **jedem** Preflight.
- **Genaue Stellen:** `services/preflight_audit_logger.php` (ganze Klasse) + Callsites: `preflight_pipeline.php:144,276`, `confirm_run_service.php:256,289,388,416,623`.
- **Vorgehen:** Entweder (a) Logger + alle Aufrufe **inkl. der Payload-Bauten** entfernen, oder (b) Setting `preflight_audit_enabled` wiederherstellen. Empfehlung (a) — niemand konsumiert `get_events`/`summarize_reason_codes`.
- **Verhalten:** keine Observable-Änderung (Logger schrieb ohnehin nichts).
- [x] Entscheidung (a) entfernen vs (b) reaktivieren — Default (a)
- [x] Callsites + zugehörige `$context = [...]`-Bauten entfernen
- [x] Klasse + ggf. `preflight_audit_logger`-Tests löschen

---

## Phase 1 — Strukturelle Entkopplung (P1, löst Duplikation an der Wurzel)

### 1.1 — `risk_class_resolver`-Service (zentralisiert 6 Kopien) `[M]`
- **Problem (Audit §5.B-HIGH):** Risk-Class-Auflösung 6×: `agent_decision_service.php:1495` + `preflight_pipeline.php:336` (mit Registry-Lookup + R3-Fallback) und schlanke `normalize_risk_class` in `queue_manager.php:874`, `confirm_run_service.php:926`, `queue_command_mapper.php:109` (static), `queue_transition_service.php:597`. LG_RISK fordert genau hier Zentralisierung.
- **Neue Datei:** `classes/local/wizard/services/risk_class_resolver.php` mit:
  - `normalize(string $riskclass): string` — trim + `skill_risk_class::is_valid` + R3-Fallback (ersetzt die 4 `normalize_risk_class`).
  - `resolve_command_risk_class(array $command, skill_registry $registry): string` — Registry-Lookup, unbekannter Skill → R3 (ersetzt die 2 vollen Resolver). Nutzt intern `normalize`.
- **Vorgehen:** Service + Unit-Test zuerst; dann die 6 Callsites auf Delegation umstellen; private Methoden entfernen. `queue_command_mapper`-static-Aufruf: Service via DI oder statische Fassade.
- **Verhalten:** identisch (gleiche R3-Fallback-Semantik überall) — entfernt nur das Drift-Risiko.
- [x] `risk_class_resolver` + Unit-Tests (valide Klasse, leer→R3, unbekannter Skill→R3)
- [x] `agent_decision_service:1495` → delegieren, private Methode raus
- [x] `preflight_pipeline:336` → delegieren
- [x] `queue_manager:874`, `queue_transition_service:597`, `confirm_run_service:926`, `queue_command_mapper:109` → delegieren
- [x] grep-Verifikation: keine lokale `normalize_risk_class`/`resolve_command_risk_class` mehr

### 1.2 — `confirm_run_service::confirm` zerlegen + Runtime-Re-Loop zurückdelegieren `[L]`
- **Problem (Audit §5.A-HIGH):** `confirm()` = 563 Zeilen (`:95–658`); konstruiert intern `orchestrator`/`interpreter`/`agent_runtime` und ruft `run_loop()` (`:449–484`) + eigenes response_type-Rewriting (`:490–534`); zwei gespiegelte catch-Blöcke (`:332–407` ≈ `:574–657`). Decision/Runtime-Logik leckt in einen Application-Service.
- **Genaue Stellen:** `classes/local/wizard/services/confirm_run_service.php:95–658`.
- **Vorgehen (zwei Schritte):**
  1. **Re-Loop zurückgeben an Runtime:** in `agent_runtime` eine öffentliche Methode `run_followup_loop(threadid, contextid, userid): array` ergänzen, die die Inline-Konstruktion (`:449–484`) kapselt. `confirm()` ruft sie statt selbst zu newen. Das spiegelt die Flowchart-Kante `CONF_FOLLOW → RUNLOOP`.
  2. **`confirm()` in private Helfer splitten:** `resolve_run_target()` (Queue-Item/Intent/Commands) · `execute_confirmed_run()` (Run-Erzeugung + Executor) · `classify_execution_outcome()` (Erfolg/Retry/Fehler — nutzt 1.1 + bestehende `build_retry_decision`, siehe 1.3-Hinweis) · `build_followup_response()` (response_type-Rewriting + Follow-up-confirmation). Die zwei catch-Blöcke auf einen gemeinsamen `build_error_payload`-Pfad zusammenführen.
- **Verhalten:** identisch; nur Methodengrenzen + Owner verschieben sich.
- [x] Runtime-Konstruktion zurück an Runtime: `agent_runtime::create_default(registry, store, authz)` ergänzt; `confirm()` ruft sie statt selbst `new orchestrator`/`new interpreter`/`new agent_runtime` (`1512a63`). Eine einzelne `run_followup_loop` passte nicht — `confirm()` nutzt denselben Runtime für beide Branches (continue→`run_loop`, terminal→`finalize_terminal_result`), daher die Factory als saubere Naht. orchestrator/interpreter-Imports aus confirm_run_service entfernt.
- [~] `confirm()` → Helfer: **`resolve_run_target` extrahiert** (Validierungs-/Resolution-Prelude: pending-item, active-item, dependency/retry-waiting-Gates, command-resolution, repeat-guard → terminal-`['result']` oder `['activequeueitemid','commandsforrun']`). `execute_confirmed_run`/`classify_execution_outcome`/`build_followup_response` offen (tief verflochtener try-Block, ~15 geteilte lokale Vars — eigener Schritt, ggf. Kontext-Objekt).
- [x] catch-Block auf wahre Semantik eingedampft (statt „Merge" — es war kein Duplikat). Flowchart-Beleg: Execution-Layer modelliert nur strukturierte Ausgänge `EXC_SUCC`/`EXC_TRANSIENT`(retrybar: nur provider_timeout/transient_io →`Q_RETRY`)/`EXC_DOMAIN`; der Executor fängt intern (`executor.php:289`) und liefert Status. Ein bis `confirm()` durchgereichter Throw ist out-of-contract (kein Flowchart-Knoten) → **terminal**, nie in die Retry-Maschinerie. Der retryable-Zweig im catch war toter Code (`infer_from_issue_codes([])→''∉RETRYABLE`) UND hätte, liefe er je, dem Flowchart widersprochen. Entfernt (verhaltenswahrend, bringt Code näher ans Modell) + klärender Kommentar; `mark_dependents_skipped` unbedingt (Exception ist immer terminal). Georg-Freigabe 2026-06-26.
- [x] bestehende confirm-run-Tests grün (Verhaltens-Anker): ai_confirm_run_contract + r3_skill_e2e + confirm_target_context_note = 6/6 nach jedem Schritt.

### 1.3 — Retry-Decision-Dopplung auflösen `[M]`
- **Problem (Audit §5.B-HIGH):** `confirm_run_service::build_retry_decision` (`:853–918`) re-implementiert R3-no-retry + Execution-Gate + Backoff aus `queue_transition_service::to_retry_waiting`/`apply_preflight_decision`; widersprüchliche Grenzen (Exponent-Cap 8 vs 30).
- **Vorgehen:** `confirm_run_service` ruft `queue_transition_service::to_retry_waiting(...)` und konsumiert dessen Ergebnis statt eigener `next_retry_at`/`backoff_ms`-Berechnung. Backoff lebt allein in `preflight_execution_gate` (siehe 2.3).
- **Verhalten:** vereinheitlicht die Backoff-Kurve — **leichte Observable-Änderung** dort, wo die beiden Pfade heute divergieren (Cap 8 vs 30). Mit Tests absichern; falls die Divergenz beabsichtigt war → Georg.
- [ ] `build_retry_decision` durch Aufruf von `queue_transition_service` ersetzen
- [ ] Backoff-Grenzen vereinheitlichen (an `preflight_execution_gate`-Konstanten)
- [ ] Test: Retry-Backoff-Sequenz identisch über beide Eintrittspfade

### 1.4 — `orchestrator.php` (3215 LOC) entlang 4 Nähte aufteilen `[XL]`
- **Problem (Audit §5.A-HIGH):** God Class, 12 Methoden >80 Zeilen.
- **Genaue Extraktionen** (Methoden aus der Audit-Methodenkarte):
  - **(a) `discovery_phase_service`** ← `run_discovery_phase` (`:581–1016`, 436 Z.) + Embeddings/Family-Verdrahtung. Größter Hebel.
  - **(b) `runtime_context_block_builder`** ← `build_runtime_context_block` (`:2507–2689`, 183 Z.) + alle `append_*`-Helfer (`:2727–2955`) + `describe_page_family` (`:2829`) + `memory_channel_for_phase` + Memory-Injection. **Constraint:** der [SYSTEM_RUNTIME]/[SYSTEM_RUNTIME_STATE]-Cache-Split (LG_RCTX, Memory `project_prompt_runtime_block_split`) muss exakt erhalten bleiben — stabile Zeilen oben, volatile unter History.
  - **(c) `planner_catalog_service`** ← `slim_prompt_catalog_for_planner`, `sanitize_runtime_catalog_for_prompt`, `render_catalog_as_text`, `compact_catalog_*`, `filter_catalog_by_selected_families` (`:1874–2308`, `:2980`).
  - **(d) `provider_status_service`** ← `get_runtime_provider_status` (`:185–355`, 171 Z.).
- **Vorgehen:** eine Naht pro PR, je mit Tests. `orchestrator` behält `process`/`process_synchronizer`/`run_selection_phase`/`run_construction_phase` als dünnen Koordinator und hält die neuen Services per DI. **Reihenfolge:** zuerst (d) provider_status (am isoliertesten), dann (c) catalog, dann (b) context-block, zuletzt (a) discovery (am meisten verflochten).
- **Verhalten:** identisch (reine Methoden-Verschiebung).
- [x] (d) `provider_status_service` — Delegator behält Public-API (Caller unverändert); strict_types-Regress `(int)$context->instanceid` gefunden+gefixt via Real-LLM (Commits `9f7fab1`+`d7c498b`)
- [x] (c) `planner_catalog_service` — 12 Methoden als Delegatoren; White-Box-Tests auf den Service umgezogen (`a1c7e85`)
- [x] (b) `runtime_context_block_builder` — Cache-Split erhalten; Modul-Kontext-Pfad real-LLM-grün (`177038d`)
- [x] (a) `discovery_phase_service` — `run_discovery_phase` (~415 Z.) verbatim extrahiert (`89729d6`). Sauberer Move wurde möglich, weil die geteilten Katalog-/Contract-Helfer zuvor in `planner_catalog_service` gefaltet wurden (`3939829`): discovery hängt jetzt nur noch an injizierbaren Services (catalogsvc/runtimecontextsvc/promptbundlebuilder) + Feldern (store/registry/routingsvc/promptprofilesvc). `build_system_prompt`/`build_prompt` als dünne Wrapper im Service repliziert (userid/contextid-Swap), `is_first_assistant_turn`+`json_encode_or_empty` dupliziert, discovery-only-Helfer mitverschoben. **PHPUnit grün (553/0); Real-LLM-Planner grün** (`get_current_user` 1/1 readonly + `normal_option_datetime` 2/2 mutierende Serie, Model `wunderbyte-privat`) — skill_call→confirmation_request-Kette→create_option ohne issue_codes/errors. strict_types-Audit: einziger `$context->id`→int-Pfad bereits gecastet. (Hinweis: `wunderbyte-trial`-Key war quota-erschöpft → erst mit `wunderbyte-privat` verifizierbar.)
- [x] `orchestrator` auf Koordinator-Rolle reduziert, <800 LOC — **2944 → 804 LOC** (−2140, **−73%**). Nach (a)/(b)/(c)/(d) zusätzlich `planner_phase_service` (run_selection_phase + run_construction_phase verbatim, gekoppelt über den Handoff → ein Service mit 8 Kollaboratoren inkl. `interpreter`); 10 phasen-lokale Helfer mitverschoben (Selection-Normalisierung, Construction-Catalog-Enrichment, Contract-/Provider-Error-Payloads, Handoff-Observations). Provider-Error-Builder bleiben zusätzlich im Orchestrator (von `process_synchronizer` geteilt, dupliziert). Anschließend tote Delegatoren (build_system_prompt/build_prompt + 12 Catalog-Delegatoren + normalize_for_observation_dedup/append_json_list_section/json_encode_or_empty) + 18 ungenutzte Imports entfernt. Orchestrator hält jetzt nur noch `process`/`process_synchronizer`/3 Phasen-Delegatoren/Synchronizer-Routing/Prompt-Template-Statics/2 geteilte Helfer. **PHPUnit grün (553/0); Real-LLM-Planner grün** (3/3, `wunderbyte-privat`). 5 White-Box-Tests auf `planner_phase_service` umgestellt. confirm_run (§1.2) bleibt separater Schritt.

### 1.5 — Engine-Agnostik: skill-spezifisches Routing aus dem Orchestrator `[L]` 🟡 verhaltenssensibel
- **Problem (Audit §5.C-HIGH):** `orchestrator.php:1922–2075` — `ensure_doc_skill_for_doc_intent` hartkodiert `explain_docs_skill::SKILL_NAME`, `ensure_list_skills_for_capability_intent` hartkodiert `list_skills_skill::SKILL_NAME`, gegated durch de/en-Keyword-Heuristiken. Verstößt gegen LG_AGN/LG_DET + Memory `feedback_agnostic_prompts_no_skill_specifics`.
- **Vorgehen-Optionen:**
  - **A (sauber, konzept-konform):** Auf die semantische Discovery vertrauen — `explain_docs`/`list_skills` über Embeddings-Intent surfacing, Keyword-Forcing ersatzlos streichen. Risiko: Recall ohne Embeddings-Pfad.
  - **B (pragmatisch):** Beide Skills als `governance => ['always_available' => true]` in ihrem `get_schema()` deklarieren (Mechanismus existiert: `adaptive_skill_catalog_service::get_mandatory_skills` `:115`). Dann sind sie immer im Katalog statt keyword-gegated — entfernt Skill-Namen + Sprachlisten aus der Engine. Kostet etwas Token-Budget (immer-include).
- **Empfehlung:** B, weil ohne Embeddings-Fallback (LG_DET) abgesichert; Token-Kosten gegen Benchmark prüfen.
- **⚠️ Diese Änderung verändert Routing-Verhalten** (keyword-gegated → immer/semantisch). Memory `feedback_agnostic_prompts_no_skill_specifics`: „nicht überstürzt ändern, erst Plan zeigen". → **Vor Umsetzung mit Georg bestätigen + Benchmark/Real-LLM (nur mit Go).**
- [x] Entscheidung A vs B mit Georg
- [x] `explain_docs`/`list_skills` Schema-Flag (B) bzw. Heuristik-Streichung (A)
- [x] `ensure_*_for_*_intent` + `looks_like_*_intent` (4 Methoden) aus Orchestrator entfernen
- [ ] Benchmark: Doc-/Capability-Intents werden weiterhin korrekt geroutet (Go nötig)

### 1.6 — Embeddings-Basisklassen (fixt zugleich Docs-RFC-4180/atomic) `[L]`
- **Problem (Audit §5.B-HIGH + §5-C2-HIGH):** Zwei ~80% identische Stacks. Der RFC-4180-escape=''-Fix + atomarer Write existiert **nur** im Skill-Katalog-Repo; das Docs-Repo (`docs_embeddings_csv_repository`) nutzt PHP-Defaults (`fgetcsv($handle)` `:84,91`, `fputcsv($handle,…)` `:164,170`) ohne Round-Trip-Validierung → **latenter Datenverlust** bei `embedding_json` mit `\/`, `\"`, `\uXXXX`.
- **Genaue Stellen:** `embeddings_csv_repository.php` (gehärtet) vs `services/lookup/docs_embeddings_csv_repository.php` (ungehärtet). Identische `headers_match()` (`:264`≡`:209`), `get_default_file_permissions()` (`:283`≡`:228`).
- **Neue Datei:** `classes/local/wizard/services/embeddings_csv_repository_base.php` (abstract):
  - `protected const CSV_ESCAPE = ''`; `abstract protected function headers(): array`; `abstract protected function key_field(): string` (bzw. Schema-Check-Hook).
  - gemeinsam: `read_rows`/`parse_file` (escape=''), `write_rows` (atomar: temp → round-trip-validate → swap), `is_valid_schema`, `exists`, `get_csv_path`, `headers_match`, `get_default_file_permissions`.
  - `embeddings_csv_repository` und `docs_embeddings_csv_repository` extends base; Docs ergänzt `read_rows_for_corpus`/`delete_corpus`.
- **Vorgehen:** Base + Test (insb. der Backslash-JSON-Round-Trip, der die Regression reproduziert); beide Repos umstellen; danach gewinnt Docs die Härtung „by construction".
- **Optional gleicher Schritt:** `embeddings_readiness_base` (`is_*_available` `:45`≡`:41`) + `embeddings_index_base` (Rebuild-Skelett). Kann als eigener PR folgen.
- **Verhalten:** Skill-Katalog identisch; **Docs-Repo wird robuster** (vorher stiller Zeilenverlust) — gewünschte Verbesserung, deckt EMB_CATALOG-Contract jetzt auch für Docs ab.
- [x] `embeddings_csv_repository_base` + Round-Trip-Regressionstest (Backslash-JSON)
- [x] `embeddings_csv_repository` extends base
- [x] `docs_embeddings_csv_repository` extends base (erbt escape='' + atomic write)
- [ ] (optional) `embeddings_readiness_base` + `embeddings_index_base`

### 1.7 — `list_skills`/`search_skills` von Engine-Interna entkoppeln `[L]` 🟡
- **Problem (Audit §5.C-HIGH):** `list_skills_skill.php:179` newt `skill_registry_factory::get_default()` + `new skill_executability_evaluator(... new authorization_service())`; `search_skills_skill.php:148–216` newt zusätzlich `embeddings_readiness_service`/`embeddings_retrieval_service`/`llm_call_service`/`conversation_store`. Verstößt gegen „Skills referenzieren die Engine nicht" (Memory `project_wizard_local_plugin_extraction`).
- **Vorgehen:** Engine-seitig je einen schlanken, injizierbaren Service bereitstellen: `skill_introspection_service` (Capability-Snapshot) und `skill_discovery_service` (RAG-Lookup). Die Skills erhalten ihn via Konstruktor/Setter (wie `recall_memory_skill` `set_runtime_threadid`) statt selbst zu newen.
- **Hinweis:** relevant für die geplante `local_wizard`-Auskopplung (Skills dürfen Engine nicht referenzieren) — daher hier mitgeplant, aber **Reihenfolge mit dem Auskopplungs-Blueprint abstimmen**.
- [x] `skill_introspection_service` + Injection in `list_skills_skill`
- [x] `skill_discovery_service` + Injection in `search_skills_skill`
- [x] grep: keine `*_factory::get_default()`/`new *_service()` mehr in `*/skills/*`

### 1.8 — `course_targeted_skill`-Trait + `diagnostic_checklist_builder` `[L]`
- **Problem (Audit §5.B-HIGH):** Cross-Context-Boilerplate (`supports_target_context`/`get_target_context_level`/`get_target_selector`/`get_required_native_capabilities`) verbatim in 5 Course/Quiz/Question-Skills; `clarify()`/`build_error_result()` byte-identisch in 4; Diagnose-`row()`/Glyph-Loop/`error_result()` 5×.
- **Genaue Stellen:**
  - Course-Target-Boilerplate: `add_activity_skill.php:81–115`, `update_activity_skill.php:80–113`, `add_quiz_skill.php:83–117`, `update_quiz_skill.php:79–112`, `generate_questions_skill.php:88–124`.
  - `clarify()`: add_activity:625 / update_activity:552 / add_quiz:537 / update_quiz:583. `build_error_result()`: + generate_questions:738.
  - Diagnose-Gerüst: `diagnose_permissions/notifications/access/enrolment/grades`. Foundation existiert bereits: `diagnostics/diagnostic_checklist_preview.php`, `diagnostics/diagnostic_link_builder.php`.
- **Vorgehen:**
  - Neue abstrakte Klasse `course/skills/course_targeted_skill.php extends core_skill_base` (oder Trait, falls Mehrfachvererbung nötig) — trägt die 4 Cross-Context-Hooks (Default `CONTEXT_COURSE` + `courseid`/`coursequery`-Selector + `moodle/course:manageactivities`) und `clarify()`/`build_error_result()`. Die 4 Activity/Quiz-Skills + generate_questions erben.
  - `diagnostics/diagnostic_checklist_builder.php` ergänzen: `row(label, status, detail, ?url)` + `build_result(rows, …)` (Glyph-Loop) + `error_result()`. Die 5 Diagnose-Skills konsumieren statt eigener Kopien.
- **Verhalten:** identisch; nur DRY.
- [ ] `course_targeted_skill` (Cross-Context + clarify + build_error_result)
- [x] 5 Skills auf die Basis umstellen, lokale Kopien entfernen
- [x] `diagnostic_checklist_builder` (row/build_result/error_result)
- [x] 5 Diagnose-Skills auf Builder umstellen
- [x] `build_source_clarification` (add_quiz/update_quiz) → `quiz_question_service`

---

## Phase 2 — Hygiene (P2)

### 2.1 — `['skill'] ?? $x['skill']`-Selbstreferenz-Sweep (63×) `[M]`
- **Problem (Audit §5.F):** 63 Treffer quer durch alle Cluster — zweiter Operand identisch, unerreichbar. Massen-Rename-Artefakt.
- **Vorgehen:** Pro Treffer den **intendierten** zweiten Key ermitteln (vermutlich `?? $x['skill_name']` oder `?? $x['name']`) — **nicht** blind das `??` streichen, da der Fallback fachlich gemeint gewesen sein könnte. Stichprobe an 3 Stellen klären, dann konsistent anwenden.
- [x] Intendierten Fallback bestimmen (Stichprobe `queue_command_mapper`, `orchestrator`, `result_payload_summarizer`)
- [x] Sweep + grep-Verifikation (0 Selbstreferenzen)

### 2.2 — Toten Code entfernen (~210+ LOC) `[M]`
- **Stellen (Audit §5.E):** `loop_finalizer.php` (ganze Datei), `runtime_step_analysis_service.php` (ganze Datei), `orchestrator.php` 6 Methoden (`:1506,3025,3085,3097,3155` + `interpreter.php:977,1215`), `prompt_policy_builder.php:175`, `agent_state.php:122,319`, `privacy_anonymizer.php:1299` (No-op) + `:1094` (Subset), `embeddings_csv_repository.php:135`, `explain_docs_skill.php` `PLANNER_DIRECT_DOC_SCORE`.
- **Vorgehen:** Pro Element grep-Verifikation „0 Caller" (Audit hat das bereits getan, vor Löschung erneut bestätigen — Stand kann sich geändert haben), dann löschen. `loop_finalizer` (250 LOC): vor Löschung prüfen, ob die „early sufficient"-Idee noch gewünscht ist (Audit-Hinweis: `run_loop` finalisiert read-only-sufficient derzeit nicht früh) — **falls ja, ist das ein Feature-Gap für Georg, nicht löschen**.
- [ ] Re-grep jedes Elements (0 Caller)
- [x] `loop_finalizer`: löschen ODER als Feature-Gap an Georg eskalieren
- [x] restliche tote Methoden/Dateien entfernen
- [x] `privacy_anonymizer::scope_identity_key_for_type` (No-op) + `get_distinct_name_index` (Subset) entfernen

### 2.3 — Backoff/TTL-Konstanten zentralisieren `[S]`
- **Problem (Audit §5.F):** `queue_transition_service.php:127` hartkodiert `min(4000,500*2^min(8,…))` vs `preflight_execution_gate`-Konstanten (Exponent-Cap 30); TTLs 300/900 als Literale in `resolve_blocked_ttl_seconds`.
- **Vorgehen:** Einzige Backoff-Quelle = `preflight_execution_gate::evaluate`; `queue_transition_service` ruft sie statt eigener Formel (überlappt mit 1.3). TTLs als benannte Konstanten `BLOCKED_TTL_R1/R2/R3` in `queue_manager`.
- [ ] Backoff nur noch in `preflight_execution_gate`
- [ ] benannte TTL-Konstanten (900/300/900)

### 2.4 — `normalize_*`-Klone + `cosine_similarity` konsolidieren `[M]`
- **Stellen (Audit §5.B):** `normalize_phase_trace` (`conversation_store:677`≡`message_persistence_service:116`); `normalize_issue_codes` (`synchronizer_input_builder:296`≡`synchronizer_output_contract:342`, auch `finalization_classifier`/`finalization_template_service`); `cosine_similarity` 3× (`embeddings_retrieval_service:233`, `family_embeddings_retrieval_service:155`, `skill_selection_debug_service:418`); Command-Input-Normalizer 3× (`agent_state:360`, `runtime_step_analysis_service:155`, `execution_observation_ledger:261` — 2 davon entfallen mit 2.2).
- **Vorgehen:** `services/vector_math.php` (cosine); ein gemeinsamer `normalize_issue_codes`-Helper (Trait oder Util); `normalize_phase_trace` in eine Quelle (z.B. `message_persistence_service`, `conversation_store` delegiert).
- [x] `vector_math::cosine_similarity` + 3 Callsites
- [x] `normalize_issue_codes` Util + 4 Callsites
- [x] `normalize_phase_trace` einquellig

### 2.5 — ANON/Email-Regex als Konstanten `[S]`
- **Problem (Audit §5.B):** ANON_USER-Regex 5× (`privacy_anonymizer:145,306,421,464,1335`), Email-Regex 4× (`:610,701,907`) — Drift-Risiko Matcher↔Resolver.
- [x] `const ANON_TOKEN_PATTERN` / `const EMAIL_PATTERN` + alle Vorkommen darauf

### 2.6 — UI-Strings → lang strings `[M]` 🟡
- **Problem (Audit §5.F):** Neuere Skills (add/update_activity, add/update_quiz, generate_questions, diagnose_*) und `ai_discard_pending:126`, `ai_upload_attachment:161`, `interpreter:940,966` (DE) geben user-facing Literale direkt zurück; ältere R0-Core-Skills nutzen korrekt `get_string`.
- **Hinweis:** Der Synchronizer formuliert ohnehin in Nutzersprache → Priorität MED, aber Observation/`usermessage`-Literale bleiben sonst EN/DE. Mit 0.2 (Sprach-Persistenz) zusammen sinnvoll.
- **Vorgehen:** Strings nach `lang/en/bookingextension_agent.php` ziehen, Skills auf `get_string`/`localized_string` umstellen (Muster der Core-Skills).
- [ ] lang-Keys ergänzen
- [ ] Skills/Endpoints umstellen
- [ ] `interpreter`-DE-Fallbacks (`:940,966`) entschärfen

### 2.7 — Domain-Feldnamen aus der Engine per Hook `[L]` 🟡
- **Problem (Audit §5.C-MED):** Booking-Feldnamen in Engine-Klassen: `privacy_anonymizer.php:59–61` (`optionquery`/`teacherquery`/`targetuserquery`), `result_payload_summarizer.php:149–178,191,421` (booking-Kategorien), `parameter_constructor.php:58–69,78` (Booking-Timestamps/User-Felder).
- **Vorgehen:** Klassifikation/Normalisierung über `domain_normalizer_hook` (DNORM) bzw. provider-deklarierte Feldlisten statt Literale. Relevant für `local_wizard`-Auskopplung.
- **⚠️ Berührt Anonymizer (sicherheitskritisch) + Construction:** sorgfältig, mit Anonymizer-Tests; **Reihenfolge/Umfang mit Georg & Auskopplungs-Blueprint abstimmen.**
- [ ] Provider-Feldlisten-Hook definieren
- [ ] `privacy_anonymizer` Feldlisten über Hook (Anonymizer-Tests!)
- [ ] `result_payload_summarizer` Kategorien über Contributor (Fallback entdomänisieren)
- [ ] `parameter_constructor` über DNORM statt Feld-Literale

### 2.8 — Verstreute Kleinigkeiten `[S]`
- `preflight_version_validator.php:129–132` doppelter `elseif` (unerreichbar) entfernen.
- `finalization_classifier.php:133` + `execution_feedback_service.php:512` No-op-Conditionals kollabieren.
- `orchestrator_prompt_profile_service::get_history_limit_for_phase` (`:85`, No-op `PHP_INT_MAX`) — echte Limits implementieren ODER Phase-Param + `MAX_HISTORY_MESSAGES` entfernen (entscheiden: war Limitierung gewollt? → tendenziell Georg).
- `attachment_token_service.php:54` `sha1(random_int)` → `bin2hex(random_bytes(32))`.
- `userid=2`-Admin-Fallback (`family_embeddings_index_service:136`, `docs_embeddings_index_service:102`) robuster machen.
- `ws`-Reason-Map + Readiness-Error-JSON → `ws_error_response`-Helper (Audit §5.B-MED).
- [ ] doppelter elseif + 2 No-op-Conditionals
- [ ] `get_history_limit_for_phase` klären/fixen
- [x] Token-Entropie + admin-Fallback
- [ ] `ws_error_response`-Helper + Callsites

---

## 🚧 Blockiert — NICHT in diesem Plan (brauchen Georgs Entscheidung)

Diese stammen aus Audit §7 (Code↔Flowchart-Diskrepanzen) bzw. sind verhaltensändernd; gemäß Flowchart-Policy **nicht** eigenmächtig:

- **D1** Finalization-Classifier-Sets sind Supersets der LG_MATRIX → Flowchart nachziehen oder Code reduzieren?
- **D2** R1-Domain-Timeout-Retry außerhalb des L3-Gates → intendiert?
- **D3** R2/R3-Synchronizer-Notices nur prompt-seitig, nicht post-validiert → harter Contract?
- **D4** Memory-Namespace `wizard.*` vs `core.*` im Flowchart.
- **D5** Family-first vs Skill-Top-K-Reihenfolge.
- **D6** `state.currentstep` nie gesetzt (LOOP_STEP).
- **1.5 / 2.6 / 2.7** sind zwar geplant, aber verhaltens-/sicherheitssensibel → vor Umsetzung Go.
- **0.3-poll** sesskey-Politik für `ai_poll_thread`.
- **2.2-loop_finalizer** mögliches Feature-Gap (early-sufficient-Finalisierung).

---

## § Stimmigkeitsprüfung gegen Flowchart & Gesamtkonzept

Jede strukturelle Änderung gegen den autoritativen Flowchart geprüft — verbessert sie das Konzept, ist sie verhaltenserhaltend?

| Item | Flowchart-Bezug | Verhaltenserhaltend? | Verbessert Konzept? | Urteil |
|------|-----------------|:--------------------:|:-------------------:|--------|
| 0.1 doc-search | EMB/lookup (semantic-primary) | ✅ (reanimiert intendiertes Verhalten) | ✅ macht den dokumentierten Pfad real | **stimmig** |
| 0.2 Sprach-Persistenz | CS14, LANG, „Synchronizer in Nutzersprache" | ✅ verbessert nur en-Fallback-Fälle | ✅ erfüllt CS14-Vertrag | **stimmig** |
| 0.3 sesskey | ENTRY (`require_sesskey()`-Kante) | ✅ | ✅ schließt CSRF-Lücke | **stimmig** (poll → Georg) |
| 0.4 try_mark_running-Log | Q_RUNNING (atomar, 1 running) | ✅ | ✅ Observability | **stimmig** |
| 0.5 audit_logger raus | — (nicht im Flowchart) | ✅ (schrieb nie) | ✅ entfernt Totlast | **stimmig** |
| 1.1 risk_class_resolver | LG_RISK „enforced in preflight/queue/decision/sync" | ✅ gleiche R3-Semantik | ✅ **genau die geforderte Zentralisierung** | **stimmig** |
| 1.2 confirm_run zerlegen | CONF_FOLLOW→RUNLOOP | ✅ | ✅ Service delegiert an Runtime, wie Flowchart | **stimmig** |
| 1.3 Retry-Dopplung | LG_RETRY, PF_L3 | 🟡 vereinheitlicht Backoff (kann divergieren) | ✅ eine Retry-Quelle | **stimmig, mit Test-Vorbehalt** |
| 1.4 orchestrator-Split | ORCH-Subgraph (schon service-dekomponiert) | ✅ | ✅ folgt vorhandener Dekompositions-Philosophie; LG_RCTX-Cache-Split bleibt | **stimmig** (Split-Snapshot-Test Pflicht) |
| 1.5 Agnostik-Routing | LG_AGN/LG_DET „no provider-specific routing heuristics" | 🟡 Routing ändert sich | ✅ **direkt konzept-mandatiert** | **stimmig, aber Go nötig** |
| 1.6 embeddings-base | EMB_CATALOG „escape='' read+write, atomic" | ✅ Skill-Katalog; Docs wird compliant | ✅ deckt Vertrag jetzt für beide Stacks | **stimmig** (behebt Diskrepanz) |
| 1.7 skills↔engine | LG_3P, Auskopplungs-Blueprint | ✅ | ✅ „Skills referenzieren Engine nicht" | **stimmig** (Reihenfolge mit Auskopplung) |
| 1.8 Skill-Basis/Diagnostics | SKILLS-Subgraph (BSKILL/CSKILL „focused skill set") | ✅ | ✅ DRY ohne Vertragsänderung | **stimmig** |
| 2.1–2.5, 2.8 Hygiene | — | ✅ | ✅ | **stimmig** |
| 2.6 UI-strings | LG_LANG | ✅ | ✅ | **stimmig** |
| 2.7 Domain-Hooks | DNORM, LG_AGN | 🟡 Anonymizer sicherheitskritisch | ✅ Engine-Agnostik | **stimmig, mit Vorbehalt + Go** |

**Kohärenz-Befund:** Alle 8 P1-Items und alle P0/P2-Items ziehen das Subsystem **in Richtung des bestehenden Flowchart-Konzepts** — kein Item führt ein neues Pattern ein, das dem Diagramm widerspricht. Drei Items (1.5, 2.6, 2.7) berühren Verhalten/Sicherheit und sind ausdrücklich Go-pflichtig. Die zwei Items, die Flowchart-Wording berühren (1.6 schließt die EMB_CATALOG-Lücke; alles andere lässt das Diagramm unangetastet), sind keine Diskrepanzen, sondern **Annäherungen an den dokumentierten Soll-Zustand**. Echte Diskrepanzen (D1–D6) sind sauber separiert und blockiert.

**Empfohlene Sequenz:** P0 (0.1→0.5) → 1.1 (entlastet 1.2/1.3) → 1.2+1.3 → 1.6 (isoliert, behebt Datenverlust) → 1.4 (d→c→b→a) → 1.8 → 1.7 (mit Auskopplung) → 1.5 (nach Go) → P2-Hygiene laufend.

*Erstellt 2026-06-23. Kein Code geändert.*
