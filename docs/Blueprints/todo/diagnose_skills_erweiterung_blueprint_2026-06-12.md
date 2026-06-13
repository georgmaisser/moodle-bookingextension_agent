# Diagnose-Skills: Machbarkeitsprüfung und Planungsdokument

Status: **Analyse / Planung** (keine Umsetzung)
Datum: 2026-06-12
Auftrag: Prüfung von fünf Diagnose-Aufgaben (Georgs Liste, Nr. 21/22/24/25/30) auf
Umsetzbarkeit als Agent-Skills — inkl. Abgleich mit bestehenden Skills,
Erweiterungs- statt Neubau-Optionen, und durchgehend: **echte moodle_url-Links in
der Observation + vernünftige Preview pro Skill**.

---

## 0. TL;DR — Empfehlung

| # | Aufgabe | Empfehlung | Aufwand | Risiko |
|---|---|---|---|---|
| — | `core.search_users` um `lastaccess`/`lastlogin`/`emailstop` erweitern | **Erweiterung** (Quick Win, Vorstufe zu 21/30) | S | gering |
| 21 | Zugriffsprobleme aufklären | **Neuer Skill** `course.diagnose_access` | M | mittel |
| 25 | Auto-Einschreibung diagnostizieren | **Neuer Skill** `course.diagnose_enrolment` (teilt Checks mit 21) | M | mittel |
| 24 | Berechtigungen über Ebenen | **Neuer Skill** `core.diagnose_permissions` (schlankes v1) | M | mittel |
| 30 | Ausbleibende Benachrichtigungen | **Neuer Skill** `core.diagnose_notifications` (Booking-Teil existiert schon) | M–L | mittel–hoch |
| 22 | Noten nachvollziehen | **Neuer Skill** `course.diagnose_grades` — v1 als Fakten-Sammler, KEIN Nachrechnen | M (v1) / L (voll) | hoch |

Alle fünf sind als **R0/readonly**-Skills umsetzbar (kein Confirm-Flow, kein
Mutations-Risiko). Die Hauptarbeit liegt nicht in den Moodle-APIs (die liefern
überraschend viel fertig), sondern in: Cross-User-Capability-Gates,
Kontext-Auflösung außerhalb der Booking-Instanz, Observation-Disziplin
(Datenmenge!), Links und Previews.

---

## 1. Was wir schon haben (Bestandsaufnahme)

### 1.1 Bestehende Diagnose-Skills (alle R0, readonly)

| Skill | Deckt heute ab | Wiederverwendbare Muster |
|---|---|---|
| `mod_booking.diagnose_booking_issue` | Warum kann User Option nicht buchen: Sichtbarkeit, Instance-Flags (disablebooking, maxperuser, banusernames), bo_availability-Conditions, Kapazität, **Kurs-Enrolment** (`is_enrolled`, Z. 358) | User-Resolve-Kaskade (targetuserid > userquery > self), Cross-User-Gate (`mod/booking:bookforothers`), Issue-Type-Autodetect, `reasons[]`-Struktur, `consistency`-Block (requested vs. resolved) |
| `mod_booking.diagnose_cancellation_issue` | Storno-Blocker (`cancelmyself`-Condition) | wie oben |
| `mod_booking.diagnose_user_booking` | Buchungshistorie + **Nachrichten-Log aus Logstore** (message_sent/reminder*-Events, 50 Zeilen, 12 Monate) | Logstore-Abfrage-Muster — direkt relevant für Aufgabe 30 |

**Wichtige Erkenntnis:** Das Diagnose-Genre ist im Agent etabliert. Die neuen Skills
sind Verallgemeinerungen vom Booking-Modul auf Kurs/System — Architektur, Gates,
Observation-Format und Preview-Muster können 1:1 übernommen werden.

### 1.2 Bestehende Read-Skills mit Synergie

- `core.search_users`: liefert bereits Profil, **enrolledcourses[] mit Rollen**,
  roles[] mit contextlevel, echte `profileurl` (moodle_url). Fehlt: `lastaccess`/
  `lastlogin`/`currentlogin`, `emailstop`, Kurs-spezifischer `user_lastaccess`.
  → Georgs Beispiel bestätigt: kleine Payload-Erweiterung gibt dem Skill eine
  Analyse-Funktion (inaktive User, „kommt der überhaupt rein?"-Vorprüfung).
- `course.search_courses`: Kurs-Resolve für Cross-Context (Vorbild: `coursequery`-
  Auflösung via context_resolver, wie `question.generate_questions` mit
  `CONTEXT_COURSE`).
- `core.get_current_user`: Self-Diagnose-Basis.

### 1.3 Infrastruktur, die die neuen Skills tragen muss

- **R0 bypasses Preflight** → ALLE Guards (Cross-User-Capability, Kurs-Zugriff)
  müssen in `execute()` liegen (Lektion aus Threads 323/326 — Preflight-Guards
  feuern bei R0 nicht).
- **Cross-Context:** `agent_context`-DTO + `context_resolver` existieren; Skills
  deklarieren `CONTEXT_COURSE` und lösen `coursequery` auf — vom Navbar-Zauberstab
  (CONTEXT_SYSTEM/USER) aus nutzbar. Genau das brauchen 21/22/25.
- **Anonymizer:** Diagnose-Observations sind userdaten-schwer (Namen, lastlogin,
  Noten). STRICT-Mode maskiert Observations; Code-Token/Links überleben seit dem
  Span-Fix. Noten + E-Mail-Adressen laufen durch dieselbe Maskierung — kein neues
  Konzept nötig, aber Testfall pro Skill.
- **Embeddings:** Jeder neue Skill braucht Catalog-Rebuild (`--embed --force`!)
  und gute Trigger/Description, sonst Discovery-Lücke (D2-Defekt betrifft knappe
  Anfragen; Diagnose-Fragen sind meist reichhaltig formuliert — geringes Risiko).

---

## 2. Querschnitt: Links und Previews (Pflicht für alle fünf)

Georgs Vorgabe (Standing Rule seit dem Link-Audit): **Links baut die Observation,
nie das LLM.** Und: jeder Diagnose-Skill liefert eine Preview.

### 2.1 Link-Helfer (einmalig zu ergänzen)

`booking_skill_support` hat `build_user_link`/`build_option_link_for_output`.
Für die Diagnose-Familie fehlt ein kleines Set (neuer Helper im Agent-Subplugin,
z. B. `diagnostic_link_builder`, alle via `moodle_url`):

| Ziel | URL | Verwendet von |
|---|---|---|
| Kurs | `/course/view.php?id=` | 21, 22, 24, 25 |
| Aktivität | `/mod/{mod}/view.php?id=` | 21, 22 |
| User-Profil (im Kurs) | `/user/view.php?id=&course=` | alle |
| Einschreibemethoden | `/enrol/instances.php?id=` | 21, 25 |
| Eingeschriebene Nutzer | `/user/index.php?id=` | 21, 25 |
| Globale Gruppen | `/cohort/index.php` | 25 |
| Gruppen im Kurs | `/group/index.php?id=` | 21 |
| **Rechte prüfen** (Core-Tool!) | `/admin/roles/check.php?contextid=` | 24 |
| Rollen zuweisen | `/admin/roles/assign.php?contextid=` | 24 |
| Setup Bewertungen | `/grade/edit/tree/index.php?id=` | 22 |
| Nutzer-Notenbericht | `/grade/report/user/index.php?id=&userid=` | 22 |
| Benachrichtigungs-Einstellungen | `/message/notificationpreferences.php?userid=` | 30 |
| Geplante Vorgänge (Admin) | `/admin/tool/task/scheduledtasks.php` | 25, 30 (nur Admin) |
| Mail-Konfiguration (Admin) | `/admin/settings.php?section=outgoingmailconfig` | 30 (nur Admin) |

Regel: Admin-Seiten-Links nur in die Observation aufnehmen, wenn der fragende User
die Seite auch öffnen darf (sonst frustrierende 403-Links).

### 2.2 Preview-Muster: gemeinsame „Diagnose-Checkliste"

Alle fünf Skills haben dieselbe Ergebnis-Gestalt: eine Liste geprüfter Punkte mit
Befund. Statt fünfmal HTML zu bauen: **ein gemeinsamer Preview-Builder**
(im Daten-Contract der Preview-API: `get_result_preview()` → `html`):

```
[✓] Eingeschrieben im Kurs „Mathematik I"        (seit 01.09., Methode: Selbsteinschreibung)
[✗] Aktivität „Quiz 3" für User sichtbar          → Voraussetzung nicht erfüllt: „Quiz 2 abschließen"
[⚠] Gruppenmodus: getrennte Gruppen               → User ist in keiner Gruppe
```

Struktur pro Zeile: `{status: ok|fail|warn, check: string, finding: string,
url: ?moodle_url}` — die Skills liefern Daten, der gemeinsame Builder rendert.
Das ist ein kleiner Baustein (S), der den Preview-Aufwand pro Skill fast
eliminiert und ein konsistentes Erscheinungsbild garantiert.

---

## 3. Die fünf Aufgaben im Detail

### 3.1 Aufgabe 21 — Zugriffsprobleme einer Person aufklären

**Neuer Skill `course.diagnose_access`** (R0, readonly, `CONTEXT_COURSE` mit
`coursequery`-Auflösung).

**Die gute Nachricht:** Moodle-Core erledigt die Aggregation bereits.
`get_fast_modinfo($course, $targetuserid)` liefert pro Aktivität `$cm->uservisible`
und — entscheidend — **`$cm->availableinfo`**: die menschenlesbare Begründung des
Availability-Baums („Nicht verfügbar, bis: Quiz 2 abgeschlossen"). Wir müssen die
Bedingungslogik NICHT nachbauen, nur die Checkliste drumherum:

1. Existiert der Kurs / ist er sichtbar (`$course->visible`, Kategorie-Sichtbarkeit)?
2. Eingeschrieben? (`is_enrolled`), Status der user_enrolments (suspendiert?
   timestart/timeend abgelaufen?), Methode aktiv?
3. Rolle im Kurs vorhanden (aus `get_user_roles`)?
4. Sektion sichtbar / Aktivität `visible`? (verlangt für „versteckt"-Befund
   `moodle/course:viewhiddenactivities`-Hinweis)
5. `$cm->uservisible` + `availableinfo` für den **Ziel-User** (modinfo explizit
   mit targetuserid laden — nicht mit dem fragenden User!)
6. Gruppenmodus + Gruppenzugehörigkeit (`groups_get_user_groups`), falls
   Verfügbarkeit gruppenbasiert.

**Probleme:**
- *Cross-User-Gate:* Diagnose über fremde User braucht Capability im Zielkurs —
  Vorschlag `moodle/course:viewparticipants` als Minimum; für „warum sehe ICH das
  nicht" (Self-Diagnose) reicht Login. Muster aus `diagnose_booking_issue`
  (bookforothers-Kaskade) übernehmen. Guard in `execute()` (R0!).
- *Versteckte Wahrheiten:* Einem Studenten, der nach sich selbst fragt, darf der
  Skill nicht verraten, WAS die versteckte Bedingung ist, wenn der Trainer sie auf
  „nicht anzeigen" gestellt hat (`availableinfo` respektiert das bereits — nicht
  an Core vorbei aus der DB lesen!).
- *Aktivitäts-Resolve:* „warum sieht Maria das Quiz nicht" → Aktivität per Name im
  Kurs auflösen (Fuzzy-Match über modinfo-cms; bei Mehrdeutigkeit Clarification).

**Links:** Kurs, Aktivität, User-Profil-im-Kurs, Einschreibemethoden, Gruppen.
**Preview:** gemeinsame Checkliste (§2.2) — ideales Erstanwendungs-Beispiel.
**Synergie:** Enrolment-Checks (Punkt 2) als interner Helper bauen, den 25
mitnutzt; `diagnose_booking_issue` deckt den Spezialfall Booking-Modul bereits ab
(im Skill-Routing über Trigger sauber trennen: „nicht buchen" → booking,
„nicht sehen/öffnen" → access).

**Aufwand: M.** Größter Einzelposten: saubere Resolve-Kaskaden (Kurs, Aktivität,
User) + Tests.

### 3.2 Aufgabe 25 — Fehlerhafte automatische Einschreibung

**Neuer Skill `course.diagnose_enrolment`** (R0, readonly, `CONTEXT_COURSE`).

**Checks:**
1. Welche Einschreibemethoden hat der Kurs (`enrol_get_instances($courseid,
   false)` — inkl. deaktivierter!), welche sind plugin-seitig an
   (`enrol_is_enabled`)?
2. *Selbsteinschreibung:* Zeitfenster (enrolstartdate/enddate), Passwort gesetzt,
   `customint3` (max. Teilnehmer) erreicht, `customint5` (Kohorten-Beschränkung),
   newenrols zugelassen?
3. *Kohorteneinschreibung:* Ziel-Kohorte der Instanz (`customint1`), ist der User
   Mitglied (`cohort_is_member`)? Rollenzuordnung der Instanz?
4. *Bestehende Einschreibung:* user_enrolments-Status (suspendiert? abgelaufen?
   timeend in Vergangenheit = klassischer „war mal drin"-Fall).
5. *Task-Gesundheit:* `task_scheduled`-Records der relevanten Tasks (cohort-sync,
   enrol-expiry): `lastruntime`, `faildelay > 0`, `disabled` — generisch lesbar,
   kein Plugin-Spezialwissen nötig.

**Probleme:**
- *Plugin-Vielfalt:* v1 bewusst auf `manual`, `self`, `cohort` begrenzen (deckt
  die Aufgabenstellung); andere Methoden nur namentlich listen.
- *Wer darf das sehen:* Enrolment-Konfiguration ist Trainer/Manager-Wissen —
  Gate `moodle/course:enrolreview` im Zielkurs; Task-Gesundheit + Links auf
  Admin-Seiten nur für Site-Admins in die Observation.
- *Kein Schreiben:* „Repariere es"-Folgewünsche (Sync anstoßen) sind bewusst NICHT
  Teil dieses readonly-Skills — wäre ein separater R2-Skill.

**Links:** Einschreibemethoden-Seite, Kohorten-Verwaltung, eingeschriebene Nutzer,
Task-Admin (admin-only), User-Profil.
**Preview:** Checkliste, eine Zeile pro Methode + Befund.
**Aufwand: M.** Teilt User/Kurs-Resolve und Enrolment-Inspector mit 21.

### 3.3 Aufgabe 24 — Berechtigungen über mehrere Ebenen

**Neuer Skill `core.diagnose_permissions`** (R0, readonly; Kontext: Kurs ODER
System).

**Scope-Entscheidung (wichtig gegen Ausufern):** v1 beantwortet zwei Fragetypen:
- *„Welche Rollen hat User X wo (für Kurs Y)?"* → Rollenzuweisungen entlang der
  Kontextkette System→Kategorie→Kurs→Modul (`get_user_roles($context, $userid,
  true)` mit Parents) — **Achtung:** `core.search_users` liefert roles[] bereits;
  hier kommt die Kette + Overrides dazu.
- *„Darf User X im Kurs Y Z tun (Capability)?"* → `has_capability()` für den
  Ziel-User am Zielkontext + **welche Overrides auf der Kette existieren**
  (role_capabilities-Einträge je Kontext der Kette, nur für die gefragte
  Capability).

**NICHT in v1:** „Wer alles hat Recht Z" (`get_users_by_capability` — teuer,
potenziell riesig) und vollständige Capability-Matrizen (tausende Einträge —
Observation-Sprengstoff). Wenn gewünscht: später, mit hartem Cap.

**Probleme:**
- *Output-Disziplin:* Die größte Gefahr ist Datenflut. Immer auf EINE Capability
  oder EINEN User × EINEN Kurs einschränken; fehlt beides → Clarification.
- *Capability-Namens-Auflösung:* User fragen „darf Maria Fragen anlegen?" — das
  Mapping Alltagssprache → `moodle/question:add` muss das LLM im Input liefern;
  der Skill validiert gegen `get_all_capabilities()` und schlägt bei Unbekanntem
  die nächstliegenden Namen vor (Levenshtein/`LIKE`).
- *Gate:* `moodle/role:review` am Zielkontext für Fremd-Analyse.
- *Anonymizer:* Rollen-/Capability-Namen sind Code-Tokens (namespace-ähnlich,
  `mod/booking:addoption`) — der Span-Schutz greift für `a.b`-Muster; das
  `x/y:z`-Muster der Capabilities sollte als Testfall in die
  Anonymizer-Suite (ggf. Pattern ergänzen).

**Links:** **`/admin/roles/check.php?contextid=`** (Moodles eingebautes
„Rechte prüfen" — der perfekte Deep-Link zur Verifikation), Rollen zuweisen,
User-Profil, Kurs.
**Preview:** Tabelle Kontextkette × Rollen + (falls Capability gefragt) Zeile pro
Ebene mit ALLOW/PREVENT/PROHIBIT-Befund.
**Aufwand: M** für das schlanke v1; L, sobald „wer hat was"-Reports gewünscht sind.

### 3.4 Aufgabe 30 — Ausbleibende Benachrichtigungen

**Neuer Skill `core.diagnose_notifications`** (R0, readonly; Kontext User/Kurs).

**Wichtige Vorklärung:** Der **Booking-Teil existiert schon** —
`diagnose_user_booking` liest die versendeten Booking-Nachrichten aus dem Logstore.
Der neue Skill behandelt die generische Frage „warum kommt Mail/Mitteilung X nicht
an" über die Subsysteme:

1. *User-Zustand:* `emailstop`-Flag, suspendiert, unbestätigt, E-Mail-Adresse
   plausibel, Bounce-Zähler (`over_bounce_threshold()`).
2. *Mitteilungs-Präferenzen:* pro Provider (`message_provider_*`-Preferences via
   `\core_message\api`) — gefiltert auf den GEFRAGTEN Kanal (Forum-Digest,
   Booking-Bestätigung, …), nie die komplette Matrix ausgeben.
3. *Forum-spezifisch:* Abo-Status (`\mod_forum\subscriptions::is_subscribed`),
   Digest-Einstellung (`maildigest`), Abo-Modus des Forums.
4. *Versand-Infrastruktur (admin-only):* `$CFG->noemailever`,
   `divertallemailsto`, Task-Gesundheit der Mail-/Digest-Tasks (lastruntime,
   faildelay), Ad-hoc-Task-Rückstau.
5. *Ehrliche Grenze:* Ob der SMTP-Server zugestellt hat, können wir NICHT sehen —
   der Skill muss das explizit sagen (Anti-Halluzinations-Zeile in der
   Observation), statt Zustellung zu behaupten.

**Probleme:**
- *Vier Subsysteme = breiteste Streuung* der fünf Aufgaben; v1 auf E-Mail+Forum+
  Booking-Verweis begrenzen, Mobile-Push explizit ausklammern.
- *Sensible Server-Config:* SMTP-Details NIE in die Observation; nur benennen,
  DASS die Mail-Konfiguration eine Fehlerquelle ist + Admin-Link (Gate:
  `is_siteadmin`).
- *Cross-User:* Präferenzen fremder User sind privat — Gate analog 21
  (`moodle/user:viewdetails` + für Präferenzen restriktiver: Trainer sieht
  Forum-Abo-Status im Kurs, aber nicht die ganze Präferenz-Matrix; im Zweifel
  Befund aggregieren: „Kanal für diesen Provider deaktiviert" ohne Detailmatrix).

**Links:** Benachrichtigungs-Einstellungen des Users, Forum (+Abo-Seite),
Task-Admin und Mail-Config (admin-only), User-Profil.
**Preview:** Checkliste entlang der Kette User-Zustand → Präferenz → Abo →
Versand-Infrastruktur.
**Aufwand: M–L** (viele flache Checks; Gate-Differenzierung ist der teure Teil).

### 3.5 Aufgabe 22 — Falsche oder fehlende Noten

**Neuer Skill `course.diagnose_grades`** (R0, readonly, `CONTEXT_COURSE`).

**Die ehrliche Einschätzung:** Das ist die schwerste der fünf. Die
Gradebook-Aggregation (Gewichtungen, natural weighting, droplow, extra credit,
hidden-Verhalten abhängig von Report-Einstellungen, needsupdate/Regrade) korrekt
NACHZURECHNEN ist ein Großprojekt mit hoher Fehlerwahrscheinlichkeit — und eine
falsche Erklärung ist hier schlimmer als keine.

**Deshalb v1 als Fakten-Sammler statt Erklär-Maschine:** Der Skill sammelt die
relevanten Fakten strukturiert, das LLM ordnet sie ein, und die Observation
enthält eine Anti-Halluzinations-Regel („erkläre nur aus den gelisteten Fakten,
rechne nicht selbst nach"):

1. *Notenstruktur:* `grade_item`-Baum des Kurses (Kategorien, Aggregationsart pro
   Kategorie, Gewichte, droplow/keephigh, Max/Min, Skala vs. Punkte).
2. *Die konkrete Note des Users:* `grade_grade` je gefragtem Item — Flags
   **hidden / locked / overridden / excluded**, rawgrade vs. finalgrade,
   feedback vorhanden?
3. *Aktivitätsseite:* z. B. Assignment-Bewertungsworkflow (Note noch nicht
   freigegeben!), Quiz-Versuch ohne sumgrades — pro Modultyp nur die 2–3
   häufigsten „Note erscheint nicht"-Ursachen (v1: assign + quiz).
4. *Systemzustand:* `needsupdate`-Flag (Neuberechnung ausstehend),
   Kurs-Einstellung `showgrades`, Report-Sichtbarkeitseinstellungen.

**Probleme:**
- *Sensibelste Daten der fünf Aufgaben:* Gate hart — Fremd-Diagnose nur mit
  `moodle/grade:viewall` im Zielkurs; Self-Diagnose nur was der User im eigenen
  Report sähe (hidden-Noten nicht aufdecken!). Doppelt prüfen, weil R0 den
  Preflight umgeht.
- *Anonymizer + Noten:* Zahlenwerte werden nicht maskiert (kein Name) — bewusst
  entscheiden, ob STRICT-Mode Noten in Observations zulässt; ggf. Schalter.
- *Versuchung Vollausbau:* Die Erklärung „warum ist die Endnote 72 statt 75"
  verlangt echtes Nachrechnen → explizit NICHT v1. Wenn der Bedarf real wird,
  ist `grade_category::aggregate_values`-Reuse ein eigenes Blueprint wert.

**Links:** Nutzer-Notenbericht (`grade/report/user` mit userid!), Setup
Bewertungen, Aktivität, User-Profil.
**Preview:** Notenbaum-Tabelle (Item, Gewicht, Note, Flags) + Befundzeilen.
**Aufwand: M (v1-Fakten-Sammler) / L (echtes Erklären).**

---

## 4. Erweiterungen bestehender Skills (statt/vor Neubau)

| Erweiterung | Skill | Inhalt | Aufwand | Nutzen |
|---|---|---|---|---|
| **lastlogin/lastaccess** (Georgs Beispiel) | `core.search_users` + `core.get_current_user` | `lastaccess`, `lastlogin`, `currentlogin`, `firstaccess` (formatiert via userdate) + pro enrolledcourse der `user_lastaccess`-Eintrag; dazu `emailstop` | **S** | Sofort Analyse-Fähigkeit („wann war Maria zuletzt da?"), Vorstufe für 21/30; ein Feld-Block in `build_user_payload()` (core_skill_base) |
| Suspendiert/abgelaufen-Befund | `core.search_users` | enrolledcourses[] um user_enrolments-Status (aktiv/suspendiert/abgelaufen) ergänzen | S | nimmt Aufgabe 25 den häufigsten Fall vorweg |
| Booking-Notification-Trigger | `mod_booking.diagnose_user_booking` | Trigger/Description um „hat keine Mail bekommen"-Formulierungen erweitern, damit Discovery den BESTEHENDEN Skill findet, bevor 30 existiert | S | Quick Win rein über Katalog (Embeddings-Rebuild nötig) |
| Modul-Sichtbarkeitszeile | `mod_booking.diagnose_booking_issue` | `$cm->uservisible`/`availableinfo` der Booking-Instanz in die instance_checks aufnehmen (Fall „sieht die Buchungsaktivität selbst nicht") | S | schließt die Lücke zwischen 21 und dem Booking-Diagnose-Trio |

Empfehlung: Diese vier Erweiterungen **vor** den neuen Skills umsetzen — sie sind
billig, sofort nützlich und liefern Erfahrungswerte (z. B. wie viel User-Detail
die Observation verträgt), die das Design der großen Skills schärfen.

---

## 5. Gemeinsame Bausteine für die Skill-Familie

Um fünf Einzelbaustellen zu vermeiden, vorab drei kleine geteilte Komponenten:

1. **`diagnostic_link_builder`** (§2.1) — moodle_url-Helfer für Kurs/Aktivität/
   Enrol/Rollen/Grade/Preferences-Seiten, mit „darf der Frager die Seite sehen?"-
   Filter. (S)
2. **Checklisten-Preview-Builder** (§2.2) — ein Renderer für
   `{status, check, finding, url}`-Zeilen, von allen fünf Skills genutzt. (S)
3. **`target_user_resolver`** — die User-Resolve-Kaskade + Cross-User-Gate-Prüfung
   aus `diagnose_booking_issue` extrahieren (targetuserid > userquery > self,
   Gate-Capability als Parameter), statt sie ein viertes Mal zu kopieren. (S–M;
   Achtung Engine-Grenze: gehört in die Skill-Schicht/Support, NICHT in
   Engine-Services — local_wbagent-Extraktion!)

---

## 6. Risiken & offene Punkte (über alle Skills)

1. **R0-Guard-Disziplin:** Alle Gates in `execute()`; pro Skill ein expliziter
   phpunit-Test „fremder User ohne Capability → saubere Fehlermeldung" (und seit
   Error-Messaging v2: `message=''` + `error_class`, damit der Synchronizer die
   echte Ursache präsentiert).
2. **Observation-Größe:** Berechtigungs- und Präferenz-Daten explodieren schnell.
   Harte Caps + Filter auf die konkrete Frage (Capability, Kanal, Item) sind
   Designprinzip, nicht Optimierung.
3. **Falsche Sicherheit:** Diagnose-Skills, die etwas NICHT prüfen können (SMTP,
   Notenberechnung), müssen das in der Observation deklarieren — sonst formuliert
   der Synchronizer Gewissheiten, die wir nicht haben.
4. **Namespace-Frage:** `course.*` vs. `core.*` — Vorschlag: kurs-gebundene
   Diagnosen (21/22/25) unter `course.`, kontextkettige/userzentrierte (24/30)
   unter `core.`. Konsistenz mit `course.search_courses`/`core.search_users`.
5. **Skill-Anzahl vs. Selection-Qualität:** +5 Skills vergrößern den
   Selection-Raum; die Benchmark-Fails 4/13 (Selector-Robustheit) sind ein
   bekanntes Signal. Gegenmittel: scharfe, disjunkte Trigger („nicht sehen" ≠
   „nicht buchen" ≠ „keine Note") + Benchmark-Szenarien pro neuem Skill.
6. **Lizenz-Gating:** Diagnose-Skills sind readonly → laufen auch im
   Readonly-Modus ohne PRO-Lizenz. Bewusst entscheiden, ob die neuen Skills
   frei oder PRO-gated sein sollen (DENY_REQUIRES_PRO) — Diagnose ist ein
   starkes Verkaufsargument in beide Richtungen.

---

## 7. Vorgeschlagene Reihenfolge

1. **Stufe 0 (Quick Wins, S):** die vier Erweiterungen aus §4 + die zwei/drei
   gemeinsamen Bausteine aus §5; Embeddings-Rebuild.
2. **Stufe 1:** `course.diagnose_access` (21) — höchster Alltagswert, beste
   API-Unterstützung, etabliert Checklisten-Preview + Link-Builder.
3. **Stufe 2:** `course.diagnose_enrolment` (25) — teilt Inspector mit 21.
4. **Stufe 3:** `core.diagnose_permissions` (24, schlankes v1).
5. **Stufe 4:** `core.diagnose_notifications` (30) — nach Erfahrung mit
   Gate-Differenzierung aus 21/24.
6. **Stufe 5:** `course.diagnose_grades` (22, v1 Fakten-Sammler) — zuletzt, weil
   sensibelste Daten + höchstes Falscherklärungs-Risiko.

Jede Stufe: Skill + Lang-Strings (EN/DE) + Trigger/Guidance + Links + Preview +
phpunit (Gates!) + Benchmark-Szenario + Embeddings-Rebuild — erst dann die nächste.
