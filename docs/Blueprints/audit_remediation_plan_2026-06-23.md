# Audit-Remediation — Umsetzungsplan mit Fortschritt (2026-06-23)

**Quelle:** `full_audit_2026-06-23.md` · **Begleitend:** `audit_followup_worklog_2026-06-23.md`, `docs_corpus_embeddings_refactor_2026-06-23.md`
**Policy:** Code↔Flowchart-Diskrepanzen (§7) NICHT eigenmächtig angleichen — nur mit Georg klären (`feedback_flowchart_policy`).

Legende: `[x]` erledigt · `[~]` teilweise · `[ ]` offen.

---

## 0. Bereits erledigt (Vorarbeit)

- [x] **Bug 1** — `_similarity`→`score` in `docs_lookup_service` (Embeddings-Refactor D1)
- [x] **Bug 3** — `require_sesskey()` bei `ai_upload_attachment`; Poll-Politik dokumentiert (Worklog)
- [x] **§6-P0.4** — `try_mark_running`-Catch loggt jetzt (Worklog)
- [x] **§6-P0.5** — `preflight_audit_logger` entfernt (Worklog)
- [x] **§5-C2 / §6-P1.9 (teilweise)** — `embeddings_csv_repository_base` (RFC-4180/atomic), Docs-Repo gehärtet; Varianten je Modell beide Stacks (Embeddings-Refactor D2/F)
- [x] **§5.E (Teil)** — `loop_finalizer`, `runtime_step_analysis_service`, diverse tote Methoden, `get_history_limit`-No-op + echter History-Cap (Worklog)
- [x] **§5.F (Teil)** — Token-Entropie, doppelter `elseif` in `preflight_version_validator` (Worklog)

---

## 1. Risikoarm — ERLEDIGT (volle Agent-Suite 497/497 grün)

- [x] **LR1 — `['skill'] ?? $x['skill']`-Selbstreferenz-Sweep (§5.F)** — **55** Stellen in 21 Dateien kollabiert (Backreference: nur gleiche Variable; trailing `?? ''` bleibt). Gegen-grep = 0 (der eine Rest `$entry['skill'] ?? $command['skill']` ist ein echter Cross-Variable-Fallback, korrekt belassen). Alle 21 Dateien `php -l` grün.
- [x] **LR2 — Toten `PLANNER_DIRECT_DOC_SCORE` entfernt** (`explain_docs_skill.php`).
- [x] **LR3 — `cosine_similarity` 3× → `vector_math::cosine_similarity`** — neuer `services/embeddings/vector_math`-Helper; `embeddings_retrieval_service`/`family_embeddings_retrieval_service`/`skill_selection_debug_service` delegieren; private Methoden entfernt. + `vector_math_test` (3 Fälle).
- [x] **LR4 — Tote No-op-Conditionals vereinfacht** — `finalization_classifier` (risk-class-if mit identischen Armen kollabiert, Kommentar präzisiert), `execution_feedback_service` (Ternary `'Link: ' : 'Link: '` → `'Link: '`).
- [x] **LR5 — 7 geschluckte Exceptions geloggt** — `debugging(..., DEBUG_DEVELOPER)` in `orchestrator` (2×), `orchestrator_routing_service` (2×), `ai_get_doc_content`, `agent_runtime::apply_synchronizer_message_polish`, `aiready`. Rückgabeverhalten unverändert.

**Abschluss:** `$ignored = $e` = 0, alle berührten Dateien `php -l` grün, volle Agent-Suite **497/497** (0 Fehler; der früher gemeldete vorbestehende `build_construction_allowed_skills`-Fehler ist im Working Tree extern aufgelöst).

---

## 2. Strukturell (P1/P2) — Vorschläge mit Ansatz, Risiko, Test

Empfohlene Reihenfolge: **S7 → S1 → S9b/S10b → S4 → S5 → S6 → S2 → S3** (klein/abgegrenzt zuerst, God-Class-Splits zuletzt).

- [x] **S7 — `ai_get_doc_content`-Renderer → `markdown_renderer`-Service (§5.D HIGH) · ERLEDIGT**
      6 Render-Methoden (~378 LOC, inkl. Traversal-Guard) 1:1 in `services/lookup/markdown_renderer` (statisch) extrahiert; WS ruft `markdown_renderer::render()` auf. WS **524 → 144 LOC**. Charakterisierungstest (`markdown_renderer_test`, 7 Fälle: Headings/Inline/Code/Listen/Tabelle/Links/Escaping) + bestehende `docs_multi_corpus_test`-WS-Regression grün.

- [x] **S1 — `risk_class_resolver`-Service (§6-P1.6 HIGH-Hebel) · ERLEDIGT**
      `services/risk/risk_class_resolver` mit `normalize()`, `resolve_for_command(command, registry)` (Registry-Lookup + R3-Fail-safe) und `rank()`. Alle **6 Kopien** entfernt und delegieren (`agent_decision_service`, `preflight_pipeline` [+ `risk_class_rank`], `queue_manager`, `queue_transition_service`, `queue_command_mapper`, `confirm_run_service`); ungenutzter `skill_risk_class`-Import in `queue_command_mapper` mit raus. Gegen-grep = 0. `risk_class_resolver_test` (5 Fälle) + 3 betroffene Contract-Tests angepasst (2 Dubletten entfernt → jetzt in resolver-Test/Geschwistertests; 1 Registry-Stub gesetzt). Volle Agent-Suite **507/507**.

- [x] **S4 — Embeddings-Basen: Bestandsaufnahme + reduzierte Umsetzung · ERLEDIGT**
      Bestandsaufnahme (inkl. Commit `a7c636b` = Skill-Katalog-CSV-Härtung aus anderem Agent + Flowchart EMB_*): Der **gemeinsame Kern ist bereits geteilt** (`embeddings_csv_repository_base` aus a7c636b+D2, `vector_math`). Die zwei **Readiness**-Services teilen real nur den 1-Zeilen-Provider-Check; die zwei **Index**-Services teilen nur Config-Resolve + Variante. Readiness/Prune/Gate/Row-Produktion sind **bewusst divergent** (geschlossene Registry mit billigem per-Skill-Diff = flowchart EMB_READY vs. offene Corpus-Quelle mit Coverage-Proxy + nicht-destruktivem Prune + Gate + Chunking).
      - [x] **`resolve_with_overrides(?model, ?dims)`** auf `embeddings_action_config_resolver` (kapselt den byte-gleichen Modell/Dims-Block); beide Index-Services delegieren, tote `orchestrator`-Imports raus. Scharfer `embeddings_config_resolver_test` (Override gewinnt/Trim/leer→Default/0→Default/null===resolve()).
      - [x] **`readiness_base`/`index_base` bewusst VERWORFEN** (begründet: falsche Abstraktion — viele Hooks, kaum gemeinsamer Körper; würde flowchart-getrennte Semantik koppeln → Cross-Stack-Regressionsrisiko). Voll-Suite 521/521.

- [ ] **S5 — Skill-Agnostik herstellen (§5.C HIGH) · Risiko: mittel**
      (a) Orchestrator-`ensure_doc_skill_for_doc_intent`/`ensure_list_skills_for_capability_intent` + de/en-Keyword-Heuristik in den **Mandatory/Family-Contract** verlagern (provider-deklarierte Trigger / `always_available`), analog `adaptive_skill_catalog_service::get_mandatory_skills()`. (b) `list_skills`/`search_skills` hinter einen von der Engine injizierten **Introspektions-/Discovery-Service** statt Registry/Evaluator im Skill zu newen. Verhalten via vorhandene Skill-Tests + Selektions-Debug abgesichert.

- [ ] **S6 — `course_targeted_skill`-Basis/Trait + Diagnose-Foundation (§6-P1.11) · Risiko: mittel**
      Trait/Basisklasse für `supports_target_context()`/`get_target_selector()`/`clarify()` (verbatim in add/update_activity, add/update_quiz, generate_questions); `build_source_clarification` nach `quiz_question_service`. Diagnose: `row()`/Glyph-Loop/`error_result()` der 5 `diagnose_*` in die `diagnostics/`-Foundation heben. Pro Skill bestehende Tests grün halten.

- [ ] **S2 — `confirm_run_service::confirm` zerlegen (§6-P1.7) · Risiko: hoch**
      563-Zeilen-Methode in resolve → execute → classify-result → build-followup → build-response; inline-Runtime-Re-Loop/Routing zurück an `agent_decision_service`/Runtime delegieren; die zwei gespiegelten catch-Blöcke vereinen. Schrittweise hinter den vorhandenen confirm-Contract-Tests; jeder Extraktionsschritt grün.

- [ ] **S3 — `orchestrator.php` God-Class splitten (§6-P1.8) · Risiko: hoch**
      4 Nähte: `discovery_phase_service`, `runtime_context_block_builder`, `planner_catalog_service`, `provider_status_service`; `orchestrator` wird dünner `process()`-Koordinator. Erst nach S1/S5 (entfernt schon Routing-/Risk-Last). Inkrementell, Agent-Suite je Schnitt.

- [ ] **S8 — Misplaced Logic in WS/Runtime (§5.D MED) · Risiko: niedrig–mittel**
      `ai_discard_pending`-Queue-Loop → `confirm_run_service::discard`; `ai_send_message`-Result-Shaping (`resolve_response_commands`, roher `$DB`-Read) über `conversation_store`/Runtime; `agent_runtime`-Clarification-Bookkeeping nach Discovery/Store.

- [~] **S9 — Engine-Agnostik-Restlecks (§5.C MED)**
      - [x] **S9b ERLEDIGT**: ANON_USER-Token-Regex (Find 4× + Parse 1×) & Email-Regex (3× via gemeinsamem `EMAIL_SUBPATTERN`) als Class-Const in `privacy_anonymizer` (byte-genau verifiziert, Drift-Schutz für die ANON-Contract-Komponente). Scharfer `privacy_anonymizer_regex_test` (Grammatik-Pinning + Anker-Semantik + Public-API).
      - [ ] offen: Domain-Feldnamen aus `privacy_anonymizer`/`result_payload_summarizer`/`parameter_constructor` per Provider-/Skill-Hook (DNORM); Showroom-URLs in `booking_issue_code_provider` nach Config.

- [~] **S10 — Hygiene-Rest (§5.F MED)**
      - [x] **S10a ERLEDIGT**: `normalize_issue_codes` (4 Defs, 2 Signatur-Varianten → `issue_code_normalizer::normalize`/`from_result`, Verhalten je Call-Site exakt erhalten) + `normalize_phase_trace` (2 Defs → `phase_trace_normalizer::normalize`) konsolidiert. Nebenbei toten `finalization_classifier::resolve_risk_class` + LR4-`$riskclass`-Rest entfernt. Scharfer `result_normalizers_test` (cast vs is_array-Guard, „first-wins"-Phase-Logik).
      - [ ] offen: Backoff/TTL zentralisieren (`preflight_execution_gate`; Exponent-Cap 8 vs 30 — **verhaltensändernd → mit Georg klären, welcher Cap korrekt**); EN/DE-UI-Literale neuerer Skills (add/update_activity, add/update_quiz, generate_questions, diagnose_*) auf `get_string` in beiden Sprachdateien.

---

## 3. Bug 2 + Flowchart §7 — Klärung mit Georg (nicht eigenmächtig)

- [ ] **Bug 2** — `user_input_lang`/`last_output_lang`-Writer (CS14/LANG): verdrahten ODER Flowchart-Reconcile.
- [ ] **§7 D1** Finalization-Classifier-Sets = Supersets der LG_MATRIX.
- [ ] **§7 D2** R1-Domain-Timeout-Retry außerhalb des L3-Gates.
- [ ] **§7 D3** R2/R3-Synchronizer-Notices nur prompt-seitig (kein Post-Check).
- [ ] **§7 D4** User-Memory-Namespace `wbagent.*` vs `core.*`.
- [ ] **§7 D5** Family-first vs Skill-Top-K-Reihenfolge.
- [ ] **§7 D6** `state.currentstep` nie gesetzt.

---

*Erstellt 2026-06-23. Fortschritt wird in diesem Dokument abgehakt.*
