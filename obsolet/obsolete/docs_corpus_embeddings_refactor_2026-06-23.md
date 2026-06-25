# Doku-Corpus & Inkrementelle Embeddings — Refactoring-Konzept (2026-06-23)

**Plugin:** `bookingextension_agent` (alle Pfade unten relativ zu dessen Wurzel; `classes/local/wizard/...` ist der **interne** Namespace dieses Plugins, kein eigenes Plugin).
**Autoritative Quelle:** `flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd` (Knoten EMB_CATALOG / EMB_READY / EMB_REBUILD beschreiben den Schwester-Stack „Skill-Katalog-Embeddings").
**Status:** KONZEPT — noch nichts umgesetzt.

---

## 1. Anforderung (Georg, 2026-06-23)

> Standardmäßig sollen die Docs des Agents (`bookingextension_agent/docs`) **und** die mod_booking-Docs dem Agent zur Verfügung stehen. Darüber hinaus soll ein Admin **beliebige weitere Doku-Quellen** einbinden können — auch die eines **fremden Plugins** — indem er den Pfad/die Komponente in eine **Textarea** kopiert, **ohne** dass das fremde Plugin Code (`docs_provider`) mitbringen muss. Wichtig: es braucht einen **günstigen, inkrementellen** Weg, pro Verzeichnis Embeddings zu erzeugen.

### Bestätigte Design-Entscheidungen (Georg, 2026-06-23)
- **Eine Zeile pro Corpus.** Quelle = **lokaler Dateipfad** ODER **Moodle-Komponente** (`mod/quiz`, `mod/quiz/docs`). **Kein** Remote-URL/HTTP-Crawler in diesem Vorhaben.
- **`docs_provider`-Klassen werden abgeschafft.** Die Textarea ist der **einzige** Mechanismus. Die zwei Defaults (Agent, mod_booking) werden als **Komponenten-Zeilen** in den Default-Wert des Settings geseedet → out-of-the-box verfügbar, ohne absolute Pfade.
- **E1 — Zeilensyntax:** `corpus_id = quelle`, wobei die Quelle Komponente **plus** Unterordner sein darf (`mod/quiz/docs`). corpus_id ableitbar, wenn weggelassen.
- **E2 — Sicherheit:** **Keine** absoluten Pfade außerhalb der Moodle-Plattform. Jede Quelle muss innerhalb `$CFG->dirroot` (bzw. einer auflösbaren Komponente) liegen; `realpath` muss unter `dirroot` bleiben, sonst Zeile verworfen + Admin-Warnung.
- **E3 — Indexpflege wie beim Skill-Katalog:** content_hash-Test, kaputte Zeilen entfernen + neu bauen, fehlende/überflüssige Corpora feststellen. **Eventual consistency**: Doku ist immer vorhanden, wird aber ggf. erst nach Task-Lauf aktuell. Synchroner Check beim Skill-Verwenden bleibt **billig** (Debounce + Coverage-Signal); der teure Hash-Diff/Prune/Rebuild läuft im **adhoc-Task**. **„Überflüssig" wird am *deklarierten* Corpus-Set (Config) gemessen, NICHT an `is_dir`** — ein deklarierter, aber gerade unlesbarer Corpus wird nie gelöscht.
- **E4 — Kein Voll-Rebuild beim Upgrade.** Der reguläre hash-basierte Drift-Check baut bei Bedarf inkrementell nach.

### Geltungsbereich: BEIDE Embeddings-Stacks (Georg, 2026-06-23)
Dieses Refactoring betrifft **nicht nur die Docs**, sondern gleichermaßen den **Skill-Katalog**-Stack. Beide teilen dieselbe Form (CSV-Store, content_hash-Reuse, Readiness→Rebuild) und sollen dieselbe Basis nutzen:
- **Docs-Stack:** `docs_embeddings_csv_repository`, `docs_embeddings_index_service`, `docs_embeddings_readiness_service`, `docs_lookup_service`, `rebuild_docs_embeddings_adhoc`.
- **Skill-Katalog-Stack:** `embeddings_csv_repository`, `embeddings_catalog_builder_service`, `embeddings_readiness_service`, `embeddings_retrieval_service`, `family_embeddings_index_service`, `family_embeddings_retrieval_service`, `rebuild_skill_catalog_embeddings_adhoc`.
- **Gemeinsam (D2 + F):** eine `embeddings_csv_repository_base` (RFC-4180/atomic) **und** die Varianten-pro-Modell-Logik (F) gelten für beide. Der Modellwechsel-Defekt (§2-Punkt 5) besteht im Skill-Katalog identisch (`embeddings_readiness_service::get_catalog_status` wertet Modell/Dim-Mismatch als `stale` → Voll-Rebuild) und wird durch F dort genauso behoben.
- **Was nur Docs betrifft:** die Multi-Corpus-Textarea (A) und Chunking (C) sind doku-spezifisch; B3 (Coverage-Drift) übernimmt umgekehrt das **vorhandene** Skill-Katalog-Drift-Modell als Vorbild für die Docs.

---

## 2. Status quo (verifiziert)

### Was bereits funktioniert
- **Component-agnostische Discovery** via `\{component}\local\wizard\docs_provider::get_doc_corpora(): array<corpus_id,root>`. Heute zwei Provider: Agent (`classes/local/wizard/docs_provider.php` → corpus `bookingextension_agent`, Root `…/docs`) und mod_booking (`mod/booking/classes/local/wizard/docs_provider.php` → corpus `mod_booking`, Root `mod/booking/docs`).
- **Registry** `services/lookup/docs_corpus_registry.php` als „single source of truth" corpus_id → absoluter Root; alle Pfade (Index, Lookup, Preview, `ai_get_doc_content`) lösen ausschließlich hierüber auf, jeder File-Read ist auf den Root confined.
- **Per-Datei-Inkrementalität**: `docs_embeddings_index_service::rebuild` (`:120–133`) berechnet `content_hash = sha1(content|model|dims)`; unveränderte Datei mit vorhandenem Embedding wird **reused** statt neu embedded → günstige Retries.

### Die echten architektonischen Lücken (kein bloßer Bug)
1. **Nur EIN Admin-Corpus.** `settings.php:216` ist `admin_setting_configtext` / `PARAM_TEXT` (eine Zeile) + `aidocs_corpusid` (eine id). Registry `resolve_all()` (`:130–144`) hängt genau **einen** Admin-Corpus an. Mehrere Quellen unmöglich.
2. **Kein Rebuild-Trigger bei Corpus-Änderung.** `docs_embeddings_readiness_service::is_index_ready()` (`:54`) prüft nur CSV-Existenz + Schema-Gültigkeit — **nicht** ob alle registrierten Corpora abgedeckt sind. Sobald ein Index existiert, gilt er als „ready" → `ensure_rebuild_scheduled_if_needed` (`:98`) schedult nie. Das `aidocsroot`-Setting hat **kein** `set_updatedcallback`. **Folge: ein neu hinzugefügtes Verzeichnis wird nie automatisch indexiert.**
3. **Destruktiver Full-Rewrite, kein per-Corpus-Scoping.** `rebuild()` scannt immer ALLE Corpora und schreibt das gesamte CSV neu (`:169`). `$deleted = count($existingrows) - $reused` (`:164`) → die Zeilen eines Corpus, dessen Root **temporär fehlt** (Tippfehler, nicht gemountet, Provider lädt nicht), landen nicht in `$newrows` und werden **stillschweigend gelöscht**. Ein einziger fehlerhafter Pfad kann einen kompletten Corpus-Index vernichten.
4. **Ganze Datei = 1 Chunk, gekappt auf 6000 Zeichen.** `build_embedding_input` (`:267`) `mb_substr(...,0,6000)`, während `line_end` die volle Datei behauptet (`:154`). Große Docs (gerade READMEs großer Corpora) verlieren Inhalt jenseits 6000 Zeichen; kein Chunking → schlechtere Retrieval-Präzision.
5. **Modellwechsel = alles wegwerfen.** `content_hash` backt Modell+Dim ein; Readiness wertet Mismatch als `stale` → voller Re-Embed, CSV mono-modellig. Zurückwechseln auf ein früheres Modell = erneuter Voll-Re-Embed. Zudem filtert `embeddings_retrieval_service` **nicht** nach Modell — heute nur korrekt, weil mono-modellig (latente Fehlklasse, sobald mehrere Modelle koexistieren). → **Phase F.**

### Unterliegende Defekte (aus Audit/Plan, hier mitgezogen)
6. **`_similarity`-Bug** (Plan §0.1): `docs_lookup_service.php:151` liest `_similarity`, geschrieben wird nur `score` → semantische Doku-Suche permanent tot, stiller Fallback auf lexikalisch. Der `explain_docs`-Skill bewirbt Semantik als „primary, language-agnostic", läuft real aber lexikalisch-only.
7. **RFC-4180/atomic-Härtung fehlt** im Docs-CSV-Repo (Plan §1.6): `docs_embeddings_csv_repository` nutzt PHP-Default-`fgetcsv`/`fputcsv` (Backslash-Escape) ohne Round-Trip-Validierung → Zeilen mit `\/`,`\"`,`\uXXXX` im `embedding_json` können still verloren gehen. Der Skill-Katalog-Repo ist bereits gehärtet.

---

## 3. Ziel-Architektur

Ein Doku-Corpus = Paar `(corpus_id, absoluter Root)`. **Quelle = ausschließlich die Admin-Textarea**, gespeist aus einem geseedeten Default. Der Index ist **per-Corpus inkrementell** und **nicht-destruktiv**; ein Corpus-Wechsel triggert automatisch nur den betroffenen Rebuild.

```
settings.php (configtextarea aidocsroot)
   │  Zeilen, je: "<corpus_id> = <pfad-oder-komponente>"  (corpus_id optional)
   ▼
corpus_source_parser  ──►  docs_corpus_registry (reiner Config-Parser, KEINE Provider-Scans mehr)
   │   resolviert Komponente→dir (core_component) bzw. validiert absoluten Pfad (is_dir)
   ▼
docs_embeddings_index_service::rebuild(?corpus_id)
   │   per-Corpus-Scoping · content_hash-Reuse · nicht-destruktiv (fremde Corpora unberührt)
   │   Chunking (Heading/Größe) statt 6000-Zeichen-Truncate
   ▼
docs_embeddings_csv_repository  (erbt RFC-4180/atomic-Basis — Plan §1.6)
   ▼
docs_lookup_service.search_semantic  (score-Key gefixt — §0.1)  ──►  explain_docs_skill
```

### Zeilensyntax (Vorschlag, §6-Entscheidung)
```
# Kommentar/Leerzeile ignoriert
bookingextension_agent              # Komponente → …/bookingextension_agent/docs (corpus_id = Komponentenname)
mod_booking                         # → mod/booking/docs
quizdocs = mod/quiz/docs            # expliziter corpus_id + dirroot-relativer Pfad
intern  = /srv/handbuch/docs        # expliziter corpus_id + absoluter Pfad
```
- **Auflösung pro Zeile:** (a) enthält `=` → `corpus_id = quelle`, sonst Quelle und corpus_id abgeleitet. (b) Quelle als Komponente+Unterordner: führender Token via `core_component::get_component_directory()` auflösen, optionalen Unterordner anhängen (`mod/quiz/docs` → `…/mod/quiz/docs`); reine Komponente ohne Unterordner → `…/docs` ergänzen. Reine Pfade werden relativ zu `$CFG->dirroot` interpretiert. (c) corpus_id-Default = Komponentenname bzw. `basename(dir)`; auf `[a-z0-9_]` normalisieren, Kollisionen → erste Zeile gewinnt + Admin-Warnung.
- **E2-Confinement (hart):** Nach `realpath()` muss der Root **innerhalb `$CFG->dirroot`** liegen (`str_starts_with(realpath, realpath($CFG->dirroot))`). Pfade außerhalb → Zeile verworfen + Admin-Warnung. Damit ist „beliebiger absoluter Pfad auf dem Server" ausgeschlossen; erlaubt ist alles unterhalb der Moodle-Installation (inkl. fremder Plugins).
- **Validierung sichtbar:** Zeilen mit unauflösbarem/fehlendem/außerhalb liegendem Verzeichnis werden übersprungen **und** dem Admin gemeldet (nicht still verworfen) — via `admin_setting`-`validate()` beim Speichern.
- **Deklariert ≠ auflösbar:** Der Parser unterscheidet die **deklarierten** corpus_ids (alle syntaktisch gültigen Zeilen) von den **auflösbaren** (Root existiert gerade). Prune-Entscheidungen im Index nutzen die *deklarierte* Menge (siehe B1) — ein deklarierter, aber momentan unlesbarer Corpus wird nie gelöscht.

---

## 4. Refactoring-Schritte (mit Checkboxen)

### Phase A — Multi-Corpus-Config & Provider-Abbau `[L]`
- **A1 Settings auf Textarea umstellen.** `settings.php:215` `admin_setting_configtext` → `admin_setting_configtextarea` (`PARAM_RAW`), neuer **Default-Wert** mit den zwei Komponenten-Zeilen (`bookingextension_agent\nmod_booking`). `aidocs_corpusid` entfällt (corpus_id jetzt pro Zeile). Lang-Strings `aidocsroot`/`aidocsroot_desc` neu fassen (Syntax dokumentieren).
  - [ ] `configtextarea` + geseedeter Default
  - [ ] `aidocs_corpusid`-Setting + Verwendungen entfernen
  - [ ] Lang-Strings aktualisieren (Syntax, Beispiele)
  - [ ] Beschreibung trägt den dynamischen Skill-Aktiv-Indikator aus **E4** (✓/✗ + Link)
- **A2 `corpus_source_parser` (neu).** `services/lookup/corpus_source_parser.php`: `parse(string $textarea): array` mit zwei Schlüsseln — `declared` (alle syntaktisch gültigen `corpus_id`, unabhängig von `is_dir`) und `resolvable` (`corpus_id => abs_root`, nur wo Root existiert UND unter `$CFG->dirroot`). Zeilen splitten, `=`-Syntax, Komponente+Unterordner-Auflösung, `realpath`-Confinement (E2), Normalisierung, Kollisions-Handling. Reine, testbare Funktion.
  - [ ] Parser + Unit-Tests (Komponente, Komponente+Unterordner, dirroot-relativ, expliziter id, Kollision, fehlend, **außerhalb dirroot → verworfen**, **deklariert-aber-unlesbar → in `declared`, nicht in `resolvable`**)
- **A3 Registry zum reinen Config-Parser machen.** `docs_corpus_registry::resolve_all()` ruft nur noch `corpus_source_parser::parse(get_config(...,'aidocsroot'))` und gibt die `resolvable`-Map zurück (`list/resolve_root/is_known/primary` unverändert). Zusätzlich `declared_corpus_ids(): array` für die Prune-Entscheidung (B1). **Provider-Scan (`discover()`, `PROVIDER_*`, `FALLBACK_ADMIN_CORPUS_ID`) entfernen.** `set_corpora_for_testing` bleibt.
  - [ ] `discover()` + Provider-Konstanten raus
  - [ ] `resolve_all()` → Parser-`resolvable`; neue `declared_corpus_ids()`
  - [ ] bestehende Registry-Tests anpassen
- **A4 `docs_provider`-Klassen löschen.** `classes/local/wizard/docs_provider.php` **und** `mod/booking/classes/local/wizard/docs_provider.php` entfernen (Defaults laufen jetzt über den geseedeten Textarea-Default). grep: keine `get_doc_corpora`-Referenz mehr.
  - [ ] beide `docs_provider.php` löschen
  - [ ] grep-Verifikation (0 Referenzen)
- **A5 Settings-Change-Trigger (proaktiver Fast-Path, optional aber empfohlen).** `aidocsroot`-Setting bekommt `set_updatedcallback`, der einen `rebuild_docs_embeddings_adhoc` schedult (Debounce teilen mit B3). Das ist nur der *schnelle* Pfad, damit ein frisch hinzugefügter Corpus nicht erst auf den nächsten Skill-Aufruf warten muss; der Skill-Use-Check (B3) bleibt das Sicherheitsnetz. Der eigentliche Diff/Prune passiert im Task (B1/B2), nicht im Callback.
  - [ ] `set_updatedcallback` → Task schedulen (Debounce)
  - [ ] Test: Setting ändern → Task gequeued (genau einmal trotz Mehrfach-Save via Debounce)

### Phase B — Indexpflege nach Skill-Katalog-Vorbild (inkrementell, nicht-destruktiv, eventual-consistent) `[L]`

> Leitbild: **dieselbe Logik wie der Skill-Katalog** (`embeddings_readiness_service::get_catalog_status` + `rebuild_skill_catalog_embeddings_adhoc`): hash-Test, kaputte/driftige Zeilen entfernen + neu bauen, fehlende/überflüssige feststellen. **Teurer Teil im Task, billiger Trigger beim Skill-Use.**

- **B1 Nicht-destruktiver Prune am *deklarierten* Set.** In `rebuild()` (Task) gilt: eine CSV-Zeile wird **nur** verworfen, wenn ihre `corpus_id` **nicht mehr deklariert** ist (nicht in `docs_corpus_registry::declared_corpus_ids()`) **oder** ihre Datei in einem *auflösbaren* Corpus verschwand bzw. ihr `content_hash`/Model/Dims driftet (→ neu bauen). Ein **deklarierter, aber gerade unlesbarer** Corpus (`is_dir` false) wird **übersprungen, nicht gelöscht**. Das ersetzt das fehlerhafte `$deleted = count($existingrows) - $reused` (`docs_embeddings_index_service.php:164`).
  - [ ] Prune-Regel: nur `corpus_id ∉ declared` ODER verschwundene Datei eines auflösbaren Corpus
  - [ ] deklariert-aber-unlesbar → Zeilen 1:1 erhalten
  - [ ] Test: unlesbarer (deklarierter) Root → Zeilen bleiben; entfernte Zeile in Config → Zeilen weg
- **B2 Per-Corpus-Scoping (für den Fast-Path).** `rebuild(?string $corpusid = null, …)` — bei gesetztem `corpusid` nur diesen scannen + nur dessen Zeilen ersetzen, Rest mergen (B1-Regel gilt). `rebuild_docs_embeddings_adhoc` liest optionales `corpus_id` aus Customdata. Default (`null`) = voller hash-Diff über alle deklarierten Corpora (nicht-destruktiv).
  - [ ] `rebuild()` um `?corpus_id` erweitern
  - [ ] Adhoc-Task: optionales `corpus_id`-Customdata
  - [ ] Test: Single-Corpus-Rebuild lässt andere Zeilen unverändert
- **B3 Billiger Skill-Use-Trigger + Drift-Erkennung.** Trennung wie beim Skill-Katalog:
  - **Synchron (im `explain_docs`-Pfad, billig):** `docs_embeddings_readiness_service` bekommt einen *leichten* Coverage-Check — „existiert für jede **deklarierte** corpus_id ≥1 Zeile?" + Debounce über `docs_embeddings_rebuild_queued_at`. Kein per-Datei-Hashing synchron. Bei fehlender Abdeckung oder abgelaufenem Debounce → `ensure_rebuild_scheduled_if_needed()` schedult den Task. Ersetzt den heutigen reinen „is_index_ready (Schema)"-Check (`:54`), der neue Corpora nie erkennt.
  - **Im Task (teuer):** voller `content_hash`/Model/Dims-Diff pro Datei → reuse/embed/prune (B1). Kaputte Zeilen (Schema/Parse) werden hier entfernt + neu gebaut.
  - **Eventual consistency** ist damit explizit: Doku ist sofort durchsuchbar (vorhandener Index), neue/aktualisierte Inhalte sind nach dem nächsten Task-Lauf aktuell.
  - [ ] billiger Coverage-Check (deklarierte ids vs vorhandene corpus_ids im CSV) + Debounce
  - [ ] `explain_docs_skill:331–333` auf den neuen Check umstellen (statt nur `is_index_ready`)
  - [ ] Task macht den vollen Hash-Diff/Prune
  - [ ] Test: neuer Corpus → synchron als „nicht abgedeckt" erkannt → Task gequeued → nach Task-Lauf abgedeckt

### Phase C — Chunking & Qualität `[L]` (Retrieval-Qualität)
- **C1 Heading/Größen-Chunking** statt 6000-Zeichen-Truncate. Große `.md` in Chunks splitten (an `##`/`###` bzw. ~Token-Budget), jeder Chunk eine CSV-Zeile mit echten `line_start`/`line_end`. `content_hash` weiterhin pro Chunk → Inkrementalität bleibt. `read_doc_by_path`/Preview müssen mit Chunk-Ranges zusammenspielen (bereits range-fähig via `linestart/linecount`).
  - [ ] Chunker (Heading/Größe) + `line_start/end` korrekt
  - [ ] `build_embedding_input` pro Chunk
  - [ ] Lookup/Preview gegen Chunk-Ranges verifizieren
  - [ ] Test: großes Doc → mehrere Chunks, voller Inhalt abgedeckt

### Phase D — Unterliegende Fixes (aus Haupt-Plan, hier gebündelt) `[M]`
- **D1 `_similarity`→`score`** in `docs_lookup_service.php:151` (== Plan §0.1). Reanimiert die semantische Suche, die dieser ganze Stack erst nutzbar macht.
  - [ ] Key-Fix + Skalierung
  - [ ] Test: semantischer Treffer > Schwelle wird geliefert
- **D2 RFC-4180/atomic-Basisklasse** (== Plan §1.6). `docs_embeddings_csv_repository` erbt die gehärtete Basis (escape='' read+write, atomarer Write mit Round-Trip-Validierung).
  - [ ] gemeinsame `embeddings_csv_repository_base` (vorbereitet für `variant_key` aus F1)
  - [ ] Docs-Repo erbt → Härtung „by construction"
  - [ ] Round-Trip-Regressionstest (Backslash-JSON)

---

### Phase E — Skill-Aktiv-Gate: kein Embedding ohne aktiven `explain_docs`-Skill `[M]`

> Anforderung (Georg): Ist der `wizard.explain_docs`-Skill **nicht aktiv**, werden Doku-Embeddings **nie** erzeugt — der Task wird gar nicht erst gescheduled, und falls er doch läuft (Altbestand/manuell), **opted er sofort out**. Zusätzlich ein grünes Häkchen / rotes Kreuz im `aidocsroot`-Settings-Hinweis mit Verweis auf den Skill.

- **E0 Eine Gate-Quelle.** Kleiner statischer Helper `docs_embeddings_gate::is_docs_skill_active(): bool` (in `services/lookup/`), der `skill_registry::is_skill_active(explain_docs_skill::SKILL_NAME)` kapselt. Für den billigen, registry-freien Pfad (settings.php) liest er direkt `aiskillenableall` ODER `get_config('bookingextension_agent', skill_registry::get_skill_toggle_setting_name('wizard.explain_docs'))` (default-off) — identische Semantik wie `is_skill_active`, ohne die Registry zu bauen. **Genau ein** Ort definiert „aktiv?".
  - [ ] `docs_embeddings_gate::is_docs_skill_active()` + Unit-Test (enableall, per-skill on/off, default-off)
- **E1 Scheduling-Guard (nie schedulen).** `docs_embeddings_readiness_service::ensure_rebuild_scheduled_if_needed()` ganz oben: `if (!docs_embeddings_gate::is_docs_skill_active()) return false;`. Damit greift das Gate für **alle** Trigger, die hierüber schedulen — Skill-Use (B3) **und** Settings-Save (A5). Optional: `get_status()` meldet `status='skill_inactive'`.
  - [ ] Guard in `ensure_rebuild_scheduled_if_needed` (vor Debounce/Coverage)
  - [ ] Test: Skill inaktiv → kein Task gequeued (auch bei fehlender Coverage)
- **E2 Task-Opt-out (falls doch gescheduled).** `rebuild_docs_embeddings_adhoc::execute()` ganz oben: Gate prüfen → bei inaktiv früh raus (`debugging('docs skill inactive, skipping rebuild')`, kein Embedding-Call). Schützt vor Altbestand-Tasks, manueller Queue, CLI.
  - [ ] Guard in `execute()` (vor jeder Arbeit)
  - [ ] Test: inaktiver Skill + gequeuter Task → No-op, keine LLM-/Embedding-Calls
- **E3 Defense-in-depth im Index-Service.** `docs_embeddings_index_service::rebuild()` gibt bei inaktivem Skill `['status'=>'skipped','reason'=>'skill_inactive', …]` zurück (analog zum vorhandenen `embeddings_provider_unavailable`-Pfad `:64`). So ist auch jeder Direktaufruf (Tests, künftige Caller) abgesichert.
  - [ ] früher `skill_inactive`-Return in `rebuild()`
- **E4 Settings-Indikator.** Im `aidocsroot`-Settings-Hinweis (A1) dynamisch anhängen: bei aktivem Skill „✓ <Skill aktiv>", sonst „✗ <Skill inaktiv — Doku-Suche & Indexierung sind deaktiviert>" mit **Link auf die Skill-Governance** (`skill_governance.php` bzw. den Toggle `aiskillenabled_wizard.explain_docs`). Quelle = `docs_embeddings_gate` (kein Registry-Bau im Settings-Render).
  - [ ] Hinweis-String dynamisch (✓/✗ + Link zum Skill-Toggle)
  - [ ] Lang-Strings `aidocsroot_skill_active` / `aidocsroot_skill_inactive`

### Phase F — Modellwechsel: Embeddings je Variante (Modell + Dimensionen) `[L]` (beide Stacks)

> Anforderung (Georg): Wird im `aiprovider_wunderbyte` ein anderes Embeddings-Modell aktiv, sind alle bestehenden Vektoren inkompatibel. Statt bei jedem Wechsel alles wegzuwerfen und neu zu bauen, sollen Embeddings **je Modell** vorgehalten und je nach aktivem Modell die passenden verwendet werden → Switch-back ohne Re-Embed.

**Ist-Zustand (verifiziert):** `content_hash` backt Modell+Dim ein (`embeddings_catalog_builder_service.php:106`, `docs_embeddings_index_service.php:120`); Readiness wertet Modell-/Dim-Mismatch als `stale` (`embeddings_readiness_service.php:94,98`) → voller Re-Embed, CSV ist mono-modellig. `embeddings_retrieval_service` filtert **nicht** nach Modell — heute nur korrekt, weil mono-modellig.

**Design: eine CSV pro Variante** (Variante = `model + '__' + dimensions`), statt Mischung in einer Datei.
- **F1 Varianten-bewusster Pfad in der CSV-Basis.** Die gemeinsame Basis aus §D2 (`embeddings_csv_repository_base`) bekommt einen `variant_key`-Parameter → Dateiname-Suffix `…__<model>__<dims>.csv` (Modellname filename-safe normalisieren). Gilt für **beide** Repos (Skill-Katalog + Docs).
  - [ ] `variant_key` in der Basis → Pfad-Suffix; Modellname-Sanitizing
  - [ ] beide Repos reichen die aktive Variante durch
- **F2 Readiness/Retrieval/Rebuild varianten-skopiert — in BEIDEN Stacks.** Aktive Variante via `embeddings_action_config_resolver::resolve()` (liefert model+dims schon). Readiness prüft „existiert das File der **aktiven** Variante + deckt alle Corpora/Skills ab?"; Retrieval öffnet nur dieses File; Rebuild baut nur dieses File. Andere Varianten-Files bleiben unberührt.
  - [ ] **Docs:** `docs_embeddings_readiness_service` / `docs_lookup_service`(→Retrieval) / `docs_embeddings_index_service` varianten-skopiert
  - [ ] **Skill-Katalog:** `embeddings_readiness_service::get_catalog_status` / `embeddings_retrieval_service` + `family_embeddings_retrieval_service` / `family_embeddings_index_service` varianten-skopiert
  - [ ] Retrieval öffnet in beiden Stacks nur die aktive Variante (kein Cross-Modell-Cosine mehr möglich)
  - [ ] Test (beide Stacks): Modellwechsel A→B baut B; A-File bleibt; Wechsel zurück A→A = ready, kein Rebuild
- **F3 content_hash entschlacken (optional).** Da das File variantenskopiert ist, kann Modell/Dim aus dem `content_hash` raus (nur noch Content) — Spalten `embedding_model`/`embedding_dimensions` bleiben als Schema-Selbstbeschreibung. Harmlos, falls man es drin lässt; Entscheidung E8.
  - [ ] (optional) Hash nur über Content
- **F4 Cleanup-Policy gegen Wildwuchs (beide Stacks).** Neuer/erweiterter `cleanup_*`-Adhoc-Task entfernt Varianten-Files **beider** Stacks (Docs + Skill-Katalog), deren Modell länger nicht aktiv war (Policy: letzte N behalten / nach mtime). Verhindert, dass jede Modell-Probe ein File-Erbe hinterlässt.
  - [ ] Cleanup-Task deckt Docs- **und** Skill-Katalog-Varianten ab (Retention-Policy, siehe E7)
  - [ ] Test: über Retention hinaus → ältestes Varianten-File je Stack entfernt

## 5. Reihenfolge & Abhängigkeiten

```
D1 (score-Fix, sofort) ──► macht Semantik überhaupt testbar
D2 (CSV-Basis)         ──► Schreibsicherheit, Voraussetzung für ruhiges Umschreiben
E0 (Gate-Helper)       ──► Voraussetzung für E1/E2/E3/E4 und für A5/B3-Scheduling
A1–A5 (Textarea/Provider-Abbau) ──► Multi-Corpus-Quelle + Trigger (A1 trägt E4-Indikator)
B1–B3 (inkrementell, nicht-destruktiv, Drift) ──► günstige per-Verzeichnis-Embeddings
E1–E4 (Skill-Aktiv-Gate) ──► an Scheduling, Task, Index, Settings eingehängt
F1–F4 (Variante je Modell) ──► baut auf D2-Basis; Switch-back ohne Re-Embed
C1 (Chunking) ──► Qualität, zuletzt
```
**Empfehlung:** D1 → D2 → **E0** → A → (E1/E2/E3 mit B, E4 mit A1) → B → **F** → C. D1/D2 sind klein und entschärfen Risiko vor dem strukturellen Umbau. E0 zuerst, weil das Gate in A5/B3 schon greifen muss. F kommt nach D2/B, weil es die CSV-Basis varianten-fähig erweitert und auf der nicht-destruktiven Prune-Logik aufsetzt. A liefert sofort sichtbaren Mehrwert (mehrere Doku-Quellen), B den „günstig inkrementell"-Kern, F den günstigen Modellwechsel, C die Qualität.

---

## 6. Entscheidungen

**Geklärt (Georg, 2026-06-23):**
- **E1 — Zeilensyntax:** `corpus_id = quelle`, Quelle = Komponente **plus** Unterordner (`mod/quiz/docs`). ✅
- **E2 — Pfad-Sicherheit:** Nur innerhalb der Moodle-Plattform; absolute Pfade außerhalb `$CFG->dirroot` **nicht** erlaubt (realpath-Confinement). ✅
- **E3 — Indexpflege:** Skill-Katalog-Logik (hash-Test, kaputte Zeilen neu, fehlend/überflüssig erkannt); billiger Skill-Use-Trigger, teurer Diff im Task; eventual consistency akzeptiert; Prune am deklarierten Set. ✅
- **E4 — Kein Voll-Rebuild beim Upgrade**; inkrementeller Drift-Check übernimmt. ✅

**Noch offen:**
- **E4b Chunking jetzt oder später.** Phase C ist der größte Einzelposten, unabhängig von A/B nachrüstbar. Sofort oder Folge-Iteration?
- **E5 Remote-URL.** Bewusst ausgeklammert. Falls je gewünscht: eigenes Fetch/Convert/Crawl-Subsystem — separates Konzept.
Antwort: remote url nicht möglich.
- **E6 Migration `mod_booking`-corpus_id.** Durch den geseedeten Komponenten-Default (`mod_booking`) bleibt die id stabil; bestehende CSV-Zeilen sind schema-kompatibel und werden via hash-Reuse übernommen — **kein** Voll-Rebuild nötig (deckt sich mit E4). Nur Bestätigung: passt der geseedete Default-Wert genau auf die bisher genutzte corpus_id?
Antwort: bestätigt.
- **E7 Retention der Varianten-Files (F4).** Wie viele Modell-Varianten vorhalten? Vorschlag: letzte 2–3 aktiv genutzten Modelle behalten, ältere per Cleanup-Task entfernen. (Mehr = mehr Plattenplatz, sofortiger Switch-back; weniger = sparsamer.)
Antwort: alle behalten. Es wird nicht so viele Modellwechsel geben, und wenn, dann hat das Gründe.
- **E8 content_hash entschlacken (F3).** Modell/Dim aus dem Hash nehmen, da File schon varianten-skopiert — oder zur Sicherheit drin lassen? (Rein kosmetisch/defensiv, kein Verhalten.)
Antwort: ich überlasse dir die entschiedung.
- **E9 Migration bestehendes (mono-modelliges) File.** Beim Einführen von F: legacy `…/docs_embeddings.csv` (ohne Varianten-Suffix) einmalig in das File der aktuell aktiven Variante umbenennen/übernehmen, statt neu zu bauen. Bestätigen.
Antwort: Es braucht keinerlei migration, wir sind noch nicht produktiv und werden die alten dateien löschen.

---

## 7. Stimmigkeit gegen Flowchart & Gesamtkonzept

- **Schwester-Stack-Angleichung:** Der Skill-Katalog-Stack (EMB_CATALOG/EMB_READY/EMB_REBUILD) macht bereits genau das, was hier für Docs fehlt — content_hash-Reuse, Drift-Erkennung, geplanter Rebuild, gehärtetes CSV. Dieses Konzept zieht den Docs-Stack **auf dieselbe Form** (gemeinsame CSV-Basis §D2, Drift→Schedule §B3). Das ist Annäherung an den dokumentierten Soll-Zustand, kein neues Pattern → **stimmig**.
- **Agnostik:** Provider-Abbau + Textarea entfernt die Notwendigkeit, dass fremde Plugins Code mitbringen, und hält die Engine domänen-agnostisch (Corpora sind reine Config). Konsistent mit LG_AGN/LG_3P. **stimmig.**
- **Registry-Invariante bleibt:** corpus_id → Root als „single source of truth", Reads auf Root confined — unverändert. **stimmig.**
- **Kein Konflikt mit der `local_wizard`-Auskopplung:** Dieses Vorhaben betrifft nur den Doku-Corpus innerhalb `bookingextension_agent`; die geplante Plugin-Auskopplung ist ein separates Thema.
- **Varianten-Files je Modell (F) sind eine konsequente Fortführung von EMB_READY.** Der Flowchart-Knoten EMB_REBUILD sagt schon „reuses valid embeddings by content_hash (re-embeds only empty/changed → cheap retries)". Das Reuse-Prinzip nur innerhalb *einer* Variante anzuwenden ist der Status quo; es **über** Modellwechsel hinaus zu erhalten (eigenes File je Variante) erweitert dieselbe Idee, ohne sie zu verletzen. Gleichzeitig wird der latente Retrieval-Korrektheitspunkt (kein Modell-Filter heute) strukturell ausgeschlossen. **stimmig.**
- **Skill-Aktiv-Gate ist konzept-konform (LG_CAP).** Der Flowchart nennt „Skill release gates: runtime + active + context + capability". Embeddings nur für einen aktiven Skill zu erzeugen, zieht diese Gate-Logik konsequent in den Hintergrund-Aufwand: ein deaktivierter Skill verursacht keine Index-Kosten. Das Gate hat **eine** Quelle (`docs_embeddings_gate`) und wird an allen Eintrittspunkten (Scheduling, Task, Index, Settings-Anzeige) konsultiert → keine divergierende „aktiv?"-Logik. **stimmig.**

---

*Erstellt 2026-06-23. Kein Code geändert. Begleitend zu `refactoring_plan_2026-06-23.md` (§0.1 = D1, §1.6 = D2) und `full_audit_2026-06-23.md`.*
