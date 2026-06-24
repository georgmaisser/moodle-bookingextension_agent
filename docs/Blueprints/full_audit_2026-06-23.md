# bookingextension_agent — Vollständiges Audit (2026-06-23)

**Auditor:** Claude (Opus 4.8), 7 parallele Tiefen-Audits je Subsystem-Cluster
**Autoritative Quelle:** `docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd`
**Scope:** `classes/` (226 PHP-Dateien, ~57.166 LOC) + `db/`, `lib.php`, `classes/external/`, `classes/task|event|privacy`

> **Flowchart-Policy (Memory):** Code↔Flowchart-Diskrepanzen werden hier **nur gemeldet**, nicht eigenständig angeglichen. Abschnitt §7 listet sie gesammelt zur Klärung mit Georg. Dieses Dokument ändert **keinen** Code.

---

## 1. Executive Summary

Das Subsystem ist architektonisch **erstaunlich flowchart-treu**: Loop (MAX_LOOP_STEPS=6), Zwei-Phasen-Planner-Contract, Risk-Class-Gating (R0–R3, TTLs 900/300/900s), Preflight-Layer-Aktivierung nach Risk, Guard-Token-Bindung, DAG/Idempotenz, duale Discovery (Embeddings + deterministischer Fallback), graceful Readiness-Gate, IDOR-Schutz und die Anonymizer-Vertragspunkte sind **alle implementiert und korrekt**. Das Skill-Layer hält den Executor sauber, und Risk-Class⇔read_only ist durchgängig validiert.

Die Schulden konzentrieren sich auf **fünf wiederkehrende Muster**:

| # | Thema | Schweregrad | Kurz |
|---|-------|-------------|------|
| A | **Zwei echte Funktionsdefekte** | 🔴 HIGH | Semantic doc-search permanent tot (`_similarity` vs `score`); `user_input_lang`/`last_output_lang` haben keinen Writer → autoritative Sprachquelle leer |
| B | **God Classes** | 🔴 HIGH | `orchestrator.php` (3215 LOC, 12 Methoden >80 Zeilen), `confirm_run_service::confirm` (563 Zeilen!), `agent_decision_service` (5 God-Methoden), `privacy_anonymizer` (2 God-Methoden) |
| C | **Systematische Duplikation** | 🟠 MED–HIGH | Risk-Class-Resolver 6×, zwei ~80% kopierte Embeddings-Stacks (Skill-Katalog vs Docs), 5× kopiertes diagnose-Gerüst, `cosine_similarity` 3×, diverse `normalize_*`-Klone |
| D | **Engine-Agnostik-Lecks** | 🟠 MED–HIGH | Skill-spezifisches Routing im Orchestrator (`explain_docs`/`list_skills` + de/en-Keywords), Booking-Domain-Feldnamen im Anonymizer/Summarizer, Skills greifen in Engine-Interna (`list_skills`, `search_skills`) |
| E | **Sloppy-Code-Flächenbrand** | 🟡 LOW–MED | **63×** `['skill'] ?? $x['skill']`-Selbstreferenz (Massen-Rename-Artefakt), ~210 LOC toter Code allein im Orchestrator/Interpreter, geschluckte Exceptions ohne `debugging()`, hartkodierte EN/DE-UI-Strings in neueren Skills, totes `preflight_audit_logger` baut bei jedem Preflight verworfene Payloads |

**Priorisierung:** Erst §6-P0 (zwei Bugs + sesskey-Lücke) — niedriger Aufwand, echte Wirkung. Dann §6-P1 (God-Class-Zerlegung + Risk-Resolver-Zentralisierung + Embeddings-Basisklasse), die den Großteil der Duplikation strukturell auflösen.

---

## 2. Methodik & Inventar

7 Cluster, je ein Tiefen-Audit (jede Datei vollständig gelesen, Compliance gegen Flowchart-Knoten-IDs geprüft):

1. Runtime-Loop-Core · 2. Orchestrator + Interpreter · 3. Decision + Queue + Preflight + Confirm · 4. Skill-Layer + konkrete Skills · 5. Discovery + Embeddings + Catalog · 6. Store + Privacy + Synchronizer + Support · 7. External + Entry/Auth + Infra

### Größenverteilung (Top-Dateien)

| LOC | Datei | Befund |
|----:|-------|--------|
| 3215 | `orchestrator.php` | 🔴 God Class — ≥8 Verantwortlichkeiten, 12 Methoden >80 Zeilen, 6 tote Methoden |
| 1660 | `services/decision/agent_decision_service.php` | 🔴 5 God-Methoden |
| 1610 | `privacy_anonymizer.php` | 🟠 2 God-Methoden, 1 tote No-op-Methode |
| 1286 | `interpreter.php` | 🟠 `interpret()` 214 Zeilen, 2 weitere God-Methoden |
| 1188 | `services/confirm_run_service.php` | 🔴 `confirm()` = **563 Zeilen**, re-implementiert Decision/Runtime |
| 1100 | `agent_runtime.php` | 🟢 weitgehend sauber, einige Misplacements |
| 1033 | `conversation_store.php` | 🟢 sauber, keine God-Methode |
| 913 | `queue/queue_manager.php` | 🟢 sauber |

LOC pro Top-Verzeichnis: `local/wizard` (root) 13.741 · `services/*` ~9.225 + Unterordner · `course/skills` 5.129 · `wizard/skills` 3.009 · `core/skills` 2.351.

---

## 3. Flowchart-Compliance-Matrix

| Subsystem | Status | Anmerkung |
|-----------|:------:|-----------|
| Runtime-Loop (RUNLOOP, LOOP_STEP, OBS_ACCUM, BUDG_CHECK, ATTB, BUDGX) | 🟢 | MAX_LOOP_STEPS=6 ✓. Aber `state.currentstep` wird im Loop nie gesetzt (Invariante verletzt, low impact). `build_preflight_retry_observation` lebt anderswo. |
| Finalization-Classifier (FCLASS / LG_MATRIX 1–8) | 🟡 | Präzedenz korrekt, aber Issue-Code-/Error-Class-Sets sind **Supersets** der Matrix → §7-D1 |
| Planner-Pipeline (ORC, 2 LLM-Calls, TSEL planned_steps[], CINT unwrap) | 🟢 | Strikte Trennung discovery→selection→construction, exakt 2 Calls ✓ |
| Decision-Routing (PROC_IN→D_PREVIEW→D_PENDING→D_LOOKUP→D_PROMOTE→D_ROUTE) | 🟢 | Reihenfolge & R3-fail-safe-Default korrekt |
| Risk-Class-Gating & TTLs (LG_RISK_CONF: R1 900 / R2 300 / R3 900) | 🟢 | Korrekt in `queue_transition_service` + `queue_manager` |
| Preflight-Layer-Aktivierung (R0 none / R1 L1+L2 / R2 +L3 / R3 +ext) | 🟢 | Korrekt; eine kleine Abweichung bei Domain-Timeout-Retry für R1 → §7-D2 |
| Queue (Q_ENQUEUE/IDEM/DAG/RUNNING/BLOCKED/PLANNED/FAIL_TTL) | 🟢 | DAG, Signatur-Idempotenz, atomares try_mark_running, TTL ✓ |
| Guard-Token (build aus preparedinput, auf Queue-Item, nicht auf DTO) | 🟢 | Korrekt |
| Duale Discovery (EMB_AVAIL; Embeddings + deterministischer Fallback, LG_DET) | 🟢 | Beide Pfade existieren; No-Embeddings-Fallback erhalten ✓ |
| FRANK Score 0.7·signal + 0.3·semantic | 🟢 | Korrekt |
| EMB_CATALOG RFC-4180 escape='' + atomarer Write | 🟡 | Nur Skill-Katalog-Repo gehärtet; **Docs-Repo nicht** → §5-C2 (latente Datenverlust-Regression) |
| Family-first, lazy concrete skill | 🟡 | Reihenfolge teils invertiert (Skill-Top-K vor Family-Ranking) |
| CSTORE CS1–CS16 | 🟢 | Alle Methoden vorhanden; **CS14 user_input_lang nie geschrieben** → §4-Bug-2 |
| Synchronizer SCONTRACT (kein command-invent, no-drift, rollback) | 🟢 | Korrekt; R2/R3-Notices nur prompt-seitig, nicht post-validiert → §7-D3 |
| „Synchronizer antwortet IMMER" (auch Fehler) | 🟢 | error→`response_type=error`+`error_presentation_requested` ✓ |
| ANON (anonymize/deanonymize fail-closed/reanchor/has_unresolved gate) | 🟢 | Alle 4 Vertragspunkte korrekt |
| External-Gates (AZ_READY graceful first, sesskey, structured error) | 🟡 | Readiness graceful & strukturiert ✓; **sesskey fehlt bei `ai_upload_attachment` (write!) + `ai_poll_thread`** → §4-Bug-3; send/confirm validieren Context vor Readiness |
| DB-Rollout (install.xml + guarded upgrade.php, LG_DB) | 🟢 | Alle create_table guarded ✓ |
| Skill-Interface / Risk-Declaration (TI, TCV) | 🟢 | Vollständig, Risk⇔read_only validiert, kein fehlendes Risk |
| Provider-first Wiring (TRFAC) | 🟢 | Korrekt |
| USERMEM (remember R0 / forget R2 / list R0) | 🟡 | Semantik korrekt, aber **Namespace `wizard.*` statt `core.*`** wie im Flowchart → §7-D4 |

---

## 4. 🔴 Konkrete Funktionsdefekte (selbst verifiziert)

### Bug 1 — Semantische Doku-Suche ist permanent tot
- **Datei:** `services/lookup/docs_lookup_service.php:151` liest `$hit['_similarity']`; `services/embeddings/embeddings_retrieval_service.php:57,69` schreibt den Score ausschließlich unter `'score'`. `_similarity` wird **nirgends** im Repo geschrieben.
- **Folge:** `$score` ist immer `0.0`; der Filter `SEMANTIC_MIN_SCORE = 0.30` (`:53,152`) verwirft **jeden** semantischen Treffer. Die Doku-Suche fällt still auf rein lexikalisches Scoring zurück — die teure Embeddings-Retrieval-Investition für Docs ist wirkungslos.
- **Verifiziert:** grep bestätigt — kein `_similarity`-Writer existiert.
- **Empfehlung:** `$hit['score']` lesen und die Skalierung (0–1 vs `round(score*1000)` bei `:169`) angleichen. Danach Regressionstest, der einen semantischen Treffer über der Schwelle erzwingt.

### Bug 2 — `user_input_lang` / `last_output_lang` haben keinen Writer
- **Datei:** Reads in `services/language_policy_service.php:62,66` und `services/confirm_run_service.php:268`. **Kein** `set_thread_metadata_value(..., 'user_input_lang', …)` / `'last_output_lang'` irgendwo (grep bestätigt).
- **Folge:** Flowchart CS14 („set/get user_input_lang metadata — authoritative turn language") und LANG („source priority: latest user message") gelten faktisch nicht. Kandidat #1 und #5 der Sprach-Policy sind permanent leer; Auflösung fällt still durch auf model-deklariertes `user_lang`/`lang` → `current_language()` → `en`. Für nicht-englische Nutzer ein latentes Sprach-Fidelity-Risiko (vgl. Memory „Synchronizer antwortet in Nutzersprache").
- **Empfehlung:** Writer beim Message-Ingest verdrahten **oder** — falls bewusst verworfen — mit Georg klären und Flowchart anpassen (Policy: klären, nicht still divergieren).

### Bug 3 — Write-Endpoint ohne `require_sesskey()`
- **Datei:** `classes/external/ai_upload_attachment.php:91` (in `db/services.php:113` als `type=write` registriert, schreibt Temp-File + mintet Token) und `ai_poll_thread.php:67` haben **kein** `require_sesskey()`. Alle anderen Write-Endpoints haben es.
- **Folge:** CSRF-Lücke beim Upload-Write-Endpoint. `ai_poll_thread` ist read (geringeres Risiko, aber Muster-Abweichung).
- **Empfehlung:** `require_sesskey()` bei `ai_upload_attachment` ergänzen; bei poll entweder ergänzen oder die sesskey-freie Lock-free-Politik explizit dokumentieren.

---

## 5. Querschnitts-Findings nach Thema

### 5.A — God Classes / SRP-Verletzungen (🔴 HIGH)

- **`orchestrator.php` (3215 LOC)** — eine Klasse besitzt Provider-/Availability-Resolution, 3 Phasen-Koordinatoren, Embeddings-Discovery, Prompt-Template-Literale, Runtime-Context-Block-Bau, Catalog-Sanitization/Rendering, User-Memory-Injection, Pagetype-Mapping und Telemetrie. 12 Methoden >80 Zeilen, darunter `run_discovery_phase` (**436**), `build_runtime_context_block` (**183**), `get_runtime_provider_status` (**171**), `run_selection_phase` (169), `run_construction_phase` (134), `get_default_initial_prompt_template_for_action` (117).
  **Empfohlene Extraktionsnähte:** (a) `discovery_phase_service`, (b) `runtime_context_block_builder` (inkl. SYSTEM_RUNTIME/SYSTEM_RUNTIME_STATE-Split + `append_*` + Memory-Injection), (c) `planner_catalog_service` (slim/sanitize/render/compact/filter), (d) `provider_status_service`. `orchestrator` wird zum dünnen `process()`-Koordinator.

- **`confirm_run_service::confirm` (`:95–658`, ~563 Zeilen)** — 🔴 schwerstes Einzelproblem. Macht Validierung, Session-Allow, Intent-Consume, Queue-Resolve, Dep/Retry-Checks, Repeat-Guard, Run-Erzeugung, Execute, Retry-Klassifikation, Audit, Follow-up-Re-Loop, Autoconfirm-Gating in einer Methode — und **konstruiert intern `orchestrator`/`interpreter`/`agent_runtime` und ruft `run_loop()`** (`:449–484`), plus eigenes response_type-Rewriting (`:490–534`). Runtime-/Decision-Logik leckt in einen Application-Service. Zwei große, weitgehend gespiegelte catch-Blöcke (`:332–407` ≈ `:574–657`).
  **Empfehlung:** zerlegen in resolve → execute → classify-result → build-followup → build-response; Follow-up-Routing zurück durch `agent_decision_service`/Runtime delegieren statt inline neu zu bauen.

- **`agent_decision_service`** — 5 God-Methoden: `execute_readonly_commands` (211), `handle_preflight` (188), `handle_command_routing` (173), `process` (123), `handle_confirm_pending` (101).

- **`interpreter`** — `interpret()` (214; sechs near-duplicate Passthrough-Blöcke), `interpret_selection_phase_output` (114), `enforce_phase_contract` (99). Zusätzlich: **mutable Parse-State** (`lastparseissuecode`/`lastparseinputexcerpt`) als Out-Parameter auf der Instanz statt Parse-Result-DTO.

- **`privacy_anonymizer`** — `anonymize_names` (~163), `get_or_create_token` (~94, security-kritisch, hohe Komplexität).

### 5.B — Systematische Duplikation (🟠 MED–HIGH)

- **[HIGH] Risk-Class-Resolution 6× kopiert** — `agent_decision_service.php:1495`, `preflight_pipeline.php:336` (beide mit Registry-Lookup + R3-Fallback), plus schlankere `normalize_risk_class` in `queue_manager.php:874`, `queue_transition_service.php:597`, `queue_command_mapper.php:109`, `confirm_run_service.php:926`. Risk-Class ist laut LG_RISK *der* Zentralisierungspunkt — aktuell copy-paste. **→ `risk_class_resolver`-Service**, alle 6 delegieren.

- **[HIGH] Zwei ~80% identische Embeddings-Stacks** — `services/embeddings/*` (Skill-Katalog) vs `services/lookup/docs_*` (Docs). Identisch: `headers_match()` (`embeddings_csv_repository.php:264` ≡ `docs_embeddings_csv_repository.php:209`), `get_default_file_permissions()` (`:283` ≡ `:228`); near-identisch `read_rows/write_rows/is_valid_schema/exists/get_csv_path`, Readiness-Provider-Check, Index-Rebuild-Skelett. **→ `embeddings_csv_repository_base` + `embeddings_readiness_base` + `embeddings_index_base`.**

- **[HIGH] Diagnose-Gerüst 5× kopiert** — `row()`, der Glyph-Observation-Loop (`['ok'=>'[OK]','fail'=>'[X]','warn'=>'[!]']`) und `error_result()` sind pixelgleich in `diagnose_permissions_skill.php`, `diagnose_notifications_skill.php`, `diagnose_access_skill.php`, `diagnose_enrolment_skill.php`, `diagnose_grades_skill.php`. **→ in die bestehende `diagnostics/`-Foundation heben.**

- **[HIGH] Cross-Context-Boilerplate + `clarify()`/`build_error_result()` über alle mutierenden Course/Quiz-Skills** — `supports_target_context()`/`get_target_selector()` verbatim in add/update_activity, add/update_quiz, generate_questions; `clarify(...)` byte-identisch in 4 Skills; `build_source_clarification` zwischen add_quiz/update_quiz dupliziert. **→ `course_targeted_skill`-Trait/Basisklasse; `build_source_clarification` in `quiz_question_service`.**

- **[HIGH] Retry-Decision doppelt** — `confirm_run_service::build_retry_decision` (`:853–918`) re-implementiert die R3-no-retry + Execution-Gate + Backoff-Logik aus `queue_transition_service::to_retry_waiting`/`apply_preflight_decision`. Zwei unabhängige Backoff/Exhaustion-Pfade für dasselbe Queue-Item, die sich bei den Grenzen widersprechen (Exponent-Cap 8 vs 30, siehe unten).

- **[MED] `cosine_similarity` 3×** — `embeddings_retrieval_service.php:233`, `family_embeddings_retrieval_service.php:155` (byte-identisch), `skill_selection_debug_service.php:418`. **→ `vector_math`-Helper.**

- **[MED] `normalize_phase_trace` byte-identisch** in `conversation_store.php:677` und `message_persistence_service.php:116`. `normalize_issue_codes` doppelt in `synchronizer_input_builder.php:296` + `synchronizer_output_contract.php:342` (und `finalization_classifier`/`finalization_template_service`). Diverse `normalize_string_list`/`normalize_queue_item_ids`-Klone.

- **[MED] Output-Contract/Response-Type-Regeln an 3 Stellen** — `phase_prompt_bundle_builder.php:290`, `prompt_policy_builder.php:94`, `orchestrator.php:1690`. Drift bereits sichtbar (Selection-Contract weicht subtil ab). **→ eine kanonische Contract-Quelle.**

- **[MED] Selection-Single-Command-Contract doppelt enforced** — `orchestrator.php:1209` (`normalize_selection_phase_output_for_handoff`) vs `interpreter.php:417,536`. Enforcement gehört an die Trust-Boundary (Interpreter); Orchestrator-Re-Normalisierung droppen.

- **[MED] ANON_USER-Token-Regex 5× / Email-Regex 4×** in `privacy_anonymizer` (`:145,306,421,464,1335` bzw. `:610,701,907`) ohne gemeinsame Konstante — Drift-Risiko zwischen Matcher und Resolver. **→ Class-Const.**

- **[MED] Readiness-Error-JSON + `reasonmap` per External-Klasse handgebaut** — divergente Shapes in `ai_send_message`/`ai_confirm_run`/`ai_privacy_precheck`; `reasonmap` identisch in `ai_send_message.php:187` + `aiready.php:280`. **→ `ws_error_response`-Helper + zentrale reason→string-Map.**

### 5.C — Engine-Agnostik-Lecks (🟠 MED–HIGH)

> Verstößt gegen die Memories „agnostische Prompts, kein Skill-Wissen" und „Executor/Engine bleibt clean".

- **[HIGH] Skill-namens-spezifisches Routing im Orchestrator** — `orchestrator.php:1922–2075`: `ensure_doc_skill_for_doc_intent` hartkodiert `explain_docs_skill::SKILL_NAME`, `ensure_list_skills_for_capability_intent` hartkodiert `list_skills_skill::SKILL_NAME`, jeweils gated durch de/en-Keyword-Heuristik (`looks_like_documentation_intent`, `looks_like_capability_intent`). Genau das von LG_AGN/LG_DET verbotene skill- + sprachspezifische Routing in der Engine. **→ in den Mandatory-Skill/Family-Contract verlagern (provider-deklarierte Keywords / `always_available`), wie `adaptive_skill_catalog_service::get_mandatory_skills()` es für Engine-Skills bereits tut.**

- **[HIGH] `list_skills_skill` greift in Engine-Interna** — `list_skills_skill.php:179`: instanziiert `skill_registry_factory::get_default()` + `new skill_executability_evaluator(..., new authorization_service())` und re-evaluiert Governance **im Skill**. `search_skills_skill.php:148–216` newt zusätzlich `embeddings_readiness_service`/`embeddings_retrieval_service`/`llm_call_service`/`conversation_store` und fährt den Discovery-RAG-Loop im Skill. Engine-Maschinerie im Skill-Kostüm — verletzt „Skills referenzieren die Engine nicht". **→ Introspektions-/Discovery-Service, den die Engine injiziert.**

- **[MED] Booking-Domain-Feldnamen im generischen Anonymizer** — `privacy_anonymizer.php:59–61`: `SQL_TEXT_FIELDS=['text','description','optionquery']`, `USER_REFERENCE_FIELDS=['userquery','teacherquery','targetuserquery']`. `optionquery`/`teacherquery` sind Booking-Skill-Parameter in einer Engine-Klasse. **→ Klassifikation per Provider-Hook/Skill-Contract.**

- **[MED] Booking-Semantik im generischen `result_payload_summarizer`** — `:149–178` erkennt `options`/„booking option", `:191` hartkodiert option/teacher/session/customfield-Wording, `:421` mappt Prefix `'booking'`→`'bookingextension_agent'`. Contributor-Mechanismus existiert, aber Fallback hartverdrahtet Booking.

- **[MED] Booking-Domain in der Construction-Schicht** — `parameter_constructor.php:58–69` (`coursestarttime`/`courseendtime`), `:78` (`teacherquery`/`selectusersquery`/`bookusersquery`) als Literale. **→ über `domain_normalizer_hook` (DNORM) statt Feldnamen-Literale.** Ebenso Booking-`get_coursemodule_from_id('booking', …)` im agnostischen Block-Builder `orchestrator.php:2533`.

- **[HIGH] Hartkodierte Showroom-URLs** — `booking_issue_code_provider.php:81,90` liefern literal `https://showroom.wunderbyte.at/.../optionview.php?optionid=73&cmid=938&userid=1`. Provider-seitig zwar akzeptabel, aber Demo-Hardcodes mit fixen IDs gehören in Config/Settings.

- **[LOW] Engine-Default `new booking_issue_code_provider()`** in `agent_decision_service.php:140` + `preflight_domain_check_runner.php:46` — injizierbar & dokumentiert, aber der konkrete Klassenname ist einkompiliert. **Bei der geplanten `local_wizard`-Auskopplung invertieren.**

### 5.D — Misplaced Logic in dünnen Schichten

- **[HIGH] `ai_get_doc_content` enthält ~380 Zeilen handgerollten Markdown→HTML-Renderer** (`:145–523`) im WS-Layer (soll gate→delegate sein). Inkl. sicherheitskritischer Traversal-Guard. **→ `services/lookup/markdown_renderer`.**
- **[MED] `ai_discard_pending` inlined die Queue-Skip-Business-Loop** (`:94–124`) statt zu delegieren wie `ai_confirm_run`. **→ `confirm_run_service::discard` / `discard_pending_service`.**
- **[MED] `ai_send_message` trägt Result-Shaping/Orchestrierung** — `resolve_response_commands` (`:377–416`) liest Queue-Item neu und rekonstruiert das Command-Envelope inkl. guard_token; `:230` raw `$DB->get_record` auf `local_wizard_ai_threads` statt über `conversation_store`.
- **[MED] `agent_runtime` besitzt Clarification-Chain-Thread-Bookkeeping** (`:295–347`) — Discovery-Input-State, gehört in Discovery/Store, nicht in den Loop-Koordinator (dessen Docblock „loop steering only" sagt).

### 5.E — Toter Code (Bestätigt via grep, ~kein Caller)

| Datei:Zeile | Element | LOC |
|---|---|---:|
| `loop_finalizer.php` (ganze Datei) | `loop_finalizer` — kein Caller | 250 |
| `runtime_step_analysis_service.php` (ganze Datei) | kein Caller, dupliziert zudem Normalizer | 171 |
| `orchestrator.php:3155` | `augment_catalog_with_recent_executable_skills` | 60 |
| `orchestrator.php:1506` | `build_construction_allowed_skills` | 29 |
| `orchestrator.php:3025/3085/3097` | `availability_from_deny_reason`, `sanitize_unavailable_skill_catalog`, `build_skill_description_index` | ~45 |
| `interpreter.php:977/1215` | `hydrate_question_field` (Kopie), `normalize_ambiguity_options` | ~50 |
| `prompt_policy_builder.php:175` | `build_trigger_policy` (nur `_compact` genutzt) | 13 |
| `agent_state.php:122/319` | `make_resumed`, `extract_observed_command_signatures` + `normalize_command_input` | ~70 |
| `privacy_anonymizer.php:1299` | `scope_identity_key_for_type` — No-op (`return $identitykey;`), Docblock lügt | 7 |
| `privacy_anonymizer.php:1094` | `get_distinct_name_index` — Subset von `get_user_name_match_index`, keine Caller im Cluster | ~46 |
| `preflight_audit_logger.php:57` | `append()` returnt früh (Setting entfernt) — **jeder** Aufruf in pipeline/confirm_run baut verworfene Kontext-Arrays | — |
| `embeddings_csv_repository.php:135` | `count_unreadable_rows()` — public, re-parst ganze Datei, ungenutzt | — |
| `explain_docs_skill.php` | `PLANNER_DIRECT_DOC_SCORE = 720` definiert, nie genutzt | — |

**Summe geschätzt ~210 LOC reiner Loop/Orchestrator/Interpreter-Totcode** plus die obigen Streuposten.

### 5.F — Sloppy-Code-Muster

- **[MED] 🔁 `['skill'] ?? $x['skill']`-Selbstreferenz — 63 Treffer** über *alle* Cluster (`agent_decision_service`, `queue_manager`, `queue_command_mapper`, `preflight_*`, `orchestrator` ~8×, `interpreter`, `embeddings_*`, `family_*`, `adaptive_skill_catalog_service`, `synchronizer_input_builder`, `completed_command_history_service`, `result_payload_summarizer`, `list_skills_skill`, `ai_send_message`, `skill_selection_debug_service`, …). Der zweite Operand ist identisch zum ersten → unerreichbarer Zweig. Klares Massen-Rename-Artefakt (vermutlich früher `skill`/`skill_name`). Harmlos, aber starker Code-Hygiene-Indikator. **→ Sweep auf den intendierten Fallback (`?? $x['skill_name']`?) oder `??` entfernen.**
- **[MED] Geschluckte Exceptions ohne `debugging()`** — `queue_manager::try_mark_running:446` (DB-Fehler ununterscheidbar von „Slot belegt" → kann Queue still blockieren), `agent_runtime::apply_synchronizer_message_polish:444`, diverse `$ignored = $e;` in `orchestrator` (`:550,1172`), `embeddings_*`, `aiready:194`. **→ mindestens `debugging()`.**
- **[MED] Backoff-Konstanten widersprüchlich** — `queue_transition_service.php:127` hartkodiert `min(4000, 500*2^min(8,…))`, `preflight_execution_gate` deklariert `BASE_MS=500/MAX_BACKOFF_MS=4000/MAX_EXPONENT=30`. Zwei Berechnungen, abweichende Grenzen (Exponent 8 vs 30). TTLs `300`/`900` als Literale in `resolve_blocked_ttl_seconds`. **→ Backoff in `preflight_execution_gate` zentralisieren; TTLs als benannte Risk-Class-Konstanten.**
- **[MED] Hartkodierte EN/DE-UI-Strings in neueren Skills** — add/update_activity, add/update_quiz, generate_questions, alle diagnose_* geben user-facing englische Literale direkt zurück (z.B. `add_activity_skill.php:302,656`, `update_quiz_skill.php:610`, `generate_questions_skill.php:321`). Die älteren R0-Core-Skills (`get_current_user`, `search_users`, `search_courses`) nutzen korrekt `get_string`/`localized_string` — **inkonsistente Regression**. Ebenso `ai_discard_pending.php:126`, `ai_upload_attachment.php:161`, interpreter-Fallbacks `interpreter.php:940,966` (DE-Literale), große EN-Prompt/Policy-Blöcke in `synchronizer_prompt_builder.php:126–171` / `synchronizer_input_builder.php:122–160`.
- **[MED] `get_history_limit_for_phase` ist No-op** — `orchestrator_prompt_profile_service.php:85` berechnet die Phase, verwirft sie (`$ignored = $normalizedphase;`) und gibt `PHP_INT_MAX` zurück → alle `array_slice(…, -PHP_INT_MAX)`-Callsites sind „alle Messages"; `MAX_HISTORY_MESSAGES=12` dadurch ungenutzt.
- **[LOW] Copy-paste-Bug doppelter `elseif`** — `preflight_version_validator.php:129–132`: zwei identische `else if (array_key_exists('skill_version', …))`, zweiter unerreichbar.
- **[LOW] Tote No-op-Conditionals** — `finalization_classifier.php:133` (if/else beide Arme identisch), `execution_feedback_service.php:512` (Ternary beide Arme `'Link: '`).
- **[LOW] `state.currentstep` nie gesetzt** — `agent_runtime::run_loop` setzt es nicht → `record_step` stempelt immer `step=0` (widerspricht Docblock + LOOP_STEP). Low impact, weil nur von totem Code gelesen.
- **[LOW] Schwache Token-Entropie** — `attachment_token_service.php:54` `sha1(...random_int())`; besser `bin2hex(random_bytes(32))` (wie der Temp-Filename bereits).
- **[LOW] `userid=2` Admin-Fallback** hartkodiert in beiden Index-Services (`family_embeddings_index_service.php:136`, `docs_embeddings_index_service.php:102`).
- **Magic Numbers** verstreut: Discovery-Budgets 12/24/36, Confidence 0.60/0.45, Intent 0.15, Top-K 5/8, dims 1536, diverse `substr(…,0,160/180/200/600)`-Trunkierungen, History-Caps 12. Viele sind benannte Consts, aber das 0.7/0.3-Blend ist in zwei Klassen dupliziert.

---

## 6. Priorisierte Remediation-Roadmap

### P0 — niedriger Aufwand, echte Wirkung (Bugs/Sicherheit)
1. **Bug 1** fixen: `_similarity`→`score` in `docs_lookup_service.php:151` + Skalierung, Regressionstest. (§4)
2. **Bug 2** klären/verdrahten: `user_input_lang`/`last_output_lang`-Writer oder Flowchart-Reconcile mit Georg. (§4)
3. **Bug 3**: `require_sesskey()` bei `ai_upload_attachment` (write!), poll-Politik dokumentieren. (§4)
4. `try_mark_running`-Catch loggen (still blockierende Queue verhindern). (§5.F)
5. `preflight_audit_logger` entfernen oder Setting wiederherstellen — verworfene Payload-Bauten auf jedem Preflight stoppen. (§5.E)

### P1 — strukturell, löst Duplikation an der Wurzel
6. **`risk_class_resolver`-Service** — die 6 Kopien zusammenführen (§5.B). LG_RISK-Zentralisierung.
7. **`confirm_run_service::confirm` zerlegen** und das inline Runtime-Re-Loop/Routing zurück an Decision/Runtime delegieren (§5.A).
8. **`orchestrator.php` entlang der 4 Nähte aufteilen** (discovery / runtime-context-block / planner-catalog / provider-status) (§5.A).
9. **Embeddings-Basisklassen** (`*_csv_repository_base`/`*_readiness_base`/`*_index_base`) — **fixt zugleich §5-C2** (RFC-4180/atomarer Write für Docs-Repo by construction).
10. **Skill-Agnostik herstellen:** skill-namens-spezifisches Routing aus dem Orchestrator in den Mandatory/Family-Contract; `list_skills`/`search_skills` hinter injizierte Introspektions-/Discovery-Services (§5.C).
11. **`course_targeted_skill`-Basis/Trait + `diagnostic_checklist_builder`** — Cross-Context-/clarify-/Diagnose-Duplikation auflösen (§5.B).

### P2 — Hygiene
12. 63× `['skill'] ?? $x['skill']`-Sweep; toten Code (§5.E) entfernen (~210+ LOC); Backoff/TTL-Konstanten zentralisieren; EN/DE-UI-Strings in neueren Skills auf lang strings ziehen; Domain-Feldnamen aus Anonymizer/Summarizer/Constructor per Hook; `normalize_*`-Klone konsolidieren; ANON/Email-Regex als Const.

---

## 7. Flowchart↔Code-Diskrepanzen — zur Klärung mit Georg (NICHT eigenmächtig angleichen)

> Gemäß Memory `feedback_flowchart_policy`: Diskrepanzen immer erst mit Georg klären.

- **D1 — Finalization-Classifier-Sets sind Supersets der LG_MATRIX.** `finalization_classifier.php:48–91`: `DIRECT_ISSUE_CODES` enthält 4 zusätzliche `CONTRACT_PHASE_*` über Matrix-Regel 3 hinaus; `TEMPLATE_ISSUE_CODES` 8 zusätzliche (`CONTRACT_SELECTION_SKILL_MISSING` + 7 `SYNC_*`) über Regel 4; `TEMPLATE_ERROR_CLASSES` `provider_error`+`internal_status` über Regel 5. Deterministisch & testbar (LG_CLASS erfüllt), aber Flowchart veraltet. **Frage: Matrix im Flowchart nachziehen, oder sind die Extras unbeabsichtigt?**
- **D2 — Domain-Timeout-Retry für R1 außerhalb des L3-Gates.** `preflight_domain_check_runner.php:58–67` liefert `retry_hint` bei >500ms unabhängig vom Risk; für R1-Batches fließt das via `apply_preflight_decision` direkt nach `Q_RETRY`, obwohl der Flowchart Execution-Gate/Retry auf R2/R3 gated. **Frage: R1-Timeout-Retry intendiert oder durch das Risk-Gate routen?**
- **D3 — R2/R3-Synchronizer-Notices nur prompt-seitig.** SCONTRACT/LG_SYNC fordern „R3 irreversibility_notice / R2 affected_scope_summary"; `synchronizer_output_contract::merge` validiert deren Präsenz **nicht** post-hoc (nur no-commands/no-drift/fact-conflict). Sie leben als Prompt-Instruktion in `synchronizer_prompt_builder`. **Frage: harter Contract (Präsenz-Check ergänzen) oder bewusst prompt-enforced?**
- **D4 — User-Memory-Namespace.** Flowchart USERMEM nennt `core.remember/forget/list_memories` unter `core_skill_base`; Code liefert `wizard.remember/forget/list_memories` unter `wizard/skills/`. Semantik (R0/R0/R2, direct/confirm) stimmt; nur Naming/Placement divergiert.
- **D5 — Family-first vs Skill-Top-K-Reihenfolge.** Flowchart: „Family-level retrieval first, concrete skill later (lazy)". Code berechnet Skill-Top-K (`search_top_k`) teils *vor* dem Family-Ranking und nutzt `family_embeddings_retrieval_service` als Boosting-Helper über bereits geholte Skill-Rows statt als reinen Family-Retriever. Funktional nah, Ordnung invertiert.
- **D6 — `state.currentstep`/Step-Messaging.** LOOP_STEP beschreibt `state.currentstep = step+1`; im Code nie gesetzt (§5.F). Klären, ob die Invariante noch gewollt ist (sonst Flowchart-Note entfernen).

---

## 8. Positiv-Befunde (skeptisch gegengeprüft, bestanden)

- **Executor bleibt clean** (`executor.php`) — alle Mutationen via `skill->execute()` mit prepared form-style Input + Guard-Token; Skill-Erkennung duck-typed (`method_exists`), keine Skill-Namens-Branches. (Memory `feedback_executor_stays_clean` eingehalten.)
- **IDOR-Schutz konsistent** — `thread_belongs_to_user` in confirm/discard/poll/debug-logs. (Adressiert die Sorge aus `project_agent_security_audit`.)
- **Pfad-Traversal-Guard** in `ai_get_doc_content` (realpath + Prefix + `.md`), **MIME aus echten Bytes** via finfo im Upload.
- **Agent-License-Gate** endpoint-basiert, memoized, PHPUnit/Behat-Override — wie `project_agent_license_gate`.
- **DB-Rollout** sauber: alle `create_table` guarded, benchmark+user_memory in install.xml *und* upgrade.php.
- **Risk-Class⇔read_only** durchgängig deklariert & via `verify_risk_class_declaration` validiert; `base_skill`-Ctor hard-fails ungültige Klasse.
- **Anonymizer** erfüllt alle 4 Vertragspunkte inkl. fail-closed Display-Redaction und Command-Input-Gate.
- **Dualer Discovery-Fallback** (No-Embeddings-Pfad) erhalten — kein Verstoß gegen LG_DET.

---

*Erstellt 2026-06-23. Kein Code geändert. Detailfindings je Cluster liegen den 7 Teil-Audits zugrunde; Zeilennummern beziehen sich auf den Stand des Arbeitsverzeichnisses zum Audit-Zeitpunkt.*
