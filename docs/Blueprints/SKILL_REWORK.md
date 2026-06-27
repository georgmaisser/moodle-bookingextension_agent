# Blueprint: Radikales Skill-Rework (Discovery + Katalog)

**Datum:** 2026-06-26 · **Status:** Design / Arbeitsliste — KEINE Umsetzung in diesem Doc · **Kontext:** noch nicht produktiv → keine Rückwärtskompatibilität nötig.

Basiert auf der systematischen Analyse aller 41 aktuellen Skills (Discovery-Crowding, Namespace-Fragmentierung, fehlende Trigger). Skills leben in zwei Repos: `mod_booking/classes/local/wizard/...` (Booking-Skills) und `bookingextension/agent/classes/local/wizard/...` (core/course/question/wizard-Skills).

---

## 0. Architektur-Verdikt (warum so)

**Spezialisierte Skills mit flachem Schema + ernsthafter Discovery-Schicht. NICHT wenige God-Skills.**

- Der Planner trennt **Selection** (welcher Skill) und **Construction** (welche Parameter). Spezialisierung verschiebt Komplexität in Discovery (fixbar via Trigger/Familien), Generik verschiebt sie in Construction (Modell füllt bei `type`-gegabelten Schemata falsche Felder — schwer fixbar).
- Regel: **ein Skill = eine Nutzer-Intention mit flachem Schema.** Splitten, wenn sich das Parameter-*Shape* gabelt oder die Intention verschieden ist. Mergen nur bei echten Duplikaten (gleiche Intention **und** gleiches Shape). Findbarkeit löst die **Discovery-Schicht**, nicht die Skill-Zahl.
- Ziel: **41 → ~33 distinkte Skills + Trigger/Familien-Discovery.**

---

## 1. Katalog-Entscheidungen (Merge / Retire)

| Aktion | Skill | Ziel |
|---|---|---|
| MERGE | `create_selflearning_option` | → `create_option` `type=selflearning` (gleiches Shape) |
| KEEP-SPLIT | `create_slotbooking_option` | bleibt separat (7 Slot-Felder = divergentes Shape), trigger-gated |
| MERGE | `update_option_trainer` | → `update_option` (Trainer ist ein Feld) |
| MERGE | `diagnose_user_booking` | → `diagnose_booking_issue` (gleiche Frage: Buchungsstatus einer Person) |
| RETIRE | `list_option_properties` | Schema-Introspektion, keine Nutzer-Intention → `configure_booking_instance action=list_fields` |
| MERGE | `add_price_category` | → `configure_booking_instance action=add_price_category` (Instanz-Konfig) |
| KEEP-GATE | `recreate_skill_catalog` | Admin-only, **raus aus normaler Discovery** |
| KEEP-GATE | `scaffold_skill` | Dev-only-Pfad |

Alle übrigen: **KEEP**, aber mit Triggern + funktionsbasierter Familie (unten).

---

## 2. Trigger-Spezifikation — ALLE Ziel-Skills

Legende: **M** = `mandatory_on_trigger` (garantiert im Katalog bei Trigger-Treffer, unabhängig vom Embedding-Rang — sparsam für „darf nie fehlen"-Fälle). `intent_triggers` sind Substring-/case-insensitive-Phrasen (de+en), distinkt gehalten, um Cross-Skill-Kollisionen zu vermeiden.

### Familie `option_create`
| Skill | M | intent_triggers (de · en) |
|---|---|---|
| `mod_booking.create_option` (R2) | **M** | erstelle option · neue buchungsoption · option anlegen · kurs anlegen · veranstaltung anlegen · workshop anlegen · create option · new booking option · add a course/event/workshop |
| `mod_booking.create_slotbooking_option` (R2) | – | slot · zeitfenster · terminbuchung · sprechstunde · slotbuchung · appointment · time slot · scheduling slot · slot booking |

> `type=selflearning` Trigger (in create_option): selbstlern · self-paced · e-learning · selbstlernkurs · self learning · self study

### Familie `option_update`
| Skill | M | intent_triggers |
|---|---|---|
| `mod_booking.update_option` (R2) | **M** | bearbeite option · ändere option · option aktualisieren · feld ändern · bild setzen · headerbild · trainer zuweisen · trainer ändern · update option · edit option · change field · set/replace image · assign trainer |
| `mod_booking.bulk_update_options` (R2) | – | alle optionen · mehrere optionen · massenänderung · bulk · alle auf einmal · update multiple options · apply to all |

### Familie `option_read`
| Skill | M | intent_triggers |
|---|---|---|
| `mod_booking.search_options` (R0) | **M** | was kann ich buchen · welche optionen · kurse anzeigen · optionen auflisten · verfügbare kurse · what can i book · list options · available courses · show bookable |
| `mod_booking.get_option_details` (R0) | – | details zur option · infos zur option · option details · show details of · option info |

### Familie `booking_users`
| Skill | M | intent_triggers |
|---|---|---|
| `mod_booking.book_users` (R2) | **M** | buche … für · buche … in · person buchen · teilnehmer hinzufügen · nutzer einschreiben · jemanden eintragen · book … into · enrol user · register participant · add user to option |

> Embedding-Beschreibung erweitern: „Eine Person/Nutzer in eine Buchungsoption eintragen/einschreiben (enrol a user into a booking option)."

### Familie `rules`
| Skill | M | intent_triggers |
|---|---|---|
| `mod_booking.create_rule_from_template` (R2) | – | buchungsregel anlegen · regel erstellen · benachrichtigungsregel · create booking rule · new notification rule · rule from template |
| `mod_booking.update_rule_from_template` (R2) | – | regel ändern · regel bearbeiten · update booking rule · edit rule |
| `mod_booking.analyze_rules` (R0) | – | welche regeln · regeln anzeigen · wann wird email gesendet · which rules · what notifications fire · analyze rules |

### Familie `instance_config`
| Skill | M | intent_triggers |
|---|---|---|
| `mod_booking.configure_booking_instance` (R2) | – | buchungseinstellungen · instanz konfigurieren · preiskategorie · felder anzeigen · configure booking activity · instance settings · price category · list fields |

### Familie `diagnose` (über Namespaces hinweg — Familien-Staging routet zuerst hierher)
| Skill | M | intent_triggers |
|---|---|---|
| `mod_booking.diagnose_booking_issue` (R0) | **M** | warum kann … nicht buchen · nicht gebucht · buchung schlägt fehl · warum bekommt … keine bestätigung · why can't … book · not booked · booking fails |
| `mod_booking.diagnose_cancellation_issue` (R0) | – | kann nicht stornieren · stornierung geht nicht · abmelden geht nicht · cannot cancel · can't unbook · cancellation problem |
| `core.diagnose_notifications` (R0) | – | keine email · keine benachrichtigung · email kommt nicht an · no email · not receiving notifications · mail blocked |
| `core.diagnose_permissions` (R0) | – | welche rechte · welche rolle · berechtigung fehlt · capability · what roles · which permissions · access rights |
| `course.diagnose_access` (R0) | – | kann kurs nicht sehen · kein zugriff auf kurs · kann aktivität nicht öffnen · cannot access course · can't see activity · no access |
| `course.diagnose_enrolment` (R0) | – | einschreibung fehlgeschlagen · nicht eingeschrieben · self-enrolment · kohorten-sync · enrolment failed · not enrolled |
| `course.diagnose_grades` (R0) | – | falsche note · note fehlt · bewertung falsch · wrong grade · missing grade · gradebook issue |

> Grenzschärfung: `diagnose_booking_issue` **delegiert** den E-Mail-Teil an `core.diagnose_notifications` (keine Cross-Reference-Prosa mehr, sondern saubere Familien-Trennung).

### Familie `course_activity`
| Skill | M | intent_triggers |
|---|---|---|
| `course.add_activity` (R2) | – | aktivität hinzufügen · seite anlegen · url/link hinzufügen · textfeld/label · buch · ordner · forum · add activity · create page/url/label/book/folder/forum |
| `course.add_quiz` (R2) | – | quiz · test erstellen · quiz anlegen · create quiz · make a test · quiz aus pdf |
| `course.update_activity` (R2) | – | aktivität bearbeiten · umbenennen · verstecken · anzeigen · edit activity · rename · hide/show activity |
| `course.update_quiz` (R2) | – | quiz bearbeiten · fragen zum quiz hinzufügen · edit quiz · add questions to quiz |

### Familie `course_read`
| Skill | M | intent_triggers |
|---|---|---|
| `course.analyze_course_structure` (R0) | – | kursstruktur · was ist im kurs · abschnitte/themen · course structure · what's in the course · list sections |
| `course.search_courses` (R0) | – | welche kurse · kurs suchen · kurse anzeigen · which courses · find/search course · list courses |

### Familie `user`
| Skill | M | intent_triggers |
|---|---|---|
| `core.get_current_user` (R0) | – | wer bin ich · mein profil · who am i · my profile |
| `core.search_users` (R0) | – | finde nutzer · user suchen · person suchen · find user · look up person · search users |

### Familie `question`
| Skill | M | intent_triggers |
|---|---|---|
| `question.generate_questions` (R2) | – | fragen generieren · fragen aus pdf · fragenbank füllen · generate questions · questions from pdf · into question bank |

> Grenze: **nur Fragenbank**. „Quiz mit Fragen" → `add_quiz`/`update_quiz`.

### Familie `meta`
| Skill | M | intent_triggers |
|---|---|---|
| `wizard.explain_docs` (R0) | **M** | erkläre · was ist · wie funktioniert · dokumentation · explain · what is · how does … work · docs |
| `wizard.list_skills` (R0) | **M** | was kannst du · welche fähigkeiten · was kann der agent · what can you do · which skills · capabilities |
| `wizard.search_skills` (R0) | always | Engine-Fallback (MANDATORY_SKILL_KEYWORDS) — bleibt immer verfügbar |

### Familie `memory`
| Skill | M | intent_triggers |
|---|---|---|
| `wizard.remember` (R0) | – | merke dir · speichere dass · remember that · note that |
| `wizard.forget` (R2) | – | vergiss · lösche erinnerung · forget that |
| `wizard.list_memories` (R0) | – | was weißt du über mich · was hast du gespeichert · what do you know about me · stored preferences |
| `wizard.recall_memory` (R0) | – | letztes mal · gestern · vorhin · neulich · last time · yesterday · earlier |

> Grenzschärfung: `list_memories` = explizit gespeicherte Fakten (Preference-Store); `recall_memory` = Konversations-Historie. Beschreibungen entsprechend trennen.

### Admin/Dev (NICHT in normaler Discovery)
| Skill | Gate |
|---|---|
| `wizard.scaffold_skill` (R0) | nur Dev-Trigger: skill vorlage · scaffold skill · neuen skill bauen |
| `wizard.recreate_skill_catalog` (R2) | Admin-only, aus Standard-Discovery ausschließen |

---

## 2b. Mechanik-Befunde aus der Umsetzung (VERIFIZIERT — Korrekturen zur Theorie)

1. **`intent_triggers` wirken NUR via `mandatory_on_trigger`-Injektion** (planner_catalog_service:130/137). Es gibt **keinen** Embedding-Rank-Boost durch Trigger. Ein Trigger auf einem Skill mit `mandatory_on_trigger=false` ist **inert**.
2. **Matching ist reines Substring** (`mb_strpos`, planner_catalog_service:161). Platzhalter wie `'buche … für'` / `'... '` matchen **nie** — Trigger müssen **echte Substrings** sein, die in realen Nachrichten vorkommen (z. B. `'buche '`, `'nicht buchen'`).
3. **`FAMILY_DISCOVERY_ENABLED = off`** → die gesamte Familien-Schicht (§ unten) ist **aktuell wirkungslos**. Erst Flag aktivieren + Familien-Scoring tunen, dann nutzen.
4. **`mandatory_on_trigger` ist eine Korrektur für UNTER-gerankte Skills — kein Allheilmittel.** Blanket-mandatory auf gut-rankenden Skills (z. B. create_option) erzeugt **Über-Selektion**. Regel: mandatory NUR für Skills, die im Embedding-Top-K nachweislich untergehen (bestätigt: `book_users`). Alle anderen: Trigger deklariert, `mandatory=false` (inert, future-ready).
5. **MESS-PROBLEM:** Der 15-Szenarien-Einzellauf-Benchmark mit `wunderbyte-privat` ist **zu verrauscht** (±2–3 Szenarien; 5 Läufe = 53–67 %), um eine 1–2-Szenarien-Discovery-Verbesserung nachzuweisen. **Vor weiterer Optimierung: belastbare Mess-Harness** (Multi-Run-Mittelung pro Szenario / größeres Set / weniger rauschendes Modell).

## 3. Discovery-Schicht (höchste Priorität, größter Hebel)

1. **`intent_triggers` für alle Aktions-Skills** (heute 2 von 41 → Ziel: alle). **`mandatory_on_trigger`** nur für die 7 „darf nie fehlen"-Fälle: create_option, update_option, search_options, book_users, diagnose_booking_issue, explain_docs, list_skills.
2. **Funktionsbasierte Familien** (siehe oben) statt namespace-basiert → Familien-Staging routet „diagnose"/„create"/„rule"-Queries erst auf die Familie, dann auf **einen** Skill. Erfordert Anpassung der Family-Registry (heute namespace-abgeleitet).
3. **Embedding-Index-Hygiene:** `example.create_record` & alle Nicht-Registry-/Test-Einträge entfernen (tauchten im Live-Top-K auf). Nach **jeder** Skill-/Trigger-/Beschreibungs-Änderung `recreate_skill_catalog`.
4. **`description` = positive distinkte Anker, keine Cross-References** („not: that's skill X" raus — Embedding honoriert das nicht).
5. **TOP_K dynamisch:** nach Familien-Filter kleines K, ohne Familien-Treffer größer. (Aktuell fix 12 = nur Pflaster.)

---

## 4. Reihenfolge & Verifikation

1. **Discovery-Schicht zuerst** (Trigger + mandatory + Familien + Index-Hygiene) — größter Gewinn ohne Skill-Umbau.
2. **Merges** (selflearning→create_option, trainer→update_option, user_booking→booking_issue, list_option_properties + price_category → configure_instance).
3. **Slot/selflearning/Quiz** als getriggerte/typisierte Pfade — Construction-Qualität messen (füllt das Modell die richtigen Felder?).
4. **Familien-Routing** umbauen (namespace → funktional).
5. Nach **jedem** Schritt: Benchmark (gleiches Modell, `core_booking_v1`) als Regressions-/Fortschrittsmaß; danach pro-Szenario-Diff (passed/skill_selected) wie im Audit.

**Mess-Baseline (wunderbyte-privat):** K=8 = 53,3 %, K=12 = 66,7 %. Ziel nach Discovery-Rework: die 3 hartnäckigen Routing-Fehler (book_users→search_skills, skill_not_in_catalog→course.*, retry_preflight) grün.

---

# 5. BESCHLOSSEN: Multi-Vector Semantic Discovery (Ziel-Architektur)

> Entscheidung Georg, 2026-06-26/27. Ersetzt die lexikalische Trigger-Schicht vollständig.

## 5.0 BINDENDE REGEL — Skill-Discovery ist AUSSCHLIESSLICH SEMANTISCH

**Skill-Findung läuft zu 100 % über Embeddings (Vektor-Ähnlichkeit). LEXIKALISCHES Matching für Discovery/Routing ist VERBOTEN.**

Konkret **NO-GO** (über die Projektlaufzeit wiederholt fälschlich als „Fix" gemacht):
- Substring-/Keyword-Matching auf der User-Nachricht (`mb_strpos`, `str_contains`, Regex auf Intent).
- `intent_triggers` / `mandatory_on_trigger` als Discovery-Mechanismus.
- `always_available`-„always-include"-Tier, `MANDATORY_SKILL_KEYWORDS`.
- **Sprachspezifische Phrasen** irgendwo in Skill-Metadaten (das Produkt ist multi-language).

**Wenn ein Skill für eine Anfrage nicht (richtig) gefunden wird, ist das IMMER ein Embedding-Problem.** Der einzig erlaubte Fix: **die Anker des Skills verbessern** (eine `example_utterance` schärfen/ergänzen) oder das **Embedding-Modell** wechseln — NIE ein lexikalischer Hack. Ein lexikalischer „Fix" wird im Review abgelehnt.

## 5.1 Index-Schema (uniform, ein Code-Pfad)
Pro Skill **mehrere** Anker-Zeilen statt einer:
```
skill          : string   (skill name, e.g. mod_booking.book_users)
anchor_text    : string   (the embedded text)
anchor_kind    : string   ('description' | 'utterance')   ← debug/tuning only
vector         : float[]   (embedding of anchor_text under <model>__<dim>)
```
- Datei weiterhin **pro Modell+Dim** getrennt (`skill_catalog_embeddings__<model>__<dim>.csv`) → sauberes Modell-A/B.
- `anchor_kind` ist optional fürs Matching, aber **Pflicht fürs Debugging** (s. 5.5).

## 5.2 Anker pro Skill
- **Anker #0 = `description`** (knapp, konzept-fokussiert — der „weiche Fallback"-Anker; lang/diffus vermeiden, sonst Cross-Matching).
- **Anker #1…N = 3–6 `example_utterances`** — natürliche **englische** Beispiel-Anfragen, **abgrenzungs-orientiert** (was diesen Skill von Geschwistern unterscheidet, nicht nur „was er tut"). English-only; Cross-Language trägt das Modell bzw. die Query-Normalisierung (5.7).

## 5.3 Retrieval (`embeddings_retrieval_service`)
- Query-Embedding gegen **alle** Anker aller Skills (Cosine).
- **Max-Aggregation pro Skill** (bester Anker gewinnt; kein Bonus für mehrere Treffer).
- **Top-K = 12 distinkte SKILLS** (nach Dedupe der Anker → Skill), **nicht** Top-12 Zeilen.
- `SEMANTIC_MIN_SCORE` (0.30 roh) auf den **besten** Anker des Skills.
- `search_top_k` muss von „Top-K Zeilen" auf „Top-K distinkte Skills, gescort per Max-Anker" umgebaut werden.

## 5.4 Skill-Schema & `skill_governance` — was sich ändert (NACHZIEHEN!)
**Entfernen** (überall, restlos):
- `get_schema()['governance']['intent_triggers']` und `['mandatory_on_trigger']`.
- `planner_catalog_service::ensure_trigger_mandatory_skills()` + `message_matches_intent_triggers()` (die lexikalische Injektion) — **löschen**.
- `skill_registry::build_prompt_contract()` Felder `mandatory_on_trigger` / `intent_triggers` (heute :532-533) — **raus**; die normalisierte Governance (`get_skill_governance`/skillmeta) darf diese Keys nicht mehr führen.
- `adaptive_skill_catalog_service::get_mandatory_skills()` + `always_available`-Tier + `MANDATORY_SKILL_KEYWORDS` — **raus** (lexikalisch).

**Hinzufügen:**
- `get_schema()['example_utterances'] = [...]` (3–6 englische Sätze) — von `skill_registry` in den prompt_contract übernommen, vom `embeddings_catalog_builder` zu Ankern (zusammen mit `description`) verarbeitet.
- `skill_governance` führt künftig **keine** Discovery-Lexik mehr — nur noch Capabilities/Risk/Activation. Discovery-Signal = ausschließlich `description` + `example_utterances` (→ Anker).

## 5.5 Debugging / Observability (NACHZIEHEN!)
- Discovery-Debug-Log: pro surfaced Skill **welcher Anker** getroffen hat (`anchor_kind` + gekürzter `anchor_text`) **und der Score** — damit Tuning sichtbar ist („book_users kam über Utterance #2, Score 0.41").
- `cli/skill_selection_debug.php`: Anker + Scores ausgeben.
- `cli/benchmark_aggregate.php`: optional `anchor_kind` des gewählten Skills mitführen.
- Debug-Source-Token (`orc|p=disc|...`): Modus-Marker `mv` (multi-vector) statt der alten Trigger-Marker.

## 5.6 Kanonisches Beispiel — der Example-String ERKLÄRT die Funktionsweise
Jeder Skill (und die `scaffold_skill`-Vorlage) trägt diesen erklärenden Block, damit Fremd-Devs den Mechanismus verstehen und NIE lexikalisch „fixen":
```php
public function get_schema(): array {
    return [
        'version' => 1,
        // DISCOVERY IS SEMANTIC. The engine embeds the user's request and matches it (cosine)
        // against the anchors below — the 'description' AND each 'example_utterances' entry are
        // embedded as SEPARATE vectors; this skill surfaces when one of its anchors is semantically
        // closest to the request. Matching is by MEANING and works across languages — it never
        // looks at literal words. So:
        //   • description: ONE short, concept-focused sentence (the soft fallback anchor).
        //   • example_utterances: 3–6 DISTINCT, natural ENGLISH requests a user would actually make,
        //     written to DISTINGUISH this skill from its siblings. English only.
        //   • NEVER add keyword/substring/intent triggers or language-specific phrases — that
        //     mechanism is removed and forbidden (see SKILL_REWORK.md §5.0).
        'description' => 'Enrol one or more users into an existing booking option.',
        'example_utterances' => [
            'enrol a specific user into a booking option',
            'add a participant to an existing option',
            'register someone for a course or event',
            'sign a person up for a booking',
        ],
        'readonly' => false,
        'governance' => [
            // capabilities / risk only — NO intent_triggers, NO mandatory_on_trigger.
            'required_native_capabilities' => ['mod/booking:bookforothers'],
        ],
        'properties' => [ /* ... */ ],
    ];
}
```

## 5.7 Cross-Language (English-only Metadaten)
Alle Anker English-only. Die Sprachbrücke (deutsche/… Query ↔ englische Anker) läuft über **eine** von zwei Optionen — **offene Entscheidung, hängt am Modell-Test:**
- **Weg A:** stark multilinguales Embedding-Modell (kein Extra-Schritt).
- **Weg B:** Query→English-Normalisierung (HyDE-artige englische Intent-Phrase) **vor** dem Embedding-Call — modell-unabhängig, +1 (mini-Modell-)Call.
→ Entscheidung per Cross-Lingual-Test des gewählten Modells. **Benchmark-Set mehrsprachig erweitern**, sonst wird Multi-Language gar nicht gemessen.

## 5.8 Migrationsschritte
1. **English-only** ziehen (Descriptions + alle Trigger-Texte → Englisch; deutsche raus). *(sofort)*
2. `intent_triggers`/`mandatory_on_trigger` aus allen Skills + Engine entfernen (5.4).
3. Index-Schema + `embeddings_catalog_builder` auf Multi-Anker, `search_top_k` auf Max→Top-12-distinkte-Skills.
4. Pro Skill `example_utterances` authoren (3–6, distinkt).
5. Debugging (5.5) nachziehen; `scaffold_skill`-Vorlage + Beispiel (5.6) aktualisieren.
6. Rebuild + Harness; Cross-Lingual-Modelltest → Weg A/B fixieren.
7. Merges (§1) — weniger Quasi-Duplikate = weniger Anker-Kollisionen.

## 5.9 Modell-A/B
Anker-Texte sind modell-agnostische Quelle. Pro Modell ein Index (`__<model>__<dim>.csv`), dieselben Szenarien über `benchmark_aggregate.php` (Multi-Run-Mittel) → Pro-Szenario-Pass-Rate vergleichen. So wird der Modellwechsel **gemessen**, nicht geraten.
