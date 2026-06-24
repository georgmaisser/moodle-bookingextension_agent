# Analyse: Dokumentations-Lookup-Skill ("explain docs") — Architekturvergleich & Empfehlung

**Datum:** 2026-06-08
**Autor:** Claude (im Auftrag von Georg)
**Status:** Analyse / Entscheidungsvorlage — keine Implementierung enthalten

---

## 1. Auftrag & Zielbild

Georg möchte einen neuen Skill für den `bookingextension_agent`, der Nutzerfragen gegen die Plugin-Dokumentation (`mod_booking/docs/`) beantwortet. Harte Anforderungen:

1. **Es muss ein Skill dieses Agenten sein** (kein Standalone-Tool, sondern eingebettet in `skill_registry`, Planner-Auswahl, Kontextpakete, Governance).
2. **Best-Practice-konform zur bestehenden agentischen Architektur** — d. h. konsistent mit den Mustern, die der Agent bereits für Skill-Discovery, Embeddings, Kontextpakete etc. verwendet, statt eine Parallelwelt zu bauen.
3. **100 % sprachagnostisch**: Eine Frage auf Chinesisch oder Deutsch muss relevante Treffer in einer (überwiegend) englischsprachigen Dokumentation finden — nicht nur "irgendwie meistens", sondern robust.
4. Drei Lösungsideen von Georg sollen bewertet werden:
   - (a) Embeddings-Datei + semantische Suche über die gesamte Doku
   - (b) Iteratives Lesen ab einem zentralen Dokument, Links folgen, nach jedem Schritt "genug Information?" prüfen, sonst weitersuchen
   - (c) "Superdokument": alle Dokumente zu einem großen Kontext zusammenfassen und dem Agenten geben
5. Der vorhandene, aktuell ungenutzte `explain_docs_topic_skill` soll angeschaut, aber **nicht** als Vorgabe behandelt werden.
6. Eine zukünftige **Websuche** als zusätzliche Quelle soll mitgedacht werden (nicht jetzt bauen, aber die Architektur darf sie nicht ausschließen).

Diese Analyse arbeitet die drei Optionen durch, bewertet das vorhandene Altdesign, prüft die im Repo bereits vorhandene Embeddings-Infrastruktur auf Wiederverwendbarkeit und endet mit einer konkreten Architekturempfehlung inkl. Skizze für Skill-Schema und Ausführungsfluss.

---

## 2. Ausgangslage: das Korpus

`public/mod/booking/docs/` wurde überprüft:

- **85 Markdown-Dateien**, **~540 KB** Gesamtgröße (reiner Text, ohne Bilder).
- Klar strukturiert in **Themenverzeichnisse mit eigenen `README.md`** (`booking_rules/`, `booking_conditions/`, `campaigns/`, `subbookings/`, `shortcodes/`, `capabilities/`, `placeholders/`, `scheduled_tasks/`, `booking-option/`, `booking_extensions/`, `actions_after_booking/`, `override_user_field/`, `developer-guides/`, `examples/`, `00_booking_messages/`, …).
- Ein **zentrales `README.md`** im Root fungiert bereits als kuratierte "Themen-Landkarte": Tabelle "I want to… → Go to…", explizite Abgrenzungshinweise für KI-Zwecke (z. B. "Questions about messages … belong to Booking rules", "Actions after booking are NOT the messaging system"), ein Schritt-für-Schritt-Workflow für die häufigste Aufgabe.
- Vereinzelt **fremdsprachige Dateien** (`certificates_de.md`) und Bilder (`pix/`-Unterordner je Thema).
- Größenordnung: Bei ~6 KB Schnitt pro Datei und üblichen Chunk-Größen von 300–800 Tokens ergäben sich bei semantischer Indexierung schätzungsweise **150–400 Chunks** — eine für Embeddings triviale, für „alles auf einmal in den Kontext“ aber bereits unangenehme Menge (540 KB Rohtext ≈ 140–180 k Tokens, je nach Encoding deutlich über dem sinnvollen Eingabebudget für eine einzelne Anfrage).

**Fazit zur Korpusgröße:** Das Korpus ist klein genug, dass *jede* der drei Strategien technisch machbar ist — aber groß genug, dass naive "alles in den Prompt"-Ansätze schon spürbar an Kosten- und Kontextgrenzen stoßen. Das Root-README ist bereits so geschrieben, dass es als Einstiegspunkt/Themen-Router funktionieren *könnte* — das ist ein Aktivposten, der in jeder Variante genutzt werden sollte.

---

## 3. Review des Altdesigns: `explain_docs_topic_skill` + `docs_lookup_service`

Wie von Georg gewünscht, wurde die vorhandene (aktuell inaktive) Implementierung vollständig gelesen — sowohl der Skill (`mod_booking/classes/local/wizard/options/skills/explain_docs_topic_skill.php`, 814 Zeilen) als auch sein Backend `docs_lookup_service` (1094 Zeilen, **historisch** über `git show be942dc:…` rekonstruiert, da die Datei in der aktuellen HEAD **nicht mehr existiert** — siehe unten).

### 3.1 Wichtiger Befund: Der Skill ist nicht nur "ungenutzt", sondern **strukturell kaputt**

`grep`/`find` über den gesamten Working Tree sowie `git log --all -S "class docs_lookup_service"` zeigen: Die Klasse `docs_lookup_service` wurde nur in den frühen Commits `be942dc` ("init") definiert und in `61f8cbb` ("vor nächstem schritt") wieder **vollständig gelöscht** (−1095 Zeilen), ohne dass der Skill, der sie zwingend voraussetzt, angepasst oder entfernt wurde. In der aktuellen HEAD existiert unter dem Pfad `services/lookup/` nur noch `option_lookup_service.php` — ein Service für ein anderes Thema.

**Konsequenz:** `explain_docs_topic_skill::create_docs_lookup_service()` instanziiert eine nicht existierende Klasse → Fatal Error "Class not found" bei jedem Aufruf. Der Skill ist also nicht "fertig, aber ungenutzt", sondern eine **Bauruine**: gutes Architektur-Skelett, dessen Fundament entfernt wurde. Das erklärt Georgs Beobachtung "currently not in use" vollständig.

### 3.2 Was am Altdesign gut ist (übernehmenswert)

Trotz der kaputten Abhängigkeit zeigt der Skill-Teil (`explain_docs_topic_skill.php`) eine Reihe robuster, wiederverwendbarer Muster, die unabhängig vom konkreten Retrieval-Mechanismus sinnvoll sind:

| Muster | Nutzen |
|---|---|
| **Themen-TOC** (`get_master_toc_index()` → Liste von `topic_id`/`title`) | Reduziert Suchraum: erst Thema bestimmen, dann *innerhalb* des Themas suchen — verringert Fehltreffer durch Begriffsüberschneidungen zwischen Themen. |
| **Themen-Erkennung mit Konfidenzwert** (`detect_best_topic()` → `topic_id`/`score`/`confidence`, Schwellenwerte `TOPIC_CONFIDENCE_THRESHOLD`/`TOPIC_MIN_SCORE`) | Erlaubt gestuftes Vorgehen: hohe Konfidenz → direkt ins Thema; niedrige → generische Multi-Query-Suche als Fallback. |
| **Zeilenfenster-Lesen** (`line_start`/`line_count`, `next_line_start`/`has_more`/`total_lines`, `DEFAULT_LINE_COUNT = 80`) | Verhindert das Volldumpen langer Dateien in den Kontext; der Agent kann iterativ "weiterlesen", wenn die erste Portion nicht reicht — ein direkter, eleganter Mechanismus für genau die "ist das genug Information?"-Schleife, die Georgs Idee (b) beschreibt. |
| **Planner-gesteuerte Direktpfade** (`doc_path`/`doc_path_candidates`, `PLANNER_DIRECT_DOC_CONFIDENCE = 0.72`) | Wenn der Planner aus dem Gesprächskontext schon weiß, welches Dokument relevant ist, kann er das deterministisch vorgeben und die Suche überspringen — spart Latenz/Kosten bei Folgefragen ("und was steht zur Wartelistenfunktion auf derselben Seite?"). |
| **Score-Heuristiken & Disambiguierung** (`DISAMBIGUATION_RATIO`, `apply_configure_intent_boost`, `prioritize_docs`) | Gibt dem System eine nachvollziehbare, debugbare Rangordnung statt einer Black-Box-Antwort — wichtig für Governance/Nachvollziehbarkeit (R0-Risikoklasse). |
| **Strukturierte Observation** (`build_full_observation_from_docs`, `build_structured_doc_payload`, `observation_full`) | Liefert dem Orchestrator/Planner maschinenlesbare Metadaten (Pfad, Zeilenfenster, URL) statt nur Fließtext — ermöglicht Folgeaktionen wie "zeig mir den Link zur Doku". |

### 3.3 Was am Altdesign das eigentliche Problem ist (genau der Punkt, an dem Georgs Sprachfrage ansetzt)

Der historische `docs_lookup_service` (1094 Zeilen) implementiert **reine Stichwortsuche**:

```php
private function extract_query_tokens(string $question): array {
    $normalized = mb_strtolower($question);
    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
    $parts = preg_split('/\s+/', trim($normalized)) ?: [];
    // Tokens < 3 Zeichen werden verworfen, dann case-insensitiver Substring-/Score-Vergleich
    ...
}
```

`score_doc()` vergleicht diese Tokens dann case-insensitiv gegen `path`/`title`/`excerpt`/`content`/`basename` der Dokumente. Das ist ein klassischer **lexikalischer (Bag-of-Words-)Ansatz**: funktioniert nur, wenn die Suchbegriffe (oder zumindest Wortstämme/Substrings davon) **wörtlich** im Zieltext vorkommen.

**Das ist exakt das Gegenteil von sprachagnostisch:**
- Eine chinesische Anfrage "如何创建预订选项" enthält keine lateinischen Buchstaben — `\p{L}\p{N}`-Tokenisierung liefert zwar CJK-Zeichen als "Wörter", aber kein einziges davon kommt in der englischen/deutschen Doku vor → Score 0 für jedes Dokument → kein Treffer.
- Selbst eine deutsche Anfrage ("Wie lege ich eine Buchungsoption an?") trifft nur zufällig, wenn Wortstämme wie "buchung" zufällig auch in `path`/`title` vorkommen (was hier der Fall sein *kann*, weil viele Verzeichnisnamen deutsch/englisch gemischt sind — aber das ist Zufall, kein Entwurfsprinzip, und bricht bei jeder Anfrage, die nicht zufällig Substring-Überlappungen hat, z. B. "Wartezeit" vs. "waiting list").

Mit anderen Worten: Das Altdesign hätte **selbst wenn `docs_lookup_service` nicht gelöscht worden wäre**, die "100 % sprachagnostisch"-Anforderung nicht erfüllt. Das ist die wichtigste Erkenntnis aus dem Review — sie bedeutet, dass wir die *Such-Engine* komplett neu denken müssen, auch wenn wir die *Skill-Orchestrierung* drumherum großenteils übernehmen können.

---

## 4. Bestandsaufnahme: vorhandene Embeddings-Infrastruktur (Wiederverwendungspotenzial für Option a)

Der Agent betreibt bereits eine produktive Embeddings-Pipeline für die **Skill-Katalog-Auswahl** (Planner-Routing). Das ist hochrelevant, weil sie exakt die Bausteine liefert, die ein semantischer Doku-Such-Ansatz bräuchte — und weil "konsistent zur bestehenden Architektur" (Anforderung 2) hier sehr konkret bedeutet: *dieselbe Pipeline für einen zweiten Anwendungsfall nutzen statt eine zweite zu bauen*.

Vorhandene Bausteine (alle in `bookingextension_agent/classes/local/wizard/services/embeddings/` bzw. angrenzend):

| Baustein | Funktion | Übertragbarkeit auf Doku-Suche |
|---|---|---|
| `embeddings_action_config_resolver` | Liest Modell/Dimensionen aus der aktiven `aiprovider_wunderbyte`-Konfiguration, Fallback auf `EMBEDDINGS_DEFAULT_MODEL = 'text-embedding-3-small'` (1536 Dim.) | 1:1 wiederverwendbar — gleiche Konfigurationsquelle, gleiches Modell. |
| `llm_call_service::invoke_embeddings()` | Kapselt den Aufruf von `aiprovider_wunderbyte\aiactions\generate_embeddings`, inkl. Fehlerbehandlung, Erfolgskennzahlen | 1:1 wiederverwendbar für Query- *und* Dokument-Embeddings. |
| `embeddings_catalog_builder_service` | Baut kanonische Zeilen, berechnet Inhalts-Hashes (`compute_content_hash`) zur Erkennung von Änderungen, normalisiert Eingabetext (`to_embedding_input`) | Muster 1:1 übertragbar: aus "Skill-Definition → Embedding-Eingabetext" wird "Doku-Chunk → Embedding-Eingabetext". Der Hash-Mechanismus löst genau das Problem "wann muss neu indexiert werden", das Doku-Embeddings ebenso haben (Markdown-Dateien ändern sich). |
| `embeddings_csv_repository` | Persistiert Katalogzeilen + Vektoren als CSV, prüft Schema-Gültigkeit | Wiederverwendbar als Speichermechanismus — oder als Vorbild für eine `docs_embeddings_csv_repository`, falls getrennte Speicherorte sinnvoller sind. |
| `embeddings_retrieval_service::search_top_k()` / `cosine_similarity()` | Reine Vektor-Ähnlichkeitssuche über vorgehaltene Katalogzeilen | 1:1 wiederverwendbar — nimmt Query-Vektor + Zeilen, liefert Top-K. Modellagnostisch, also auch für Doku-Chunks nutzbar. |
| `embeddings_readiness_service` | Prüft, ob der Katalog aktuell ist (Modell/Dimensionen/Hash-Vergleich), stößt bei Bedarf einen `adhoc_task`-Rebuild an (mit Debounce) | Direkt übertragbares Muster für "Doku-Embeddings sind veraltet → Rebuild im Hintergrund anstoßen", inkl. der bereits gelösten Debounce-Problematik. |
| `rebuild_skill_catalog_embeddings_adhoc` (Adhoc-Task) | Asynchroner Rebuild, mit `mtrace`-Fortschrittsmeldungen | Vorlage für einen analogen `rebuild_docs_embeddings_adhoc`-Task. |
| Orchestrator-Query-Aufbau (`orchestrator.php` ~Z. 590–640) | Baut den Such-Query aus der letzten Nutzer-Nachricht **plus** offenen Planungs-Intents zusammen, cached das Ergebnis pro `sha1(query|model|dimensions|user|context)` | Zeigt das etablierte Caching-Muster — wichtig, weil Embedding-Aufrufe Latenz/Kosten verursachen und Doku-Anfragen oft wortgleich wiederkehren ("Wie lege ich eine Buchungsoption an?" wird x-mal gestellt). |

**Zentrale Erkenntnis:** Die für Option (a) nötige Infrastruktur existiert bereits **vollständig** für einen strukturell identischen Anwendungsfall (Katalogeinträge statt Doku-Chunks, aber gleiche Pipeline: Text → Embedding → Cosine-Top-K → Cache → Staleness-Check → Async-Rebuild). Eine Doku-Embeddings-Lösung wäre also kein Neubau, sondern im Kern eine **Parametrisierung/Spezialisierung** der vorhandenen Pipeline auf eine zweite Content-Quelle. Das ist ein starkes Argument für Option (a) im Sinne von Anforderung 2 ("best practice zu meiner bestehenden Architektur").

Zusätzlich nutzt das verwendete Modell `text-embedding-3-small` (OpenAI) **multilinguale Einbettungen**: Texte in unterschiedlichen Sprachen mit ähnlicher Bedeutung landen im Vektorraum nah beieinander — nicht perfekt (Genauigkeit sinkt bei sehr unterschiedlichen Sprachpaaren wie Chinesisch↔Englisch gegenüber Deutsch↔Englisch), aber **grundsätzlich sprachübergreifend funktionsfähig**, ohne dass Wortlaute übereinstimmen müssen. Das ist der entscheidende Unterschied zur lexikalischen Suche des Altdesigns.

---

## 5. Bewertung der drei Lösungsideen

### 5.1 Option (a): Embeddings-Datei + semantische Suche

**Funktionsweise:** Die Doku wird in Chunks zerlegt (z. B. pro H2/H3-Abschnitt oder Datei), jeder Chunk wird einmalig (und bei Änderung erneut) als Vektor gespeichert. Zur Laufzeit wird die Nutzerfrage selbst eingebettet, die Top-K ähnlichsten Chunks werden per Cosinus-Distanz gefunden und liefern Kandidaten-Dokumente/-Abschnitte.

**Bewertung:**

| Kriterium | Einschätzung |
|---|---|
| Sprachagnostik | **Stark.** Multilinguale Embedding-Modelle bilden Bedeutung statt Wortlaut ab — eine deutsche oder chinesische Frage landet im selben Vektorraum-Bereich wie der passende englische Doku-Abschnitt, *ohne* dass Übersetzung im klassischen Sinn stattfinden muss. Das ist der einzige der drei Ansätze, der das Sprachproblem **strukturell**, nicht nur durch Zusatzschritte löst. |
| Architektur-Konsistenz | **Sehr hoch** — siehe Abschnitt 4: nahezu alle Bausteine existieren bereits und müssten "nur" auf Doku-Chunks umgemünzt werden. |
| Kosten/Latenz | Indexierung: einmalig + inkrementell bei Änderungen (Hash-basiert, läuft asynchron). Laufzeit: ein Embedding-Call pro Anfrage (cachebar) + reine Vektor-Arithmetik (billig, lokal). Insgesamt das güntigste Laufzeitprofil der drei Optionen, weil die teure Arbeit (Embedding aller Chunks) vorab und asynchron passiert. |
| Wartung bei Doku-Änderungen | Durch Content-Hash-Vergleich (`compute_content_hash`) automatisch erkennbar; Rebuild läuft im Hintergrund. Kein manueller Schritt nötig. |
| Schwächen | (1) Erfordert einen Indexierungs-/Rebuild-Mechanismus (zusätzlicher Betriebsteil, aber als Muster bereits vorhanden und erprobt). (2) Liefert *Kandidaten*-Chunks, keine Antwort — die eigentliche Texterklärung muss weiterhin vom LLM aus dem gefundenen Originaltext generiert werden (das ist aber bei allen drei Optionen so). (3) Chunk-Granularität ist ein Tuning-Thema — zu grob → unscharfe Treffer, zu fein → Kontextverlust. |
| Risiko | Gering — das Muster ist im selben Repo bereits produktiv für eine strukturell identische Aufgabe im Einsatz. |

### 5.2 Option (b): Iteratives Lesen ab zentralem Dokument + Link-Folgen + Genug-Information-Prüfung

**Funktionsweise:** Der Agent startet am Root-`README.md` (das bereits als kuratierter Themen-Router aufgebaut ist), liest einen Ausschnitt, bewertet "reicht das?", folgt bei Bedarf internen Links zu Unterseiten, wiederholt das Vorgehen.

**Bewertung:**

| Kriterium | Einschätzung |
|---|---|
| Sprachagnostik | **Schwach, sofern nicht mit (a) kombiniert.** Das Verfahren *navigiert* zwar sprachunabhängig (Linkstruktur ist sprachneutral), aber die Entscheidung "welchem Link folge ich?" und "reicht der gelesene Text als Antwort?" beruht auf demselben LLM, das auch die finale Antwort generiert — und genau hier *kann* Sprachagnostik durch das LLM selbst hergestellt werden (moderne LLMs verstehen Fragen auf Chinesisch und können englischen Text dazu in Bezug setzen). Das funktioniert tendenziell **gut für Verständnis, aber schwach für Auffindung**: Wenn die gesuchte Information mehrere Klick-Ebenen tief in einem Unterordner liegt, dessen README in einer für die Anfrage "uncharakteristischen" Sprache/Begrifflichkeit verfasst ist, kann der Pfad verfehlt werden — ohne semantische Vorab-Filterung ist das Navigieren ein Ratespiel mit mehreren teuren LLM-Zwischenschritten. |
| Architektur-Konsistenz | **Hoch** — das Zeilenfenster-Lese-Muster (`line_start`/`line_count`/`has_more`) aus dem Altdesign ist exakt dafür gemacht und bereits im Skill-Stil des Agenten erprobt. Die "genug Information?"-Schleife entspricht dem allgemeinen Muster mehrstufiger Skill-Aufrufe im Orchestrator (Planner ruft Skill erneut mit Folgeparametern auf). |
| Kosten/Latenz | **Am teuersten und langsamsten**: Mehrere sequenzielle LLM-Hin-und-Her-Aufrufe (lesen → bewerten → ggf. weiterlesen/Link folgen → erneut bewerten …) bedeuten mehrfache Modellaufrufe pro Nutzeranfrage, die sich nicht parallelisieren lassen (jeder Schritt hängt vom vorigen ab). Bei "Pech" (falscher Link gewählt) potenziell viele Iterationen. |
| Determinismus/Debugbarkeit | Geringer — der Pfad durch die Doku ist von Modellentscheidungen abhängig und kann zwischen zwei identischen Anfragen variieren (insbesondere wenn Temperatur > 0). Schlechter nachvollziehbar/governance-tauglich als ein score-basiertes Ranking. |
| Schwächen | Skaliert schlecht mit Tiefe/Breite der Linkstruktur; bei 85 Dateien in bis zu 3 Verzeichnisebenen potenziell viele Sprünge nötig; jede Fehlentscheidung kostet einen vollen Modell-Roundtrip. |
| Stärken | Funktioniert auch *ohne* Vorab-Indexierung — kein Rebuild-Mechanismus nötig, "liest einfach was da ist". Gut geeignet als **Fallback/Ergänzung**, wenn die semantische Suche keinen ausreichend confident Treffer liefert (genau das Muster, das `explain_docs_topic_skill` mit seiner "Themen-Erkennung → Multi-Query-Fallback → Root-Doc-Fallback"-Kaskade bereits vorsieht). |

**Wichtige Differenzierung:** Option (b) ist **kein Ersatz** für eine Such-/Auffindungs-Strategie, sondern ein **Lese-/Vertiefungs-Mechanismus**. Sie beantwortet die Frage "wie konsumiere ich ein gefundenes Dokument inkrementell", nicht "wie finde ich das richtige Dokument". Genau deshalb passt das Zeilenfenster-Muster aus dem Altdesign so gut in *jede* der drei Optionen — es ist orthogonal zur Findungsstrategie.

### 5.3 Option (c): "Superdokument" — alles zusammenfassen und dem Agenten geben

**Funktionsweise:** Alle (oder die wichtigsten) Markdown-Dateien werden zu einem großen Kontext-Blob zusammengefügt und bei jeder Anfrage komplett an das LLM übergeben.

**Bewertung:**

| Kriterium | Einschätzung |
|---|---|
| Sprachagnostik | **Stark** — das LLM sieht den vollen Originaltext und kann jede Anfrage in jeder Sprache dagegen abgleichen; kein Auffindungsschritt, der scheitern könnte. In dieser Hinsicht sogar das "robusteste" Verfahren. |
| Kosten/Latenz | **Inakzeptabel bei diesem Korpus für den Dauerbetrieb.** ~140–180 k Tokens Rohtext pro Anfrage (Eingabe) – das sprengt bei vielen Modellen das Kontextfenster oder zumindest das sinnvolle Kosten-/Latenzbudget für eine *einzelne* Anfrage in einem interaktiven Chat-Flow um ein Vielfaches. Selbst bei Modellen mit großem Kontextfenster: Jede Anfrage kostet (Input-Tokens × Preis) — bei 85 Dateien und potenziell hoher Anfragefrequenz im Produktivbetrieb ist das wirtschaftlich nicht tragbar, zusätzlich steigt die Antwortlatenz spürbar (Verarbeitung großer Kontexte dauert länger). |
| Aktualität | Muss bei jeder Doku-Änderung neu zusammengesetzt werden — kein Vorteil gegenüber Embeddings (dort: Hash-Vergleich + inkrementeller Rebuild ist günstiger als ein komplettes Neuzusammensetzen + Neuversenden). |
| Skalierbarkeit | Verschlechtert sich linear mit Korpuswachstum — während (a) durch Indexierung nahezu konstant in der Anfragekosten bleibt. Bei jeder künftigen Erweiterung der Doku (neue Subplugins, neue Themen) wird das Problem schlimmer, nicht besser. |
| Architektur-Konsistenz | **Niedrig** — würde eine Sonderbehandlung im Skill darstellen, die quer zu allen sonst im Agenten etablierten Mustern (schlanke Kataloge, Embedding-Vorfilterung, kontextsparsames Slimming, z. B. `slim_prompt_catalog_for_planner`) liegt. Der gesamte Agent ist explizit darauf ausgelegt, Kontext **klein** zu halten (siehe `runtimecatalog`/Slimming/Top-K-Filterung im Orchestrator) — ein "alles reinkippen"-Skill würde dieses Architekturprinzip durchbrechen. |

**Fazit zu (c):** Technisch der "einfachste" Ansatz im Sinne von "kein Suchproblem mehr", aber bei diesem Korpus und dieser Architektur klar **nicht empfehlenswert** als laufender Betriebsmodus. Sinnvoll bleibt eine *abgeschwächte* Variante: ein **vorab kuratiertes "Mini-Superdokument" je Thema** (z. B. das jeweilige README + 1–2 Kerndateien eines Themenordners zusammengefasst) als Inhalt eines *gefundenen* Themen-Treffers — das ist aber dann kein eigenständiger Suchmechanismus mehr, sondern eine Lesehilfe *nach* erfolgreicher Auffindung (= verschmilzt mit Option b/Zeilenfenster-Lesen, nur in größeren, vorbereiteten Blöcken statt live zusammengestellter Linkpfade).

### 5.4 Direktvergleich

| | (a) Embeddings | (b) Link-Folgen + Sufficiency-Check | (c) Superdokument |
|---|---|---|---|
| Sprachagnostik | ✅ stark, strukturell gelöst | ⚠️ abhängig vom LLM-Navigationsschritt, anfällig bei tiefen Strukturen | ✅ stark, aber "brute force" |
| Laufzeitkosten | ✅ niedrig (1 Embedding-Call, gecacht) | ❌ hoch (mehrere sequenzielle LLM-Roundtrips) | ❌ sehr hoch (riesiger Input pro Anfrage) |
| Latenz | ✅ niedrig | ❌ hoch (sequenziell) | ❌ hoch (große Eingabe) |
| Architektur-Fit | ✅ nahezu 1:1 vorhandene Bausteine | ✅ Lese-Muster vorhanden, aber Findungslogik fehlt | ❌ widerspricht Slimming-Prinzip des Agenten |
| Skalierbarkeit (Korpuswachstum) | ✅ sublinear (Index wächst, Anfragekosten konstant) | ⚠️ Suchraum wächst, mehr Iterationen nötig | ❌ linear, irgendwann nicht mehr tragbar |
| Determinismus/Debugbarkeit | ✅ score-basiertes Ranking, reproduzierbar | ❌ modell-pfadabhängig | n/a (kein Suchschritt) |
| Vorab-Aufwand | ⚠️ Indexierungspipeline nötig (aber: Muster existiert bereits) | ✅ keiner | ✅ keiner (außer Zusammensetzen) |
| Eignung als alleinige Lösung | sehr gut | nur als Ergänzung | nicht empfehlenswert im Dauerbetrieb |

---

## 6. Empfehlung: Hybridarchitektur — Embeddings als primärer Finder, Themen-Router + Zeilenfenster-Lesen als Konsumptions-/Fallback-Schicht

Die Optionen schließen sich nicht gegenseitig aus — im Gegenteil, das Altdesign zeigt bereits die richtige *Schichtung*, nur mit der falschen untersten Schicht. Empfehlung:

```
┌─────────────────────────────────────────────────────────────┐
│ Skill: explain_docs_skill (R0, Teil der skill_registry)      │
│                                                               │
│  1. AUFFINDUNG (primär):  Embedding-basierte semantische     │
│     Top-K-Suche über vorindexierte Doku-Chunks                │
│       → löst die Sprachfrage strukturell (Abschnitt 4/5.1)   │
│                                                               │
│  2. ROUTING/DISAMBIGUIERUNG (aus Altdesign übernehmen):       │
│     Themen-TOC + Konfidenz-Scoring zur Eingrenzung,           │
│     Planner-Direktpfade (doc_path/doc_path_candidates),       │
│     Score-Heuristiken & Disambiguierungs-Schwellenwerte       │
│                                                               │
│  3. KONSUM (aus Altdesign übernehmen): Zeilenfenster-Lesen    │
│     mit has_more/next_line_start — der Agent liest portions-  │
│     weise weiter, bis er "genug" hat (= Georgs Idee b, aber   │
│     als Lese-, nicht als Such-Mechanismus)                     │
│                                                               │
│  4. FALLBACK: Wenn Embedding-Suche keinen Treffer über        │
│     Schwellenwert liefert → Root-README lesen + ggf.          │
│     Multi-Query-Stichwortsuche als zweite Stimme               │
│     (kein Schaden, aber auch kein Ersatz für (1))             │
│                                                               │
│  5. (Zukunft) ERWEITERUNG: Websuche als dritte Quelle,        │
│     hinter denselben Schwellenwert-Gate gehängt — siehe §8    │
└─────────────────────────────────────────────────────────────┘
```

### 6.1 Warum diese Kombination und nicht eine "reine" Option

- **Reines (a)** würde die wertvollen Orchestrierungs- und Lese-Muster aus dem Altdesign verschenken und dem Nutzer entweder den ganzen Treffer-Chunk vorsetzen (Kontext-Verschwendung bei langen Dokumenten) oder bei knappen Chunks zu wenig Tiefe liefern.
- **Reines (b)** löst das eigentliche Such-/Sprachproblem nicht zuverlässig — es würde bei jeder fremdsprachigen Anfrage zum Glücksspiel, welchen Link das LLM wählt, und das mehrstufig-teuer.
- **Reines (c)** ist wirtschaftlich und architektonisch die schlechteste Wahl bei diesem Korpus.
- Die Kombination nutzt **(a)** für das, worin Embeddings unschlagbar sind (sprachunabhängiges *Finden* der relevanten Stelle in einem mittelgroßen Korpus, bei niedrigen Laufzeitkosten dank Vorab-Indexierung), und **(b)**-artiges Zeilenfenster-Lesen für das, worin iteratives Vorgehen unschlagbar ist (kontextschonendes *Konsumieren* eines gefundenen Dokuments in für das Gespräch angemessener Tiefe). Das Themen-TOC dient als Brücke dazwischen — und genau das war die *Architektur-Idee* des Altdesigns, die nur mit der falschen Such-Engine kombiniert war.

### 6.2 Konkreter Implementierungsvorschlag (Skizze)

**Neue/zu reaktivierende Komponenten:**

1. **`docs_embeddings_index_service`** (neu, Vorbild `family_embeddings_index_service`):
   - Durchläuft `mod_booking/docs/**/*.md`, zerlegt jede Datei in Chunks (Vorschlag: pro `##`/`###`-Abschnitt, mit Datei-/Abschnittstitel als Präfix für besseren Embedding-Kontext — analog zu `to_embedding_input()`),
   - berechnet Inhalts-Hashes je Chunk (Wiederverwendung von `compute_content_hash`-Logik),
   - ruft `llm_call_service::invoke_embeddings()` auf (gleiches Modell/gleiche Konfiguration wie der Skill-Katalog — `embeddings_action_config_resolver`),
   - persistiert über ein Repository analog `embeddings_csv_repository` (eigene Datei/Tabelle, getrennt vom Skill-Katalog, gleiches Schema-Validierungsmuster).

2. **`docs_embeddings_readiness_service`** (neu, 1:1 Vorbild `embeddings_readiness_service`):
   - Hash-/Modell-/Dimensionsvergleich, Anstoß eines `rebuild_docs_embeddings_adhoc`-Tasks bei Veralterung, mit Debounce.

3. **`docs_lookup_service`** (neu aufgebaut — *nicht* identisch zur historischen Version!):
   - `search_semantic(string $question, int $limit)`: bettet die Frage ein (`invoke_embeddings`), nutzt `embeddings_retrieval_service::search_top_k()` über die Doku-Chunk-Vektoren, liefert Top-K mit Score, Dateipfad, Zeilen-/Abschnittsbereich.
   - `read_doc_by_path()`, `read_root_doc()`: **1:1 aus dem Altdesign übernehmen** (Zeilenfenster-Logik ist sprach- und suchunabhängig korrekt).
   - `get_master_toc_index()`: **aus dem Altdesign übernehmen/aktualisieren** — das Root-README ist bereits ein kuratierter Themen-Router; daraus die TOC ableiten (ggf. ergänzt um ein explizites Frontmatter-Feld pro Themen-README, falls robuster als Linktext-Parsing).
   - `search_multi()` (lexikalisch): **als Fallback-Stimme behalten**, aber im Ranking klar nachrangig zur semantischen Suche gewichten — sie hilft bei sehr kurzen, hochspezifischen Begriffen (Funktionsnamen, Capability-Strings wie `mod_booking:viewreports`), bei denen exakte Substring-Treffer wertvoller sind als semantische Nähe.
   - `detect_best_topic()`: kann **entweder** auf der historischen TOC-Stichwortlogik **oder** (besser, konsistenter) ebenfalls auf einem Embedding-Vergleich "Frage ↔ Themen-Beschreibung" basieren — letzteres ist sprachagnostisch und vereinheitlicht den Mechanismus.

4. **`explain_docs_skill`** (Skill-Hülle, Vorbild `explain_docs_topic_skill`, aber mit "skill"-Terminologie statt "task" gemäß abgeschlossenem Rename, siehe `bookingextension_agent_rename_task_zu_skill_plan_2026-06-07.md`):
   - Schema: `question` (Pflicht), `outputlang`, optional `doc_path`/`doc_path_candidates` (Planner-Direktpfade), `line_start`/`line_count` für Folgeaufrufe, ggf. `search_queries` als Ergänzung für die lexikalische Fallback-Stimme.
   - Ausführungsreihenfolge: Direktpfad-Modus → semantische Top-K-Suche (mit Konfidenz-Schwelle, analog `PLANNER_DIRECT_DOC_CONFIDENCE`/`DISAMBIGUATION_RATIO`) → Themen-Eingrenzung bei Mehrdeutigkeit → lexikalischer Fallback → Root-README-Fallback.
   - Antwortformat: strukturierte Observation mit Pfad, gelesenem Ausschnitt, `has_more`/`next_line_start`, Doku-URL — **1:1 aus dem Altdesign übernehmen** (`build_full_observation_from_docs`, `build_structured_doc_payload`, `build_doc_url`).

### 6.3 Was unverändert aus dem Altdesign übernommen werden sollte

Damit kein wertvolles Architekturdenken verloren geht — explizit zur Wiederverwendung markiert:

- Zeilenfenster-Lesemechanik (komplett, inkl. Konstanten `DEFAULT_LINE_COUNT`, `FIRST_STEP_LINE_COUNT`)
- Planner-Direktpfad-Eskalation (`doc_path`/`doc_path_candidates`, Schwelle `PLANNER_DIRECT_DOC_CONFIDENCE`)
- Themen-TOC-Konzept und Konfidenz-Gating (Schwellenwerte ggf. neu kalibrieren, da sich die Score-Skala mit Embeddings ändert)
- Strukturierte Observation/Payload-Builder
- `looks_like_configuration_question()`-artige Intent-Erkennung als zusätzlicher Boost-Faktor (sprachspezifische Regex-Heuristiken sind hier unkritisch, weil sie nur das *Ranking* verfeinern, nicht die *Auffindung* tragen — die liegt jetzt bei den Embeddings)

### 6.4 Was bewusst verworfen werden sollte

- Die lexikalische Tokenisierung/Score-Logik des historischen `docs_lookup_service` als **primärer** Suchmechanismus (sie erfüllt Anforderung 3 nicht) — als **sekundäre** Fallback-Stimme mit niedriger Gewichtung ist sie dagegen unschädlich und sogar wertvoll für exakte Begriffstreffer.
- Ein "Superdokument"-Ansatz als Dauerbetriebsmodus (Abschnitt 5.3).

---

## 7. Zur Sprachagnostik im Detail — warum "Embeddings + Themen-Routing" und nicht "Übersetzung vorschalten"

Eine naheliegende Alternative wäre, die Nutzerfrage vor der Suche maschinell ins Englische zu übersetzen (LLM-Zwischenschritt) und dann lexikalisch zu suchen. Das wurde bewusst **nicht** als Hauptweg empfohlen:

- **Zusätzlicher LLM-Roundtrip** pro Anfrage (Kosten/Latenz) — und zusätzliche Fehlerquelle (Übersetzungsfehler verschieben sich 1:1 in Suchfehler; Fachbegriffe wie "Subbooking", "Slot", "Campaign" werden ggf. falsch übersetzt oder fälschlich übersetzt, obwohl sie im Original-Doku-Jargon stehen bleiben sollten).
- **Embeddings lösen das Problem strukturell mit, ohne Zwischenschritt**: Multilinguale Modelle bilden "Wartelistenfunktion", "waiting list feature" und "候补名单功能" in räumlich nahe Vektoren ab — das *ist* bereits die "Übersetzung", nur im Bedeutungsraum statt im Textraum, und ohne dass ein zusätzlicher, fehleranfälliger Textgenerierungsschritt nötig wäre.
- Das deckt sich mit der bestehenden Architekturentscheidung des Agenten: Die Skill-Katalog-Auswahl funktioniert *bereits* sprachübergreifend nach exakt diesem Prinzip (Nutzer können in beliebiger Sprache fragen, der Embedding-Vergleich gegen die — englisch verfasste — Skill-Katalog-Beschreibung funktioniert dennoch). Es gibt also bereits einen **Existenzbeweis im selben Repo**, dass dieses Prinzip für genau dieses Sprachproblem funktioniert.

**Resteinschränkung, die offen bleibt (ehrlich benannt):** Multilinguale Embeddings sind nicht *perfekt* sprachagnostisch — die Trefferqualität bei sehr unterschiedlichen Sprachpaaren (z. B. Chinesisch ↔ Englisch) ist messbar niedriger als bei näher verwandten Paaren (z. B. Deutsch ↔ Englisch). "100 % sprachagnostisch" im Sinne von "identische Trefferqualität in jeder Sprache" ist mit *keinem* der drei Ansätze technisch garantierbar — auch nicht mit Übersetzung (die ihrerseits sprachpaarabhängig unterschiedlich gut funktioniert) oder mit dem LLM-Navigationsansatz (b) (dessen Sprachverständnis ebenfalls modell- und sprachpaarabhängig variiert). Die Embedding-Variante ist jedoch der Ansatz, der dieser Anforderung **strukturell am nächsten kommt** und zugleich der einzige, der das Problem ohne zusätzliche Fehlerquelle (Übersetzung) oder Kostenexplosion (Superdokument) angeht. Empfehlung: Die Anforderung "100 % sprachagnostisch" pragmatisch als *"die Architektur darf keine Sprache strukturell bevorzugen oder ausschließen"* auslegen (was Embeddings erfüllen) statt als *"jede Sprache liefert mathematisch identische Ergebnisse"* (was kein Verfahren erfüllen kann).

---

## 8. Zukünftige Websuche-Integration — wie die empfohlene Architektur das vorbereitet

Georg möchte eine künftige Websuche mitgedacht haben. Die empfohlene Schichtung ist dafür bereits passend vorbereitet, **ohne dass jetzt etwas gebaut werden muss**:

- Die Kaskade „semantische Doku-Suche → Themen-Eingrenzung → lexikalischer Fallback → Root-README" kann um eine **weitere, nachrangige Stufe** „Websuche" ergänzt werden, die **nur dann** greift, wenn alle internen Quellen unter dem Konfidenz-Schwellenwert bleiben (gleiches Gating-Prinzip wie `TOPIC_CONFIDENCE_THRESHOLD`/`DISAMBIGUATION_RATIO` — diese Schwellenwert-Architektur ist bereits darauf ausgelegt, beliebig viele Quellen in eine Rangfolge zu bringen).
- Ergebnisse einer Websuche würden in dasselbe **strukturierte Observation-Format** eingebettet (`build_structured_doc_payload`-Äquivalent mit `source: 'web'` statt `source: 'local_docs'`), sodass der Orchestrator/Planner keine Sonderbehandlung pro Quelle bräuchte.
- Risikoklassen-technisch wäre eine Websuche vermutlich **nicht mehr R0** (externe Datenquelle, potenziell andere Datenschutz-/Zuverlässigkeits-Eigenschaften) — das müsste im Schema/Governance-Layer als eigene Eskalationsstufe abgebildet werden (z. B. eigener Sub-Skill `search_web_for_docs` mit höherer Risikoklasse, den der Hauptskill bei Bedarf nachgelagert aufruft, statt die Websuche in denselben R0-Skill zu integrieren).
- Aus Sprachsicht ist Websuche tendenziell **unkritischer** als die lokale Doku-Suche, weil Suchmaschinen selbst bereits sprachübergreifende Relevanzmodelle einsetzen — die hier vorgeschlagene embeddings-zentrierte Architektur würde also durch eine Websuche-Erweiterung nicht in Frage gestellt, sondern nur um eine zusätzliche, nachrangige Quelle ergänzt.

**Empfehlung:** Jetzt nichts für Websuche bauen, aber das Schema/die Kaskade so gestalten, dass eine zusätzliche Quelle als weiterer Eintrag in der Prioritätskette ergänzt werden kann, ohne bestehende Verträge zu brechen (z. B. `source`-Feld in der strukturierten Observation von Anfang an vorsehen, auch wenn aktuell immer `'local_docs'`).

---

## 9. Erweiterung: mehrere Korpora & dynamische Doku-Aufnahme ("read this" / "learn this")

Im Gespräch nach Fertigstellung der Erstanalyse hat Georg den Anwendungsbereich bewusst erweitert: Der Skill soll nicht nur die `mod_booking`-Doku durchsuchen können, sondern **beliebig viele Dokumentationsquellen** — `local_shopping_cart`, allgemeine Moodle-Doku, und perspektivisch Inhalte, die Nutzer per Chat-Befehl ("read this" / "learn this" + URL) live hinzufügen. Dieser Abschnitt arbeitet die dabei diskutierten Punkte in die Architektur ein.

### 9.1 Mehrere feste Korpora (mod_booking, local_shopping_cart, Moodle-Kerndoku, …)

Das ist eine **direkte, risikoarme Verallgemeinerung** der in §6 vorgeschlagenen Architektur — keine neue Idee, sondern eine Parametrisierung:

- Jeder Doku-Chunk erhält ein zusätzliches Feld **`corpus_id`** mit folgendem Schema (siehe §9.3 für Details):
  - Registrierte Plugin-Korpora: **Moodle-Komponentenname** — `mod_booking`, `local_shopping_cart`, `moodle_core` etc. (global eindeutig, aus dem Dateipfad direkt ableitbar, im restlichen Code bereits überall als kanonischer Bezeichner verwendet).
  - Dynamisch gelernte Korpora ("learn this"): `learned:<contextid>:<sha1_der_kanonisierten_url>` — Präfix macht den Typ sofort erkennbar, `contextid` löst die Sichtbarkeitsfrage, SHA1 der URL macht den Bezeichner deterministisch (gleiche URL von zwei Nutzern → gleicher `corpus_id`, kein doppelter Eintrag).
- Die semantische Suche (`search_top_k`) bleibt unverändert — sie muss lediglich optional nach `corpus_id` filtern oder gewichten können (z. B. "bevorzuge Treffer aus dem Korpus, das zum aktuellen Plugin-Kontext passt, aber schließe andere nicht kategorisch aus" — wichtig, weil Nutzerfragen Plugin-Grenzen oft nicht kennen, etwa "wie hängen Buchung und Warenkorb zusammen?").
- Das Themen-TOC (§6.2, Punkt 3) wird zu einer **Liste von Korpus-Wurzeln** mit je eigener TOC — strukturell dieselbe Hierarchie wie bisher, nur eine Ebene höher angesetzt.
- Governance-seitig bleibt das R0 (alles weiterhin lesender Zugriff auf am Server liegende `.md`-Dateien) — **solange** die Korpora vorab kuratiert/registriert sind (siehe 9.3) und nicht "irgendwas, was der Nutzer gerade postet".

**Fazit 9.1:** Unkritisch, sollte von Anfang an ins Schema (Chunk-Datensatz + Observation-Format) aufgenommen werden — kostet praktisch nichts, vermeidet aber eine spätere Migration des gesamten Index.

### 9.2 "Read this" / "learn this" — Live-Aufnahme von Inhalten per Chat-Befehl

Das ist die deutlich anspruchsvollere Idee, weil sie den Skill von einer reinen *Lese*-Funktion (R0) zu einer *Schreib*-Funktion macht (neuer Index-Eintrag wird angelegt). In der Diskussion wurden zwei Eingrenzungen vorgeschlagen, die den Unterschied machen:

> "if we have the read docs limited to .md files only, and only on the same Moodle site"

Diese doppelte Restriktion verändert das Risikoprofil fundamental:

| Ohne Restriktion (beliebige URL, beliebiger Inhalt) | Mit Restriktion (nur `.md`, nur eigene Moodle-Instanz) |
|---|---|
| Serverseitiges Abrufen beliebiger URLs → klassisches **SSRF-Risiko** (interne Netzwerkadressen, Cloud-Metadaten-Endpunkte etc. könnten als "Doku-URL" getarnt angefragt werden) | Entfällt weitgehend: Eine URL der eigenen Instanz lässt sich auf einen **lokalen Dateisystempfad auflösen** — der Server muss gar keinen HTTP-Request an sich selbst stellen, sondern liest die Datei direkt von der Platte (gleicher Mechanismus wie beim bestehenden `docs/`-Ordner) |
| HTML-Parsing nötig → Sanitisierungs-/XSS-/Skript-Ausführungs-Risiken beim späteren Anzeigen extrahierter Inhalte | **Entfällt komplett**: `.md` ist reiner Text, exakt das Format, das die bestehende Pipeline (Chunking, Embedding, Anzeige) schon verarbeitet — keine neue Inhaltskategorie |
| Crawl-Umfang potenziell unbegrenzt (das ganze Web hinter einem Link) | Crawl-Umfang ist von vornherein auf die **eigene Instanz** begrenzt — die Linkstruktur ist bekannt, endlich und bereits Gegenstand der bestehenden Themen-TOC-Logik |
| Eigene, neue Risikoklasse nötig (externe Netzwerkzugriffe, Drittinhalte) | Bleibt nahe an R0/R1 — strukturell kaum unterscheidbar von "lies eine zusätzliche lokale Markdown-Datei, die noch nicht im Standard-Korpus registriert ist" |

**Empfehlung zur konkreten Umsetzung dieser eingegrenzten Variante:**

1. **Same-Site-Prüfung robust implementieren**: Vergleich gegen `$CFG->wwwroot` (nicht nur String-Prefix-Vergleich — anfällig für Open-Redirect-/Host-Header-Tricks), idealerweise durch Auflösen der URL in einen lokalen Dateisystempfad, sodass de facto **kein** ausgehender HTTP-Request nötig ist.
2. **Strikte `.md`-Filterung** vor jeder Verarbeitung — alles andere wird abgelehnt, nicht "best-effort geparst".
3. **Hartes Limit für Link-Folgen** (max. Tiefe, max. Seitenzahl) — auch *innerhalb* der eigenen Instanz, damit "learn this" nicht versehentlich zu "indiziere die ganze Moodle-Instanz" eskaliert (Stichwort: jemand postet die URL der Root-Doku-Seite, und der Crawler folgt transitiv allen 85+ Dateien plus Kerndoku plus jedem anderen Plugin).
4. **Sofortige, on-demand Indizierung statt Warten auf den Tageszyklus** (siehe 9.3) — der Nutzer, der "learn this" sagt, möchte direkt danach Fragen stellen können, nicht morgen.
5. **Risikoklasse**: vermutlich nicht mehr reines R0 (es entsteht ein neuer, schreibender Index-Eintrag, ggf. mit Sichtbarkeits-/Lebenszyklus-Fragen — wer darf den "gelernten" Korpus später abfragen? nur der anfragende Nutzer? der ganze Kurs?). Empfehlung: als eigener Sub-Skill mit eigener (etwas höherer) Risikoklasse modellieren, den der Haupt-Such-Skill nicht selbst ausführt, sondern an den der Planner bei Bedarf eskaliert — konsistent mit dem unter §8 für Websuche vorgeschlagenen Muster ("Suchskill bleibt R0, Spezialfälle mit anderem Risikoprofil sind eigene, höher eingestufte Sub-Skills").

**Fazit 9.2:** Mit den von Georg selbst vorgeschlagenen Einschränkungen (`.md` + same-site) wird aus einer ursprünglich riskanten "Web-Crawling"-Idee eine **vergleichsweise harmlose Erweiterung** des bestehenden Lesemechanismus — im Kern "zusätzliche lokale Markdown-Datei zur Laufzeit registrieren" statt "beliebige Webinhalte aufnehmen". Das passt gut in die ohnehin empfohlene Architektur, verdient aber eine eigene (geringfügig höhere) Risikoeinstufung wegen des *Schreib*-Charakters (neuer Index-Eintrag) und der Lebenszyklus-/Sichtbarkeitsfragen, die das aufwirft.

### 9.3 Wann müssen Vektoren neu erzeugt werden? Eine Indexierungs-/Recreation-Strategie

Diese Frage stellte sich konkret bei der Erweiterung auf mehrere Korpora: Reicht ein zentraler, täglicher Hash-Abgleich über *alles*, oder braucht es mehr Differenzierung?

**Geprüfte Idee:** Einen einzigen globalen Index aus *allen* `.md`-Dateien des Servers aufbauen und täglich per Hash-Vergleich aktuell halten.

*Chancen dieser Idee:*
- Ein einziger Mechanismus, ein Zeitplan, eine Code-Basis — operative Einfachheit.
- Planbare Ressourcennutzung: ein nächtlicher Batch-Lauf mit bekannter Kostenobergrenze, der in lastarmen Zeiten laufen kann.
- Kein "Kaltstart" mitten im Gespräch — alles ist immer bereit, keine Embedding-Latenz zur Laufzeit.
- Tageszyklus passt zur Realität: Doku ändert sich selten, ein täglicher Hash-Diff erkennt Änderungen, ohne ständig zu prüfen.

*Risiken/Nachteile dieser Idee (der Kernpunkt aus der Diskussion):*
- Ein typischer Moodle-Server enthält **Tausende** `.md`-Dateien — Drittanbieter-Bibliotheken, `node_modules`-artige Verzeichnisse, `CHANGELOG`/`LICENSE`/`README` praktisch jedes Plugins, Kern-Entwicklerdokumentation. Die allermeisten davon sind für "erkläre mir mod_booking/shopping_cart" **Rauschen**.
- Das bläht (a) die initiale Embedding-Last massiv auf (potenziell ein "Riesen-Erstlast"-Ereignis, genau wie von Georg befürchtet) und (b) **verschlechtert die Trefferqualität**, weil z. B. das `README` einer Drittbibliothek bei zufälliger Begriffsüberschneidung höher ranken könnte als die eigentliche Booking-Dokumentation.
- Ein täglicher Hash-Abgleich über Tausende Dateien verursacht nicht-trivialen Dateisystem-Overhead (Stat/Read-Operationen), selbst wenn sich nichts geändert hat.
- Lebenszyklus-Probleme verschärfen sich: Ein zentraler Riesenindex macht "Vektoren eines stillgelegten Plugins entfernen" oder "pro Korpus debuggen" schwerer als nötig.
- Wichtig: Selbst ein globaler Index löst die Multi-Korpus-Frage nicht "kostenlos" — Suchergebnisse müssten weiterhin nach Quelle gefiltert/eingeordnet werden (zwei verschiedene Plugins können ähnliche Begriffe verwenden), die Komplexität wandert also nur von "mehrere Indizes verwalten" zu "ein Index mit Filterlogik", ohne dass die Notwendigkeit einer Quellen-Kennzeichnung verschwindet.

**Empfehlung — die Mitte zwischen "alles" und "viele getrennte Indizes":**

- **Ein** einheitlicher Speicher-/Pipeline-Mechanismus (eine Tabelle/CSV-Struktur, ein Hash-Vergleichs-Job, ein Adhoc-Rebuild-Muster — exakt das, was `embeddings_readiness_service` heute schon für den Skill-Katalog leistet), aber
- **gespeist aus einer expliziten Korpus-Registry/Allowlist** (`mod_booking/docs`, `local_shopping_cart/docs`, ausgewählte Moodle-Kerndoku-Pfade, plus dynamisch über "learn this" hinzugefügte Einträge) statt blindem Durchsuchen des gesamten Dateisystems.
- Jeder Chunk trägt `corpus_id` nach dem unter §9.1 definierten Schema (Moodle-Komponentenname für registrierte Korpora, `learned:<contextid>:<sha1_url>` für gelernte) — das löst sowohl die Filterung bei der Suche als auch die Lebenszyklus-Frage ("entferne alle Vektoren mit `corpus_id = X`") elegant und einheitlich, ganz gleich ob der Korpus fest registriert oder "gelernt" ist.
- **Zeitliche Differenzierung nach Anlass, nicht nach Korpus-Typ:**
  - Registrierte Standard-Korpora (`mod_booking`, `local_shopping_cart`, Kerndoku): **täglicher Hash-Diff** genügt vollauf — diese ändern sich selten, und die Dateimenge bleibt durch die Allowlist klein und vorhersagbar (Größenordnung: Dutzende bis wenige Hunderte Dateien, nicht Tausende).
  - Dynamisch gelernte Inhalte ("learn this"): **sofortige, synchrone (oder zumindest kurz-asynchrone) Indizierung** beim Anlegen — der Nutzer wartet aktiv darauf, danach Fragen stellen zu können; ein Tageszyklus wäre hier inakzeptabel. Technisch dasselbe Adhoc-Task-Muster wie der reguläre Rebuild, nur mit sofortiger statt geplanter Triggerung und kleinerem Arbeitsumfang (ein neu hinzugekommener Korpus statt voller Re-Scan).
- Ergebnis: Die operative Einfachheit der "ein-Mechanismus"-Idee bleibt erhalten (kein Wildwuchs an Indexierungs-Sonderwegen), ohne deren Kernproblem (Rauschen durch unkuratiertes Vollscannen, Riesen-Erstlast) zu erben.

**Fazit 9.3:** Nicht "alles auf dem Server", sondern "alles, was explizit in die Registry aufgenommen wurde" — über denselben einheitlichen Mechanismus, mit `corpus_id` als gemeinsamem Dreh- und Angelpunkt für Filterung, Lebenszyklus *und* Indexierungs-Taktung (täglich für stabile, registrierte Korpora; sofort für aktiv angeforderte "gelernte" Inhalte).

---

## 10. Umsetzungsschritte (grobe Reihenfolge, nicht Bestandteil dieser Analyse als Auftrag — zur Orientierung)

1. Neuen `docs_lookup_service` **von Grund auf** implementieren (nicht aus dem historischen Code wiederherstellen — der ist lexikalisch und löst das Kernproblem nicht), mit semantischer Suche als Primärmechanismus + den unter §6.3 gelisteten Übernahmen. Schema von Anfang an um `corpus_id` (§9.1/§9.3) und `source` (§8) ergänzen, auch wenn beide zunächst nur einen festen Wert tragen — vermeidet eine spätere Index-Migration.
2. `docs_embeddings_index_service` + `docs_embeddings_readiness_service` + Adhoc-Rebuild-Task analog zur Skill-Katalog-Pipeline aufbauen (Wiederverwendung der generischen Bausteine `llm_call_service::invoke_embeddings`, `embeddings_retrieval_service`, `embeddings_action_config_resolver`). Indexierungs-Eingabe von Anfang an über eine **Korpus-Registry/Allowlist** steuern (§9.3), nicht über blindes Dateisystem-Scannen.
3. Chunking-Strategie für die ~85 Markdown-Dateien des `mod_booking`-Korpus festlegen und prototypisch evaluieren (Abschnitts- vs. Datei-Granularität; Titel-Präfixe für besseren Embedding-Kontext) — dieselbe Strategie sollte sich verlustfrei auf weitere Korpora (z. B. `local_shopping_cart`) übertragen lassen.
4. `explain_docs_skill` als neuen Skill in `skill_registry` registrieren (mit "skill"-Terminologie, R0), unter Wiederverwendung der Observation-/Lese-Bausteine aus dem Altdesign. Suchparameter um optionale Korpus-Eingrenzung erweitern (z. B. `corpus_hint`), Standardverhalten bleibt korpusübergreifend.
5. Test-Matrix mit mehrsprachigen Anfragen (DE/EN + mind. eine nicht-lateinische Sprache, z. B. ZH) gegen bekannte Zieldokumente aufbauen, um die Trefferqualität messbar zu machen — Schwellenwerte (Konfidenz, Disambiguierung) anhand realer Ergebnisse kalibrieren statt aus dem Altdesign zu übernehmen (dessen Werte für eine *lexikalische* Score-Skala kalibriert waren und für eine *Cosinus-Ähnlichkeits*-Skala neu bestimmt werden müssen).
6. Websuche **nicht** in dieser Phase bauen — aber Schema/Observation-Format von Anfang an um ein `source`-Feld ergänzen (§8), damit die spätere Erweiterung keine Breaking Changes erfordert.
7. Weitere feste Korpora (`local_shopping_cart`, Moodle-Kerndoku) **nach** Stabilisierung des `mod_booking`-Falls als zusätzliche Registry-Einträge ergänzen (§9.1) — keine Architekturänderung, nur Konfiguration + Erstindexierung.
8. "Read this"/"learn this" (§9.2) als **eigenständigen Folge-Skill mit eigener Risikoklasse** planen, nicht als Erweiterung des Such-Skills selbst — frühestens, nachdem die Kernsuche produktiv und kalibriert ist.

---

## 11. Offene Fragen für Georg

- **Chunk-Granularität:** Bevorzugt er datei- oder abschnittsbasierte Chunks? (Abschnittsbasiert liefert präzisere Treffer, datei-basiert vereinfacht das Zeilenfenster-Lesen, weil "Treffer-Chunk" und "lesbares Dokument" deckungsgleich bleiben.)
- **Eigener Embeddings-Index oder gemeinsamer Katalog mit dem Skill-Katalog?** Technisch spricht nichts dagegen, beide im selben Speicher zu führen (z. B. mit einem `kind`-Diskriminator `skill`/`doc_chunk`) — das würde Infrastruktur weiter teilen, aber auch Kopplungen erhöhen. Empfehlung dieser Analyse: **getrennt führen** (eigenes Repository/eigene Tabelle), gleiche Pipeline-Bausteine, um Rebuild-Zyklen unabhängig zu halten (Skill-Katalog ändert sich bei Code-Änderungen, Doku-Katalog bei Doku-Änderungen — unterschiedliche Taktung).
- **Soll die fremdsprachige Datei `certificates_de.md`** besonders behandelt werden (z. B. als kanonische deutsche Quelle bevorzugt, wenn `outputlang = 'de'`)? Das wäre eine zusätzliche Ranking-Dimension, die über reine semantische Nähe hinausgeht.
- **Schwellenwert-Kalibrierung:** Soll vor dem produktiven Rollout eine kleine manuell kuratierte "golden set"-Liste (Frage → erwartetes Zieldokument, mehrsprachig) angelegt werden, um die Konfidenz-/Disambiguierungs-Schwellenwerte messbar statt geschätzt zu setzen?
- **Korpus-Registry (neu, §9.3):** Wer pflegt die Allowlist der indizierten Korpora — ist das eine Admin-Einstellung (UI-Konfiguration), eine Konfigurationsdatei, oder soll sie sich (teil-)automatisch aus installierten Plugins mit `docs/`-Verzeichnis ableiten?
- **Sichtbarkeit "gelernter" Inhalte (neu, §9.2):** Wenn ein Nutzer per "learn this" eine Doku-Quelle hinzufügt — ist das danach für ihn allein abfragbar, für den ganzen Kurs/Kontext, oder global für die Instanz? Das beeinflusst sowohl die Risikoeinstufung als auch das Datenmodell (`corpus_id` müsste dann ggf. mit einer Sichtbarkeits-/Eigentümer-Dimension verknüpft werden).
- **Lebenszyklus "gelernter" Korpora:** Sollen sie unbegrenzt bestehen bleiben, nach Inaktivität ablaufen, oder explizit löschbar sein ("vergiss das wieder")? Das ist insbesondere relevant, falls "gelernte" Inhalte personenbezogen sichtbar sind (Datenschutz-/Aufräum-Pflichten).
