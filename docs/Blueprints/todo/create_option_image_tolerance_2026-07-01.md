# Blueprint · create_option: Bild-Capability, Retry-Toleranz & die Prompt-vs-Harness-Grenze

> **Status:** TEMPORÄR — nicht committen. Planungsdokument, kein Produktions-Doc.
> **Datum:** 2026-07-01
> **Auslöser:** Debug-Thread 60 (`mod_booking.create_option` „erstelle … nimm das bild" → Schema-Mismatch-Schleife).
> **Scope:** `bookingextension_agent` (Engine/Harness) + `mod_booking` Option-Skills.

---

## 1. Was in Thread 60 passierte

Der Constructor (llm_debug 6387) baute für „erstelle eine Buchungsmöglichkeit … nimm das bild":

```json
{"skill":"mod_booking.create_option","parameters":{
  "text":"Meine Testbuchung","maxanswers":10,
  "coursestarttime":"2026-07-03T10:00:00+02:00",
  "courseendtime":"2026-07-03T12:00:00+02:00",
  "coursequery":"booking",                                          // ungültig
  "bookingimagestorage":0,                                          // erfunden
  "attachmenturl":"…/draftfile.php/…/wunderbyte-agent-logo.png"     // erfunden
}}
```

`check_structure` verwarf die 3 Unknown-Keys → `RECOVERABLE_INPUT_ERROR` → Retry → erneut Bad Keys → Terminal-Fehler (Msg 176 „…wird automatisch wiederholt", kam aber nicht mehr durch).

**Zwei unterschiedliche Defekte, oft verwechselt:**

| Defekt | Natur | Richtige Schicht |
|---|---|---|
| `coursequery` statt `activityquery`/omit | Construction-Bias (Halluzination) | Prompt/Guidance |
| Bild via `attachmenturl`/`bookingimagestorage` | **Capability-Gap**: `create_option` hat gar keinen Bild-Param | Feature bauen |

`create_option` Prompt-Whitelist (`create_option_skill.php:165`) — **kein** Bild-Feld:
```
text, coursestarttime, courseendtime, optiondates, optiondatesmode,
maxanswers, teacherquery, teacheremail, prices, bookingopeningtime,
bookingclosingtime, maxoverbooking, override, outputlang, activityquery
```
`update_option` hat dagegen den Guidance-Pack `mod_booking.header_image_attachment` (update_option_skill.php:232): Attachment-Token → `headerimage_token`.

---

## 2. Der Smoking Gun in der Retry-Message

`create_option_skill::build_create_option_retry_message()` (Z. 422) endet heute mit:

> „Remove unknown keys: …" … **„Resend exactly one corrected task_call for the same task."**

Das **verbietet** dem LLM den ehrlichen Ausweg: Es *muss* einen task_call nachliefern. Für das Bild existiert kein gültiger Key → das Modell kann nur (a) das Bild still weglassen oder (b) erneut halluzinieren → Schleife.

Architektur ist aber auf unserer Seite: bei `RECOVERABLE_INPUT_ERROR` setzt der Interpreter `response_type => 'clarification'` (interpreter.php:250) → läuft über den Synchronizer (ch.13 Matrix). Der „das geht nicht"-Weg ist voll unterstützt — die Retry-Message verbietet ihn nur.

---

## 3. Die entscheidende Frage: Prompt-Arbeit vs. Harness-Arbeit

Best Practice ist **nicht** „Prompt oder Harness", sondern ein Unterscheidungskriterium:

> **Wenn das LLM diese Anweisung ignoriert — ist das Ergebnis *unsicher* oder nur *suboptimal*?**
> - Unsicher → **muss** in den Harness (Invariante, darf nicht von einem probabilistischen Modell abhängen).
> - Suboptimal → **gehört in den Prompt**, *sofern* der Harness darunter einen sicheren/begrenzten Boden garantiert.

**Grundregel: Garantien nach unten in den Code, Urteilsvermögen nach oben in den Prompt.**
Man kann „Ich habe X angelegt, das Bild ging nicht, weil…" nicht für jeden Fall deterministisch in PHP schreiben, ohne die Sprachfähigkeit des Modells nachzubauen — also soll man es nicht. Output-Form steuern ist der legitime Zweck eines Prompts. Anti-Pattern wäre nur, eine **Sicherheits**-Invariante dem Prompt anzuvertrauen.

### Der Harness hat den Boden schon (verifiziert)

- `agent_runtime.php:69` — `LOOP_MAX_RETRIES_PER_ISSUE = 1` → Retries begrenzt, keine Endlosschleife.
- `check_structure` → kein ungültiger Key exekutiert je (Invariante).
- `LOOP_RETRY_EXHAUSTED` (agent_runtime.php:250) → terminaler Zustand bei Nicht-Konvergenz.

→ „Erfinde keinen Key" ist damit ein **suboptimal-wenn-ignoriert**-Fall (abgefangener Schema-Fehler + begrenzter Retry), **nicht** unsicher. **Also ist der Prompt die richtige primäre Schicht** — gerade *weil* die Invarianten schon deterministisch im Harness sitzen.

---

## 4. Die „richtige" Lösung: 3-schichtig & proportional

### Schicht 1 — Capability (bauen, nicht wegerklären)
`headerimage_token` auf `create_option` freischalten + `header_image_attachment`-Guidance-Pack teilen (existiert auf `update_option:232`).
- Das Bild ist ein legitimer, unterstützbarer Wunsch — ein **Capability-Gap**.
- Weder besseres Prompting noch Harness-Toleranz darf ein fehlendes Feature kaschieren (Kategorienfehler aus *beiden* Schichten raushalten).
- Reine Skill-Arbeit (Schema-Whitelist + geteilter Guidance-Pack).

### Schicht 2 — Prompt (Konvergenz steuern)
`build_create_option_retry_message()` umbauen:
1. **Escape-Hatch ergänzen:** „Wenn ein Teil des Nutzerwunsches auf keinen erlaubten Key abbildbar ist, erfinde keinen Key. Sende nur die gültigen Keys **und** sag dem User klar, welcher Teil nicht umgesetzt werden konnte und warum."
2. **Zwangszeile entschärfen:** „Resend exactly one corrected task_call…" → so, dass der ehrliche Teilerfolg / die Erklärung **erlaubt** ist.
- **Scoping (tragende Grenze):** Escape-Hatch nur für **optionale** Teile ohne Slot (Bild). Fehlende **Pflichtfelder** (Titel) und **Targeting** bleiben „nachfragen", nicht „still weglassen". Der Code trennt das schon (`missingtitle` → eigener Clarification-Zweig) → Formulierung sauber auf den unknown-props-Zweig begrenzen. **Kein** Blanko-„lass alles weg, was nicht passt".
- Optional generalisierbar: Escape-Hatch in den **geteilten** Retry-Helfer aller mutierenden Option-Skills → „systemweit toleranter" über die Prompt-Schicht, ohne Engine-Kanal.

### Schicht 3 — Harness-Boden ehrlicher machen (die eine echte Lücke)
Heute liefert `LOOP_RETRY_EXHAUSTED` ein generisches „Bitte vereinfache deine Anfrage" (agent_runtime.php:739).
- Best Practice für einen Boden: der deterministische Terminal soll **wahrheitsgemäß** sagen, was passierte („nicht unterstützte Parameter: X"), statt einer generischen Bitte.
- Klein, deterministisch, invarianten-artig (User bekommt *immer* eine ehrliche Endmeldung) — **ohne** die schwere Toleranz-Maschinerie.

---

## 5. Bewusst AUFGESCHOBEN: der Engine-Toleranz-Kanal (Option „ii")

**Idee:** Statt Fehler zu werfen, den gültigen Param-Teil ausführen und die verworfenen Inputs als Result-Metadatum (`ignored_inputs[]`) zum Synchronizer tragen — analog dem existierenden Muster `affected_scope_summary` (R2) / `irreversibility_notice` (R3).

**Warum aufgeschoben:** Der volle Warnungs-Kanal ist die **Harness-Version des Häufig-Falls** — genau das, was das LLM in der Prompt-Schicht schon gut kann. **Proportionale Verteidigung:** die schwere deterministische Maschine erst bauen, wenn *Daten* zeigen, dass der Prompt-Tail (LLM ignoriert die Anweisung trotzdem) real und häufig genug ist. Vorher überbaut man ein probabilistisch seltenes Problem mit teurer Engine-Komplexität + Flowchart-Delta.

**Falls doch nötig (Referenz, damit die Analyse nicht verloren geht):**
- Neuer 4. Validierungs-Ausgang in `parameter_contract_validator` / Skill-`check_structure`: `valid_with_ignored_inputs`.
- `ignored_inputs` ist Metadatum, **kein** issue_code (sonst kippt die Finalization-Matrix ch.13 auf template_only → Routing kaputt).
- Zwei Disclosure-Konsumenten: `sufficient` → Synchronizer (erzwungen via `synchronizer_output_contract`, neuer Code `SYNC_IGNORED_INPUTS_MISSING`); `confirmation_request` → deterministisch an die Message hängen (direct_final, kein Synchronizer).
- Observation: gedroppte **Key-Namen** über `observation_builder` (Werte durch `privacy_anonymizer`).
- Flowchart-Deltas: `PVAL` (Z.107), `SYNC_GATE`/`SCONTRACT` (Z.160/164), `LG_SYNC`/`LG_RISK_SYNC` (Z.619/623).
- Partition droppable/blocking ist die tragende Invariante — Targeting/Pflicht niemals still droppen (sonst „erfolgreich das Falsche mutiert").

---

## 6. Leitsatz

**Capability für das Bild bauen · Prompt für die Konvergenz steuern · Harness-Boden ehrlich halten · Toleranz-Kanal aufgeschoben, bis er sich verdient.**

„Richtig" heißt nicht Prompt-vs-Harness, sondern: jede Sorge in die Schicht legen, die zu ihrer Natur passt (Invariante vs. Urteil), mit einem sicheren Boden im Harness, damit der Prompt frei optimieren darf — und die Maschinerie an die **tatsächliche** Fehlerrate dimensionieren, nicht an den schlimmsten vorstellbaren Fall.

---

## 6b. Umsetzungsstand (2026-07-01) + Mechanik-Korrektur

**Schicht 1 (Bild-Capability) — UMGESETZT:**
- `create_option` Whitelist um `headerimage_token` erweitert (create_option_skill.php ~165). Der geteilte Execute-Service (`booking_skill_mutation_execute_service::apply_headerimage_token_to_data`) verarbeitet das Token bei create bereits → Bild-auf-Create funktioniert real.
- `header_image_attachment`-Guidance-Pack in `booking_skill_base::header_image_attachment_prompt_pack()` gezogen; `update_option` UND `create_option` referenzieren ihn (DRY). Pack-Text um „auch beim Anlegen" ergänzt.

**Schicht 2 (Escape-Hatch) — UMGESETZT, im geteilten Helfer:**
- Neuer `booking_skill_base::build_unsupported_params_guidance(array $allowedkeys)` — „nur erlaubte Keys; wenn etwas auf keinen Key passt, nicht erfinden, sondern dem User ehrlich sagen; kein Auto-Retry versprechen". Für jeden Option-Skill nutzbar.
- `create_option::build_create_option_retry_message` nutzt ihn; die Zwangszeile „Resend exactly one corrected task_call" **entfernt**.

**Anker-Test:** `mod/booking/tests/agent_create_option_image_test.php` (2 Tests, pur/DB-frei). phpcs 0/0 (das `@covers` „error" ist ein reiner /tmp-Pfad-Artefakt, in-tree sauber). **PHPUnit noch nicht ausgeführt** (kein PHP lokal; VM-Checkout ist divergenter/geteilter Branch → nicht unaufgefordert anfassen).

**KORREKTUR der Schicht-3-Prämisse (§4/§7.3):** Die Annahme „`LOOP_RETRY_EXHAUSTED` liefert das generische ‚vereinfache deine Anfrage'" ist **so nicht korrekt**:
- `RECOVERABLE_INPUT_ERROR` → `response_type='clarification'` → **terminale Clarification** (kein Framework-Retry). Das „wird automatisch wiederholt" aus Thread 60 war der Synchronizer, der die Engine-Retry-Jargon-Message fehlrenderte → **genau das behebt Schicht 2** (der eigentliche Thread-60-Pfad).
- `LOOP_RETRYABLE_ISSUE_CODES` = nur `CONTRACT_PARSE_ERROR` + `CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED`, nur bei `response_type='error'`. Deren Exhaust finalisiert mit dem **letzten** Result (trägt den echten Fehler), nicht mit dem generischen Text.
- Das echte generische „vereinfache"-Terminal ist `build_budget_exceeded_result` (Loop-Schritte/Budget erschöpft), dessen Message der **Template-Service über den Lang-Key `BUDGET_EXCEEDED`** rendert. Echte Fehler sieht dort heute **nur der Admin** (`is_siteadmin` → `(Details: …)`).

→ **Schicht 3 ist damit orthogonal zu Thread 60** und keine mechanische Zeile, sondern eine **Design-Entscheidung**: Wie viel technische Ehrlichkeit bekommt ein End-User auf einem Sackgassen-Terminal (das der Code heute bewusst vor Endnutzern verbirgt)? Das gehört Georg, nicht mir. **Wartet auf Entscheidung** (siehe §7.5).

---

## 7. Offene Entscheidungen für Georg

1. Schicht 2 nur in `create_option` oder gleich im **geteilten** Retry-Helfer aller Option-Skills?
Antowrt: im geteilten helfer
2. `headerimage_token` auf `create_option` jetzt (Schicht 1) oder separat?
Antwort: jetzt
3. Schicht 3 (ehrlicher Terminal) im selben Zug oder eigener kleiner Fix?
Antwort: gleich
4. Option „ii" endgültig zurückstellen (ja/nein)?
antowrt: ja
5. **NEU (nach Mechanik-Korrektur 6b):** Schicht 3 „ehrlicher Terminal".
Antwort: **als durch Schicht 2 erledigt betrachten** — kein i18n/Template-Touch. Die Thread-60-Falschmeldung ist weg; das generische `BUDGET_EXCEEDED`-Terminal bleibt bewusst knapp für End-User.
6. **PHPUnit ausführen?**
Antwort: **auf VM ausgeführt** — `/var/www/moodle/public` spiegelt den lokalen Checkout (Sync auf lokale Disk), Edits + Test dort live, kein Datei-Juggling nötig. `agent_create_option_image_test` **2/2 grün** (7 Assertions). Die eine PHPUnit-Deprecation (`@covers`-Docblock) ist harness-weit und tritt bei bestehenden Tests genauso auf.

---

## 8. ABGESCHLOSSEN

Schicht 1 + 2 umgesetzt, phpcs 0/0, PHPUnit 2/2 grün. Schicht 3 verworfen (durch Schicht 2 abgedeckt), Option „ii" zurückgestellt. Offen nur noch: Deploy (Embeddings-Rebuild, damit der `headerimage_token`-Guidance-Pack + die geänderte create_option-Description in die Selection/Construction-Embeddings einfließen) — braucht Georgs Go.
