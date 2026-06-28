# Course Diagnose/Overview — Skill-Konsolidierung (Blueprint)

Status: **UMGESETZT (2026-06-28).** Alle 5 Phasen implementiert; Agent-Suite 555 Tests grün.
Siehe §11 Umsetzungsstatus.
Scope: die **course.***-Diagnose-/Analyse-Skills. `core.*`-Diagnose und `mod_booking.*`-Diagnose
sind eigene Domänen und bleiben außen vor (siehe §8).

---

## 1. Ziel

Die überlappenden course-Skills auf **wenige, klar geschnittene** Skills + **einen geteilten
Loader** reduzieren — ohne in den „Riesen-Report"-Modus zu kippen. Leitsatz:

> **Den Lader teilen, die Antworten getrennt & fokussiert halten.**

Nebeneffekt (gewollt): die in der Benchmark gemessene **Confusable-Cluster-Varianz** der
diagnose_*-Familie verschwindet.

---

## 2. Ist-Zustand

Fünf R0-Skills, alle readonly, alle laden im Kern Kurs + Aktivitäten (+ optional User-State):

| Skill | Frage | Zentrale Inputs |
|---|---|---|
| `course.diagnose_access` | Warum sieht/öffnet User X Kurs/Aktivität nicht? | user, course, activity |
| `course.diagnose_enrolment` | Warum ist X (nicht) eingeschrieben? | user, course |
| `course.diagnose_progress` | Wie weit ist X / warum nicht abgeschlossen? | user, course, activity |
| `course.diagnose_grades` | Fehlende/falsche Note von X? | user, course, item |
| `course.analyze_course_structure` | Was enthält der Kurs (Struktur)? | course (user-unabhängig, sichtbarkeitsgefiltert) |

**Schon geteilt (nutzbar):**
- `core_skill_base`: `resolve_courseid()`, `resolve_userid()`, `search_course_candidates_for_preview()`,
  `course_input_targets_operating_context()` (readonly-eager Kurs-Fallback, 2026-06-28).
- `classes/local/wizard/diagnostics/`: `diagnostic_result_builder`, `diagnostic_checklist_preview`,
  `diagnostic_link_builder` (Checklisten-Output der diagnose_*-Skills).
- `services/activities/course_structure_service` (für analyze).

**Was NICHT geteilt ist (die eigentliche Duplizierung):** jede Skill macht ihre **eigene**
Kurs-/User-/Aktivitäts-Auflösung in `execute()`, mit leicht unterschiedlicher Semantik
(diagnose_* nutzen `resolve_courseid` + immer-ambient; analyze nutzt den Registry-Resolver). Die
Aktivitäts-/Item-Auflösung ist überall **literaler Namens-Match → „nicht gefunden" → Aufgabe**
(Threads 526/528).

---

## 3. Probleme

1. **Überlappung/Routing-Varianz.** access/enrolment/progress/grades sind vier fast identische
   Skills → genau der **Confusable-Cluster**, an dem der Selektor in der Benchmark schwankt.
2. **Vorzeitiges Aufgeben.** Aktivität nicht per Name auflösbar → „existiert nicht" + der User
   wird gebeten, selbst nachzusehen — obwohl der Agent die Liste per `get_fast_modinfo` selbst hätte
   (Threads 526/528).
3. **Duplizierte, divergente Auflösung.** N Eigen-Implementierungen statt einer Primitive.
4. **Riesen-Report-Gefahr.** „Alles über Kurs + User" wäre langsam, teuer, schwer interpretierbar.

---

## 4. Design-Prinzipien

1. **Loader teilen, Report trennen.** Geteilt wird das **Laden** (Kurs + modinfo + optional
   User-State), getrennt bleibt die **fokussierte Antwort**.
2. **Eager, aber sicher — Code enumeriert, das LLM löst auf (enumerate-then-reason).**
   Die **Auflösung** von Aktivität/Item gehört **nicht** in deterministischen Code: „die 2. Aktivität"
   (Ordinal über alle Typen), „das 3. Quiz" bei zwei `mod_quiz` + einem `mooduell` usw. lassen sich nicht
   robust per Typ-Filter/Ordinal-Logik kodieren — jede solche Regel macht es schlimmer. Stattdessen:
   - **Code (deterministisch):** liefert ein **reiches Inventar** (`id · modname · name · section · position ·
     visible`) — exakt 1 Namens-Treffer → direkt benutzen; sonst Inventar als **Observation** zurückgeben.
   - **LLM (Entscheidung):** liest Inventar (Typ **und** Name) und ruft den Skill mit der konkreten
     `activityid`/`itemid` erneut auf — **oder** stellt eine `clarification`, wenn echt unklar.
   **Nie** Ordinal im Code raten, **nie** den Lookup an den User delegieren (der Agent hat die Liste selbst).
3. **Readonly-only.** Mutierende Skills bleiben am konservativen Engine-Pfad
   (`CONTEXT_TARGET_UNRESOLVED → clarification + confirmation`). Diese Konsolidierung berührt keinen
   Mutationspfad und **keine Prompts**.
4. **Report-Größe ist First-Class.** Fokussierte Facetten-Ausgabe; Struktur mit Limit/Sektions-Steuerung.

---

## 5. Ziel-Architektur

### 5.1 Neuer geteilter Loader (kein Skill)
`services/course/course_context_loader` (Arbeitsname):
- Kurs auflösen — **eager** via `course_input_targets_operating_context()` (Fallback auf ambient,
  name-match-aware), Cross-Context per unique Namens-Treffer + `can_access_course`-Gate.
- `get_fast_modinfo($courseid)` → alle Aktivitäten (eine Quelle für „der ganze Kurs").
- **Reiches Inventar** als kanonische Ausgabe: pro Aktivität `id · modname · name · section · position ·
  visible` (kompakt, eine Zeile/Aktivität). Dasselbe Inventar speist die Struktur-Antwort **und** die
  Aktivitäts-Auflösung der diagnose-Skill.
- Optionaler User-State (Einschreibung, Rolle, Completion, Grades) — **nur** wenn ein User gefragt ist.
- **Aktivität/Item-Auflösung (enumerate-then-reason):** exakt 1 Namens-Treffer → direkt resolve;
  sonst gibt der Loader das **Inventar als Observation** zurück (KEIN deterministisches Ordinal/Typ-Raten,
  KEIN „nicht gefunden"-Abbruch). Echt 0 Aktivitäten des Bereichs → saubere „kein X im Kurs"-Meldung.
- Sichtbarkeitsfilterung per acting user (wie heute in `course_structure_service`).

Hier lebt **einmal** die Logik, die heute 5× dupliziert/divergent ist.

### 5.2 Zwei Skills statt fünf

**A) `course.diagnose_user_in_course`** (user-in-course, R0)
- **`aspect`-Parameter**: `access | enrolment | progress | grades` (ggf. mehrere) → **fokussierte**
  Checkliste je Facette über die bestehende `diagnostics/`-Foundation.
- Inputs: user, course, optional **unscharfe** `activityquery`/`itemquery` **UND** aufgelöste
  `activityid`/`itemid` (Letztere für den 2. Schritt der LLM-Auflösung).
- **Zwei-Schritt-Auflösung (enumerate-then-reason):** ist die Aktivität/Item nicht eindeutig per Name,
  gibt der Skill das **Inventar als Observation** + Instruktion zurück („wähle die `activityid` und rufe
  erneut auf, oder frage den User"); das LLM ruft im nächsten Schritt mit konkreter `id` auf oder klärt.
  Die Instruktion lebt in der **Observation (engine-static)**, nicht im geseedeten Prompt.
- Selektor wählt **einen** Skill (kein 4-Wege-Konflikt); die Facette setzt der **Constructor**
  (lexikalisch meist eindeutig: „sehen"→access, „Note"→grades, „wie weit"→progress, „eingeschrieben"→enrolment).
- Mehrdeutige Anfrage → mehrere knappe Facetten ODER `clarification`.

**B) `course.analyze_course_structure`** (bleibt, user-unabhängig, R0)
- Struktur/Übersicht; nutzt denselben Loader.
- **Größen-Steuerung**: Sektion-Filter / Limit / Zusammenfassung statt Voll-Dump bei großen Kursen.

→ **5 → 2 Skills**, ein geteilter Loader darunter.

### 5.3 Was NICHT passiert
- Kein „voller Kursgraph annotiert mit jedem User-State" (Riesen-Report-Falle).
- Kein deterministisches Ordinal-/Typ-Raten im Code.
- **Keine Änderung am geseedeten Selektor-/Constructor-Prompt**; kein Eingriff in den Mutationspfad.
  (Die enumerate-then-reason-Instruktion + der Synchronizer-Guard sind engine-/Observation-seitig — siehe §8.6.)

---

## 6. Report-Größe (First-Class-Constraint)
- diagnose_user_in_course: nur die gefragte `aspect`, kompakte `[OK]/[!]`-Checkliste.
- analyze_course_structure: Sektions-/Limit-Parameter, Kurz-Zusammenfassung bei vielen Aktivitäten.
- **Inventar kompakt** (eine Zeile/Aktivität, `id·modname·name·section·position·visible`): es wird dem
  LLM zur Auflösung gegeben — bei großen Kursen knapp halten, damit der 2. Schritt schnell + lesbar bleibt.

---

## 7. Migrationsplan (phasiert, jede Phase grün + committet)

1. **Loader extrahieren** (`course_context_loader`) + Unit-Tests; noch von keinem Skill genutzt.
2. **analyze_course_structure** auf den Loader umstellen (Verhalten gleich; Tests bleiben grün).
   Reiches Inventar im Loader scharfschalten (Basis für die spätere LLM-Auflösung).
3. **`course.diagnose_user_in_course`** neu anlegen (aspect-Param, Loader, diagnostics-Foundation),
   Verhalten der vier Alt-Skills facettenweise nachbilden + Tests.
4. **Alt-Skills deaktivieren/entfernen** (access/enrolment/progress/grades) — Contract-/Capability-
   Bereinigung; Embeddings-Rebuild; Benchmark-Cluster-Szenarien auf den neuen Skill+aspect umstellen
   (die confusable Geschwister entfallen → Cluster löst sich auf).
5. **Doku/Flowchart** aktualisieren (Skill-Katalog, families, der readonly-eager-Loader als Knoten).

> Reihenfolge so, dass nach jeder Phase ein klarer Test zeigt, ob es sich auszahlt. Embeddings-Rebuild
> und Benchmark-Lauf erst nach Georgs Go (Real-LLM/Key-Konvention).

---

## 8. Offene Entscheidungen / Risiken

1. **aspect: Selektion → Constructor.** Verschiebt die LLM-Entscheidung; meist Gewinn (eindeutig +
   Cluster weg), aber multi-aspect-Anfragen brauchen eine klare Regel (mehrere Facetten vs. clarification).
2. **Skill-Namen/Abwärtskompatibilität.** Entfernen von `course.diagnose_access` etc. — gibt es externe
   Referenzen (Tests, Doku, gespeicherte Threads, Trigger)? Vor dem Entfernen prüfen.
3. **Scope core.*/mod_booking.***. `core.diagnose_permissions/notifications` und die booking-Diagnosen
   sind eigene Domänen — **vorerst nicht** mitkonsolidieren. Später ggf. dasselbe Muster.
4. **diagnose_grades = item, nicht activity.** `aspect=grades` braucht Item-Auflösung (Grade-Items),
   nicht nur modinfo — der Loader muss beide Inventare (activity/item) liefern.
5. **Auflösung ist jetzt LLM-vermittelt (Trade-off).** Vorteil: robust gegen „2. Aktivität" / „3. Quiz"
   (gemischte Modultypen). Nachteile, die wir akzeptieren: **ein Extra-Turn** (enumerate → auflösen) und
   **nicht deterministisch unit-testbar** für den konkreten Pick. Deterministisch testbar bleibt: das
   **Inventar** (korrekt/vollständig) und der **`activityid`/`itemid`-Pfad** (Skill verarbeitet eine
   aufgelöste id korrekt). Den eigentlichen Pick deckt der Real-LLM-Lauf ab.
6. **Zwei LLM-Nahtstellen (Phasen-Kohärenz).** Damit der enumerate-then-reason-Loop nicht doch im
   „gibt-auf"-Bias endet: (a) die **Observation** trägt Daten **+ nächste Aktion** (minimiert Abhängigkeit
   von der Planner-Initiative); (b) ein **Synchronizer-Guard** „gib die vom Skill gelieferten Kandidaten
   weiter; fordere den User nie auf, einen Lookup zu machen, den der Agent schon hat". Beides ist
   engine-/Observation-seitig, **kein** Eingriff in den geseedeten Selektor-/Constructor-Prompt.
7. **Größerer Umbau.** Loader + Skill-Merge + Embeddings + Benchmark + Doku — bewusst **kein**
   Nebenbei-Commit, sondern die 5 Phasen oben.

---

## 9. Verbindung zu laufender Arbeit
- Baut auf der **readonly-eager-Resolution** (`course_input_targets_operating_context`, commits
  67bbbee/6e5c898) auf — der Loader ist deren natürliche Heimat.
- Adressiert direkt den **diagnose-Confusable-Cluster** aus `BENCHMARK_REDESIGN.md` /
  `TEST_SUITE_AUDIT_AND_REFACTOR.md` (Cluster 3): aus vier Geschwistern wird ein Skill + aspect.
- enumerate-then-reason (Code listet Typ+Name, LLM löst auf) ist die saubere Lösung für die Threads
  515/526/528 und robust gegen „die 2. Aktivität" / „das 3. Quiz" (gemischte Modultypen).

---

## 11. Umsetzungsstatus (2026-06-28)

- **Phase 1** — `services/course/course_context_loader` (Inventar + enumerate-then-reason) +
  `core_skill_base::resolve_readonly_course_context_id()` (geteilter readonly-eager Kurs-Resolver) +
  Unit-Tests. ✓
- **Phase 2** — `analyze_course_structure` auf den geteilten Resolver migriert (Registry-Dup entfernt). ✓
- **Phase 3** — `course.diagnose_user_in_course` (aspect access|enrolment|progress|grades) als Orchestrator;
  je Aspekt ein verbatim extrahierter Diagnoser unter `diagnostics/aspects/`. ✓
- **Phase 4** — die 4 Alt-Skills (access/enrolment/progress/grades) + ihre Tests entfernt; `db/access.php`
  (4 Caps raus, 1 rein), `version.php` Bump; LLM-Matrix-Provider + Benchmark-Route-Szenarien auf den
  neuen Skill umgestellt. ✓
- **Phase 5** — dieser Status; Doku-Korpus/Flowchart-Detail über den Doku-Pfad.

Offen (nach Georgs Go, Real-LLM/Key-Konvention): **Embeddings-Rebuild** (Skill-Katalog geändert) +
Benchmark-Lauf zur Bestätigung, dass der diagnose-Cluster sich aufgelöst hat.

---

## 10. Ein-Satz-Zusammenfassung
Fünf überlappende course-Skills → **zwei** (user-in-course mit `aspect`; course-overview) über **einem
geteilten, readonly-eager Loader**; geteilt wird das Laden, getrennt & fokussiert bleiben die Antworten
— das beseitigt Routing-Varianz und das vorzeitige Aufgeben, ohne Riesen-Reports und ohne Prompt-/
Mutations-Eingriff.
