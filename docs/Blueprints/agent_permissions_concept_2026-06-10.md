# Rechte-Konzept für den wbagent — Admin → Teacher → Student

**Datum:** 2026-06-10
**Status:** Konzept (zur Diskussion mit Georg)
**Anlass:** Navbar-Rollout machte sichtbar, dass Kurs-/CM-AI-Toggles auch Admins blockieren. Gewünschtes Zielbild: Admins nie durch Kurs-/CM-Beschränkungen gebremst; Teacher durch den Admin pro Kurs steuerbar; Studenten später mit eng begrenztem Skill-Set (Fragen stellen, Dinge einbringen).

---

## 1. Leitprinzipien

1. **Moodle-native:** Wer was darf, entscheiden Rollen + Capabilities + Kontexthierarchie — keine hardcodierten Audiences im Code. Wichtig: **Site-Admins passieren `has_capability()` immer** (`moodle/site:doanything`). „Admin darf alles" ist in Moodle eingebaut; wir müssen es nur nicht versehentlich aushebeln (genau das tun die AI-Toggles heute, weil sie Settings und keine Capabilities sind).
2. **Zwei-Gate-Modell bleibt** (gelockte Entscheidung 2026-06-09): Gate 1 = Agent-Exposure (Agent-/Skill-Caps + Skill-Toggles), Gate 2 = native Moodle-Capability der Kernaktion im Preflight. **Anti-Bypass:** Der Agent verschafft nie ein Recht, das der User nativ nicht hat. Daraus folgt: Auch ein großzügiges Gate 1 ist nie gefährlich — es zeigt höchstens Skills, deren Preflight dann verweigert.
3. **Verfügbarkeit ≠ Berechtigung:** Die Core-Toggles `enableaitools` (Kurs + Kursmodul) sind eine *Verfügbarkeitsschicht* („In diesem Kurs soll KI nicht benutzt werden"), keine Rechteverwaltung. Sie sollen für nicht-privilegierte Nutzer gelten — und per Capability übersprungen werden können.
4. **Wir treffen keine Entscheidungen für Admins:** Alle Defaults sind Vorschläge über Rollen-Archetypen; jede Site kann sie im Rollen-UI umbiegen.

---

## 2. Das Schichtenmodell (Prüfreihenfolge)

| # | Schicht | Mechanismus | Gilt für |
|---|---------|-------------|----------|
| 0 | Global | Plugin installiert, Provider konfiguriert/aktiv, Feature-Schalter (`inject_in_navbar`), Core-AI-Policy-Akzeptanz pro User (macht core_ai selbst) | alle |
| 1 | **Gate 1a — Agent öffnen** | `agent:useaiinstructions` am aktuellen Kontext (Navbar-Hook, Fragment, jeder WS-Call) | alle außer Admins (implizit) |
| 2 | **Verfügbarkeit** | Kurs-/CM-`enableaitools` — **nur erzwungen für User ohne `agent:ignoreaiavailability`** (NEU) | Teacher/Studenten; Manager/Admins überspringen |
| 3 | **Gate 1b — Skill-Exposure** | per-Skill-Caps `agent:skill_*` (Tiers) + Admin-Skill-Enable-Toggles + `get_required_context_level()` | alle außer Admins (implizit) |
| 4 | **Gate 2 — native Capability** | `require_native_capabilities()` im Preflight am Operating-Kontext (P4c) — z. B. `mod/booking:addoption`, `moodle/question:add` | **ausnahmslos alle**, auch Admins formal (die bestehen ihn implizit) |
| 5 | Kontextwechsel | `context_resolver` + `require_capability_at()` re-prüft die Cap am Ziel-Kontext (Eskalation ausgeschlossen) | alle |

Der einzige neue Baustein ist Schicht 2 als *bedingte* Schicht — heute gilt sie unbedingt und bremst Admins aus.

---

## 3. Capability-Inventar

### Bestehend (bleibt)
| Capability | Level | Default |
|---|---|---|
| `agent:useaiinstructions` | MODULE → **auf COURSE senken** (s. Delta) | editingteacher |
| `agent:skill_*` (~100, generiert) | MODULE | Tier teacher (`teacher`+`editingteacher`) / Tier manager / adminonly |
| `agent:debugskillselection`, `agent:viewbenchmarks`, `agent:managebenchmarks` | SYSTEM | manager |

### Neu
| Capability | captype/Level | Default | Zweck |
|---|---|---|---|
| **`agent:ignoreaiavailability`** | read / COURSE | `manager` CAP_ALLOW (Admins implizit via doanything) | Kurs-/CM-AI-Toggles überspringen. Beantwortet exakt: „Admin soll keine course/cm-Beschränkung haben, Teacher schon." Da pro Kurs(bereich) zuweisbar, kann ein Admin sie gezielt auch einzelnen Vertrauens-Teachern geben. |
| *(Phase 3)* `agent:skill_*` Studenten-Tier | MODULE/COURSE | `student` CAP_ALLOW | siehe §5 |
| *(optional, Phase 2/3)* `agent:unlimitedusage` | read / SYSTEM | manager | Quota-Bypass, siehe §6 |

### Delta an Bestehendem
- `useaiinstructions`: `contextlevel` von MODULE auf **COURSE** senken — checkbar war sie schon überall (P1), aber so erscheint sie im Kurs-Rollen-UI und ist auf Kursbereichs-Ebene natürlich zuweisbar. Kein Verhaltensbruch (Modul erbt vom Kurs).

---

## 4. Rollen × Phasen-Matrix

| Rolle | Phase 1 (jetzt: Admins) | Phase 2 (Teacher) | Phase 3 (Studenten) |
|---|---|---|---|
| **Site-Admin** | alles, überall, kein Toggle bremst (implizit) | — | — |
| **Manager** | wie Admin via Defaults (`useaiinstructions`? → bewusst entscheiden; `ignoreaiavailability` ja) | unverändert | unverändert |
| **Editingteacher/Teacher** | hat `useaiinstructions` heute schon — in Phase 1 ggf. per Rollen-Override site-weit auf PREVENT, wenn der Rollout wirklich nur Admins sehen soll | aktiv: Steuerung durch (a) Kurs-Toggle `enableaitools` (kein Bypass!), (b) Rollenzuweisung pro Kursbereich, (c) Skill-Enable-Toggles | unverändert |
| **Student** | nichts | nichts | `useaiinstructions` CAP_ALLOW + nur Studenten-Skill-Tier |

**Wichtig für Phase 1:** Der heutige `editingteacher`-Default bedeutet, dass Teacher den Agenten *jetzt schon* sehen, sobald die Navbar an ist und Provider/Toggles passen. Wenn Phase 1 strikt „nur Admins" heißen soll, ist der saubere Moodle-Weg: site-weiter Rollen-Override (useaiinstructions → PREVENT für editingteacher) ODER Default im Plugin erst ab Phase 2 auf editingteacher setzen. Empfehlung: Default jetzt rausnehmen (kein Archetyp), in Phase 2 wieder rein — das ist ein bewusster „Opt-in-per-Release"-Pfad und verhindert Überraschungen auf Kundensystemen.

---

## 5. Studenten-Phase: enges, additives Skill-Set

Studenten bekommen **keinen Zugriff auf bestehende Teacher-Skills**, sondern ein eigenes Tier mit anderem Interaktionsmuster:

- **Fragen stellen (R0):** `core_ask_question` / Docs-/Kurs-RAG — rein lesend, Gate 2 = `moodle/course:view`-Niveau.
- **Eigenes einsehen (R0):** z. B. `booking_get_my_bookings` — strikt auf `$USER` gefenced (Thread-Fencing existiert schon).
- **Dinge einbringen (Request-Muster statt Mutation):** `core_submit_suggestion` schreibt in eine agent-eigene Suggestions-Tabelle (Inbox für Trainer/Admin), statt Kursdaten zu ändern. Gate 2 trivial erfüllbar, weil die „Mutation" nur den eigenen Vorschlag anlegt. Trainer bestätigt → erst *seine* Caps führen die echte Aktion aus. Das Anti-Bypass-Prinzip bleibt damit wasserdicht.
- Risikoklassen: Studenten-Tier ausschließlich R0 + Request-Skills; kein Skill mit `RISK_DATALOSS`.

---

## 6. Kostenkontrolle (orthogonal, aber Voraussetzung für Phase 2/3)

Rechte beantworten „darf", nicht „wie viel". Vor Teacher-/Studenten-Rollout braucht es eine Quota-Schicht: Requests (und/oder Tokens) pro User und Tag, Settings pro Tier (z. B. `quota_requests_teacher`, `quota_requests_student`), Bypass via `agent:unlimitedusage` (Default manager, Admins implizit). Durchsetzung zentral im Entry-Point (`ai_send_message`), Zählung pro User/Tag in einer kleinen Tabelle oder MUC.

---

## 7. Konkretes Code-Delta (klein)

1. `db/access.php`: neue Cap `ignoreaiavailability`; `useaiinstructions` contextlevel → COURSE; (Phase 1 ggf. Archetyp-Default von useaiinstructions entfernen).
2. `orchestrator::get_runtime_provider_status()` + `aiready`: `courseenabled`/`contextenabled` nur erzwingen, wenn der User **nicht** `ignoreaiavailability` am Kontext hat (Readiness braucht dafür die userid — hat sie). Checks-Anzeige: übersprungene Toggles als „nicht relevant (Bypass)" statt rot.
3. `agent_runtime`/Entry-Points: dieselbe Bypass-Bedingung an der Laufzeit-Verfügbarkeitsprüfung (nicht nur Readiness), damit Anzeige und Verhalten konsistent sind.
4. Phase 3: `$studentskills`-Tier in db/access.php + neue Skills (eigene Blueprints).
5. `local_wbagent`-Auskopplung: Caps werden 1:1 zu `local/wbagent:*` — Konzept unabhängig davon.

## 8. Offene Entscheidungen für Georg

- O1: Phase 1 strikt? → `useaiinstructions`-Default (editingteacher) jetzt entfernen oder per Site-Override lösen?
- O2: Soll `ignoreaiavailability` auch das **CM-Toggle** überspringen (konsequent: ja) oder nur das Kurs-Toggle?
- O3: Manager in Phase 1 wie Admin behandeln (useaiinstructions-Default für manager) — ja/nein?
- O4: Quota-Schicht schon in Phase 2 (Teacher) oder erst Phase 3?
