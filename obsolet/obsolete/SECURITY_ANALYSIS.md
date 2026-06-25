# Sicherheitsanalyse – `bookingextension_agent`

> **Art:** Defensives, read-only Code-Audit des eigenen Plugins (autorisiert).
> **Scope:** `mod/booking/bookingextension/agent` (+ die ausführende Engine/Skills unter
> `mod/booking/classes/local/wizard/`, die der Agent aufruft).
> **Methodik:** Vier parallele, adversariale Tiefen-Audits (AuthZ/Kontext-Isolation, Injection,
> LLM-spezifische Risiken, Daten-Exposure/Secrets/Entry-Points) + manuelle Verifikation aller High/Medium-Funde
> am Quellcode.
> **Stand:** 2026-06-09. Plugin-Version `2026060803`, Branch `first-release`.

---

## 1. Gesamtbewertung

Die **Architektur ist im Kern solide abgesichert** — insbesondere die sicherheitskritischste Fläche (das
LLM mutiert Buchungsdaten) ist sauber gebaut: Mutationen laufen nie aus client-/LLM-gelieferten Parametern,
jede Mutation wird serverseitig pro Skill mit nativen Moodle-Capabilities gegen den **echten** Nutzer geprüft,
Ziele sind auf die Buchungsinstanz gescoped, und das Confirm-Gate ist nutzergebunden. Es gibt **keine**
bestätigte SQL-Injection, **kein** Path-Traversal, **kein** stored/reflected XSS, **keine** SSRF-Fläche und
**keine** Privilege-Escalation über den Agenten.

Die Funde konzentrieren sich auf **eine wiederkehrende Schwachstellenklasse**: thread-gebundene Lese-/
Zustands-Endpunkte vertrauen einer **client-gelieferten, fortlaufend nummerierten und damit ratbaren
`threadid`** ohne Bindung an Eigentümer/Kontext (IDOR). Hinzu kommt ein **fehlender Privacy-Provider**
(Compliance) und eine **deaktivierte Origin-Prüfung im Trial-Backend**.

### Findings-Übersicht

| ID | Titel | Schwere | Status |
|----|-------|---------|--------|
| SEC-01 | IDOR: fremde **LLM-Debug-Logs** lesbar (`ai_get_thread_debug_logs`) | **Hoch** | Bestätigt |
| SEC-02 | IDOR: fremde **Thread-Step-Messages** lesbar (`ai_poll_thread`) | **Mittel** | Bestätigt |
| SEC-03 | IDOR: fremde **pending Queue-Items** verwerfbar (`ai_discard_pending`) → Cross-User-DoS | **Mittel** | Bestätigt |
| SEC-04 | **Fehlender Privacy-Provider** trotz PII-Speicherung (DSGVO) | **Hoch (Compliance)** | Bestätigt |
| SEC-05 | **Trial-Origin-Verifikation deaktiviert** + triviale Challenge (Backend) | **Mittel** | Bestätigt |
| SEC-06 | **Indirekte Prompt-Injection**: keine Daten/Instruktions-Trennung | Mittel (mitigiert) | Bestätigt |
| SEC-07 | **Guard-Token ohne Server-Secret** (HMAC) | Niedrig (Härtung) | Verdacht |
| SEC-08 | Haupt-Capability ohne `riskbitmask` | Niedrig | Bestätigt |
| SEC-09 | Nicht registrierte/gestubbte External-Mutations-Klassen (toter Code) | Info (Cleanup) | Bestätigt |

**Gemeinsame Wurzel von SEC-01–03:** ungescopte `threadid`. Eine zentrale Helper-Methode
`require_thread_owned_by($threadid, $userid, $contextid)` plus konsequenter Einsatz in allen drei Endpunkten
(und idealerweise in den `conversation_store`-Gettern selbst) schließt die gesamte Klasse.

---

## 2. Was gut abgesichert ist (geprüft & bestätigt)

Diese Punkte wurden aktiv geprüft und sind korrekt — wichtig für die Einordnung, dass das Fundament trägt:

- **Mutations-/Executor-Pfad (kein Privilege-Escalation):** Der Executor re-prüft `require_use_capability`
  + `require_valid_context` und erzwingt pro Skill die native Capability `bookingextension/agent:skill_*` am
  aufgelösten **Modul-Kontext** gegen die **echte** `userid` (`executor.php:106,145`,
  `skill_executability_evaluator.php:170-192`). Der Agent umgeht die Booking-Checks nicht.
- **Kein Parameter-Tampering über das LLM:** Commands werden serverseitig in eine thread-gebundene Queue
  persistiert; `ai_confirm_run` sendet nur eine `queue_item_id`, die echten Parameter werden serverseitig aus
  der Queue gelesen (`confirm_run_service` → `queue_command_mapper`). LLM-gelieferte `optionid`/`courseid`
  können den Kontext nicht verlassen — Targeting ist immer auf die `bookingid` des `cmid` gescoped
  (`booking_skill_support.php:899,264-272,2643`).
- **Async-Ausführung ohne Eskalation:** Der Adhoc-Task liest `userid` aus serverseitig gesetzten Custom-Data
  und re-autorisiert als dieser Nutzer (`execute_ai_run_adhoc.php:70,120`); **kein** Admin-/Cron-Recht für die
  Skill-Autorisierung.
- **Confirm-Gate nutzergebunden, kein LLM-Selbst-Confirm:** `consume_pending_intent` lehnt fremde Nutzer ab
  (`conversation_store.php:776-781`); Intents sind single-use (Replay-Schutz). Die Session-Autoconfirm-Erlaubnis
  wird **nur** in `confirm_run_service::confirm()` bei explizitem `allow_session=true` aus `ai_confirm_run`
  gesetzt — das LLM hat keinen Schreibpfad darauf.
- **Korrektes Thread-Ownership-Muster existiert bereits** in `ai_send_message.php:211-222`
  (`get_record(... id+userid+contextid+status)`) — genau dieses Muster fehlt in SEC-01/02/03.
- **File-Upload gehärtet:** MIME serverseitig via `finfo` aus dem Binärinhalt (nicht Browser-Angabe),
  Whitelist nur jpeg/png/webp/gif/pdf (**SVG/HTML abgewiesen** → kein stored XSS), Zufallsname in
  nicht-web-erreichbarem Temp-Dir, Token an `userid`+`contextid`+TTL gebunden, Thumbnail via GD re-encodiert
  (`ai_upload_attachment.php:126-144`, `attachment_token_service.php:80-100`).
- **Keine SQL-Injection:** alle `*_sql`-Abfragen nutzen benannte Platzhalter; Volltextsuche korrekt mit
  `sql_like_escape()` + `$DB->sql_like()` (`conversation_store.php:436-445`). Kein ORDER-BY/Tabellenname aus Input.
- **Kein Path-Traversal:** `ai_get_doc_content.php:99-113` nutzt `PARAM_PATH` + `realpath()` + Präfix-Check
  gegen Docs-Root + `.md`-Whitelist + Registry-gemappte Roots.
- **XSS-Sinks serverseitig saniert:** LLM-/Assistant-Output via `ws_message_formatter` →
  `format_text(markdown_to_html(...), FORMAT_HTML)` (KSES); Markdown-Renderer escaped jeden Textteil
  (`htmlspecialchars(ENT_QUOTES)`); Skill-Previews escapen Nutzerfelder mit `s()`. Templates nutzen
  `{{…}}` (auto-escaped); Triple-Stache nur für server-kontrollierte Lang-Strings/JSON.
- **Keine SSRF-Fläche:** keine input-gesteuerten HTTP-Requests im Plugin.
- **Top-Level-Seiten geschützt:** `benchmark_*.php`, `skill_governance.php`, `skill_selection_debug.php`
  verlangen `require_login` + `moodle/site:config` bzw. `admin_externalpage_setup`; State-Changes haben
  `confirm_sesskey()`. Reine Admin-/Debug-Tools.
- **Trial-WS (Moodle-Seite) solide:** `request_trial_key`/`activate_trial_context` verlangen **zusätzlich**
  `moodle/site:config` + `require_sesskey()` — ein normaler Teacher kann keine Tools fremd aktivieren.
- **Keine API-Key-Leaks im PHP-Code:** LLM-Keys liegen in `core_ai`/`aiprovider_wunderbyte`, nicht in
  `settings.php`; kein `error_log`/`var_dump` mit Secrets; nur `debugging(..., DEBUG_DEVELOPER)`.
- **Anonymisierung serverseitig erzwungen:** `precheck_user_message` läuft auf der server-getrimmten
  Nachricht, kein Client-Bypass (`ai_send_message.php:232-233`).

---

## 3. Funde im Detail

### SEC-01 — IDOR: fremde LLM-Debug-Logs lesbar · **Hoch** · Bestätigt
**Ort:** `classes/external/ai_get_thread_debug_logs.php:68-101` →
`classes/local/wizard/conversation_store.php:993-999`

**Schwachstelle:** `execute()` prüft nur `require_use_capability($USER->id, $context->id)` (Z.87) auf dem
**vom Client übergebenen** `contextid` und ruft dann `get_llm_debug_entries($params['threadid'], …)` (Z.98)
auf. Die Datenschicht filtert ausschließlich nach `['threadid' => $threadid]` (Z.996-999) — **keine** Bindung
an `userid` oder `contextid`. Es fehlt zudem `require_sesskey()`.

**Exploit:** Ein Nutzer mit `useaiinstructions` in *irgendeiner* Booking-Instanz iteriert die fortlaufende,
ratbare `threadid` (`SEQUENCE=true`) und liest — bei site-weit aktivem Debug-Modus
(`llm_debug_logger::is_enabled()`) — die **vollständigen, ungekürzten** `requesttext`/`responsetext` fremder
Threads: komplette Prompts inkl. Nutzereingaben anderer Personen, ggf. (de-anonymisierte) PII und Systemdaten,
über Kontext-/Kursgrenzen hinweg.

**Fix:** Vor dem Abruf Thread laden und Ownership erzwingen (analog `ai_send_message.php:211-222`):
`userid === $USER->id && contextid === $context->id`; `require_sesskey()` ergänzen; idealerweise
`get_llm_debug_entries` selbst per JOIN auf `local_wizard_ai_threads.userid` scopen (Defense-in-Depth).

### SEC-02 — IDOR: fremde Thread-Step-Messages lesbar · **Mittel** · Bestätigt
**Ort:** `classes/external/ai_poll_thread.php:93-102` → `conversation_store.php:221-227`

**Schwachstelle:** Bei `threadid > 0` wird die ID ungeprüft übernommen (`$tid = $params['threadid']`, Z.94)
und an `get_step_messages_since($tid, …)` (Z.102) gegeben; das SQL filtert nur `WHERE threadid = :threadid`.
Der Auto-Resolve-Pfad (`threadid = 0`, Z.97) ist korrekt nutzergebunden — der explizite Pfad nicht.
Kein `require_sesskey()`.

**Exploit:** Enumeration fremder `threadid` → Auslesen der Progress-/Step-Nachrichten fremder Konversationen.
Sensitivität geringer als SEC-01 (primär Status-/„thinking"-Texte), aber Cross-User-Konversationsinhalt und
nicht debug-mode-gated.

**Fix:** Identische Ownership-Prüfung wie SEC-01 + `require_sesskey()`.

### SEC-03 — IDOR: fremde pending Queue-Items verwerfbar (Cross-User-DoS) · **Mittel** · Bestätigt
**Ort:** `classes/external/ai_discard_pending.php:91-123`

**Schwachstelle:** `pending_intent_service::consume($threadid, $userid, $contextid)` (Z.91) ist korrekt
nutzergebunden — aber sein Rückgabewert wird **nicht ausgewertet**. Die anschließende Schleife iteriert
`get_queue_items($params['threadid'])` (Z.96) und setzt alle aktionierbaren mutierenden Items per
`to_skipped()` auf „skipped" — direkt auf der **frei übergebenen** `threadid`, ohne Eigentümer-Check.

**Exploit:** Nutzer B (mit `useaiinstructions`) ruft `ai_discard_pending` mit der `threadid` von Nutzer A auf
und annulliert dessen ausstehende, bestätigungsbereite Buchungs-Mutationen → Denial-of-Function gegen A. Kein
Datenabfluss, aber Integritäts-/Verfügbarkeitsproblem.

**Fix:** Vor der Skip-Schleife Thread-Ownership prüfen; oder die Schleife nur ausführen, wenn `consume()` ein
gültiges (nutzergebundenes) Intent zurückgab.

### SEC-04 — Fehlender Privacy-Provider trotz PII-Speicherung · **Hoch (Compliance)** · Bestätigt
**Ort:** kein `classes/privacy/provider.php` vorhanden; PII-Tabellen in `db/install.xml`:
`local_wizard_ai_threads`, `local_wizard_ai_messages`, `local_wizard_ai_runs`, `local_wizard_ai_llm_debug`
(alle mit `userid` + Freitext-Konversationen/Roh-Logs).

**Schwachstelle:** Das Plugin speichert personenbezogene Konversationen, LLM-Roh-Logs und Runs, deklariert
aber **keinen** Privacy-Provider. Diese Daten werden damit weder im Datenschutz-Export erfasst noch beim
„Nutzer löschen"-Request entfernt — Verstoß gegen die Moodle-Privacy-API-Pflicht und DSGVO Art. 15/17.

**Fix:** `classes/privacy/provider.php` implementieren
(`\core_privacy\local\metadata\provider` + `\core_privacy\local\request\plugin\provider` +
`core_userlist_provider`) mit Export/Delete für alle vier Tabellen (Attachments nicht vergessen).
Benchmark-Tabellen entweder als nicht-personenbezogen begründen oder einbeziehen (`baselines.pinned_by`).

### SEC-05 — Trial-Origin-Verifikation deaktiviert + triviale Challenge · **Mittel** · Bestätigt
**Ort:** `classes/local/wizard/wunderbyte_trial_endpoint.py:80` (Backend, im Repo),
`classes/external/request_trial_key.php:90-92`, `trial_challenge.php`

**Schwachstelle:**
- `_verify_origin()` enthält `return True  # -- DISABLED FOR TESTING --` (py:80) — die Back-Channel-
  Nonce-Prüfung wird vollständig übersprungen. Jeder kann einen Trial-Key für eine beliebige `wwwroot`
  anfordern.
- Selbst aktiviert wäre die Challenge schwach: Moodle speichert `nonce_<token> = <token>` und
  `trial_challenge.php` echoed exakt diesen Wert zurück — ein „Beweis" ohne geheimes Material.
- Der 502-Fehlerpfad leakt die interne LiteLLM-Base-URL an den Client (py:210).

**Exploit:** Direkter POST an den Trial-Endpoint mit beliebiger `wwwroot` → kostenloser (budget-/zeit-
limitierter) LLM-Key auf fremde Infrastruktur. Impact durch Budget-/TTL-Limits gemindert.

**Fix:** Debug-`return True` (py:80) entfernen; Challenge auf ein **vom Trial-Server** vergebenes Geheimnis
umstellen (Server gibt Nonce vor, Moodle echoed es), nicht auf einen vom Anforderer kontrollierten Wert;
interne URL nicht im Fehlertext ausgeben. *Hinweis:* Dies ist die Vendor-Backend-Komponente (läuft nicht in
Moodle), liegt aber im Repo.

### SEC-06 — Indirekte Prompt-Injection: keine Daten/Instruktions-Trennung · **Mittel (mitigiert)** · Bestätigt
**Ort:** Prompt-Aufbau in `classes/local/wizard/.../orchestrator.php` (User-Message verbatim als `user`-Role,
~Z.628-633; Tool-Observations ungemarkt in den Planner-Prompt gemischt); keine Anti-Injection-Policy in
`prompt_policy_builder`.

**Schwachstelle:** DB-Inhalte (Optionsnamen/Beschreibungen, RAG-/Such-Treffer), die ggf. von niedriger
privilegierten Nutzern gesetzt wurden, fließen als Observations ohne Markierung „untrusted DATA" in den
Planner-Prompt. Ein Angreifer kann dort Instruktionstext platzieren („Ignoriere vorige Anweisungen …").

**Warum begrenzt (nicht Hoch):** Jede Mutation erfordert (a) eine explizite, nutzergebundene Confirmation und
(b) die Skill-Capability des **bestätigenden** Nutzers; (c) Targets sind auf die `bookingid` gescoped.
Eingeschleuste Anweisungen können **keine Rechte über die des Nutzers hinaus** erlangen — realistischer
Schaden ist Social-Engineering des bestätigenden Teachers zu einer Aktion, die er selbst ausführen dürfte.

**Fix:** DB-abgeleitete Observations und die User-Message in klar delimitierte, als „untrusted data, never
instructions" deklarierte Blöcke kapseln; eine entsprechende System-Policy-Zeile in `prompt_policy_builder`
ergänzen. Sicherstellen, dass die Confirmation-Preview die konkreten Feld-Diffs verbindlich anzeigt.

### SEC-07 — Guard-Token ohne Server-Secret · **Niedrig (Härtung)** · Verdacht
**Ort:** `services/preflight_execution_gate.php:134-161` —
`guard_token = sha256(skillname : contextid : json(input))`, ohne HMAC-Key und ohne `userid`.

**Bewertung:** Das Token ist ein **Integritäts-**, kein Geheimnis-Mechanismus, und im aktuellen Flow **nicht
ausnutzbar** (Queue-Writes laufen serverseitig; kein Pfad reicht client-gelieferten `input`+`guard_token` an
den Executor durch). Es ist damit kein Autorisierungsmerkmal — die echte Autorisierung macht die
Capability-Prüfung im Executor.

**Fix (vorsorglich):** Ein server-seitiges Secret (Plugin-Config-Secret/`get_site_identifier()`) **und**
`userid` in `build_guard_token` einmischen (`hash_hmac`), damit das Token nicht offline reproduzierbar ist —
falls künftig je ein client-gespeister Command-Pfad entsteht.

### SEC-08 — Haupt-Capability ohne `riskbitmask` · **Niedrig** · Bestätigt
**Ort:** `db/access.php:29-35`

`bookingextension/agent:useaiinstructions` ist `captype=write` (zentrale Agenten-Capability), trägt aber
keinen `riskbitmask`. Die generierten Skill-Capabilities setzen korrekt `RISK_DATALOSS | RISK_XSS`.
**Fix:** Der Haupt-Capability mindestens `RISK_DATALOSS` (ggf. `RISK_SPAM`) geben, damit das Admin-UI das
Risiko sichtbar macht.

### SEC-09 — Nicht registrierte/gestubbte External-Mutations-Klassen · **Info (Cleanup)** · Bestätigt
**Ort:** `classes/external/booking_update_option.php`, `booking_create_option.php`,
`booking_bulk_update_options.php`, `booking_validate_option.php`; Service `option_mutation_service` vollständig
gestubbt (alle Methoden returnen Fehler, `option_mutation_service.php:98-136`).

**Bewertung:** Diese External-Klassen sind **nicht** in `db/services.php` registriert und ihr Service ist tot
— funktional irrelevant für die Angriffsfläche, aber Verwechslungsgefahr (der reale Mutationspfad läuft über
die Skills, nicht über diese Klassen). **Fix:** entfernen oder als deprecated kennzeichnen.

---

## 4. Remediation – Priorität

1. **Sofort (Hoch):** SEC-01 — Thread-Ownership + `require_sesskey()` in `ai_get_thread_debug_logs`; Logs per
   `userid` scopen. (Roher PII-/Prompt-Abfluss über ratbare IDs.)
2. **Kurzfristig (Mittel):** SEC-02 + SEC-03 — gleiche Ownership-Prüfung in `ai_poll_thread` und
   `ai_discard_pending`. Gemeinsamer Helper `require_thread_owned_by()`.
3. **Compliance (Hoch):** SEC-04 — Privacy-Provider implementieren (blockiert sauberen Produktionsbetrieb in
   der EU).
4. **Backend (Mittel):** SEC-05 — `_verify_origin`-Debug-Zeile entfernen, Challenge härten.
5. **Defense-in-Depth (Mittel/Niedrig):** SEC-06 (Prompt-Daten/Instruktions-Trennung), SEC-07 (HMAC-Token),
   SEC-08 (riskbitmask), SEC-09 (toten Code entfernen).

### Empfohlener gemeinsamer Fix für SEC-01/02/03 (Skizze)

```php
// in conversation_store oder einem authz-Helper:
public function require_thread_owned_by(int $threadid, int $userid, int $contextid): void {
    global $DB;
    if (!$DB->record_exists('local_wizard_ai_threads',
            ['id' => $threadid, 'userid' => $userid, 'contextid' => $contextid])) {
        throw new \required_capability_exception($context, '...', 'nopermissions', '');
    }
}
// in jedem der drei execute(): nach validate_context + require_use_capability,
// vor dem Datenzugriff aufrufen (für threadid > 0). Plus require_sesskey().
```

---

## 5. Methodische Hinweise / Grenzen

- Alle High/Medium-Funde wurden **am Quellcode manuell verifiziert** (Datenschicht-Filter, fehlende Checks,
  korrektes Vergleichsmuster, Datei-Existenz, Python-Zeile).
- Nicht abschließend dynamisch getestet (kein laufendes System in diesem Audit): die genaue
  Ausnutzbarkeit über Kontextgrenzen (z. B. ob `validate_context` einen `contextid` akzeptiert, der nicht zur
  `threadid` passt) — der Code legt sie nahe, ein Penetrationstest am Live-System sollte SEC-01/02/03
  bestätigen.
- Der LLM-Aufruf selbst läuft über einen externen Shared-Service (`core_ai`/`aiprovider_wunderbyte`),
  außerhalb dieses Plugins — dort nicht auditiert (außer der im Repo liegende Python-Trial-Endpoint, SEC-05).
