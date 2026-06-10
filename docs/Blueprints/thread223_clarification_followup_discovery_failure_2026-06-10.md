# Blueprint: Der Clarification/Confirmation-Vertrag ist auf BEIDEN Seiten defekt (Threads 223 & 224)

*Stand: 2026-06-10 · Status: E/F/A/C + D(Skill-Teil) UMGESETZT; B + D2 offen (siehe §8)*

## Umsetzungsstand (2026-06-10)

| Fix | Status | Datei(en) | Risiko |
|---|---|---|---|
| **E** Synthesizer-Bypass für blockierende Clarifications | ✅ umgesetzt | `finalization_classifier` (neu: `INFORMATIVE_CLARIFICATION_CODES`; blockierende clarification mit Issue-Code → `DIRECT_FINAL`) + Contract-Tests | niedrig, generisch |
| **F** `content` = Quelltext, keine fertigen Fragen | ✅ umgesetzt | `generate_questions_skill::get_schema` | trivial |
| **A** nie nach optionalen Feldern fragen | ✅ umgesetzt | `prompt_policy_builder` (MUTATION CLARIFICATION MINIMIZATION) | niedrig |
| **C** Discovery-Query um Originaltask anreichern (zustandslose Heuristik) | ✅ umgesetzt | `orchestrator::run_discovery_phase` + Helfer `is_low_semantic_followup`/`find_recent_substantial_user_text` | niedrig, kein State |
| **D (Skill-Teil)** imperative search_skills-Observations | ✅ umgesetzt | `search_skills_skill` (Empty-Query- + Erfolgs-Observation) | niedrig |
| **B** Pending-Intent für Clarifications (Advisory) | ⏸ offen | `agent_decision_service`/`pending_intent_service` | State-Maschine, braucht VM-Tests; überlappt mit C |
| **D2** discovered_skills in nächsten Selektions-Katalog injizieren | ⏸ offen | Orchestrator-Loop-Control | Loop-Chirurgie, braucht VM-Tests |

**Warum B + D2 offen:** Beides ist State-/Loop-Verhalten, das ohne lauffähige Tests nicht verantwortbar
„blind" geändert wird. **C neutralisiert den Auslöser bereits** (Originaltask bleibt in der Discovery), und B
ist im Plan ohnehin als *optionales* Determinismus-Upgrade markiert; `discovered_skills` wird heute **nirgends**
injiziert (der Kommentar im Skill ist aspirational) — D2 ist die echte, aber riskante Framework-Arbeit. Nach
VM-Validierung von E/F/A/C entscheiden, ob B/D2 überhaupt nötig sind.

## 0. Worum es geht

Zwei reale Threads zeigen denselben systemischen Schwachpunkt der **agentic Loop** aus zwei Richtungen — er
betrifft den ganzen **Clarification/Confirmation-Vertrag**, nicht einen einzelnen Skill:

- **Thread 223 — Eingangsseite:** Stellt der Agent eine Rückfrage und der User antwortet knapp („medium",
  „ja", „die zweite"), verliert die nächste Runde den ursprünglichen Auftrag in der **Discovery** und spiralt
  in `core.search_skills` als Sackgasse.
- **Thread 224 — Ausgangsseite:** Der Preflight gibt **korrekt** eine Rückfrage zurück
  (`GENERATE_QUESTIONS_TARGET_AMBIGUOUS` mit der Kategorie-Liste), aber der **Synthesizer** nimmt dieses
  Ergebnis nicht auf — er ignoriert die Clarification, halluziniert „keine Funktion verfügbar", wählt selbst
  eine Kategorie und dumpt die Fragen in den Chat.

Beides hängt am selben Vertrag: *„Eine Runde, die mehr Input vom User braucht, ist KEINE abgeschlossene
Runde."* Die Engine behandelt sie an beiden Enden, als wäre sie eine.

**Verdict vorweg:** Von 6 identifizierten Defekten (A–F) sind **4 reine Framework-Probleme**, 2 sind hybrid
(überwiegend Framework). Es ist **kein** „an einem Skill schrauben"-Thema — die Lösung gehört legitim in die
Engine und kontaminiert keine Skills.

---

## 1. Evidenz (Logs Thread 223)

| Zeit | Phase (llm_debug id) | Beobachtung |
|---|---|---|
| Turn 1 | Selector `1176` | Kandidaten **enthalten `core.generate_questions`** — Planner antwortet trotzdem `response_type:clarification`, fragt nach *difficulty*, `commands:[]`, `planned_steps:[]`, `next_step_intent:"Schwierigkeit … abklären"` |
| Turn 2 | Discovery `1178` | Embedding-Query = **nur `"medium"`** (req-Feld wörtlich) |
| Turn 2 | Selector `1179` | Kandidaten = `forget / is_lookup_request / recall_memory / remember / search_skills` → **`generate_questions` fehlt** → wählt `core.search_skills`, input `{}` |
| Turn 2 | Construction `1180` | `tk=1`; statt search_skills-Ergebnis rückzurouten: Sackgassen-Clarification „search_skills kann nicht schreiben" |
| Turn 2 | Sync `1181` | finalisiert die Sackgasse als User-Antwort |

Zusatzfakten aus dem Code:
- `difficulty` ist **optional** (Default `'medium'`, `generate_questions_skill.php:307/479`) → die Rückfrage in Turn 1 war unnötig.
- In Turn 1 war `generate_questions` Kandidat → der Planner *hätte* direkt ausführen können.

---

## 2. Die vier Defekte, je mit Skill-vs-Framework-Einordnung

### A — Planner fragt nach einem *optionalen* Feld (Auslöser)
**Mechanik:** Der Selektor (Planner-LLM) entscheidet anhand des Planner-Prompts (Decision-Order/Guidance),
ob er ausführt oder rückfragt. Hier hat er auf ein optionales Feld hin geklärt, obwohl alle Pflicht-Inputs da
waren.
**Einordnung: HYBRID, überwiegend FRAMEWORK.** Die generelle Regel „fehlende *optionale* Inputs defaulten
still, Rückfrage nur bei fehlendem *Pflicht*-Input oder echter Ambiguität" gehört in den **Planner-Prompt**
(`synchronizer_prompt_builder`/Selektor-Decision-Order) — sonst muss jeder Skill-Autor jedes optionale Feld
defensiv umformulieren. Skill-Anteil: prompt_meta-Hygiene (z. B. `difficulty` aus `input_fields_for_prompt`
nehmen, Beschreibung „optional, default medium, nie erfragen") — nur Pflaster, nicht die Lösung.
**Betroffen:** Planner-Prompt-Builder (Engine).

### C — Discovery-Query nutzt nur die letzte Nachricht (Kern-Bug)
**Mechanik:** `orchestrator::run_discovery_phase` baut `$querytext` aus der **jüngsten User-Nachricht**. Es gibt
bereits einen Augmentierungs-Hook — `$querytext .= ' ' . $pendingstepintent` und das Anhängen geplanter
Platzhalter-Intents — aber der greift nur, wenn `planned_steps`/`next_step_intent` eines **mehrstufigen
Plans** gefüllt sind. Eine **nackte Clarification** füllt diese nicht (Turn 1: `planned_steps:[]`), also bleibt
die Augmentierung leer und Turn 2 embedded nur `"medium"`.
**Einordnung: reines FRAMEWORK.** Ein Skill hat null Einfluss darauf, woraus die Discovery-Query gebaut wird.
**Betroffen:** `orchestrator::run_discovery_phase` (der Hook existiert schon — er wird nur bei Clarifications
nicht gespeist).

### B — Clarification trägt keinen resümierbaren Pending-Intent
**Mechanik:** Es gibt einen `pending_intent_service` + `build_pending_resolution_clarification`
(`agent_decision_service.php:211/333/340`) — aber er wird über
`build_mutating_commands_from_pending_intent` nur für **mutierende, bereits vorbereitete Commands** (Confirm-
Flow) bestückt. Eine **Planner-emittierte Clarification** (Selektor sagt „clarification", `commands:[]`)
erzeugt **keinen** Pending-Intent. Turn 2 muss daher von Null neu discovern.
**Einordnung: reines FRAMEWORK.** Cross-Turn-Planner-State kann kein Skill persistieren.
**Betroffen:** `agent_decision_service` + `pending_intent_service` (Mechanik existiert, deckt diesen Pfad nicht).

### D — `search_skills` endet in der Sackgasse statt rückzurouten (das sichtbare Symptom)
**Mechanik:** `search_skills` ist als Discovery-Zwischenschritt designt
(`search_skills_skill.php:210`: „surface discovered skills as observation so the next planner turn can
re-select"). Aber die Loop hat nach der Ausführung **nicht** eine neue Selektionsrunde über die gefundenen
Skills erzwungen — die Construction-Phase (`tk=1`) durfte user-facing finalisieren („kann nicht schreiben").
**Einordnung: HYBRID.** Skill-Anteil: imperativere Observation-Formulierung („Du MUSST jetzt einen dieser
Skills wählen"). Framework-Anteil (das Eigentliche): Nach `search_skills` muss die Engine die discovered Skills
in den **Kandidatensatz der nächsten Selektion** injizieren und eine **Re-Selection** erzwingen, statt
finalisieren zu lassen.
**Betroffen:** Orchestrator-Loop-Control + Katalog-Injektion; sekundär der Skill.

---

## 2b. Thread 224 — die Ausgangsseite: Synthesizer ignoriert das Preflight-Ergebnis

**Verlauf:** User „Erstelle zwei MC-Fragen zu New York direkt in der default Fragendatenbank". Diesmal lief alles
richtig bis zum Preflight:
- Selector `1183`: `skill_call core.generate_questions` ✓
- Construction `1184`: `confirmation_request` mit vollen Params — **und** das LLM hat die Fragen bereits in
  `content` vorab ausformuliert.
- **Preflight gab korrekt `GENERATE_QUESTIONS_TARGET_AMBIGUOUS` zurück** (unsere neue Kategorie-Nachfrage):
  zwei Kategorien — „Default for System shared question bank (14) [id 6]" und „Mooduell (0) [id 7]".

**Was der Synthesizer (`1185`, ac=agr) als Observation bekam — wörtlich:**
```
[OBSERVATION 1] FINAL_SOURCE_RESULT
response_type=clarification
issue_codes=GENERATE_QUESTIONS_TARGET_AMBIGUOUS
message=This course has more than one question bank category... Where exactly...
  - …Default… (14 question(s)) [category id 6]
  - …Mooduell (0 question(s)) [category id 7]
  Just reply with the name of the category you want and I will create the questions there.
```

**Was der Synthesizer daraus machte (`1185`):** `response_type=sufficient` mit „Ich habe ‚default
Fragendatenbank' als Kategorie-ID 6 interpretiert … Ich kann jedoch derzeit keine Fragen direkt in der
Datenbank erstellen, da keine entsprechende Funktion verfügbar ist. Hier sind die zwei Fragen, die Sie
hinzufügen könnten: …" → er ignoriert die Rückfrage, **wählt selbst** Kategorie 6 (genau das, was die
Clarification verhindern sollte), **halluziniert** „keine Funktion verfügbar" (falsch — der Skill existiert und
stand vor der Ausführung) und **dumpt** die vorab generierten Fragen in den Chat.

### E — Clarification/Confirmation-Ergebnis wird durch den Synthesizer geschleust und verfälscht (Kern-Bug 224)
**Mechanik:** Ein terminaler Zustand „braucht mehr User-Input" (`response_type=clarification`, hier aus dem
Preflight) wird **nicht verbatim emittiert**, sondern an den Synthesizer (`generate_agent_reply`) gegeben,
dessen Auftrag „compose a polished **final** answer" lautet. Eine Rückfrage in einen „finalisiere die Runde"-
LLM zu füttern lädt strukturell zum Erfinden von Abschluss ein. Bei sauberem Input (eine einzelne
Clarification, Thread 223 Turn 1) gelingt die Wiedergabe manchmal; bei widersprüchlichem Input
(`confirmation_request` aus Construction **+** `clarification` aus Preflight **+** vorab generierte Fragen)
halluziniert er Abschluss. In Thread 219 wurde dieselbe Kategorie-Clarification verbatim gezeigt — die
**Inkonsistenz** (mal verbatim, mal synthetisiert) ist selbst der Defekt.
**Einordnung: reines FRAMEWORK.** Routing-/Finalisierungs-Entscheidung der Loop.
**Betroffen:** Orchestrator-Finalisierung + `synchronizer_*`. Regel muss sein: *Ein nicht-abgeschlossener
Zustand (clarification/confirmation/needs_*) umgeht den Synthesizer und wird verbatim emittiert; der
Synthesizer läuft NUR für echte Completions mit zusammenzufassenden Observations.*

### F — Construction formuliert die Fragen vorab in `content` aus (Skill-Vertrag, sekundär)
**Mechanik:** Das `content`-Feld ist als **Quelle/Thema** gedacht, das Construction-LLM hat aber fertige Fragen
(inkl. markierter Lösungen) hineingeschrieben. Das ist der „Stoff", den der Synthesizer dann dumpen konnte —
ein Verstärker von E, nicht die Ursache.
**Einordnung: SKILL.** Schärfere `content`-Beschreibung („Quelltext/Thema, KEINE fertigen Fragen") +
ggf. prompt_meta. Engine bleibt unberührt.
**Betroffen:** `generate_questions_skill::get_schema` (`content`-Beschreibung).

---

## 3. Skill vs. Framework — die Kernfrage

| Defekt | Seite | Skill-Arbeit | Framework-Arbeit | Dominanz |
|---|---|---|---|---|
| A optionale Felder | Eingang | prompt_meta-Hygiene (Pflaster) | Planner-Decision-Order-Regel | **Framework** |
| C Discovery-Query | Eingang | — | Query aus Konversationskontext/Pending-Task speisen | **Framework** |
| B Pending-Intent | Eingang | — | Pending-Intent auch für Planner-Clarifications | **Framework** |
| D search_skills-Routing | Eingang | Observation-Wording | Re-Selection erzwingen + Katalog-Injektion | **Framework** (mit Skill-Support) |
| **E Synthesizer verfälscht Clarification** | **Ausgang** | — | clarification/confirmation umgeht Synthesizer → verbatim emittieren | **Framework** |
| F content vorab ausformuliert | Ausgang | `content`-Beschreibung schärfen | — | **Skill** |

**Fazit:** Das ist **fast ausschließlich ein Framework-Thema** (A,B,C,D,E = Engine; nur F ist Skill). Anders als
beim generischen `add_activity`-Skill (wo wir Task-Logik bewusst aus der Engine heraushalten) gehört die Lösung
**hier legitim in die Engine** — es ist ein Defekt der generischen Planungsschleife selbst, kein Task-Wissen.
Eine Engine-Änderung hier kontaminiert nichts. **E ist besonders heikel**, weil der Synthesizer aktiv die
Sicherheits­logik untergräbt, die wir gerade erst gebaut haben (er wählt selbst eine Kategorie, statt die
Rückfrage durchzulassen).

---

## 4. Auswirkung aufs agentic Framework (Generalisierung)

Der Fehler ist **nicht** auf generate_questions beschränkt. Er trifft **jeden** Pfad, bei dem auf eine
Agent-Rückfrage eine **niedrig-semantische** Antwort folgt — und das ist der Normalfall:

- **Confirmations:** „ja" / „passt" / „mach" — semantisch leer für die Discovery.
- **Auswahl-Antworten:** „die zweite", „medium", „Biologie" — unsere **frisch gebaute Ziel-Kategorie-
  Nachfrage** (generate_questions) ist exakt so verwundbar: User antwortet „Biologie" → Discovery auf
  „Biologie" holt generate_questions evtl. nicht ins Top-K → gleiche Spirale.
- **Difficulty-/Slot-Fills** allgemein.

Damit ist C+B eine **systemische Zuverlässigkeitslücke der Multi-Turn-Loop** auf der *Eingangsseite*, kein
Einzelfall. Jede neue Clarification, die wir in irgendeinen Skill einbauen, erbt dieses Risiko, solange die
Engine die Query nicht kontextualisiert.

**Thread 224 ergänzt die Ausgangsseite (E):** Selbst wenn der Preflight *perfekt* eine Rückfrage liefert, kann
der Synthesizer sie verwerfen. Das ist mindestens so gravierend wie C, denn:
- Es **untergräbt aktiv** unsere gerade gebaute Sicherheits­logik (der Synthesizer wählt selbst eine Kategorie,
  statt die Rückfrage durchzulassen) — IDOR-/Falschziel-Risiko inklusive.
- Es trifft **alle** Skills, deren Preflight `needs_clarification`/`confirmation` zurückgibt — also genau das
  Muster, auf das wir gerade setzen (Kategorie-Nachfrage, künftig `add_activity`-Feldfehler etc.).
- Es ist **inkonsistent**: dieselbe Clarification kam in Thread 219 verbatim durch, in 224 nicht.

**Gemeinsamer Nenner (das eigentliche Framework-Prinzip):** *Ein Turn, der mehr User-Input braucht, ist kein
abgeschlossener Turn.* Die Engine muss solche Zustände an **beiden** Enden gesondert behandeln — beim **Eingang**
(Originaltask in die Discovery falten, C/B) und beim **Ausgang** (verbatim emittieren, nicht synthetisieren, E).
Sekundär-Risiko **D**: `search_skills` verwandelt Fehlschläge zusätzlich in selbstbewusste „geht nicht"-Antworten.

---

## 5. Fix-Richtungen (Framework-seitig, noch nicht umgesetzt)

> Alle Framework-Fixes sind Engine-Änderungen ohne Skill-Kontamination. C und E sind die größten Hebel;
> C nutzt sogar einen **bereits existierenden** Hook.

**Ausgangsseite (Thread 224):**
- **E (höchste Priorität, Sicherheits-relevant):** In der Finalisierung der Loop einen **Bypass** einbauen:
  ist das `FINAL_SOURCE_RESULT` ein nicht-abgeschlossener Zustand (`response_type` ∈ {clarification,
  confirmation_request, needs_clarification/needs_confirmation-Issues}), wird dessen `message` **verbatim**
  als Assistant-Antwort emittiert — der Synthesizer (`generate_agent_reply`) läuft **gar nicht**. Der
  Synthesizer bleibt ausschließlich für echte Completions mit zusammenzufassenden Observations. Beseitigt die
  Inkonsistenz 219↔224 und verhindert, dass der Synthesizer eigene Ziel-Entscheidungen trifft.
- **F (Skill, klein):** `content`-Beschreibung in `generate_questions_skill::get_schema` schärfen
  („Quelltext/Thema, **keine** fertig formulierten Fragen") — entzieht E den „Stoff" zum Dumpen.

**Eingangsseite (Thread 223):**

- **C — zuerst, als reine Heuristik OHNE neuen State (risikoärmste 80-%-Lösung):**
  Die Discovery-Query bei einem Follow-up um die letzte *substanzielle* User-Nachricht aus dem **Verlauf**
  anreichern (in `$querytext` falten; der Hook `$querytext .= ' ' . $pendingstepintent` ist schon da, muss nur
  gespeist werden). „Substanziell" = die letzte User-Nachricht, die **kein** reines Bestätigungs-/Kurzwort ist
  („medium", „ja", „die zweite"). **Entscheidend: C persistiert nichts.** Es schaut jeden Turn frisch in den
  Verlauf — es gibt also **keinen Pending-State, der „kleben" könnte**. Damit ist Georgs Sorge („was, wenn ich
  doch was anderes will") für C gegenstandslos:
  - Schreibt der User einen **vollen neuen Auftrag** („zeig mir die Kursteilnehmer"), ist *diese* Nachricht
    selbst semantisch reich → die Discovery findet den neuen Skill; der kleine angehängte alte Task fällt nicht
    ins Gewicht.
  - Das Augment **beißt nur bei kurzen, mehrdeutigen** Nachrichten — und die sind direkt nach einer Rückfrage
    fast immer die Antwort darauf, kein Themenwechsel. C ist dadurch **selbstbegrenzend**.

- **B — optionales Upgrade, NUR als „Advisory-Augment", mit explizitem Lebenszyklus:**
  Statt C heuristisch zu lassen, den Originaltask beim Emittieren einer Clarification als **Pending-Intent**
  persistieren (nicht nur für mutierende Commands wie heute) und im Folge-Turn deterministisch als
  Query-Augment nutzen. **Wichtige Abgrenzung:** B ist **Advisory** (reichert nur die Discovery an), **niemals
  Hard-Resume** (führt den alten Skill NICHT erzwungen aus) — Hard-Resume wäre genau das Hijack-Problem, das
  wir nicht wollen.

  **Vergessen-Mechanik (wiederverwendet die EXISTIERENDE Confirm-Lifecycle, nichts Neues):**
  `pending_intent_service` hat bereits `get/set/clear/consume`, und `agent_decision_service` cleart heute schon
  über drei Ausgänge — B hängt sich exakt daran:
  1. **Expliziter Abbruch** — Trigger `core.discard_pending_confirmation` („egal / etwas anderes / vergiss
     das") → `clear()`.
  2. **Ersetzen durch neuen Intent** — wählt der nächste Turn einen *anderen* Skill / neuen
     `confirmation_request`, wird der Pending-Intent überschrieben bzw. (bei sonstigem Terminal-Ergebnis)
     gecleart.
  3. **Konflikt sichtbar machen** — `should_block_new_intent_while_pending()` surfacet einen abweichenden neuen
     Intent („du hast noch X offen"), statt heimlich zu kapern.
  4. **Auflösung** → `consume()`; plus **TTL / Turn-Limit als Backstop** (nach 1–2 Turns verfällt der
     Pending-Intent automatisch, da er im Thread-Metadata liegt und nicht ewig kleben soll).

  *Synergie mit E:* derselbe persistierte Originaltask, den E verbatim als Rückfrage emittiert, ist der, den B
  für den Folge-Turn vorhält.

- **A:** Planner-Decision-Order ergänzen: **niemals nach optionalen Feldern fragen**; optionale Slots defaulten
  still. Plus Skill-Hygiene (difficulty nicht als „erfragbar" markieren).
- **D (Sicherheitsnetz):** Nach `search_skills` eine **erzwungene Re-Selection** über die discovered Skills
  (Katalog-Injektion) statt Finalisierung.

**Wirkketten-Logik:** A reduziert die *Häufigkeit* von Follow-up-Turns; **E** stellt sicher, dass eine Rückfrage
überhaupt **korrekt beim User ankommt**; C+B reparieren die *Korrektheit* des Follow-up-Turns; D ist das
Sicherheitsnetz. Ohne E nützt der ganze Eingangs-Fix wenig — die Rückfrage muss erst einmal sauber rausgehen.

---

## 6. Empfohlene Reihenfolge & Aufwand (grob)

1. **E** — **zuerst**, weil sicherheits­relevant und weil eine Rückfrage erst einmal korrekt ankommen muss.
   Finalisierungs-Bypass für nicht-abgeschlossene Zustände. Klar abgrenzbar.
2. **F** — trivial, begleitet E (Skill-Schema-Text).
3. **A** — klein, sofort spürbar (weniger unnötige Rückfragen). Prompt-Regel + prompt_meta.
4. **C (als Heuristik)** — mittel, größter Qualitätssprung auf der Eingangsseite. Query-Bau in
   `run_discovery_phase` um die letzte substanzielle Nachricht ergänzen. **Kein neuer State** → risikoarm,
   nichts kann „kleben". Bewusst **vor** B.
5. **B (optional, später)** — nur wenn wir die Auflösung *deterministisch* statt heuristisch wollen. **Nur als
   Advisory-Augment** mit dem Lifecycle aus §5 (Discard-Trigger / Überschreiben / Block-surface / TTL /
   consume). Nicht zwingend für den Fix — Verschärfung.
6. **D** — mittel. Loop-Control-Re-Selection; primär Robustheit/UX.

---

## 7. Offene Fragen

1. **E-Scope:** Welche `response_type`/Issue-Severities lösen den Synthesizer-Bypass aus? Vorschlag:
   `clarification`, `confirmation_request` und jede `needs_clarification`/`needs_confirmation`-Issue. Gibt es
   Completions, die wir *doch* synthetisieren wollen, obwohl sie eine Rückfrage enthalten? (vermutlich nein)
2. **Verbatim vs. leicht poliert:** Soll die Clarification 100 % verbatim raus (deterministisch, mein Favorit)
   oder darf ein *eng geführter* Reply-Schritt nur Anrede/Sprache anpassen, ohne Inhalt zu ändern? Risiko:
   jeder LLM-Schritt kann wieder halluzinieren (siehe 224).
3. **C vs. B — entschieden:** **C zuerst als zustandslose Heuristik** (kein Pending-State → kein „Kleben",
   Themenwechsel unkritisch). **B** nur als *optionales* späteres Upgrade und *ausschließlich* in der
   Advisory-Augment-Form mit dem §5-Lebenszyklus — nie Hard-Resume. Offen bleibt nur: brauchen wir B
   überhaupt, oder reicht C dauerhaft?
4. **„Substanziell" (für C):** Heuristik = letzte User-Nachricht, die kein reines Bestätigungs-/Kurzwort ist.
   Brauchen wir dafür eine kleine Stoppwort-/Längen-Regel, oder reicht „ist-Bestätigung?"-Trigger negiert?
5. **search_skills-Politik (D):** strikt interner Discovery-Schritt, nie terminale Antwort?
6. **Scope jetzt:** **E+F+A** zuerst (Sicherheit + schnelle Wins), **C (Heuristik)** direkt danach, **B/D** als
   optionale Folge?

---

*Verwandt:* `project_agent_skill_discovery_visibility` (search_skills-RAG-Fallback verdrahtet),
`project_agent_confirm_loop_guards` (Confirm-Loop-Guards), `skill_catalog_planner_analysis.md`,
`generischer_activity_skill_blueprint_2026-06-10.md` (Gegenstück: dort Engine *clean halten*; hier gehört der
Fix legitim *in* die Engine).
```
