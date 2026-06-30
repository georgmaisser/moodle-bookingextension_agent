# Pre-Go-Live Status & Working-MD Cleanup — `bookingextension_agent`

**Datum:** 2026-06-30 · **Basis:** der vollständige Audit-Korpus (`docs/audit/`, 18 Sections + 5 Cross-cutting,
23 Auditoren) abgeglichen gegen die jüngsten Commits. Dieses Dokument konsolidiert: (A) was nach den
Remediation-Commits noch offen & go-live-relevant ist, (B) welche Flowchart-/Architektur-Doku nachzuziehen
ist, (C) eine Klassifikation der Working-MD-Dateien für das anstehende Cleaning.

> Methode: Findings (Severity + Resolution-Status) wurden gegen den Code der zitierten Dateien gegengeprüft,
> um „laut Audit offen, aber per Commit längst erledigt" (und das Gegenteil) zu erkennen. `phpcs --standard=moodle`
> und PHPUnit liefen auf dem 2026-06-30-Fix-Mount **nicht** — die Security-Fixes sind handgeprüft, vor Merge
> im Container verifizieren.

---

## A. Go-Live-Status: erledigt vs. offen

**Audit-Gesamtverdikt: 🟡 CONDITIONAL GO — 0 Blocker.** 153 Findings, **9 erledigt**, 144 offen (138 davon
non-gating MEDIUM/LOW/INFO). Alle **sicherheits-/privacy-/datenkritischen HIGHs sind gefixt.**

### Erledigt (verifiziert gegen Code)

| Finding | Schwere | Commit | Was |
|---|---|---|---|
| 12-F01 | HIGH (Security) | `9b7cb77` | `core.search_users` PII-Leak → per-Kandidat `user_can_view_profile()`-Gate |
| 06-F01 / C1-F03 | HIGH (Security) | `9b7cb77` | `javascript:`/`data:`-URI-XSS in markdown_renderer → Scheme-Allowlist |
| 15-F01 | HIGH (Privacy) | `9b7cb77` | unbegrenzter LLM-Debug-PII-Store → Retention-Task (30 d default) |
| C1-F01 / C2-F03 / 16-F02 / 18-F01 | HIGH/MED | `8402580` | Benchmark-`pinbaseline`-Write auf Read-Cap → `managebenchmarks` enforced |
| 16-F01 / C2-F01 / C2-F02 | HIGH | `5c6556c` | kaputter Upgrade-Pfad (`local_wizard_*`) + falsche Data-Model-Doku → upgrade.php geleert, Doku korrigiert |
| C1-F02 / 02-F03 | MEDIUM | `8402580` | toter `managebenchmarks`-Cap → bekommt mit C1-F01 seinen Konsumenten |

### Noch offen & go-live-relevant

**Echte HIGHs (nur 2, beide gegen Code bestätigt):**

1. **[05-F01] HIGH — Engine→Domain-Leak in `parameter_constructor`** · 🔧 **IN ARBEIT (2026-06-30, George)** ·
   `classes/local/wizard/services/construction/parameter_constructor.php:58,81,118,140-154`
   Die Engine-Schicht hardcodet mod_booking/musi-Feldnamen (`coursestarttime`, `courseendtime`, `teacherquery`/
   `selectusersquery`/`bookusersquery`, `search_queries`, `question`). **Kein Security-Gate — Contract-Reinheit.**
   Fix läuft: in den bereits aufgerufenen per-Skill-Hook `normalize_skill_input` (`:116`) verschieben — **vor dem
   Engine-Freeze für die `local_wizard`-Auskopplung** (danach teurer; erfordert einige Änderungen). Plan:
   `Blueprints/skill_engine_service_boundary*` (Leaks B.2/B.3/B.5).

2. **[09-F01] HIGH — Executor-Kapitel beschreibt nicht-existenten Async-Task** · ✅ **ERLEDIGT (2026-06-30)** ·
   `docs/architecture/11-executor.md` korrigiert: §3 Deny-Tabelle nennt jetzt `DENY_REQUIRES_PRO` (in Reihenfolge,
   ohne `DENY_SKILL_VERSION_UNSUPPORTED`), §7 auf „Execution ist immer inline" umgeschrieben (kein
   `execute_ai_run_adhoc`/`aiexecutionmode` mehr), Files-Header bereinigt. `.mmd EXC_EVAL` bleibt per Flowchart-Policy
   beim Maintainer.

**Maintainer-Entscheidungen — getroffen & umgesetzt (2026-06-30):**

- ✅ **[15-F01] Debug-Logging gegated** — Entscheidung George: nur loggen, wenn `aidebugmode` an ist. Beide
  `llm_call_service`-Stellen nutzen jetzt `log_exchange` (self-gated); `log_exchange_always` + `$forcelog`
  entfernt; Test-Basis setzt `aidebugmode`. Retention (30 d) bleibt für den Debug-Fall.
- ✅ **[15-F02] eigene admin-only Capability** — Entscheidung George: nicht `site:config`, sondern neue
  `bookingextension/agent:manageaiproviders` (leere archetypes = admin-only, explizit delegierbar, kein
  Automatismus). Beide Provider-Endpunkte umgestellt; Lang en/de + version-Bump.

**Engine-Cleanups erledigt 2026-06-30 (nach 05-F01, mit Freigabe):**

- ✅ **[C3-F01]** WB_ACTION_*-FQCN-Duplikation → zentrale `wb_action_names`-Klasse; alle 10 Klassen + 6 inline
  `class_exists`-Literale aliasen sie (Drift inkl. Leading-Backslash eliminiert).
- ✅ **[06-F02]** totes `search_top_k()` entfernt.
- ✅ **[12-F02]** `recreate_skill_catalog`-Scope `['module']` → `['system']` deklariert (Cap-Move teacher→manager
  bleibt deine Governance-Entscheidung).
- ℹ️ **[03-F05]** „totes `has_observations()`" = False Positive (wird genutzt).

**MEDIUM/LOW, noch offen (nicht launch-gating):**
- ✅ **[04-F06]** byte-identische Provider-Error-Builder → in `provider_error_result_trait` extrahiert (erledigt).
- **[C3-F02 / 03-F03 / 05-F02]** weitere Logik-Duplikate (Issue-Code-Klassifizierer, `normalize_*`) —
  Verhaltens-riskantere Merges (Klassifizierer könnten subtil divergieren); bewusst zurückgestellt.
- **[C2-F06]** `strict_types` (69/295) — am Orchestrator bewusst NICHT (bekannter Coercion-Bug).
- **[05-F03]** `spawn_contract_service` — **behalten** (test-abgedeckter Spawn-Contract-Seam, ch.11 §8; kein toter Code).
- **[02-F02]** totes `require_capability_at()` — Engine-Interface-Methode, Entfernung separat.

**Doc-Coverage-HIGH-Cluster (Doku-only, siehe B):** C5-F01 (observability.md noch `local_wizard_`),
C5-F02 / 03-F02 / 04-F07 (stale orchestrator-Zeilennummern), C5-F05 (`bx_agent_user_memory` in Data-Model fehlt).

---

## B. Flowchart & Architektur — was nachzuziehen ist

> Policy (`feedback_flowchart_policy`): der Flowchart ist die primäre Architektur-Doku; Diskrepanzen werden
> **gemeldet, nicht eigenmächtig angeglichen**. Diese Liste ist Meldung, keine Umsetzung.

**Flowchart `AGENT_IMPLEMENTATION_FLOWCHART.mmd`: aktuell — keine Aktion.** Das generische Module-Target-Feature
(`module_target_resolver`, `operating_context_target_registry`, `skill_operating_context_resolver`,
`context_resolver`, `module_targeted_skill`-Trait, `activityquery`, Scope-Kaskade, `CONTEXT_TARGET_UNRESOLVED`)
ist in `LG_OPCTX` (`:617`), `PF_L2P` (`:202`), `PP_RUN` (`:193`), `MV_SUBSET` (`:128`) vollständig abgebildet.
D5-Dimension laut Audit verhaltenstreu (kein Widerspruch in 14 Subgraphen).

**Architektur-Kapitel hängen hinterher — Reihenfolge nach Wichtigkeit:**

| Prio | Datei | Was fehlt / ist falsch |
|---|---|---|
| ✅ erledigt 30.06. | `architecture/09-preflight-pipeline.md` | Neue §2b „Operating-context resolution & Gate 2"; §3 läuft jetzt am Operating-Context; §6 Audit als „retired" korrigiert (`preflight_audit_enabled` entfernt). |
| ✅ erledigt 30.06. | `architecture/07-selection-and-construction.md` | §4 dokumentiert jetzt das Target-Selection-Feld (`activityquery`) für module-targeted Skills + Verweis auf Preflight-Auflösung (die Thread-561-Lücke). |
| ✅ erledigt 30.06. | `architecture/11-executor.md` | **= 09-F01.** Deny-Tabelle korrigiert (`DENY_REQUIRES_PRO` statt `DENY_SKILL_VERSION_UNSUPPORTED`); §7 „Execution ist immer inline"; Files-Header bereinigt. |
| ✅ erledigt 30.06. | `architecture/02-authorization-and-context.md` | Cap-Tabelle um `managegovernance` + `runbenchmarks` ergänzt + Admin-Page-Gating-Story; §2 „ambient vs operating context" (Gate 1/Gate 2); `search_users`-`user_can_view_profile()`-Filter vermerkt. |
| ✅ erledigt 30.06. | `architecture/03-conversation-store.md` | §8 ergänzt: `purge_old_llm_debug_entries()` + Gating-/Retention-Notiz (Logging nur bei `aidebugmode`, 30-d-Pruning). |
| kosmetisch | `reference/flowchart-guide.md:115-121` | C4-F05: abgelöster „mandatory tier"-Eintrag noch da; QNORM-STATUS-Label-Rest (C4-F03). Authoritative `.mmd` ist korrekt. |

**Kernsatz:** Wer nur die Architektur-Kapitel (nicht den `.mmd`) liest, erfährt nichts vom Module-Target-/
Operating-Context-Feature, das Flowchart **und** Code längst implementieren. Kapitel **07 + 09** sind die wichtigsten.

---

## C. Working-MD-Cleanup — Klassifikation

Empfehlung: „erledigt/abgelöst" nach `obsolet/` **archivieren** statt hart löschen (Git hat die History ohnehin,
aber `obsolet/` ist die etablierte Ablage hier). Vor dem Entfernen eines „COMPLETE"-Docs etwaige Rest-Entscheidungen
in einen `todo/`-Stub auslagern (unten markiert).

### Entfernen / archivieren — erledigt oder abgelöst

| Datei | Status-Evidenz | Empfehlung |
|---|---|---|
| `Blueprints/AUDIT_DEAD_CODE_AND_FLOWCHART_2026-06-26.md` | **Abgelöst** durch den neuen `docs/audit/`-Korpus (2026-06-30, 23 Auditoren). Behaviorale Items (R2-TTL etc.) sind in C4 reflektiert. | **REMOVE** |
| `Blueprints/COURSE_DIAGNOSE_SKILL_CONSOLIDATION.md` | „UMGESETZT (2026-06-28). Alle 5 Phasen, 555 Tests grün." | **ARCHIVE → obsolet/** |
| `Blueprints/ENTITY_LINK_OBSERVATION_HARDENING.md` | „UMGESETZT (2026-06-28)" (Single-Pfad war bereits korrekt) | **ARCHIVE → obsolet/** |
| `Blueprints/todo/proposed_action_preview_rollout_2026-06-29.md` | „COMPLETE (2026-06-29). All 17 write tasks" (Commit `98ce8c1`/`d86d640`) | **ARCHIVE** — vorher offene „Decision #1 (Label-Lokalisierung)" in `todo/`-Stub auslagern |
| `Blueprints/todo/embeddings_trigger_consolidation_2026-06-26.md` | „IMPLEMENTED (Phases 0–4). Phases 5–6 deferred." | **ARCHIVE** — Phases 5–6 vorher als kurzen `todo/`-Stub sichern |

### Review vor Entscheidung — teilweise erledigt

| Datei | Status-Evidenz | Empfehlung |
|---|---|---|
| `Blueprints/phase0_dtofree_and_leak_inversion_2026-06-28.md` | „Detailplanung"; Phase 0 laut Projektstand KOMPLETT (DTO-freier Contract final-gelockt) | **ARCHIVE**, aber als Basis von `skill_engine_service_boundary*` verlinkt lassen |
| `Blueprints/BENCHMARK_REDESIGN.md` | „proposal for external validation (2026-06-28)"; Run-from-UI ist geshippt (`8402580`), aber `prior_state`-Seeding / Szenario-Taxonomie evtl. offen. Audit 18-F08: `benchmarking.md` beschreibt Szenario-Inventar falsch. | **KEEP/REVIEW** — Rest-Scope klären, dann erst archivieren |
| `Blueprints/SKILL_REWORK.md` | „Design / Arbeitsliste — KEINE Umsetzung", teils umgesetzt (Skill-Merges) | **KEEP/REVIEW** — umgesetzte Teile streichen, Rest behalten |

### Behalten — aktive Planung, direkt an offenen Go-Live-/Extraction-Arbeiten

| Datei | Warum behalten |
|---|---|
| `Blueprints/local_wizard_extraction_plan_2026-06-28.md` | die große anstehende Auskopplung; Planungsbasis |
| `Blueprints/skill_engine_service_boundary_2026-06-29.md` + `_IMPLEMENTATION_2026-06-29.md` | **der Plan für den offenen HIGH 05-F01** (Leaks B.2/B.3/B.5) — vor dem Cut umzusetzen |
| `Blueprints/TEST_SUITE_AUDIT_AND_REFACTOR.md` | Test-Härtungs-Proposal, noch nicht umgesetzt |
| `Blueprints/course_index_search_blueprint_2026-06-25.md` | Roadmap `course.*`-Indizierung, KEINE Umsetzung |

### Behalten — expliziter Roadmap-Backlog (jeweils als Roadmap-Commit eingecheckt)

| Datei | Commit |
|---|---|
| `Blueprints/todo/fill_form_skill_2026-06-29.md` | `07520f3` |
| `Blueprints/todo/parallel_subagents_2026-06-30.md` | `96394db` |
| `Blueprints/todo/reporting_skills_family_2026-06-29.md` | `49afa30` |
| `Blueprints/todo/diagnose_course_progress_2026-06-26.md` | Konzept |

### Nicht anfassen (Referenz-Doku)

`docs/architecture/*` (Updates s. B), `docs/developer-guides/*`, `docs/operations/*`, `docs/reference/*`,
`docs/skills/*`, `docs/audit/*` (Go-Live-Record — nach Launch archivierbar, wenn alle Findings geschlossen sind).

### Code-Cruft (kein MD, aber Teil von „cleaning")

- ✅ `classes/local/wizard/__pycache__/` — Build-Cruft (C4-F06 / 11-F07) **entfernt 2026-06-30**.
- ✅ `db/caches.php` — tote Caches `aiwaitstate`/`aiwaitmailbox` **entfernt** (C2-F04), Doc §6 angepasst.
- ⏸️ `classes/local/wizard/wunderbyte_trial_endpoint.py` + `wunderbyte_shop_endpoint.py` (C2-F05 / C5-F09 / 11-F07) —
  server-seitige Referenz-Implementierungen, 0 PHP-Referenzen. **Nicht eigenmächtig gelöscht** (von mir nicht
  erstellt; evtl. einzige Repo-Kopie). George-Entscheidung: aus `classes/` rausziehen/löschen — sag Bescheid.
- ⏸️ `obsolet/` (`ROADMAP.md`, `todo`) — vor dem Packaging klären (C4-F06 / 16-F11).

---

## D. Empfohlene Reihenfolge bis Go-Live

1. **05-F01** (Engine-Leak) — 🔧 bereits in Arbeit (George, 2026-06-30); *vor* dem Engine-Freeze für den `local_wizard`-Cut abschließen (Plan: `skill_engine_service_boundary*`).
2. ✅ **Architektur-Kapitel 02 + 07 + 09 + 11 nachgezogen (2026-06-30)** — inkl. 09-F01. Rest: Kap. 03
   (LLM-Debug-Retention, minor) + `flowchart-guide.md` C4-F05/QNORM (kosmetisch) + `agent_access_service`/`wb_license`
   (02-F04-Rest) offen.
3. **Maintainer-Entscheidung 15-F01** (Debug-Logging gaten?) + **15-F02** (Credential-Write auf `site:config`?).
4. **Doc-Cluster:** ✅ C5-F01 (observability + data-model re-prefixt), ✅ C5-F05 (`bx_agent_user_memory` ergänzt),
   ✅ 02-F04 (Operating-Context + PRO/Readonly-Gate dokumentiert), ✅ Kap. 03 Retention, ✅ **C5-F02** (stale
   Orchestrator-Zeilennummern in Kap. 04/05 + flowchart-guide entfernt und auf stabile Klassen-/Methodennamen
   re-anchort), ✅ **C5-F03** (`query_english_normalizer` Discovery-LLM-Call in Kap. 06 §4 + 16 dokumentiert,
   „no chat call" weichgezeichnet), ✅ **C5-F04** (flowchart-guide-Mandatory-Tier-Eintrag an ch.06 §4 angeglichen:
   `get_mandatory_skills()` entfernt, einzige Force-Include `wizard.search_skills`). **Doc-Cluster komplett.**
5. **`phcs --standard=moodle` + PHPUnit im Container** über die 2026-06-30-Fixes (wurden nur handgeprüft).
6. **Cleaning** der MD-/Code-Cruft nach Abschnitt C.
7. MEDIUM/LOW-Backlog (Cache-/Cap-Leichen, Duplikation, `strict_types`) nach Launch.
</content>
</invoke>
