# AI Error Messaging — Schluss mit „The AI provider returned an error"

**Datum:** 2026-06-11
**Status:** Blueprint v2 (gegen HEAD verifiziert). **Vorgänger:** [`AI_ERROR_MESSAGING_ANALYSIS.md`](AI_ERROR_MESSAGING_ANALYSIS.md) (2026-06-08, umgesetzt in `f9506c4`) hat die Klassifizierungs-Infrastruktur geschaffen (failurereason-Mapping, error_class, weiche Berechtigungsprüfung). Dieses Dokument ist die Lückenanalyse danach: Die Klassifizierung existiert, aber sechs Quellen setzen die `message` weiterhin auf die Pauschale — v2 stellt die Meldungs-Auflösung zentral und ursachenehrlich.
**Ziel (Georg):** Die generische Provider-Pauschalmeldung darf den User **nie mehr** erreichen. Jeder Fehler zeigt seine echte Ursachenklasse — und zwar **präsentiert vom user-facing LLM (Synchronizer)**, wie alle anderen Antworten auch (Sprachtreue, Konversationston). Es geht NICHT darum, den Synchronizer durch Template-Strings zu ersetzen, sondern darum, ihm endlich klassengerechte Fehlerinformation zu geben statt der nichtssagenden Provider-Floskel.

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

1. **Der Synchronizer präsentiert Fehler (Regelfall).** Fehler sind Antworten wie alle anderen: Der Synchronizer formuliert sie in der Sprache des Users, im Konversationskontext, mit sinnvollem nächsten Schritt („In diesem Kurs ist KI deaktiviert — soll ich dir zeigen, wo du das einschalten kannst?"). Voraussetzung ist nur, dass er **klassengerechte Information** bekommt statt der Floskel.
2. **Strukturierte Fehler-Observation als Synchronizer-Input.** Quellen 1–6 liefern keine fertige `message` mehr, sondern einen klassifizierten Payload (`error_class`, `issue_codes`, `errors[]`-Detail). Daraus baut die Engine eine Fehler-Observation für den Synchronizer, z. B.:
   `[ERROR] class=course_disabled · reason: AI tools are disabled for this course · explain this to the user in their language and suggest the next step; do NOT blame the AI provider; do NOT invent other causes.`
   Diese Observation ist Engine-Instruktionstext (`observation_engine_static` — keine Anonymizer-Korruption).
3. **Skill-Wahrheit gewinnt:** Hat ein failed Result ein nicht-leeres `detail` (Klasse D), ist DAS der Inhalt der Fehler-Observation — der Synchronizer präsentiert die Skill-Meldung, nie eine Provider-Floskel darüber.
4. **Provider nur beschuldigen, wenn der Provider schuld ist:** Klassen C/E werden als „interner Planungsfehler"/Governance benannt; `exception_thrown` als interner Statusfehler.
5. **Template-Fallback NUR wenn der Synchronizer selbst nicht kann.** Bei den Klassen, in denen kein LLM-Call möglich oder sinnvoll ist (A: Provider nicht verfügbar; B: auth_failed/quota/timeout/transient — man kann einen toten Provider nicht bitten, sich zu entschuldigen), greift der deterministische `finalization_template_service` mit lokalisierten, klassenspezifischen Strings. Genau dafür hat v1 das `template_only`-Routing gebaut — es bekommt jetzt vollständige, ehrliche Texte pro Klasse statt der Pauschale. Für ALLE anderen Klassen gilt Prinzip 1.
6. **Zwei Sichten:** User sieht die Synchronizer-Formulierung (bzw. den Klassen-Template-Text); Admins (`is_platform_admin`/Debug-Mode) zusätzlich `errors[0]`-Originaldetail — Muster wie im aiready-Panel.
7. **Jede neue Fehlerquelle muss klassifizieren:** kein neues `get_string('ai_provider_error', …)` außerhalb des Template-Fallbacks (CI-Grep).

## 5. Umsetzungsplan

| Phase | Inhalt | Aufwand |
|---|---|---|
| **P1** | **Fehler-Observation-Builder** (Engine): aus `error_class` + `issue_codes` + `errors[]`/`results[].detail` eine `[ERROR]`-Observation für den Synchronizer bauen (engine-static, mit Präsentations-Instruktion und Anti-Floskel-/Anti-Erfindungs-Regel); Routing: Fehler-Results der Klassen C/D/E laufen durch den normalen Synchronizer-Pfad statt `direct_final` mit Pauschale | mittel |
| **P2** | Quellen 3/4/5/6 klassifizieren (`error_class = 'internal_contract'` / `'internal_status'`) und an P1 anschließen; **keine** `message`-Pauschale mehr an der Quelle | klein |
| **P3** | **Template-Fallback vervollständigen** (nur Klassen A/B, in denen kein Synchronizer-Call möglich ist): Lang-Strings en/de pro Klasse (`error_ai_internal_status`, `error_ai_provider_timeout`, `error_ai_transient_io`, `error_ai_empty_response`); `ERROR_CLASS_LANG_KEYS` komplettieren; Quelle 1/2 nutzen ausschließlich diese Klassentexte | klein |
| **P4** | Admin-Detail-Kanal im Chat (debugmessage-Feld existiert; bei `is_platform_admin` Original-`errors[0]` anhängen) | klein |
| **P5** | CI-Guard: `ai_provider_error` nur noch im Template-Fallback; deterministische Unit-Tests: (a) Payload je Klasse → erwartete Observation bzw. Template-Text, (b) Synchronizer-Contract-Test, dass die Fehler-Observation im Sync-Prompt landet | klein |

**Nicht-Ziele:** Keine Änderung der Retry-Logik (Backoff/Klassifizierung bleibt); der Synchronizer wird NICHT durch Templates ersetzt (Templates nur als Fallback, wenn der Provider selbst die Ursache ist); Klasse-D-Wurzeln werden weiterhin skill-seitig gefixt (Muster: Scope-Guard, Grounding-Contract).

## 6. Testplan

- Unit (deterministisch, kein LLM): (a) Fehler-Observation-Builder — pro (error_class, issue_codes, detail) die erwartete `[ERROR]`-Observation; (b) Template-Fallback-Matrix für die A/B-Klassen.
- Synchronizer-Contract: Fehler-Observation erscheint im Sync-Prompt; Sync-Antwort ist `sufficient`/`error` mit leerem `commands` (bestehender Vertrag), Sprache folgt User-Input.
- Benchmark: `confirmation_request_r1` erwartet weiterhin `error` als response_type — Texte ändern sich, Typen nicht; `budget_exceeded`-Szenario unverändert.
- Real-LLM-Spotchecks: Skill-Fehler (Klasse D) am Dashboard → Synchronizer präsentiert das Skill-Detail in User-Sprache; provozierter Quota-/Timeout-Fall (Stub) → Template-Text.

## 7. Bezug zu erledigten Arbeiten

- Threads 323/326 (Klasse D am Dashboard): skill-seitig gefixt (`dbd3f93ec`, `d268c07b0`, `5ec2b78a2` + Instanzliste) — dieser Blueprint beseitigt die Verpackungsschicht, die solche Fehler als Provider-Probleme tarnt.
- Dashboard-Readiness-Fixes (`4d6ca0b`): `exception_thrown` ist seither selten — verdient aber trotzdem die eigene Klasse statt der Provider-Floskel.
