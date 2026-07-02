# Blueprint: Semantische Site-Suche über einen Search-Area-Embeddings-Adapter

*Stand: 2026-06-10 · Status: Analyse / Vormerkung (Roadmap WS7), KEINE Umsetzung jetzt*

## 0. Idee in einem Satz

Moodles globale Suche ist nicht semantisch. Aber die **Search-Areas** der Plugins liefern bereits
indexierbare, zugriffskontrollierte „Chunks". Wir behalten diese Areas als Korpus und ersetzen die
**Engine** (Keyword-Matching) durch unsere **Embeddings** — so wird Site-Content über den Agent semantisch
auffindbar, ohne pro Plugin zu scrapen.

## 1. Was Moodle schon liefert (der Hebel)

Global Search trennt sauber:
- **Search-Areas** (`\core_search\base`-Subklassen, z. B. `mod_forum\search\post`,
  `mod_page\search\activity`, `core_course\search\course`): je Area liefert `get_document_recordset()` /
  `get_document()` die Chunks als `\core_search\document` mit `title`, `content`, `description1/2`,
  `contextid`, `courseid`, `owneruserid`, `modified`.
- **Zugriffskontrolle**: jede Area hat `check_access($id)` → `ACCESS_GRANTED|DENIED|DELETED`; der Manager
  kennt `get_areas_user_accesses()` (erlaubte Kontexte je User).
- **Engine** (`\core_search\engine`, Solr/simpledb): das eigentliche Matching.

Wir nehmen Areas + `check_access`, ersetzen die Engine.

## 2. Knackpunkt 1 — Berechtigungen (Make-or-Break)

Ein flacher Embeddings-Index hat **keine** Rechte. Naives Top-K-Retrieval würde Inhalte leaken, die der User
nicht sehen darf (fremde Kurse, versteckte Aktivitäten, fremde Abgaben). Pflicht: **Index global, Retrieval
per-User nachgefiltert** — Top-K großzügig holen (over-fetch 50–200), dann jeden Kandidaten durch
`check_access()` der zuständigen Area + Kontext-Sichtbarkeit filtern. Gleiches Modell wie Moodle mit Solr
(globaler Index, Filter nach `get_areas_user_accesses()`). Effizienz-Tuning: bei schmalem Zugriff viel
over-fetchen nötig.

## 3. Knackpunkt 2 — Skalierung (CSV-Modell bricht)

Unser CSV-Embeddings-Katalog ist top für ~hunderte **Skills**, aber Site-Content ist 10⁵–10⁷ Chunks:
- **Kein CSV/Linear-Scan** → echter Vektor-Store (DB-Tabelle mit Vektor-Spalte + ANN, pgvector, oder externer
  Store).
- **Chunking**: Dokumente stark unterschiedlich groß → ~500-Token-Chunks mit Overlap, ein Vektor pro Chunk,
  Mapping Chunk → Dokument → Context (für die Access-Filterung).
- **Inkrementelles (Re-)Indexing + Deletes**: an Moodles Such-Indexing-Pipeline andocken
  (`get_recordset_by_timestamp`, Index-Task); gelöschter Content muss aus den Embeddings raus.
- **Kosten/Rate-Limits**: ganze Site embedden kostet echtes Geld → Batching, inkrementell, **kuratierte
  Area-Whitelist** (Kursinhalte/Seiten/Bücher/Foren/Glossare ja; rauschige/riesige Areas nein).

## 4. Architektur-Gabel

**Option A — Side-Adapter (der ursprüngliche Vorschlag):** Areas iterieren → eigener Embeddings-Index →
eigenes Retrieval + **manuelles** `check_access`. Volle Kontrolle, aber Access-Filterung selbst nachgebaut,
lebt neben der Core-Suche.

**Option B — eigene `\core_search\engine`** (`searchengine_wbvector`): Moodles Manager füttert via
`add_documents()`, Queries laufen über `execute_query($filters, $accessinfo)` — Moodle liefert die erlaubten
Kontexte des Users mit, wir machen nur ANN darin. Vorteil: **Access nativ geerbt** (kein selbstgebautes
Leak-Risiko), normale Such-Box wird semantisch. Nachteil: stärkere Kopplung an den (Solr-geformten)
Engine-Contract, Indexing über Core-Index-Task.

**Empfehlung:** Option B ernsthaft prüfen — sie erbt genau die Sicherheits-Maschinerie, die in Option A das
Hauptrisiko ist. Der Agent-Skill ruft dann die Engine im semantischen Mode (oder bleibt bei A mit striktem
Post-Filter).

## 5. Datenmodell-Skizze (für beide Optionen ähnlich)

```
wbsearch_chunk
  id, areaid (z. B. "mod_forum-post"), docid (Original-Dokument-ID der Area),
  contextid, courseid, owneruserid, chunkno, content (Klartext-Chunk),
  contenthash, modified
wbsearch_vector
  chunkid → vector (BLOB/pgvector), model, dimensions
```
Retrieval: ANN über `wbsearch_vector` → Chunk-Metadaten → `check_access(docid)` der Area → erlaubte Treffer →
zurück an den Skill (Titel, Snippet, Deep-Link zum Kontext).

## 6. Indexing-Flow

1. Index-Task (an Core-Such-Index andocken oder eigener Cron) iteriert Whitelist-Areas via
   `get_document_recordset()` / `get_recordset_by_timestamp(lastrun)`.
2. Pro Dokument: Content chunken, `contenthash` vergleichen (nur geänderte neu embedden), Embeddings via
   Wunderbyte `generate_embeddings`-Action (Batching).
3. Upsert in `wbsearch_chunk`/`wbsearch_vector`; entfernte Dokumente → Chunks/Vektoren löschen.

## 7. Engine-Cleanliness (Dauer-Vorgabe)

Alles als **Skill** (`core.find_content` / `search_site`) + skill-eigene Services (Index-Builder, Chunker,
Retrieval+Access-Filter), die die **vorhandene** Embeddings-Infrastruktur wiederverwenden (Wunderbyte
`generate_embeddings`, `embeddings_retrieval_service`). Die Agent-Engine routet nur zum Skill und kennt von
„Site-Suche" nichts. Bei Option B kommt zusätzlich ein eigenes `search`-Subplugin (`searchengine_wbvector`)
außerhalb des Agents.

## 8. v1-Scope (wenn wir es angehen)

- Kuratierte Area-Whitelist (page, book, forum, glossary, course summary).
- Inkrementelles Index über den Such-Index-Task; Content-Hash-Dedup.
- Over-fetch + `check_access`-Post-Filter (Option A) bzw. nativer `execute_query`-Access (Option B).
- Admin-Toggle + welche Areas; Deep-Links in Treffern.

## 9. Offene Fragen

1. **Option A vs B** — manueller Access-Filter vs. `\core_search\engine` (Access gratis, Engine-Contract).
2. **Vektor-Store** — DB-Tabelle + eigene Cosine (einfach, ~10⁵) vs. pgvector/externer ANN (skaliert, mehr
   Infra).
3. **Area-Whitelist** — welche Inhaltstypen sind den Index wert?
4. **Indexing-Trigger** — Core-Such-Index-Task (Areas liefern auch ohne aktivierte Solr-Engine) vs. eigener
   Cron.

## 10. Risiken

- **Access-Leak** bei fehlendem/fehlerhaftem Post-Filter (höchstes Risiko → Option B entschärft strukturell).
- **Indexgröße/Kosten** unkontrolliert → Whitelist + inkrementell + Content-Hash zwingend.
- **Drift** zwischen Moodle-Index und unserem Index (bei Option A zwei Quellen) → an denselben Index-Task
  hängen.

## 11. Nachtrag (2026-07-02): Volltext-Verfügbarkeit — die „nur Keywords"-Sorge, am Core verifiziert

Zwischenfrage: *„Die Search-API gibt uns nur Keywords, nicht den Volltext — dann ist der Ansatz sinnlos?"*
Am Core-Code geprüft (`search/classes/*`, `mod/*/classes/search/*`): **Der tokenisierte Keyword-Index ist
Sache der Engine (Solr/simpledb).** Wir konsumieren aber die **Search-Areas** (`get_document()`), und die
liefern den **Volltext** im Feld `content`:

| Area | `content` = |
| --- | --- |
| `mod_page` | `content_to_text($record->content)` → ganzer Seitenkörper |
| `mod_forum\post` | `content_to_text($record->message)` → komplette Beitragsnachricht |
| `mod_book\chapter` | `content_to_text($record->content)` → ganzes Kapitel |
| `mod_glossary\entry` | `content_to_text($entry->definition)` → komplette Definition |

`content_to_text()` ruft `html_to_text($content, 75, false)` — die `75` ist die **Umbruch-Breite**, KEINE
Kürzung; es kommt der vollständige Klartext zurück. `search/classes/document.php` und `manager.php` enthalten
keine Truncation.

**Einzige echte Grenze:** die generische Area `\core_search\base_activity` (Aktivitäten OHNE eigene
Content-Area) indexiert nur `name` + **`intro`** (nicht den Body). Deshalb die kuratierte Whitelist
(§3/§8): sie zielt genau auf die Areas mit eigener `get_document()`-Volltextausgabe; content-lose
Aktivitäten steuern nur Titel+Intro bei (bekannter Trade-off, kein K.o.). **Fazit: Der Ansatz bleibt
tragfähig.**

---

*Verwandt:* `project_agent_skill_discovery_visibility` (vorhandene Embeddings-/Retrieval-Infrastruktur),
ROADMAP.md WS7.
