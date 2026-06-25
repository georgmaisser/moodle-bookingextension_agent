# „Wo finde ich …?" — Settings-Locator-Skill (Entwurf)

Status: **Entwurf / Planung** (keine Umsetzung)
Datum: 2026-06-12
Fokus: mod_booking zuerst; Architektur so, dass Moodle-Core und weitere Plugins
später andocken können.
Bezug: diagnose_skills_erweiterung_blueprint_2026-06-12.md (Link-/Preview-Regeln),
semantische_site_suche_embeddings_adapter_2026-06-10.md (Embeddings-Korpora),
docs_lookup_skill_analysis_2026-06-08.md (Retrieval-Kaskade)

---

## 1. Auftrag und Anspruch

User fragen: *„Wo kann ich Einstellungen zur Stornierung treffen?"* Der Skill soll:

1. die **richtigen Orte** nennen — über alle Ebenen (Site-Admin, Booking-Instanz,
   Buchungsoption, Verwaltungsseiten),
2. **kontextbewusst verlinken** — wer in einer Buchungsaktivität steht, bekommt
   den Link auf *diese* Instanz, nicht eine Wegbeschreibung,
3. **je nach Recht** den aktuellen Wert mit ausgeben,
4. auf **Folgefragen** („was macht diese Einstellung genau?") eine Erklärung
   liefern können.

Georgs zentrale Anforderung: **So ein Skill muss vollständig sein, um sinnvoll zu
sein.** Ein Locator, der 80 % der Settings kennt, erzeugt falsche Gewissheit
(„gibt es nicht") — schlimmer als gar keiner. Vollständigkeit ist deshalb keine
Qualitätsstufe, sondern Architekturprinzip: **Der Katalog wird generiert, nicht
gepflegt** (§4).

## 2. Die Settings-Landschaft von mod_booking (verifiziert)

| Ebene | Quelle | Umfang | Link-Ziel |
|---|---|---|---|
| **Site** | `settings.php` (2753 Zeilen) | 120+ Settings unter 61 Headings; 41 Headings Pro-bedingt | `/admin/settings.php?section=modsettingbooking` + **Anker `#admin-{name}`** (Moodle rendert jede Settings-Zeile mit dieser ID — Deep-Link aufs einzelne Setting!) |
| **Site, externe Seiten** | 9 `admin_externalpage`-Einträge im `modbookingfolder` | Preiskategorien, Semester, Custom Fields, Optionsformular-Konfiguration, Instanz-/Options-Templates, Availability-Conditions, Rules, Campaigns, Zertifikate | jeweils eigene URL (`/mod/booking/pricecategories.php` …) |
| **Instanz** | `mod_form.php` (moodleform_mod, mehrere Header) | ~30+ Elemente (Confirmation-Mails, maxperuser, cancancelbook, Semester …) | `/course/modedit.php?update={cmid}` + Header-Anker `#id_{headername}container` |
| **Option** | `classes/option/fields/` — **79 Feldklassen**, Interface `fields`, Koordinator `fields_info::get_field_classes()` | canceluntil, bookingopeningtime, prices, availability … | Optionsformular (per Option) bzw. Optionsliste der Instanz |
| **Instanz-Reports/Verwaltung** | edit_rules (auch Instanzebene), Kampagnen, Slot-Rules (`slotrules.php`) … | — | jeweilige Seite mit cmid |

Schon „Stornierung" zeigt die Streuung, die der Skill auflösen muss:
Site-Heading `cancellationsettings` (Pro: canceluntil-Modus, Cooling-off),
Instanz (`cancancelbook`, `allowupdatedays`), Option (`canceluntil`,
`disablecancel`), plus shopping_cart-Seite bei bezahlten Buchungen.

## 3. Skill-Design

### 3.1 Zuschnitt: generischer Skill, komponentenweise Kataloge

**`core.find_setting`** (R0, readonly) im Agent-Subplugin — NICHT
`mod_booking.find_setting`. Begründung: Die Frage „wo stelle ich X ein" ist
nicht booking-spezifisch; booking ist nur der erste und größte Katalog. Der
Skill arbeitet gegen ein Provider-Interface (analog `skill_provider` /
`docs_provider`):

```php
interface settings_catalog_provider_interface {
    /** @return setting_entry[] */
    public function get_setting_entries(): array;   // introspektiert, s. §4
    public function get_component(): string;          // 'mod_booking'
}
```

`setting_entry` (DTO, keine Engine-Abhängigkeit):

```
id            'mod_booking/canceluntil@option'
component     'mod_booking'
level         site | site_page | instance | option
name          'canceluntil'
label         Lang-String-Ref (EN+DE aufgelöst für Embeddings)
description   Settings-Beschreibung (= die vorhandene Doku!)
keywords      ['stornieren', 'cancellation', 'cancel', 'frist', ...]
prerequisite  z.B. 'pro_license' | 'cancancelbook aktiviert' | null
capability    wer die Seite öffnen darf (z.B. moodle/site:config,
              mod/booking:updatebooking, mod/booking:editoption)
url_builder   callable(agent_context): ?moodle_url  — kontextbewusst!
value_reader  ?callable(agent_context): ?string — optional, gate-pflichtig
doc_ref       ?string — Verweis für Erklär-Folgefragen (§6)
```

### 3.2 Input/Output

- **Input:** `question` (Pflicht), `settingquery` (extrahierter Suchbegriff),
  optional `level` (site|instance|option|all), `outputlang`.
- **Ablauf:** Embeddings-Retrieval über den Settings-Korpus (§5) → Top-K (z. B. 8)
  → pro Treffer `url_builder` mit aktuellem `agent_context` ausführen →
  Capability-Filter für Links und Werte → Observation.
- **Observation** (Links baut die Observation, nie das LLM — Standing Rule):

```
[SETTINGS LOCATOR] query="Stornierung" context=Buchungsinstanz "Kursbuchung HS26" (cmid 412)
- [Option] "Stornieren bis" (canceluntil): pro Buchungsoption; Formular-Abschnitt
  "Verfügbarkeit" → <link Optionsliste der Instanz>. Aktueller Wert: nur pro Option
  (siehe get_option_details).
- [Instanz] "Nutzer dürfen selbst stornieren" (cancancelbook): <link modedit #anker>.
  Aktueller Wert: Ja.
- [Site] Abschnitt "Cancellation settings" (PRO): Cooling-off, relative Fristen →
  <link admin/settings.php?section=modsettingbooking#admin-coolingoffperiod>.
  (Wert nicht angezeigt: keine Site-Admin-Rechte.)
RULES: Nenne nur die gelisteten Orte. Wenn der gesuchte Begriff nicht dabei ist,
sage das ehrlich und biete die Moodle-Settings-Suche an: <link /admin/search.php?query=...>.
```

- **Kontextbewusstsein:** `url_builder` bekommt das `agent_context`-DTO:
  - In Buchungsinstanz (CONTEXT_MODULE/booking): Instanz-Links mit diesem cmid,
    Options-Ebene mit Link auf die Optionsliste; war im Gesprächsverlauf eine
    konkrete Option referenziert, deren Options-Formular direkt.
  - Im Kurs: existiert genau EINE Booking-Instanz → deren Links; mehrere →
    Clarification mit Instanzliste (Muster `build_no_instance_scope_result`).
  - Navbar/System: Site-Links direkt, Instanz-/Options-Ebene als „gilt pro
    Instanz/Option" mit Kurssuche-Hinweis.

### 3.3 Wert-Auslesen (je nach Recht)

Dreistufig, immer in `execute()` geprüft (R0 umgeht Preflight!):

| Ebene | Reader | Gate |
|---|---|---|
| Site | `get_config('booking', $name)` | `moodle/site:config` |
| Instanz | `singleton_service::get_instance_of_booking_settings_by_cmid()` | `mod/booking:updatebooking` im Modul-Kontext |
| Option | NICHT hier — Verweis auf `mod_booking.get_option_details` | dort bereits gegated |

Ohne Recht: Link ja (wenn Seite erreichbar), Wert nein, mit explizitem Vermerk in
der Observation — der Synchronizer soll sagen „den aktuellen Wert kann ich mit
deinen Rechten nicht auslesen", nicht schweigen. Werte-Formatierung über die
Setting-Defs (Checkbox → Ja/Nein, Select → Label statt Rohwert).

### 3.4 Preview

Wiederverwendung des Checklisten-/Tabellen-Preview-Builders aus dem
Diagnose-Blueprint (`{level, label, value?, url}`-Zeilen): Tabelle „Einstellung /
Ebene / aktueller Wert / Öffnen-Button". Ein Treffer-Klick führt direkt zum
Anker. Kein eigener Renderer nötig.

## 4. Vollständigkeit: Katalog generieren, nicht pflegen

Das Kernproblem. Drei Quellen, drei Strategien:

### 4.1 Site-Settings — Introspektion des Admin-Trees (vollautomatisch)

Ein Build-Service läuft `admin_get_root()` für die Booking-Sektion(en) ab und
extrahiert pro `admin_setting`: `name`, `visiblename`, `description`,
Section-Key, Heading-Zuordnung. Das ist **per Konstruktion vollständig** — jedes
neue Setting in `settings.php` erscheint beim nächsten Katalog-Build von selbst.
Die 9 `admin_externalpage`-Einträge kommen aus demselben Tree (Name + URL).

**Achtung Pro-Bedingungen:** 41 von 61 Headings sind Pro-bedingt — `settings.php`
registriert sie nur, wenn PRO aktiv ist. Der Katalog-Build muss auf der
Ziel-Installation laufen (Scheduled Task / nach Upgrade), dann stimmt er
automatisch mit dem überein, was der Admin tatsächlich sieht. Einträge, die nur
wegen fehlender PRO-Lizenz fehlen würden, optional mit `prerequisite='pro_license'`
trotzdem aufnehmen („gibt es, braucht PRO") — bessere Antwort als „gibt es nicht".

### 4.2 Options-Felder — Introspektion der Feldklassen (vollautomatisch)

`fields_info::get_field_classes()` liefert die 79 Klassen;
`mod_booking.list_option_properties` enumeriert sie heute schon für das
Create/Update-Schema. Derselbe Mechanismus füttert den Katalog (Name, Header im
Optionsformular, Lang-Strings). Neue Feldklasse → automatisch im Katalog.

### 4.3 Instanz-Formular — der harte Teil

`mod_form.php` ist klassischer mform-Code ohne Registry. Optionen:

- **(a) Dry-Run-Introspektion (empfohlen):** Formular einmal instanziieren
  (Muster aus dem generischen add_activity-Blueprint: mform als maschinenlesbarer
  Vertrag), `_elements` durchlaufen, `element->getName()/getLabel()` + zugehörigen
  Header extrahieren. Aufwand einmalig mittel; danach vollautomatisch.
  Risiko: mod_form braucht Kurs-/CM-Kontext für die Instanziierung — im
  Build-Task mit einer realen (beliebigen) Instanz oder definierten Testdaten.
- **(b) Kuratierte Liste + CI-Vollständigkeitstest:** Hand-Katalog, und ein
  phpunit-Test macht den Dry-Run aus (a) nur zum ZÄHLEN/Vergleichen — schlägt
  fehl, sobald ein Formularfeld ohne Katalogeintrag auftaucht. Weniger elegant,
  aber (a)-Risiko entfällt im Runtime-Pfad.

Empfehlung: (a) anstreben, (b) als Sicherheitsnetz unabhängig davon einbauen.
**Der CI-Vollständigkeitstest ist nicht optional** — er IST die Garantie, auf der
Georgs Anspruch ruht, für alle drei Quellen (Site-Tree-Zählung, Feldklassen-
Zählung, mform-Zählung gegen Katalog).

### 4.4 Synonyme/Keywords — der einzige kuratierte Teil

Labels + Descriptions decken viel ab, aber „Stornierung" muss auch
`coolingoffperiod` finden. Pro Eintrag ein optionales `keywords`-Feld, kuratiert
NUR dort, wo Label+Description nicht reichen. Embeddings (§5) reduzieren den
Bedarf deutlich (semantische Nähe statt Wortgleichheit); die Benchmark zeigt,
wo nachkuratiert werden muss.

## 5. Retrieval: dritter Embeddings-Korpus

Die Infrastruktur existiert (Skill-Katalog + Docs-Korpus nutzen
`embeddings_catalog_builder_service` / `embeddings_retrieval_service` mit
Cosine-Similarity, CSV-Repository). Der Settings-Katalog wird der **dritte
Korpus**: pro Eintrag ein Embedding-Text aus `label(EN+DE) + description +
keywords + level + component`. Query → Top-K → Capability-/Kontext-Filter.

- Mehrsprachigkeit: EN+DE-Strings in den Embedding-Text aufnehmen (das
  Embedding-Modell matcht DE-Fragen auf EN-Labels ordentlich, aber beide Sprachen
  im Text machen es robust).
- Lexikalischer Fallback (LIKE über name/label/keywords), wenn Embeddings nicht
  ready — gleiche Kaskade wie `explain_docs_skill`.
- Letzter Fallback in JEDER Antwort ohne sicheren Treffer: Link auf Moodles
  eingebaute Settings-Suche `/admin/search.php?query={begriff}` (existiert,
  Param `query`, rein lexikalisch, nur Site-Ebene, `$hassiteconfig`-gated —
  Link nur für Admins ausgeben).
- Rebuild: in den bestehenden Embeddings-Rebuild eingehängt
  (`cli/rebuild_embeddings_fixture.php`-Familie; `--force`-Falle beachten) +
  Scheduled Task nach Plugin-Upgrade (Katalog-Hash-Vergleich, nur Delta neu
  embedden — Muster Chunk-Hash-Dedup aus dem Site-Suche-Blueprint).

## 6. Erklär-Folgefragen („was macht das genau?")

Zwei Quellen, ehrlich getrennt:

1. **Die Setting-Description selbst** ist die offizielle Doku — sie steht schon
   im Katalog-Eintrag und gehört in die Observation. Für viele Folgefragen
   reicht das, ohne zweiten Skill-Call.
2. **`explain_docs_skill`** für tieferes: Der Agent-Docs-Korpus dokumentiert
   heute den AGENTEN, nicht die Booking-Fachlichkeit — das muss man wissen,
   sonst verspricht man Erklärtiefe, die der Korpus nicht hat. Option: einen
   zweiten Docs-Korpus `mod_booking_userdocs` registrieren (docs_provider kann
   mehrere Korpora), gespeist aus der Wunderbyte-Endnutzer-Doku, und
   `doc_ref` der Katalogeinträge dorthin zeigen lassen. **Offene Frage an
   Georg** (§9/3) — ohne diesen Korpus bleibt Stufe 2 ehrlich begrenzt auf
   Setting-Descriptions.

Mechanik der Folgefrage: kein Sonderbau. Der Locator legt `doc_ref` und den
Hinweis „für Details: explain"-Guidance in die Observation; die normale
Skill-Selection routet die Folgefrage zu `explain_docs_skill` (Trigger der
beiden Skills sauber abgrenzen: „wo finde ich" ≠ „was bedeutet").

## 7. Generalisierung über Booking hinaus (im Hinterkopf, nicht v1)

Die Architektur trägt sie ohne Umbau:

- **Moodle-Core + beliebige Plugins, Site-Ebene:** §4.1-Introspektion über den
  GESAMTEN Admin-Tree statt nur der Booking-Sektion — derselbe Service, ein
  Parameter. Damit beantwortet der Skill „wo stelle ich die Passwort-Policy ein"
  site-weit, ohne dass irgendjemand kuratiert. (Katalog wird groß: ~2000+
  Settings — Embeddings-Korpus skaliert, Top-K bleibt konstant.)
- **Instanz-/Aktivitätsebene anderer Module:** je Plugin ein
  `settings_catalog_provider` (Interface §3.1). Booking ist Referenz-
  implementierung; course-Settings (`/course/edit.php`) wären der zweite
  natürliche Provider.
- **Namespace bleibt `core.find_setting`** — ein Skill, viele Kataloge; das
  `component`-Feld filtert, der Kontext priorisiert (in einer Booking-Instanz
  ranken Booking-Treffer vor Core-Treffern).

V1-Schnitt: nur der mod_booking-Provider (alle 4 Ebenen) + Admin-Search-Fallback.

## 8. Risiken und Stolpersteine

1. **Vollständigkeits-Erosion:** ohne CI-Test (§4.3) driftet der Katalog ab —
   der Test ist Teil der Definition of Done, nicht Nice-to-have.
2. **Pro-/Bedingungs-Sichtbarkeit:** Settings, die nur unter Bedingungen
   existieren (PRO, abhängige Checkboxen, `hideIf`), dürfen nicht als „nicht
   vorhanden" erscheinen → `prerequisite`-Feld + ehrliche Formulierung.
3. **Rechte-Leaks:** Wert-Auslesen strikt gaten (§3.3); auch LINKS auf
   Admin-Seiten nur, wenn erreichbar (403-Links frustrieren). Beides in
   `execute()`, mit phpunit-Tests pro Gate (R0!).
4. **Anker-Stabilität:** `#admin-{name}` ist Moodle-Standard, aber
   `#id_{header}container` im modedit-Formular ist fragiler (Theme/Collapse).
   Fallback: Link ohne Anker + Abschnittsname im Text nennen.
5. **Mehrdeutigkeit:** „Stornierung" trifft 6+ Orte — das ist KEIN Fehler,
   sondern die Antwort (gruppiert nach Ebene). Aber Top-K begrenzen und bei
   echt vagen Queries (1 Wort, viele Ebenen) eine Rückfrage stellen
   (Clarification-Muster).
6. **Selection-Abgrenzung:** Kollisionsgefahr mit `explain_docs_skill`
   („wie funktioniert X") und `list_option_properties` („welche Felder hat eine
   Option"). Scharfe Trigger: Orts-Fragen (wo/where/einstellen/finden/
   konfigurieren) → Locator. Benchmark-Szenarien für alle drei Abgrenzungen.
7. **Katalog-Staleness:** Plugin-Update ändert Settings → Rebuild-Hook nach
   Upgrade + Hash-Delta-Embedding (§5), sonst veraltete Links.

## 9. Offene Fragen an Georg

1. **Skill-Name/Scope bestätigen:** `core.find_setting` als generischer Skill mit
   Booking-Provider (Empfehlung) — oder bewusst `mod_booking.find_setting`
   starten und später generalisieren?
2. **Instanz-Formular v1:** Dry-Run-Introspektion (a) gleich versuchen oder mit
   kuratierter Liste + CI-Zähltest (b) starten?
3. **Endnutzer-Doku-Korpus:** Gibt es eine Wunderbyte-Booking-Doku (Markdown/
   exportierbar), die wir als zweiten Docs-Korpus fürs Erklären registrieren
   können? Ohne sie bleibt Erklärtiefe = Setting-Descriptions.
4. **Site-weite Core-Settings** schon in v1 mitnehmen (Admin-Tree komplett,
   nur für Site-Admins sichtbar) oder strikt Booking-only starten?
5. **PRO-Gating des Skills selbst:** Locator frei für alle (Readonly-Modus) oder
   PRO-Feature?

## 10. Aufwandsschätzung (V1 = Booking-Provider)

| Baustein | Aufwand |
|---|---|
| `setting_entry`-DTO + Provider-Interface + Registry-Anbindung | S |
| Katalog-Builder Site (Admin-Tree-Introspektion + externe Seiten) | M |
| Katalog-Builder Option (fields_info-Reuse) | S |
| Katalog-Builder Instanz (Dry-Run mform) + CI-Vollständigkeitstests (alle 3 Quellen) | M |
| Embeddings-Korpus Nr. 3 (Builder/Retrieval-Reuse, Rebuild-Hook) | M |
| Skill `core.find_setting` (Retrieval, Kontext-URL-Builder, Wert-Gates, Observation, Preview, Trigger, Lang EN/DE) | M |
| Tests (Gates, Kontext-Varianten Navbar/Kurs/Instanz, Abgrenzungs-Benchmarks) | M |

Gesamt: **mittel** — getragen davon, dass Embeddings-Infrastruktur, Preview-
Builder, Kontext-Resolver und die Introspektions-Vorbilder
(`list_option_properties`, add_activity-mform-Muster) existieren. Der
qualitätskritische Teil ist nicht der Skill, sondern die
Katalog-Generierung + ihre CI-Absicherung.
