# Blueprint · Kontext-spezifische Indexierungs-Governance (Kursbereiche + Kurse)

> **Status:** Entschieden (Georg, 2026-07-02) — Umsetzung K1–K4 in einem Zug beauftragt.
> **Scope:** `bookingextension_agent` Site-Search-Governance — Erweiterung der Area-Freischaltung um
> die räumliche Dimension (Kursbereich/Kurs), inkl. Estimator/Ampel pro Scope und Delta-Sync.
> **Dach-Dokument:** `retrieval_foundation_and_site_search_2026-07-02.md` (§5b, §11.28). Dieses Doc
> ist das maßgebliche Detail-Konzept; bei Widerspruch gilt das Dach-Dokument.

## 1. Motivation

Embeddings kosten Geld. Die Governance-Seite schaltet bisher pro **Area** site-weit (seit heute plus
`includefiles` pro Area-Zeile). Die Indexierungs-Entscheidung muss zusätzlich **räumlich** steuerbar
sein: pro Kursbereich und pro Kurs — mit Kosten-Schätzung und Ampel **an der Entscheidung**, bevor
freigeschaltet wird.

## 2. Datenmodell (vorhanden, kein Schema-Change)

`{bx_agent_search_scope}(area, scopetype ∈ {site, category, course}, scopeid, enabled, includefiles)`
— war von Anfang an dafür ausgelegt (§5b.3 Dach-Doc); bisher nur `site`-Zeilen in Verwendung.
`sitesearch_scope_repository::set_enabled()/set_includefiles()` akzeptieren `scopetype`/`scopeid`
bereits.

## 3. Semantik: Kaskade mit Spezifizitäts-Vorrang (ENTSCHIEDEN)

Pro Area und Kurs gewinnt die **spezifischste Regel-Zeile vollständig** — d. h. mit ihrem
`enabled`- UND ihrem `includefiles`-Wert als Paar (Entscheidung Georg: keine getrennte Kaskade der
beiden Flags):

```
Kurs-Zeile  >  tiefste Kategorie-Zeile auf dem Kategorie-PFAD des Kurses  >  Site-Zeile  >  Default AUS
```

### 3.0 Wildcard-Area-Regeln (Nachtrag 2026-07-02i, ENTSCHIEDEN)

Der häufigste Admin-Wunsch — „indiziere DIESEN Kurs komplett, inkl. aller Aktivitäten" — braucht
eine Regel über **alle** Areas. Dafür ist `area = '*'` als **Wildcard-Zeile** zugelassen (kein
Schema-Change; Bedeutung: alle Areas mit `contextsupport` module|course — User-/Message-/Block-Areas
bleiben per §9 draußen). Ein Makro, das N Einzelregeln anlegt, ist ausdrücklich NICHT gewollt: es
speichert einen Schnappschuss statt der Absicht (neu installierte Plugin-Areas wären nicht
abgedeckt) und flutet die Regel-Listen.

**Kaskade mit Tiebreaker (lexikografisch — Scope zuerst, dann Area-Spezifität):**

1. Scope-Spezifität: Kurs > tiefste Kategorie am Pfad > Site (unverändert).
2. **Bei gleichem Scope-Level: konkrete Area-Zeile schlägt Wildcard-Zeile.**

Verhaltens-Beispiele (normativ):
- Wildcard-Kurs-Regel AN + Site-Zeile „Foren AUS" → der Kurs wird **komplett** indexiert
  (Kurs-Scope schlägt Site, auch als Wildcard).
- Wildcard-Kurs-Regel AN + konkrete Kurs-Regel „Foren AUS" (gleicher Kurs) → alles außer Foren
  (gleicher Scope, konkrete Area gewinnt).
- Site-Wildcard AN = „alles überall" — zulässig; die Gesamt-Ampel macht die Konsequenz sichtbar.

**UI:** eigener Abschnitt **über** der Area-Tabelle („Kurs- und Kursbereichs-Regeln — alle
Inhaltsbereiche"): dieselbe Dynamic-Form mit `area='*'` vorbelegt, dieselbe Regel-Listen-Darstellung;
Schätzung einer Wildcard-Regel = **Summe über alle abgedeckten Areas** des Scopes (bounded, „>N",
file-inklusive nach Flag). Die Area-Panels bleiben für die Feinsteuerung.

**Enforcement:** Resolver prüft pro Scope-Level zwei Zeilen (konkret, dann `'*'`); Indexer,
Delta-Sync und Suche konsumieren weiter nur `effective()`/`shape()`. Wildcard-Mutationen feuern den
Delta-Chokepoint **pro betroffener Area** (Adhoc pro Area oder multi-area-fähige customdata —
Implementierungsdetail, Backfill/Prune-Semantik unverändert).

- **Kategorie-Vererbung pfad-basiert** (Entscheidung Georg): eine Regel auf Kategorie K gilt für
  alle Kurse in K und allen Unterkategorien, bis eine tiefere Kategorie- oder Kurs-Regel sie
  überschreibt. Auflösung über `core_course_category`-Pfad (`path`-Spalte), NICHT nur exakte Ebene.
- Deckt ohne Modus-Schalter beide Muster ab: **Allowlist** (Site aus + ausgewählte Scopes an) und
  **Blocklist** (Site an + Ausschlüsse aus).

### 3.1 Resolver-Contract (eingefroren für die parallele Umsetzung)

`services/sitesearch/sitesearch_scope_resolver.php`:

```php
final class sitesearch_scope_resolver {
    // Effektive Regel für einen Kurs: ['enabled' => bool, 'includefiles' => bool].
    public function effective(string $area, int $courseid): array;

    // Regel-Form einer Area für die Indexer-Strategiewahl:
    // ['strategy' => 'off'|'allowlist'|'blocklist',
    //  'allowedcourseids' => int[]   (nur allowlist: vollständige erlaubte Kursmenge),
    //  'excludedcourseids' => int[]  (nur blocklist: verbotene Kurse),
    //  'sitedefault' => ['enabled' => bool, 'includefiles' => bool]]
    public function shape(string $area): array;

    // Vollständige erlaubte Kursmenge (für Delta-Diff und Estimator-Summen); bounded/lazy.
    public function allowed_courseids(string $area): array;
}
```

- 'off' = keine Zeile gewährt irgendwo Enablement → Area komplett inaktiv.
- Request-lokales Caching (static) erlaubt; Quelle der Wahrheit ist immer die Tabelle.
- `site_content_area_registry::enabled_area_keys()` bedeutet fortan: „Area hat irgendwo aktive
  Coverage" (Site-Zeile enabled ODER mindestens eine enabled Kategorie-/Kurs-Zeile).

## 4. Enforcement im Indexer: zwei Lese-Strategien, ein Schreibpfad

- **allowlist:** pro erlaubtem Kurs ein context-gescopter Recordset
  `get_document_recordset($cursor, context_course::instance($courseid))` (Core-API-Parameter existiert
  genau dafür, `base.php:317`). Kein verschwendetes Lesen. Globaler Area-Cursor bleibt gültig
  (jeder Kurs-Recordset wird mit demselben Cursor gefahren; Kursmengen-Änderungen laufen über
  Delta-Sync, nie über Cursor-Tricks).
- **blocklist:** globaler Recordset wie heute + billiger Skip pro Record über die courseid
  (aus dem Document), bevor gechunkt/embeddet wird.
- Beide münden unverändert in `replace_document()`. `includefiles` wird pro Dokument über
  `effective($area, $courseid)` aufgelöst (nicht mehr pro Area global).

### 4.1 Delta-Sync statt Rebuild (ENTSCHIEDEN: Adhoc sofort)

Regel-Änderungen (anlegen/ändern/löschen, enabled- oder includefiles-Flip) lösen **gezielte**
Synchronisation aus, niemals einen Site-Rebuild:

1. Chokepoint im Repository/Service: erlaubte Kursmenge (und Files-Menge) der Area **vor und nach**
   der Mutation berechnen → Diff = `backfillcourseids` (neu erlaubt ODER Files-Flag geändert) +
   `prunecourseids` (nicht mehr erlaubt).
2. **Adhoc-Task** `sitesearch_scope_sync_adhoc` mit customdata `{area, backfill[], prune[]}` wird
   sofort gequeued (Entscheidung Georg: sofort, nicht erst zum Stundenlauf — wer freischaltet, hat
   die Ampel gesehen). Backfill = context-gescopter Recordset ab 0 durch die normale Pipeline
   (`replace_document` ist idempotent/diff-basiert; Files-Flag-Änderungen korrigieren sich dabei von
   selbst, weil die Chunk-Menge des Docs neu berechnet wird). Prune = Löschen der Zeilen dieser
   Area in diesen Kursen (neue Store-Op, §4.2).
3. **Fingerprint-Rückbau:** Die heutige `|files:<areas>`-Komponente im Fingerprint entfällt —
   Datei-Flags sind jetzt scope-abhängig und laufen über Delta-Sync. Der Fingerprint trägt nur noch
   die Chunker-/Pipeline-Version (echte globale Rebuild-Gründe).

### 4.2 Neue Store-Op (additiv, Muster der inkrementellen Ops)

`delete_owner_in_course(string $area, string $emodel, int $edims, string $owner, int $courseid): void`
— löscht die Zeilen EINER Area in EINEM Kurs (Prune-Pfad; `delete_by_course` ist area-übergreifend
und dafür zu grob). CSV-Store wirft (db-only, fail-closed, wie die übrigen inkrementellen Ops).

### 4.3 Retrieval

Unverändert (Index enthält nur Erlaubtes; `check_access` bleibt Autorität). Zusätzlich als
Transitions-Härtung: der Query-Pfad prüft pro finalem Treffer billig `effective(area, courseid)`
— gerade deaktivierte, noch nicht geprunte Inhalte verschwinden sofort aus den Ergebnissen.

## 5. Estimator + Ampel pro Scope

- Estimator nimmt einen Scope: **Kurs** = context-gescopter Recordset-Count + Ø-Chunks-Sample
  (bestehende Mechanik + `$context`-Parameter, file-inklusive je Regel); **Kategorie** = Summe über
  ihre Kurse, bounded (erste N Kurse exakt, Abbruch an der Rot-Schwelle → „>N"); **Site** = heutiges
  Verhalten.
- Governance-Seite zeigt die Schätzung **vor dem Speichern** einer Regel (Kandidaten-Zeile mit
  Chunks + Ampel) und pro Area die **effektive Coverage** (Kurs-Anzahl + Summen-Chunks + Ampel).
- **Gesamt-Ampel** neu: Summe aller aktiven Area×Scope-Schätzungen = Budget-Signal der Site.
- MUC-Cache gekeyt Area+Scopetype+Scopeid+Files-Modus; Invalidierung bei Regel-Änderung.

## 6. Governance-UI (ENTSCHIEDEN: Regel-Liste mit Pickern, kein Baum)

Pro Area-Zeile ein Aufklapper „Geltungsbereich":

```
[Area]  Site-Default: ○ aus ● an          (Site-Zeile = Default-Regel)
  Regeln:
   ● an   Kursbereich „Geschichte"     ~4.200 Chunks 🟡   [Dateien: an]   [entfernen]
   ○ aus  Kurs „Personalinterna"           —                              [entfernen]
  [+ Kursbereich hinzufügen]  [+ Kurs hinzufügen]     ← zeigt Schätzung vor dem Speichern
  Effektiv: 38 Kurse · ~11.300 Chunks 🟡
```

- **Bevorzugt (Georg): `\core_form\dynamic_form`** für „Regel hinzufügen/bearbeiten" — Modal mit
  Autocomplete-Elementen (Kurs via `course`-Element, Kategorie via Select über
  `core_course_category::make_categories_list()`), AJAX-Submit; die **Vorab-Schätzung** (Chunks +
  Ampel) wird nach Scope-Auswahl angezeigt (z. B. via Formular-Re-Render oder als Teil der
  Submit-Antwort vor dem endgültigen Speichern). Kein hartes Muss — wenn der Aufwand explodiert,
  ist das sesskey-POST-Muster der bestehenden Toggles der zulässige Fallback.
  **Gotcha (Projekt-Erfahrung):** in `dynamic_form` IMMER `$this->optional_param()` der Form
  verwenden, nie das globale `optional_param()`. Nach AMD-Änderungen `npx grunt amd
  --root=public/mod/booking/bookingextension/agent` auf der VM.
- Kategorie-Auswahl: Capability-gefiltert; Kurs-Auswahl: Core-`course`-Autocomplete-Element.
- Gleiche Capability (`configuresitesearch`); Enable-/Files-/Entfernen-Toggles der Regel-Liste
  dürfen beim bestehenden sesskey-POST-Muster bleiben.

## 7. Phasen (in einem Zug beauftragt)

| Phase | Inhalt |
|---|---|
| K1 | `sitesearch_scope_resolver` + Repository-Erweiterung (`list_rules`/`delete_rule`/Diff-Chokepoint) + Tests |
| K2 | Indexer-Strategien + `sitesearch_scope_sync_adhoc` (Backfill/Prune) + Store-Op §4.2 + Fingerprint-Rückbau + Query-Transitions-Check + Tests |
| K3 | Estimator pro Scope + Gesamt-Ampel + Cache-Keys + Tests |
| K4 | UI-Regel-Editor (Aufklapper, Picker, Vorab-Schätzung, effektive Coverage) |

## 8. Getroffene Entscheidungen (Georg, 2026-07-02)

1. Spezifischste Regel-Zeile gewinnt **komplett** (enabled + includefiles als Paar).
2. Kategorie-Vererbung **pfad-basiert** auf Unterkategorien.
3. Backfill/Prune **sofort als Adhoc-Task** beim Speichern.
4. UI = **Regel-Liste mit Pickern**, kein Kategorie-Baum.
5. **Wildcard-Area-Regeln `area='*'`** (Nachtrag 2026-07-02i): „Kurs/Kategorie komplett indexieren"
   als eine Regel statt N Einzelregeln; Tiebreaker = Scope zuerst, dann konkrete Area vor Wildcard
   (§3.0). Kein Makro, das Einzelregeln erzeugt.

## 9. Nicht-Ziele / Grenzen v1

- Keine Scope-Steuerung unterhalb Kurs-Ebene (einzelne Aktivitäten) — `check_access` +
  Sichtbarkeit decken das nutzerseitig ab.
- Frontpage (SITEID) zählt als normaler Kurs-Scope.
- Areas mit `contextsupport='other'` (User/Message/Block) kennen keine courseid → für sie gilt nur
  die Site-Zeile; Kategorie-/Kurs-Regeln sind für sie wirkungslos (UI blendet die Picker dort aus).
