# AI Error Messaging — Schluss mit „The AI provider returned an error"

**Datum:** 2026-06-11
**Status:** Blueprint (gegen HEAD verifiziert; Neuerstellung der verlorenen Erstfassung)
**Ziel (Georg):** Die generische Provider-Pauschalmeldung darf den User **nie mehr** erreichen. Jeder Fehler zeigt seine echte Ursachenklasse — in User-Sprache, mit Admin-Detail wo sinnvoll.

---

## 1. Problem

`$string['ai_provider_error'] = 'The AI provider returned an error. Please try again later.'` ist heute der Sammelbecken-Text für **grundverschiedene** Fehlerklassen. Live-Beispiele:

- **Threads 323/326:** Ein Skill-Fehler („Ungültige Kursmodul-ID" — kein Provider beteiligt!) erschien dem User als Provider-Fehler. *(Wurzel inzwischen skill-seitig gefixt: Scope-Guard `d268c07b0`/`5ec2b78a2` — aber die Verpackungsschicht, die das möglich machte, existiert weiter.)*
- **Dashboard-Readiness (2026-06-10):** Ein TypeError im Routing (`exception_thrown`) zeigte dem Admin „The AI provider returned an error" + „No AI provider configured" gleichzeitig — beides falsch.

Die Folgen: User probieren sinnlos „später noch einmal", Admins suchen am falschen Knopf (Provider-Konfiguration statt eigentlicher Ursache), und Bug-Reports verlieren die Ursache.

## 2. Ist-Inventur (gegen HEAD, 2026-06-11)

### 2.1 Quellen der Pauschalmeldung (`ai_provider_error`)

| # | Stelle | Tatsächliche Fehlerklasse | Pauschale berechtigt? |
|---|---|---|---|
| 1 | `orchestrator::build_provider_error_result()` (~Z. 1564) | **Echter Provider-Fehler** (Call fehlgeschlagen); `error_class` wird bereits differenziert: `auth_failed`, `quota_exceeded`, `provider_timeout`, `transient_io`, `provider_error` | teilweise — aber `message` ignoriert die eigene Klassifizierung |
| 2 | `orchestrator::build_empty_provider_result()` (~Z. 1601) | Provider lieferte leeren Content (`transient_io`) | nein — „leere Antwort, bitte erneut" wäre ehrlich |
| 3 | `orchestrator::build_selection_contract_error_result()` (~Z. 1530) | **Interner Contract-Fehler** (Planner-Output ungültig) — kein Provider-Problem | **nein** |
| 4 | `orchestrator::build_selector_handoff_error_result()` (~Z. 1547) | Interner Handoff-Fehler (selected_skill fehlt) | **nein** |
| 5 | `ai_send_message.php:178` (`exception_thrown`-Zweig) | Beliebige Exception in `get_runtime_provider_status` (z. B. der Dashboard-TypeError) | **nein** |
| 6 | `aiready.php` reasonmap `'exception_thrown' => 'ai_provider_error'` | dito, Readiness-Panel | **nein** |

### 2.2 Was bereits existiert (und ausgebaut statt neu erfunden werden soll)

- **`ai_error_classifier::classify_from_response()`** → issue codes (`TRIAL_TOKEN_INVALID`, `AI_PROVIDER_QUOTA_EXCEEDED`, …).
- **`error_class`** auf Provider-Fehler-Payloads (`auth_failed` / `quota_exceeded` / `provider_timeout` / `transient_io` / `provider_error`).
- **`finalization_template_service`**: mappt bereits `issue_codes` und `error_class` auf bessere Texte (`ISSUE_CODE_LANG_KEYS`, `ERROR_CLASS_LANG_KEYS`, `ISSUE_CODE_MESSAGES`) — greift aber nur im Template-Finalisierungspfad, nicht an den Quellen 1–6.
- **Readiness-Reasonmap** (`ai_send_message`/`aiready`): `subsystem_missing`, `no_provider`, `provider_inactive`, `actions_missing`, `course_disabled`, `context_disabled` → eigene `error_ai_*`-Strings. **Nur** `exception_thrown` fällt auf die Pauschale zurück.
- **Skill-Results** tragen die Wahrheit bereits in `results[].detail` (lokalisiert) + `issue_codes` — sie geht nur auf dem Weg zur `message` verloren.

## 3. Fehler-Taxonomie (vollständige Ursachenliste)

### A. Verfügbarkeit („AI provider not available", Pre-Flight vor jedem Call)
| Reason | Bedeutung | Zielgruppe der Meldung |
|---|---|---|
| `subsystem_missing` | core_ai nicht installiert/verfügbar | Admin |
| `no_provider` | kein Provider konfiguriert | Admin |
| `provider_inactive` | Provider konfiguriert, aber deaktiviert / keine Text-Action | Admin |
| `actions_missing` | benötigte Action-Klassen fehlen (z. B. WB-Planner-Actions) | Admin |
| `course_disabled` | Kurs-Toggle `enableaitools` aus (gilt nicht für Bypass-Cap-Inhaber) | User + Admin-Hinweis |
| `context_disabled` | CM-Toggle aus | User + Admin-Hinweis |
| `exception_thrown` | Statusermittlung selbst crashte | **eigene Klasse:** „interner Fehler bei der Statusprüfung" + debugging() |

### B. Provider-Call-Fehler (Call gemacht, Antwort schlecht)
`auth_failed` (Token/Key ungültig) · `quota_exceeded` · `provider_timeout` · `transient_io` (Verbindung/leerer Content) · `provider_error` (Rest, mit Originaltext im Admin-Detail).

### C. Contract-/Planner-Fehler (intern, Provider hat geliefert)
`CONTRACT_PARSE_ERROR` (kein valides JSON) · Selection-Contract-Verletzungen · `CONTRACT_SELECTION_SKILL_MISSING` (Handoff). Ehrliche Meldung: „Bei der Planung ist ein interner Fehler aufgetreten — bitte erneut versuchen" (Retry-Schleife existiert bereits; Meldung nur, wenn Retries erschöpft).

### D. Skill-Ausführungsfehler (die 323er-Klasse)
`results[].status = error/failed` mit lokalisiertem `detail`. **Regel: `detail` IST die User-Meldung.** Niemals durch eine Provider-Floskel ersetzen. `RECOVERABLE_INPUT_ERROR` → Clarification-Fluss (existiert), nicht Fehler-Fluss.

### E. Governance/Framework
`runtime_disabled` (Skill deaktiviert) · `PERMISSION_ERROR`/`NO_NATIVE_CAPABILITY` · `EXECUTION_GUARD_MISSING/MISMATCH` (interner Fehler, nicht Provider) · `RUNTIME_UNAVAILABLE`.

## 4. Design-Prinzipien

1. **Eine zentrale Auflösung:** `finalization_template_service::resolve_message()` wird DIE Stelle, die aus `issue_codes` + `error_class` + `results[].detail` die User-Meldung baut. Die Quellen 1–6 liefern nur noch klassifizierte Payloads (`error_class`, `issue_codes`, `errors[]` für Admin-Detail) — **keine** `message`-Pauschale mehr an der Quelle.
2. **Skill-Wahrheit gewinnt:** Hat ein failed Result ein nicht-leeres `detail`, ist das die Meldung (Klasse D schlägt B/C-Fallbacks).
3. **Provider nur beschuldigen, wenn der Provider schuld ist:** Klassen C/E erhalten eigene „interner Fehler"-Strings; `exception_thrown` ebenso.
4. **Zwei Sichten:** User sieht den lokalisierten Klassentext; Admins (`is_platform_admin` bzw. Debug-Mode) zusätzlich `errors[0]`-Originaltext — heute schon teilweise im aiready-Panel etabliert, gleiches Muster für Chat-Fehler.
5. **Synchronizer-Vertrag respektieren:** Fehler-Results gehen als `direct_final`/`template_only` raus (Matrix existiert) — der Synchronizer darf eine korrekte Fehlerklasse nie in eine Pauschale „glätten".
6. **Jede neue Fehlerquelle muss klassifizieren:** Lint-/Review-Regel: kein neues `get_string('ai_provider_error', …)` außerhalb des zentralen Resolvers (CI-Grep).

## 5. Umsetzungsplan

| Phase | Inhalt | Aufwand |
|---|---|---|
| **P1** | Neue Lang-Strings (en/de): `error_ai_internal_planning` (Klasse C), `error_ai_internal_status` (`exception_thrown`), `error_ai_provider_timeout`, `error_ai_transient_io`, `error_ai_empty_response`; `ERROR_CLASS_LANG_KEYS` im Template-Service vervollständigen (alle B-Klassen) | klein |
| **P2** | Quellen 3/4/5/6 auf interne Klassen umstellen (`error_class = 'internal_contract'` / `'internal_status'`), Quelle 1/2: `message` aus dem Template-Service statt Pauschale | klein |
| **P3** | Failed-Run-Pfad: `message` aus `results[].detail` zusammensetzen (mehrere Results: erste Fehlerzeile + „weitere Fehler"-Hinweis); `ai_confirm_run`-Antwortpfad identisch behandeln | mittel |
| **P4** | Admin-Detail-Kanal im Chat (debugmessage-Feld existiert; bei `is_platform_admin` an die Fehlermeldung anhängen) | klein |
| **P5** | CI-Guard: Grep-Test, dass `ai_provider_error` nur noch im zentralen Resolver vorkommt; deterministische Unit-Tests je Fehlerklasse (Payload rein → erwartete Meldung raus) | klein |

**Nicht-Ziele:** Keine Änderung der Retry-Logik (Backoff/Klassifizierung bleibt); keine neuen Fehlerquellen-Patterns; Klasse-D-Wurzeln werden weiterhin skill-seitig gefixt (Muster: Scope-Guard, Grounding-Contract).

## 6. Testplan

- Unit: `finalization_template_service`-Matrix — pro (issue_codes, error_class, results) die erwartete Meldung (deterministisch, kein LLM).
- Benchmark: `confirmation_request_r1` erwartet weiterhin `error` als response_type — Texte ändern sich, Typen nicht; `budget_exceeded`-Szenario unverändert.
- Real-LLM-Spotchecks: provozierter Quota-/Timeout-Fall (Stub), Skill-Fehler (Klasse D) am Dashboard.

## 7. Bezug zu erledigten Arbeiten

- Threads 323/326 (Klasse D am Dashboard): skill-seitig gefixt (`dbd3f93ec`, `d268c07b0`, `5ec2b78a2` + Instanzliste) — dieser Blueprint beseitigt die Verpackungsschicht, die solche Fehler als Provider-Probleme tarnt.
- Dashboard-Readiness-Fixes (`4d6ca0b`): `exception_thrown` ist seither selten — verdient aber trotzdem die eigene Klasse statt der Provider-Floskel.
