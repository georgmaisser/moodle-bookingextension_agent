# Test-Suite Audit & Refactoring-Blueprint

Status: Proposal (2026-06-28). Statische Analyse, **keine** Real-LLM-Daten herangezogen.
Scope: die PHPUnit-Suite des `bookingextension_agent` (`tests/**`) — deterministische
Unit-/Contract-Tests **und** Real-LLM-Tests — geprüft nach denselben Parametern wie das
Benchmark-Audit (`BENCHMARK_REDESIGN.md`): Abdeckung, Skill-/Response-Type-Varianz, Sprache,
Schwierigkeit/Disambiguierung, Tier-Korrektheit, Noise-Handling, Redundanz, Completeness-Guards.

> Dieses Dokument ergänzt `BENCHMARK_REDESIGN.md`. Das Benchmark-Audit betrachtete die
> *separate* Benchmark-Suite (`classes/local/wizard/benchmark/`, 15 Szenarien, 7 Skills,
> N-Run-aggregiert). Dieses Dokument betrachtet die **PHPUnit-Suite** und das Verhältnis
> beider zueinander — die heute **nicht** aufeinander abgestimmt sind.

---

## 0. Prüf-Parameter (dieselben wie im Benchmark-Audit)

1. **Abdeckung** — welche Skills / response_types / Risk-Klassen werden geprüft?
2. **Varianz/Streuung** — sind die Fälle breit genug (alle Namespaces, confusable Cluster)?
3. **Sprache** — nur `de` oder auch `en`/cross-language (Weg-B-Brücke)?
4. **Schwierigkeit** — trennscharf (Disambiguierung) oder triviale 1-aus-N-Picks?
5. **Tier-Korrektheit** — deterministische Vertragsregeln deterministisch getestet, modell-
   abhängiges Routing separat?
6. **Noise-Handling** — wie gehen Real-LLM-Tests mit Nicht-Determinismus (temp 0!) um?
7. **Redundanz** — testen mehrere Schichten unabsichtlich dasselbe?
8. **Completeness-Guards** — gibt es einen Mechanismus, der „jeder Skill ist abgedeckt" erzwingt?

---

## 1. Test-Taxonomie (Ist-Zustand)

Die Suite zerfällt in **fünf** Schichten — heute ohne dokumentierte Rollen-Trennung:

| # | Schicht | Ort | LLM? | Dateien | Charakter |
|---|---|---|---|---|---|
| L1 | Skill-/Service-Unit-Tests | `tests/*_test.php` | nein | ~50 | `execute()`/Service-Logik je Skill, deterministisch |
| L2 | Engine-Contract-Tests | `tests/agent/contracts/*` | nein | ~37 | Planner-Vertrag/Decision-Rules deterministisch (das echte „Tier 1") |
| L3 | Real-LLM Skill-Matrix | `tests/agent/abstract_llm_skill_matrix_testcase.php` + `llm_skill_matrix_scenario_provider.php` | **ja** | 1 Provider, **40 Skills** | 1 Utterance je registriertem Skill, End-to-End routbar+ausführbar |
| L4 | Real-LLM Multistep-Flows | `tests/agent/real_llm_multistep/*` | **ja** | 9 + `r3_skill_e2e` | mehrstufige Flows (confirmation, autoconfirm, datetime, …) |
| L5 | Benchmark (separat) | `classes/local/wizard/benchmark/*` | **ja** | 15 Szenarien / 7 Skills | N-Run-aggregiert, Stable-Fail-Metrik |

**Sofort sichtbar:** L3 und L5 tun **dasselbe** (Real-LLM Single-Turn Skill-Routing) — mit
unterschiedlichen Harnesses, Scoring und Coverage, ohne Bezug aufeinander. Das ist der
zentrale Refactoring-Hebel (→ R1).

---

## 2. Per-Schicht-Bewertung

### L1 — Skill-/Service-Unit-Tests · **solide**
- Pro Skill ein deterministischer Test (`diagnose_*`, `add_activity`, `add_quiz`,
  `update_activity/quiz`, `analyze_course_structure`, `generate_questions`, memory, …).
- ✔ Gute Breite über Namespaces; ✔ deterministisch; ✔ CI-tauglich.
- ⚠ Teilweise **Redundanz** mit L3/L4 (z. B. `generate_questions` hat Unit- *und*
  cross-context- *und* real-LLM- *und* Matrix-Abdeckung). Nicht falsch, aber unkoordiniert.

### L2 — Engine-Contract-Tests · **stärkste Schicht**
- ~37 Tests für decision/risk-gating, preflight-Layer/Risk-Klassen, finalization_classifier,
  queue-/pending-Transitions, synchronizer-Contracts, prompt/language-Contract, native_capability.
- ✔ Deterministisch, gezielt, schnell — das ist faktisch der „Tier 1" aus `BENCHMARK_REDESIGN.md`,
  **schon vorhanden**.
- ⚠ Der „deterministic"-Tier der **Benchmark** (`short_confirm_ja` etc.) **dupliziert** dieses
  Konzept, hat aber keinen Executor (kein `setup_state`-Seeding, wird nirgends gefahren). →
  gehört nach L2 konsolidiert (→ R4).

### L3 — Real-LLM Skill-Matrix · **breit, aber flach + de-only + binär**
- ✔ **40 Skills** abgedeckt (alle `course.*`, `core.*`, `wizard.*`, `question.*`, viele
  `mod_booking.*`) — die mit Abstand breiteste Routing-Abdeckung im Repo.
- ✔ **Completeness-Guard**: `get_missing_registered_skill_scenarios()` erzwingt, dass jeder
  registrierte (owned) Skill eine Matrix-Szenario hat. **Vorbildlich** — sollte Standard für
  alle Routing-Schichten werden (→ R5).
- ✔ Echtes State-Seeding vorhanden (Generator-Optionen, Memory-Tokens) — die Fähigkeit, die das
  Benchmark-Redesign erst *bauen* will (§5.1), **existiert hier schon** (→ R1/R4).
- ✘ **1 Utterance je Skill**, gezielt auf *diesen* Skill formuliert → testet **Recall**, nicht
  **Disambiguierung** zwischen Geschwister-Skills. Genau die confusable Cluster
  (`create_option` vs `_selflearning_` vs `_slotbooking_`; die 4+ Diagnose-Varianten;
  `mod_booking.search_courses` vs `course.search_courses`) werden **nicht** adversarial geprüft.
- ✘ **de-only** (0 `en`-Varianten) — Weg-B-Brücke ungetestet.
- ✘ **Binär single-run**: pro Szenario ein Lauf, pass/fail. Die Retry-Schleife (1..8) fängt
  **nur transiente Provider-Fehler** ab (kein Maskieren von Routing-Noise — gut), aber bei einem
  *bei temp 0 nicht-deterministischen* Modell ist ein binärer Real-LLM-Test im CI **per
  Konstruktion flaky**. Das ist exakt das Anti-Pattern, das `BENCHMARK_REDESIGN.md` für die
  Benchmark verwirft — in PHPUnit aber noch gelebt (→ R2).

### L4 — Real-LLM Multistep-Flows · **wertvoll, aber gleiche Noise-Frage**
- Deckt mehrstufige Abläufe (confirmation_flow, lecture_autoconfirm, normal_option_datetime
  [544 LOC], search_*, get_current_user, list_skills, generate_questions).
- ✔ Testet echte Pipeline-Übergänge, die L1/L2 nicht erreichen.
- ✘ Wie L3: real-LLM **binär single-run** → flaky-Risiko; key-gated (skip ohne
  `BOOKING_TEST_AI_KEY`) → ohne Key **gar keine** kontinuierliche Verifikation.
- ⚠ Teilüberlappung mit L2 (Teile von confirmation_flow sind deterministische Vertragsregeln,
  die in `contracts/` gehören).

### L5 — Benchmark · siehe `BENCHMARK_REDESIGN.md`
- Schmal (7 Skills), aber **richtig entrauscht** (N-Run, Stable-Fail). Das Scoring-Modell, das L3/L4
  fehlt, ist hier vorhanden — aber auf der schmalen Coverage. Spiegelbild zu L3.

---

## 3. Querschnitts-Befunde

**A. Zwei divergente Real-LLM-Routing-Suiten (L3 ↔ L5).** Breite (L3, 40 Skills, binär) und
Entrauschung (L5, 7 Skills, N-Run) leben getrennt. Keine teilt Szenario-Quelle, Scoring oder
Reporting. → Vereinheitlichen: **eine** Szenario-Quelle, **ein** N-Run-Scoring.

**B. Noise-Handling widersprüchlich.** Das Redesign sagt „single-run % ist Noise" — gilt für L3
und L4 genauso, wird dort aber nicht angewandt. Binäre Real-LLM-Tests im blockierenden CI sind
flaky; ohne Key sind sie tot.

**C. Confusable-Disambiguierung fehlt überall.** Weder L3 (Recall-Utterances) noch L5 testen
Geschwister-Trennschärfe. Das ist laut Redesign der eigentliche Wert von Real-LLM-Tests.

**D. Durchgängig de-only.** L3, L4, L5 alle ohne `en`-Varianten. Cross-language ist eine bekannte
Varianzquelle und ungetestet.

**E. State-Seeding-Fähigkeit ungenutzt geteilt.** L3 kann echten Thread-State seeden; die Benchmark
will das erst bauen. Doppelarbeit vermeidbar.

**F. Completeness-Guard nur in L3.** L5 (Benchmark) und L4 (Flows) haben keinen „kein Skill
vergessen"-Guard. Das L3-Muster sollte verallgemeinert werden.

**G. Tier-Lecks.** Deterministische Vertragsregeln tauchen in Real-LLM-Schichten auf (Teile von
L4; der nie-gefahrene „deterministic"-Benchmark-Tier). Vertragsregeln gehören nach L2.

**H. Unkoordinierte Redundanz.** Einige Skills sind 3–4-fach abgedeckt (Unit + cross-context +
real-LLM + Matrix), andere Cluster gar nicht trennscharf. Abdeckung ist breit, aber nicht
*absichtlich* verteilt.

---

## 4. Ziel-Architektur (Soll)

Vier klar getrennte Rollen, jede mit genau einem Zweck und einer CI-Semantik:

| Rolle | Schicht | LLM | CI | Metrik |
|---|---|---|---|---|
| **Skill-Logik** | L1 (bleibt) | nein | blockierend | binär 100 % |
| **Engine-Vertrag** | L2 (+ konsolidierte Benchmark-„deterministic") | nein (Stub/seeded) | blockierend | binär 100 % + Determinismus-Guard |
| **Routing-Qualität** | **L3 ∪ L5 vereint** | ja | **nicht-blockierend**, N-Run | Stable-Fail-Set + per-Skill-Pass-Rate, getrennt nach Skill-Hit / RT-Hit / JSON / Contract |
| **Flow-Integrität** | L4 (entrauscht) | ja | nicht-blockierend, N-Run | Stable-Fail je Flow |

Leitsatz (analog Redesign): **deterministisches Verhalten → deterministische, blockierende
Tests; modellabhängiges Verhalten → entrauschte, nicht-blockierende N-Run-Suiten.** Kein binärer
Real-LLM-Test im blockierenden CI.

---

## 5. Refactoring-Empfehlungen (priorisiert)

> Keine dieser Empfehlungen wurde umgesetzt — dies ist eine Spezifikation zur Abstimmung.

**R1 — L3 und L5 auf eine Szenario-Quelle vereinen.** *(größter Hebel)*
`llm_skill_matrix_scenario_provider` (40 Skills, Seeding, Completeness-Guard) als **einzige**
Routing-Szenario-Quelle etablieren; der Benchmark-Runner (N-Run/Stable-Fail/Matrix-Reporting)
konsumiert dieselbe Quelle, statt eigene 7 Szenarien zu pflegen. Ergebnis: breite Coverage **und**
Entrauschung an einer Stelle. Effort: mittel. Risiko: mittel (Harness-Brücke).

**R2 — Real-LLM-Tests aus dem blockierenden CI nehmen.** L3/L4 binär→N-Run-aggregiert; im
blockierenden CI nur L1/L2. Real-LLM läuft als separate, entrauschte Suite (lokal/nightly mit
Key). Beendet Flakiness und „ohne-Key-tot". Effort: klein–mittel. Risiko: klein.

**R3 — Confusable-Cluster-Szenarien ergänzen** (in der vereinten Quelle aus R1). Pro Cluster ein
Szenario je Geschwister-Skill mit gepinntem `expected_skill` **und** „nicht-Geschwister"-Assert:
Create-Cluster (3), Booking-Diagnose (3), Course-Diagnose (4), Authoring (add/update activity/quiz),
`search_courses`-Namespace-Kollision, Memory-Familie. Macht die Suite *schwer* genug für echte
Varianz. Effort: mittel. Risiko: klein. → setzt Produktentscheid §8.2 voraus (siehe unten).

**R4 — Benchmark-„deterministic"-Tier nach L2 konsolidieren.** Die als `deterministic` getaggten
Benchmark-Szenarien (`short_confirm_ja`, `clarification_missing_date`,
`duplicate_prevention`, `ambiguous_*`) als echte Contract-Tests in `tests/agent/contracts/`
abbilden — mit dem **bereits existierenden** State-Seeding (aus L3) statt narrativem Text. Das löst
den `confirm_pending`-Erreichbarkeitsfehler endgültig (kein Re-Bauen nötig). Effort: mittel.
Risiko: klein.

**R5 — Completeness-Guard verallgemeinern.** Das L3-Muster (`get_missing_registered_skill_scenarios`)
auf die vereinte Routing-Quelle anwenden und um Dimensionen erweitern: jeder owned Skill braucht
(a) ein Routing-Szenario, (b) `de`+`en`, (c) Cluster-Zugehörigkeit, falls Geschwister existieren.
Ein fehlender Eintrag = roter (deterministischer) Test. Effort: klein. Risiko: klein.

**R6 — `en`-Varianten der Routing-Kern-Szenarien.** In der vereinten Quelle ein `language`-Feld;
für die Kern-Cluster je `de`+`en`. Misst die Weg-B-Brücke direkt. Effort: klein–mittel.

**R7 — Redundanz dokumentieren/trimmen.** Pro Skill bewusst entscheiden, welche Schicht was prüft
(L1 = Logik, L2 = Vertrag, Routing-Suite = Auswahl, L4 = Flow). Offensichtliche Doppelungen
zusammenführen; absichtliche Mehrfachabdeckung im Test-Docblock begründen. Effort: klein, laufend.

**R8 — L4-Flows entrauschen + Tier-Lecks schließen.** Deterministische Anteile der Flow-Tests nach
L2 ziehen; den Rest in die N-Run-Real-LLM-Suite (R2) überführen. Effort: mittel.

---

## 6. Offene Produktentscheidungen (Owner nötig, blockieren R3/R4)

Identisch zu `BENCHMARK_REDESIGN.md` §8 — hier referenziert, weil R3/R4 davon abhängen:
1. „Create X **again**" direkt nach Erstellung → Duplikat (act) oder no-op (`sufficient`)?
2. Low-Risk-Mutation → **immer** `confirmation_request`, oder direkt `skill_call`?
   (Beobachtete Inkonsistenz bei `update_option_trainer`.)
3. Capability ohne passenden Skill → `error` oder `wizard.search_skills`?

---

## 7. Ein-Satz-Zusammenfassung

Die PHPUnit-Suite ist in der **deterministischen** Schicht (L1/L2) stark und in der
**Real-LLM**-Schicht *breit aber flach, einsprachig und binär-flaky*; sie überlappt unkoordiniert
mit der separaten, schmalen-aber-entrauschten Benchmark. Das Refactoring führt **eine** breite,
entrauschte, mehrsprachige, cluster-trennscharfe Routing-Suite zusammen (R1+R3+R6), nimmt
Real-LLM aus dem blockierenden CI (R2), konsolidiert deterministische Vertragsregeln nach L2
(R4) und macht „kein Skill vergessen" zum erzwungenen Guard (R5).

---

## Anhang A — Konkrete Confusable-Cluster-Szenarien (Spezifikation zu R3)

Vorschlag für die vereinte Routing-Quelle (R1). Jede Zeile = ein Szenario.
**Prinzip:** pro Cluster ein Szenario je Geschwister-Skill; die Utterance ist so formuliert, dass
**genau ein** Skill korrekt ist. Pflicht-Assertions je Szenario:

- `expected_skill` = der gepinnte Skill,
- **Negativ-Assert** `skill_selected NOT IN {Geschwister}` (das ist der Trennschärfe-Kern; fehlt
  heute überall),
- `response_type` erwartet (i. d. R. `skill_call`; bei Mutationen ggf. `confirmation_request`,
  siehe Produktentscheid §6.2).

`+en` = zusätzlich eine englische Variante derselben Utterance (R6). Daten-Entitäten in Anführungs-
zeichen müssen im `data_fixture` (Benchmark-Redesign §5.2) real existieren.

### Cluster 1 — Create-Varianten (`mod_booking`) · `+en`
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_create_option` | „Erstelle eine Veranstaltung 'Erste Hilfe Grundkurs' am 5. Mai von 9–12 Uhr." | `mod_booking.create_option` | selflearning, slotbooking |
| `cl_create_selflearning` | „Lege einen Selbstlernkurs 'Datenschutz-Grundlagen' an, den Teilnehmer jederzeit selbst starten können." | `mod_booking.create_selflearning_option` | create_option, slotbooking |
| `cl_create_slotbooking` | „Richte eine Terminbuchung mit wählbaren Zeitslots für Beratungsgespräche ein." | `mod_booking.create_slotbooking_option` | create_option, selflearning |

### Cluster 2 — Booking-Diagnose (`mod_booking`) · `+en`
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_diag_booking` | „Warum kann Anna Berger sich nicht für 'Erste Hilfe Grundkurs' anmelden?" | `mod_booking.diagnose_booking_issue` | cancellation, user_booking |
| `cl_diag_cancellation` | „Warum kann Peter Mayer seine Buchung für 'Yoga Intensiv' nicht stornieren?" | `mod_booking.diagnose_cancellation_issue` | booking_issue, user_booking |
| `cl_diag_user_booking` | „Zeig mir den Buchungsstatus von Max Mustermann — wo ist er überall angemeldet?" | `mod_booking.diagnose_user_booking` | booking_issue, cancellation |

### Cluster 3 — Course-Diagnose (`course`) · `+en`
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_diag_access` | „Maria kommt nicht in den Kurs 'Mathematik 1' hinein — woran liegt das?" | `course.diagnose_access` | enrolment, grades, progress |
| `cl_diag_enrolment` | „Warum ist Tom nicht im Kurs 'Physik' eingeschrieben?" | `course.diagnose_enrolment` | access, grades, progress |
| `cl_diag_grades` | „Warum sieht Lisa ihre Note im Kurs 'Chemie' nicht?" | `course.diagnose_grades` | access, enrolment, progress |
| `cl_diag_progress` | „Wie weit ist Jonas im Kurs 'Onboarding' gekommen, was fehlt ihm noch?" | `course.diagnose_progress` | access, enrolment, grades |

> `access` vs `enrolment` sind bewusst sehr nahe — der härteste Trennschärfe-Test des Sets.

### Cluster 4 — Course-Authoring (`course`)
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_add_activity` | „Füge dem Kurs 'Online Workshop' eine Zoom-Aktivität hinzu." | `course.add_activity` | add_quiz, update_activity, update_quiz |
| `cl_add_quiz` | „Lege im Kurs 'Mathematik 1' ein neues Quiz 'Abschlusstest' an." | `course.add_quiz` | add_activity, update_activity, update_quiz |
| `cl_update_activity` | „Ändere die Beschreibung der Forum-Aktivität im Kurs 'Onboarding'." | `course.update_activity` | add_activity, add_quiz, update_quiz |
| `cl_update_quiz` | „Setze beim Quiz 'Abschlusstest' das Zeitlimit auf 30 Minuten." | `course.update_quiz` | add_activity, add_quiz, update_activity |

### Cluster 5 — Kurs-Container vs. Buchungs-Option (echtes Ziel-Verb-Paar) · `+en`
Verifiziert: Es gibt **nur einen** wählbaren Such-Skill je Ziel — `course.search_courses` (Moodle-
**Kurs**) und `mod_booking.search_options` (Buchungs-**Option**). `mod_booking.search_courses` ist
**kein** wählbarer Skill (siehe Smell unten). Der echte Trennschärfe-Test ist daher „gleiches Verb,
verschiedenes Ziel":

| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_search_courses` | „Welche Kurse gibt es zum Thema Datenschutz?" | `course.search_courses` | mod_booking.search_options |
| `cl_search_options` | „Welche buchbaren Veranstaltungen gibt es nächste Woche?" | `mod_booking.search_options` | course.search_courses |

> ⚠ **Code-Smell zur Bereinigung (kein Test-Problem):** `mod_booking.search_courses` wird referenziert
> (`booking_skill_base.php` Z. 95 & 345; Prompt in `create_option_skill.php:1308` „call
> booking.search_courses FIRST"; WS-Wrapper in `booking_skill_support.php`), existiert aber **nicht**
> als wählbarer Skill (keine `TASK_NAME`-Klasse). Folgt der Planner der Prompt-Anweisung, zeigt er auf
> einen nicht im Katalog vorhandenen Skill → erzwungener Fallback. Die Prompt-Zeile + die zwei
> Metadaten-Map-Einträge gehören entfernt (oder `course.search_courses` referenzieren).
> Hinweis: Routing-Szenarien `route_search_courses_de/en` existieren bereits in der Benchmark.

### Cluster 6 — Memory-Familie (`wizard`)
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_remember` | „Merk dir, dass ich Buchungen immer zuerst als Entwurf anlegen will." | `wizard.remember` | recall_memory, forget, list_memories |
| `cl_recall` | „Was hattest du dir über meine Buchungsvorlieben gemerkt?" | `wizard.recall_memory` | remember, forget, list_memories |
| `cl_forget` | „Vergiss, was du dir über meine Vorlieben gemerkt hast." | `wizard.forget` | remember, recall_memory, list_memories |
| `cl_list_memories` | „Was hast du dir alles über mich gemerkt?" | `wizard.list_memories` | remember, recall_memory, forget |

> `recall` (gezielter Abruf) vs `list_memories` (vollständige Aufzählung) ist die subtile Kante hier.

### Cluster 7 — Update-Option allgemein vs. trainer-spezifisch (`mod_booking`)
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_update_option` | „Ändere die maximale Teilnehmerzahl von 'Erste Hilfe Grundkurs' auf 25." | `mod_booking.update_option` | update_option_trainer |
| `cl_update_trainer` | „Setze Max Mustermann als Trainer für 'Erste Hilfe Grundkurs'." | `mod_booking.update_option_trainer` | update_option |

### Cluster 8 — Regeln (`mod_booking`)
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_create_rule` | „Erstelle aus der Vorlage eine Regel, die bei jeder Buchung eine Bestätigungsmail sendet." | `mod_booking.create_rule_from_template` | update_rule_from_template, analyze_rules |
| `cl_update_rule` | „Ändere die bestehende Regel 'Erinnerung 24h' auf 48 Stunden vorher." | `mod_booking.update_rule_from_template` | create_rule_from_template, analyze_rules |
| `cl_analyze_rules` | „Welche Regeln sind für diese Buchungsinstanz aktiv und was tun sie?" | `mod_booking.analyze_rules` | create_rule_from_template, update_rule_from_template |

### Cluster 9 — Core-Diagnose vs. Booking-Diagnose (`core`) · adversariale Paare
| Key | Utterance (de) | expected_skill | NOT |
|---|---|---|---|
| `cl_diag_permissions` | „Welche Rechte fehlen mir, um Trainer zuweisen zu dürfen?" | `core.diagnose_permissions` | mod_booking.diagnose_booking_issue, course.diagnose_access |
| `cl_diag_notifications` | „Warum bekomme ich keine Benachrichtigungen über neue Buchungen?" | `core.diagnose_notifications` | mod_booking.diagnose_booking_issue |

---

### Zusammenfassung Anhang A
- **~27 de-Szenarien** über 9 Cluster; `+en` für die Kern-Cluster (1–3, 5) ⇒ ~40 Szenarien gesamt.
- Jedes mit gepinntem `expected_skill` **und** Negativ-Assert gegen die Geschwister — das ist die
  heute fehlende Trennschärfe.
- Ein **Produktentscheid** bestimmt das erwartete `response_type` der mutierenden Cluster (1, 4, 7, 8):
  die Mutations-`confirmation_request`-Frage (§6.2) — vor dem Festschreiben klären.
- Ein **Code-Smell** (kein Test-, sondern Cleanup-Thema): die danglenden
  `mod_booking.search_courses`-Referenzen (Cluster 5) — Prompt-Zeile + zwei Metadaten-Maps entfernen.
- Reihenfolge: erst §6-Produktentscheide klären → dann Cluster als Szenarien in der vereinten
  Quelle (R1) anlegen → Completeness-Guard (R5) um Cluster-Zugehörigkeit erweitern.
