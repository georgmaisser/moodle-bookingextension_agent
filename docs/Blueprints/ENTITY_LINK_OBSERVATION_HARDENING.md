# Entity-Link Observation Hardening (Kategorie B & C) — Blueprint

Status: Proposal (2026-06-28). Reine Analyse/Plan, keine Code-Änderungen.
Scope: alle entity-liefernden Skills sollen die moodle_url ihrer Entity **strukturiert in der
Observation** mitgeben, damit die (bereits vorhandene) Synchronizer-LINK-POLICY Kurse/Optionen/
User/Aktivitäten **immer** verlinkt ausgeben kann.

> **Caveat:** Der Booking-Branch wird gerade reorganisiert. Alle `mod_booking`-Pfade/-Signaturen
> (insb. `booking_skill_support::build_option_link_for_output`) **nach Stabilisierung gegenprüfen**.
> Die Agent-Seite (`bookingextension/agent`) ist stabil.

---

## 0. Verifikations-Update (2026-06-28) — durch Lesen der echten Observations korrigiert

Das ursprüngliche Audit war **grep-basiert** (URL-Indikatoren pro Datei) und hatte **False Negatives**:
Skills, die über **geteilte Helfer** bauen, zeigen keine URL-Treffer in der eigenen Datei.
Nach Verifikation des tatsächlichen `observation_full`-Inhalts:

- **Agent-Seite hat KEINE echten Lücken** (keine Änderung nötig):
  - `get_current_user` nutzt `core_skill_base::build_user_observation_full()`, das **`profileurl=<url>` pro User** ausgibt (Z. 694).
  - `update_quiz` schreibt „Quiz URL: <url>" in `observation_full` (Z. 564).
  - `generate_questions` legt die Question-Bank-URL in die Observation (+ Preview).
  - `explain_docs` nimmt die Doc-Quellen-URL in `build_observation_full` auf.
  - Kategorie B (URL „nur in Prosa") ist damit für den Synchronizer **ausreichend** — der Link ist in
    der Observation; ein zusätzliches strukturiertes Feld wäre nur Preview-Komfort, **kein** echter Mangel.

- **Die EINZIGE echte Lücke ist mod_booking Kategorie C: die Option-Mutationen.**
  Verifiziert: der **geteilte** Mutations-Kern
  `booking_skill_mutation_execute_service::persist_and_verify_single_option` (genutzt von
  create_option, create_selflearning_option, create_slotbooking_option, update_option,
  update_option_trainer, bulk_update_options) baut die Verifikations-Observation als
  **„Option \<id\>: confirmed"** — **ID-basiert, ohne moodle_url-Link**.

**Konsequenz — DRY-Fix an EINER Stelle:** in der Verifikations-Observation des geteilten
Mutations-Kerns pro Option zusätzlich `booking_skill_support::build_option_link_for_output($cmid,$optionid)`
ausgeben (z. B. „Option \<id\> '<Titel>' (<link>): confirmed"). Das deckt **alle** Option-Mutationen auf
einmal ab. `add_price_category` / `list_option_properties` separat prüfen, falls sie nicht über denselben
Kern laufen.

**UMGESETZT (2026-06-28, nach Reorg-Abschluss):** Bei genauerer Prüfung war der **Single-Pfad bereits
gelöst** — `build_verification_observation_fields()` stellt den `$executiondetail` (`„…id=… link=…"`) der
`observation_full` voran, und `result_payload_summarizer` bevorzugt `observation_full`. Es fehlte nur der
**Bulk-Pfad**: die `observation_full`-Verifylines (`„Option <id>: confirmed"`) trugen keinen Link, obwohl
`detail` (linklist) ihn hatte. **Fix:** die drei Bulk-Verifylines geben jetzt
`„Option <id> (<moodle_url>): …"` aus (via `build_option_link_for_output`, identisch zur bereits
vorhandenen `linklist`-Logik). Regressionstest: `test_bulk_update_emits_option_links_in_observation`.
Mutations-Contract-Suite grün (10/10 + 2/2). Agent-Seite: keine Änderung nötig (§0 oben).

---

## 1. Ausgangslage

Die Regel „Kurse/Optionen/Aktivitäten/User immer verlinkt ausgeben" ist **bereits korrekt** im
Synchronizer-Output-Contract verankert (`synchronizer_prompt_builder.php`, LINK POLICY: „include the
URL given for it in the observations … use those URLs EXACTLY as provided"). Der Synchronizer kann
aber **nur verlinken, was die Observation als Daten liefert.** Der Hebel liegt also **upstream in den
Skills**, nicht im Prompt.

**Etablierte Konvention (Vorbild, Kategorie A):**
- mod_booking: `booking_skill_support::build_option_link_for_output($cmid, $optionid)` → Muster
  „Title (link)" pro Option in `observation_full` (siehe `get_option_details`, `search_options`;
  Kommentar dort: *„Entity mentions always carry real moodle_url links for the synchronizer"*).
- Agent: `diagnostic_link_builder` (`course()`, `activity()`, `user_profile()`, …) → in den
  diagnose-Skills als Checklist-Row mit `url`-Feld.

**Audit-Ergebnis (Kategorien):**
- **A — strukturiert ✅:** diagnose_user_in_course, diagnose_permissions, diagnose_notifications,
  search_users, get_option_details, search_options, search_courses, add_activity, add_quiz,
  update_activity, analyze_course_structure, configure_booking_instance, analyze_rules,
  create_rule_from_template, update_rule_from_template.
- **B — URL nur in Prosa / unvollständig ⚠️:** book_users, diagnose_booking_issue,
  diagnose_cancellation_issue, diagnose_user_booking, update_quiz, generate_questions, explain_docs.
- **C — keine URL trotz linkbarer Entity ❌:** create_option, create_selflearning_option,
  create_slotbooking_option, update_option, update_option_trainer, add_price_category,
  bulk_update_options, list_option_properties (alle → Buchungsoption ohne Link); get_current_user (User
  ohne Profil-Link).
- **N/A:** remember, forget, recall_memory, list_memories, list_skills, recreate_skill_catalog,
  scaffold_skill, search_skills (keine linkbare Entity — korrekt ohne URL).

---

## 2. Zielzustand (Konvention)

Für **jede** Entity, die ein Skill in der Observation erwähnt (Kurs, Buchungsoption, Aktivität, User,
Regel):

1. **PRIMÄR — in `observation_full`:** die Entity als **„Name (moodle_url)"** ausgeben, URL gebaut über
   den **kanonischen Helper** (nie selbst zusammengebaut). Das ist die Datenquelle, an die die
   Synchronizer-LINK-POLICY bindet.
2. **SEKUNDÄR — strukturiertes Feld:** wo ein Preview/WS die Entity konsumiert, zusätzlich ein
   `link`/`url`-Feld pro Entity im Result (wie `search_options` mit `'link' => …`). Für Previews/
   deterministische Ausgabe; entkoppelt von der LLM-Formatierung.

**Helper (wiederverwenden, nicht duplizieren):**
- Option → `booking_skill_support::build_option_link_for_output($cmid, $optionid)`.
- Kurs/Aktivität/User → `diagnostic_link_builder` (ggf. zu einem allgemeineren `entity_link_builder`
  umbenennen, falls außerhalb des diagnose-Kontexts genutzt — optional, siehe §6).

**Nicht-Ziel:** Synchronizer-Prompt/LINK-POLICY anfassen — die ist korrekt. Kein Eingriff in den
Mutationspfad-Vertrag.

---

## 3. Kategorie C — Lücken schließen (höchste Priorität)

Diese Skills liefern/verändern eine Entity, geben aber **gar keinen** Link. Das untergräbt die
„immer verlinken"-Regel **an der Quelle** (z. B. „Ich habe die Veranstaltung X erstellt" ohne Link auf X).

| Skill | Entity | Aktion |
|---|---|---|
| `create_option` | erstellte Option | Erfolgs-`observation_full` + „Name (Option-Link)"; `resultid`=optionid; Link via build_option_link_for_output |
| `create_selflearning_option` | erstellte Option | dito |
| `create_slotbooking_option` | erstellte Option | dito |
| `update_option` | geänderte Option | „Name (Link)" in der Erfolgs-Observation |
| `update_option_trainer` | betroffene Option | „Name (Link)" |
| `add_price_category` | betroffene Option | „Name (Link)" |
| `bulk_update_options` | N Optionen | je geänderte Option „Name (Link)" (kompakt, ggf. erste K + „… und M weitere") |
| `list_option_properties` | die Option | Option-Link im Kopf der Observation |
| `get_current_user` (Agent) | der User | Profil-Link via `diagnostic_link_builder::user_profile()` in `build_user_observation_full` |

**Hinweis bulk:** Report-Größe beachten (kompakte Liste, nicht jeder Treffer mit voller URL bei sehr
vielen — erste K verlinkt + Zähler), konsistent mit dem Report-Größen-Constraint.

---

## 4. Kategorie B — von Prosa auf vollständig/strukturiert heben

Diese bauen schon *eine* URL, aber unvollständig (nur ein Link, oder nur im Fließtext ohne
Pro-Entity-Paarung). Ziel: **jede** erwähnte Entity als „Name (Link)".

| Skill | Heutiger Stand | Aktion |
|---|---|---|
| `book_users` | 1 URL (Option?) in Prosa | gebuchte Option **und** betroffene User je als „Name (Link)" (User-Profil-Link) |
| `diagnose_booking_issue` | 1 URL, kein Feld | Option + ggf. User als „Name (Link)" pro Erwähnung |
| `diagnose_cancellation_issue` | 1 URL | dito |
| `diagnose_user_booking` | 1 URL | je gebuchte Option „Name (Link)" + User-Profil-Link |
| `update_quiz` | URL in Prosa | Quiz-Aktivität als „Name (Link)" (activity-Link) |
| `generate_questions` | URL in Prosa | Ziel-Kurs/Fragebank als „Name (Link)" |
| `explain_docs` | 1 URL | Doku-Quelle(n) als „Titel (Link)" (sofern Entity-artig) |

---

## 5. Vorgehen (phasiert, jeweils mit Test)

1. **Agent-Seite zuerst** (stabil): `get_current_user` (C) + `update_quiz`/`generate_questions`/
   `explain_docs` (B). Pro Skill ein Test: `observation_full` enthält „<Name> (…/view.php?…)" bzw. den
   erwarteten Link.
2. **mod_booking nach Reorg-Stabilisierung:** Option-Mutationen (C) — create_*/update_*/add_price/bulk/
   list_option_properties — via `build_option_link_for_output`; dann book_users + diagnose_* (B).
   Vorher Helper-Signatur/Pfad gegenprüfen.
3. Optional **§6** (Helper-Konsolidierung), falls sich beim Umsetzen Duplizierung zeigt.

Test-Muster (deterministisch, ohne LLM): Skill ausführen → assert, dass `observation_full` den
kanonisch gebauten Link der Entity enthält (kein selbstgebauter String). Für Previews zusätzlich:
strukturiertes `link`/`url`-Feld vorhanden.

---

## 6. Optionale Helper-Konsolidierung (nur falls nötig)

- `diagnostic_link_builder` ist faktisch ein allgemeiner **Entity-Link-Builder** (course/activity/
  user_profile…), liegt aber im `diagnostics`-Namespace. Wird er von Nicht-diagnose-Skills genutzt
  (z. B. `get_current_user`), ist das okay, aber ein Rename/Alias zu `entity_link_builder` wäre
  ehrlicher. **Kein** Muss für diese Hardening-Runde.
- mod_booking-Seite: `build_option_link_for_output` ist bereits der kanonische Option-Helper — nur
  konsequent in allen Option-Skills verwenden.

---

## 7. Risiken / offene Punkte

1. **Booking-Reorg:** mod_booking-Dateien/Helfer können sich verschieben → Pfade/Signaturen vor der
   Umsetzung verifizieren. Agent-Seite unabhängig davon machbar.
2. **Report-Größe** bei bulk/Listen: verlinkte Ausgabe kompakt halten (erste K + Zähler).
3. **Strukturiertes Feld vs. Prosa:** für den Synchronizer reicht „Name (Link)" im
   `observation_full`-Text; das strukturierte `link`-Feld ist nur für Previews/WS nötig — nicht
   überall zwingend, aber empfohlen wo ein Preview existiert.
4. **Privacy/Anonymizer:** User-Profil-Links enthalten userids — sicherstellen, dass die
   Link-Ausgabe mit dem Anonymizer-Pfad konsistent ist (Links sind Engine-Daten, keine Klartext-PII,
   aber der Display-Gate-Pfad ist zu beachten).

---

## 8. Ein-Satz-Zusammenfassung

Die „immer verlinken"-Regel ist im Synchronizer schon korrekt; die Lücke liegt **upstream**: die
Skills der Kategorien **C** (gar kein Link — v. a. die Option-Mutationen) und **B** (Link nur in Prosa)
müssen die etablierte „Name (moodle_url)"-Konvention über die **vorhandenen** Helper
(`build_option_link_for_output`, `diagnostic_link_builder`) konsequent in `observation_full` liefern —
ohne Synchronizer-/Prompt-Eingriff.
