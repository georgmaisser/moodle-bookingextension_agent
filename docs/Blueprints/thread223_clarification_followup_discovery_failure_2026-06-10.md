# Blueprint: Clarification-Follow-up verliert den Task in der Discovery (Thread 223)

*Stand: 2026-06-10 · Status: Diagnose + Framework-Impact-Analyse, KEINE Code-Änderungen*

## 0. Worum es geht

Thread 223 zeigt einen Multi-Turn-Fehlschlag, der **kein Skill-Bug** ist, sondern eine systemische
Schwäche der **agentic Loop**: Sobald der Agent eine Rückfrage stellt und der User knapp antwortet
(„medium", „ja", „die zweite"), verliert die nächste Runde den ursprünglichen Auftrag und spiralt in
`core.search_skills` als Sackgasse. Das betrifft potenziell **jede** Clarification/Confirmation — auch die,
die wir gerade erst gebaut haben (difficulty-Frage, Ziel-Kategorie-Nachfrage von generate_questions).

**Verdict vorweg:** 2 von 4 Defekten sind reine **Framework-Probleme**, 2 sind **hybrid** (überwiegend
Framework, kleiner Skill-Anteil). Es ist **kein** „an einem Skill schrauben"-Thema.

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

## 3. Skill vs. Framework — die Kernfrage

| Defekt | Skill-Arbeit | Framework-Arbeit | Dominanz |
|---|---|---|---|
| A optionale Felder | prompt_meta-Hygiene (Pflaster) | Planner-Decision-Order-Regel | **Framework** |
| C Discovery-Query | — | Query aus Konversationskontext/Pending-Task speisen | **Framework** |
| B Pending-Intent | — | Pending-Intent auch für Planner-Clarifications | **Framework** |
| D search_skills-Routing | Observation-Wording | Re-Selection erzwingen + Katalog-Injektion | **Framework** (mit Skill-Support) |

**Fazit:** Das ist **zu ~90 % ein Framework-Thema.** Anders als beim generischen `add_activity`-Skill (wo wir
Task-Logik bewusst aus der Engine heraushalten) gehört die Lösung **hier legitim in die Engine** — es ist ein
Defekt der generischen Planungsschleife selbst, kein Task-Wissen. Eine Engine-Änderung hier kontaminiert
nichts.

---

## 4. Auswirkung aufs agentic Framework (Generalisierung)

Der Fehler ist **nicht** auf generate_questions beschränkt. Er trifft **jeden** Pfad, bei dem auf eine
Agent-Rückfrage eine **niedrig-semantische** Antwort folgt — und das ist der Normalfall:

- **Confirmations:** „ja" / „passt" / „mach" — semantisch leer für die Discovery.
- **Auswahl-Antworten:** „die zweite", „medium", „Biologie" — unsere **frisch gebaute Ziel-Kategorie-
  Nachfrage** (generate_questions) ist exakt so verwundbar: User antwortet „Biologie" → Discovery auf
  „Biologie" holt generate_questions evtl. nicht ins Top-K → gleiche Spirale.
- **Difficulty-/Slot-Fills** allgemein.

Damit ist C+B eine **systemische Zuverlässigkeitslücke der Multi-Turn-Loop**, kein Einzelfall. Jede neue
Clarification, die wir in irgendeinen Skill einbauen, erbt dieses Risiko, solange die Engine die Query nicht
kontextualisiert. Das ist die wichtigste Erkenntnis dieses Dokuments.

Sekundär-Risiko **D**: `search_skills` ist als Sicherheitsnetz gedacht, verschlimmert aber aktuell die Lage,
weil es Fehlschläge in selbstbewusste „geht nicht"-Antworten verwandelt — schlechter als ein ehrliches
„ich habe den Auftrag verloren".

---

## 5. Fix-Richtungen (Framework-seitig, noch nicht umgesetzt)

> Alle vier sind Engine-Änderungen ohne Skill-Kontamination. C ist der größte Hebel und nutzt einen
> **bereits existierenden** Hook.

- **C (höchster Hebel, kleinster Eingriff):** Bei Clarification-/Confirmation-Follow-ups die Discovery-Query
  um den **originären Task** anreichern — die letzte *substanzielle* User-Nachricht und/oder den persistierten
  offenen Intent in `$querytext` falten (der Hook `$querytext .= ' ' . $pendingstepintent` ist schon da, muss
  nur gespeist werden). Erwartung: generate_questions wäre in Turn 2 wieder im Top-K.
- **B:** Beim Emittieren einer Clarification einen **Pending-Intent mit dem Originaltask** persistieren
  (nicht nur für mutierende Commands), den der nächste Turn resümiert/als Query-Augment nutzt. Schließt C
  zustands-sauber ab; knüpft an `pending_intent_service` an.
- **A:** Planner-Decision-Order ergänzen: **niemals nach optionalen Feldern fragen**; optionale Slots defaulten
  still. Plus Skill-Hygiene (difficulty nicht als „erfragbar" markieren).
- **D (Sicherheitsnetz):** Nach `search_skills` eine **erzwungene Re-Selection** über die discovered Skills
  (Katalog-Injektion) statt Finalisierung; Skill-Observation imperativer formulieren.

**Wirkketten-Logik:** A reduziert die *Häufigkeit* von Follow-up-Turns; C+B reparieren die *Korrektheit* von
Follow-up-Turns; D ist das *Sicherheitsnetz*, falls doch nichts matcht. C ist Pflicht, A ist billig, B macht C
robust, D verhindert die verwirrende Sackgasse.

---

## 6. Empfohlene Reihenfolge & Aufwand (grob)

1. **A** — klein, sofort spürbar (weniger unnötige Rückfragen). Prompt-Regel + prompt_meta.
2. **C** — mittel, größter Qualitätssprung. Query-Bau in `run_discovery_phase` kontextualisieren.
3. **B** — mittel. Pending-Intent für Clarifications; macht C deterministisch statt heuristisch.
4. **D** — mittel. Loop-Control-Re-Selection; primär Robustheit/UX.

---

## 7. Offene Fragen

1. **Query-Augment-Heuristik vs. Pending-Intent (C vs. B):** Reicht uns C (letzte substanzielle Nachricht in
   die Query falten) als pragmatische 80 %-Lösung, oder wollen wir gleich B (sauberer State)?
2. **Was ist „substanziell"?** Heuristik (Wortzahl/letzte Nicht-Bestätigungs-Nachricht) vs. explizit
   persistierter Task. Risiko: zu viel Kontext verwässert das Embedding.
3. **search_skills-Politik:** Soll der Skill je user-facing finalisieren dürfen, oder ist er **strikt** ein
   interner Discovery-Schritt (nie terminale Antwort)?
4. **Scope jetzt:** nur C+A (schnell, hoher Nutzen) — B+D als Folge-Arbeit?

---

*Verwandt:* `project_agent_skill_discovery_visibility` (search_skills-RAG-Fallback verdrahtet),
`project_agent_confirm_loop_guards` (Confirm-Loop-Guards), `skill_catalog_planner_analysis.md`,
`generischer_activity_skill_blueprint_2026-06-10.md` (Gegenstück: dort Engine *clean halten*; hier gehört der
Fix legitim *in* die Engine).
```
