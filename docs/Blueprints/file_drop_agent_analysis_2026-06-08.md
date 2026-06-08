# File Drop & Attachment API — Analyse & Konzept
**Datum:** 2026-06-08  
**Author:** Georg Maisser / Claude Code  
**Status:** Planung — keine Code-Änderungen

---

## 1. Motivation

Aktuell kann der Agent nur Text entgegennehmen. Zwei konkrete Use-Cases sollen ermöglicht werden:

| Use-Case | Beschreibung |
|---|---|
| **Header-Bild** | User zieht ein Bild (JPEG/PNG/WebP) in den Chat → Agent setzt es als Header-Bild einer Buchungsoption über den bestehenden `booking.update_option`-Skill |
| **PDF → Inhalt extrahieren** | User zieht ein PDF → Server extrahiert Text (PHP, kein LLM) → LLM bekommt den Text als normalen Text-Kontext → erzeugt daraus z.B. Quiz-Fragen, Beschreibung, Terminliste |

**Wichtige Designentscheidung:** Das LLM bekommt **niemals Binärdaten**. Bilder werden serverseitig in der Moodle File API gespeichert. PDFs werden serverseitig per PHP zu Text gemacht — das LLM sieht nur den extrahierten Text.

---

## 2. Ist-Zustand Inventur

### 2.1 Frontend

Der `dragging`-State in `aiinstructions.js` existiert bereits, aber ausschließlich für das Resize-Handle zwischen Chat- und Preview-Pane. Kein File-Drop-Support vorhanden.

`ai_send_message` nimmt aktuell nur `message` (string) + `contextid` + `threadid` entgegen — kein Feld für Datei-Referenzen.

### 2.2 Moodle File API — vorhandene File Areas in mod_booking

| File Area | Ebene | Verwendung |
|---|---|---|
| `bookingoptionimage` | Booking-Option | Pro-Option Header-Bild |
| `bookingimages` | Booking-Instanz | Globales Bild für alle Optionen |
| `myfilemanageroption` | Booking-Option | Allgemeiner Dateimanager pro Option |
| `templatefile` | Booking-Instanz | Template-Dateien |

Moodle verwaltet temporäre Uploads über `draft_itemid` (Session-gebunden). Permanente Speicherung via `file_save_draft_area_files()`.

### 2.3 `booking.update_option` Skill

Der bestehende Skill kennt bereits optionale Felder für Medien, der Schema-Eintrag für Bilder ist jedoch nicht vorhanden. Ziel ist es, diesen Skill um einen `image_token`-Parameter zu erweitern, der auf einen zuvor hochgeladenen temporären Upload zeigt — anstatt einen neuen Skill zu bauen.

### 2.4 PDF-Parsing — nicht vorhanden

Kein PDF-to-Text in Agent oder `local/ai_manager`. Moodle Core hat `\core_search\document_factory` für Content-Extraktion, aber das ist an die Suchindexierung gebunden und nicht direkt nutzbar. Für direktes PDF-to-Text im Agent muss eine eigene Lösung gebaut werden.

---

## 3. Architektur der zwei Datenpfade

### Pfad A — Bild → Moodle Storage → Buchungsoption Header

```
Browser (Drag & Drop)
  → ai_upload_attachment WS (multipart)
    → Validierung (MIME, Größe)
      → Temp-Datei + opaker Attachment-Token (TTL 10 min)
        → User tippt: "Setze das als Header für Option X"
          → LLM plant: booking.update_option {optionid, image_token}
            → Skill löst Token auf → file_save_draft_area_files()
              → bookingoptionimage file area der Option
                → Option hat neues Header-Bild
```

Das LLM sieht niemals Bilddaten. Es plant lediglich den Skill-Aufruf mit dem Token als Parameter.

### Pfad B — PDF → PHP-Text-Extraktion → LLM-Kontext

```
Browser (Drag & Drop)
  → ai_upload_attachment WS (multipart)
    → Validierung (MIME, Größe)
      → Temp-Datei + opaker Attachment-Token (TTL 10 min)
        → Server extrahiert Text (pdftotext / PHP-Parser)
          → extrahierter Text wird in user-Nachricht injiziert:
            "--- DOKUMENT: datei.pdf ---\n<text>\n---\n\nErstelle daraus Fragen."
              → normaler LLM-Aufruf mit erweitertem Text-Kontext
                → LLM generiert Fragen / Felder / Struktur
```

Das LLM sieht nur den extrahierten Text als normalen String — kein besonderer API-Pfad.

---

## 4. Detailkonzept

### 4.1 Upload-Endpunkt: `ai_upload_attachment`

Neuer External-WS für den Upload-Schritt (identisch für beide Pfade):

```
POST bookingextension_agent_ai_upload_attachment
  contextid (int)
  file      (multipart file)

→ {
    success:          bool,
    attachment_token: string,   // opakes Token, serverseitig TTL 10 min
    attachment_type:  string,   // 'image' | 'pdf' | 'unsupported'
    display_name:     string,   // Dateiname für UI-Anzeige
    thumbnail_html:   string,   // fertig gerenderte Vorschau (Bild: <img>, PDF: Icon + Name)
    message:          string,   // Fehlermeldung wenn success=false
  }
```

**Validierung:**
- Erlaubte MIME-Typen: `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `application/pdf`
- Max. Dateigröße: konfigurierbar, Default 10 MB Bilder / 20 MB PDF
- Token: signierter Hash aus `userid + contextid + tmp_filepath + timestamp`, gespeichert in Moodle Cache (TTL 10 min)
- Datei liegt in `make_temp_directory('bookingextension_agent/uploads')` bis zur Verarbeitung

### 4.2 Attachment-Token-Service

```php
class attachment_token_service {
    const TTL_SECONDS = 600;

    public function create(int $userid, int $contextid, string $tmppath, string $mime): string;
    public function resolve(string $token, int $userid, int $contextid): array; // ['path'=>..., 'mime'=>...]
    public function invalidate(string $token): void;
    public function cleanup_expired(): void;   // via adhoc task
}
```

Storage: Moodle Cache API (application-level). Token kann nach Verarbeitung sofort invalidiert werden; Temp-Datei wird dabei gelöscht.

### 4.3 Erweiterung `ai_send_message`

Neuer optionaler Parameter:

```
attachments: JSON-Array von Attachment-Tokens + Typ-Hint
  z.B.: [{"token": "tok_abc", "type": "image"}, {"token": "tok_def", "type": "pdf"}]
```

**Im PHP-Handler, vor dem LLM-Aufruf:**

- Für jedes `type: 'pdf'`: Token auflösen → Text extrahieren → in die user-Nachricht injizieren (§4.5)
- Für jedes `type: 'image'`: Token bleibt als Referenz erhalten, wird in Thread-Metadata gespeichert; LLM bekommt nur die Info "Bild angehängt: datei.jpg (Token: tok_abc)" als Texthinweis in der Nachricht

### 4.4 Erweiterung `booking.update_option` Skill

Neuer optionaler Input-Parameter:

```json
"image_token": {
  "type": "string",
  "description": "Attachment token from a previously uploaded image file. When provided, saves the image as the booking option header image.",
  "required": false
}
```

**In `execute()`:**

```php
$imagetoken = trim((string)($input['image_token'] ?? ''));
if ($imagetoken !== '') {
    $tokensvc = new attachment_token_service();
    $attachment = $tokensvc->resolve($imagetoken, $userid, $contextid);
    // MIME-Check: muss image/* sein
    // Draft File Area erstellen, Datei hineinkopieren
    $draftitemid = file_get_unused_draft_itemid();
    $fs = get_file_storage();
    $fs->create_file_from_pathname([
        'contextid' => \context_user::instance($userid)->id,
        'component' => 'user',
        'filearea'  => 'draft',
        'itemid'    => $draftitemid,
        'filepath'  => '/',
        'filename'  => basename($attachment['path']),
    ], $attachment['path']);
    // Permanent speichern in bookingoptionimage
    file_save_draft_area_files($draftitemid, $optioncontextid, 'mod_booking', 'bookingoptionimage', $optionid);
    $tokensvc->invalidate($imagetoken);
}
```

Kein neuer Skill nötig — der bestehende `booking.update_option` Skill bekommt einen Parameter.

### 4.5 PDF-Text-Extraktion

**Service: `pdf_text_extractor`**

```php
class pdf_text_extractor {
    const MAX_CHARS = 15000;   // ~3.750 Token

    public function extract(string $filepath): string;
    public function is_available(): bool;   // prüft pdftotext oder Fallback-Lib
}
```

**Stufe 1 — Shell (bevorzugt, wenn `pdftotext` verfügbar):**

```php
$output = [];
$path = escapeshellarg($filepath);
exec("pdftotext {$path} - 2>/dev/null", $output, $ret);
$text = implode("\n", $output);
```

**Stufe 2 — PHP-Fallback (`smalot/pdfparser`, Composer-Dependency):**

```php
$parser = new \Smalot\PdfParser\Parser();
$text   = $parser->parseFile($filepath)->getText();
```

**Injection in die user-Nachricht (in `ai_send_message`):**

```
Wenn die originale user-Nachricht lautet:
  "Erstelle aus diesem Dokument Fragen"

Wird injiziert als:
  "--- DOKUMENT: Seminarplan.pdf (Seite 1-5) ---
   Einführung in agiles Projektmanagement
   ...extrahierter Text...
   --- ENDE DOKUMENT ---
   
   Erstelle aus diesem Dokument Fragen"
```

Dies ist ein normaler String in der user-Nachricht. Kein spezieller LLM-API-Pfad.

**Token-Limit-Handling:** Wenn Text > `MAX_CHARS`: kürzen + Hinweis ans LLM ("Dokument wurde auf die ersten X Zeichen beschränkt"). Für sehr große Dokumente: User informieren, dass nur ein Teil verarbeitet wurde.

---

## 5. UI — Drag-and-Drop-Zone

**Strukturelle Änderung im Template:**

```html
<div id="booking-ai-input-area" class="booking-ai-dropzone">
  <!-- Thumbnails ausstehender Attachments -->
  <div id="booking-ai-attachment-tray" class="d-none"></div>
  
  <div class="booking-ai-input-row">
    <textarea id="booking-ai-message"></textarea>
    <!-- Mobile-Fallback: normaler File-Button -->
    <label class="booking-ai-attach-btn" title="Datei anhängen">
      <input type="file" accept="image/*,application/pdf" multiple class="d-none">
      📎
    </label>
    <button id="booking-ai-send">Senden</button>
  </div>
</div>
```

**JS-Flow:**

```js
// Drag-over: visuelle Hervorhebung
dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.classList.add('booking-ai-dropzone--active');
});

dropzone.addEventListener('drop', async (e) => {
    e.preventDefault();
    dropzone.classList.remove('booking-ai-dropzone--active');
    for (const file of [...e.dataTransfer.files]) {
        await uploadAndTrackAttachment(file);
    }
});

// uploadAndTrackAttachment():
// 1. Client-Validierung (Typ, Größe)
// 2. POST zu bookingextension_agent_ai_upload_attachment
// 3. thumbnail_html in #booking-ai-attachment-tray einfügen (mit X-Button)
// 4. {token, type, display_name} in pendingAttachments[] speichern
// 5. Bei Send: attachments-JSON aus pendingAttachments bauen, mitschicken
// 6. Nach erfolgreichem Send: pendingAttachments leeren, Tray leeren
```

**Was das LLM als Kontext bekommt (impliziter Texthinweis in der Nachricht):**

Für Bilder fügt der Server automatisch einen kurzen Texthinweis vor die Benutzernachricht:  
`"[Angehängtes Bild: header.jpg — Token: tok_abc]"`  
So kann das LLM im Skill-Aufruf das Token als `image_token`-Parameter setzen, ohne je die Bilddaten gesehen zu haben.

---

## 6. User Experience — Flows

### Flow 1: "Setze Headerbild"

```
1. User zieht "header.jpg" in den Chat
2. Thumbnail erscheint im Attachment-Tray oberhalb des Textfelds
3. User tippt: "Setze das als Header für Option 'Yogakurs Grundlagen'"
4. ai_send_message schickt message + [{token:"tok_abc", type:"image"}]
5. Server ergänzt message: "[Angehängtes Bild: header.jpg — Token: tok_abc]\nSetze das als Header..."
6. LLM plant: booking.update_option {optionid: 42, image_token: "tok_abc"}
7. Confirmation: User bestätigt
8. Skill: Token auflösen → Datei → bookingoptionimage file area
9. Token invalidiert, Temp-Datei gelöscht
10. Preview-Panel zeigt aktualisierte Buchungsoption mit neuem Header-Bild
```

### Flow 2: "Fragen aus PDF erstellen"

```
1. User zieht "Seminarinhalt.pdf" in den Chat
2. PDF-Icon erscheint im Attachment-Tray ("Seminarinhalt.pdf")
3. User tippt: "Mach mir 10 Quiz-Fragen aus diesem Dokument"
4. ai_send_message schickt message + [{token:"tok_def", type:"pdf"}]
5. Server: pdftotext → 4.200 Zeichen extrahiert
6. Injizierte Nachricht ans LLM: "--- DOKUMENT: Seminarinhalt.pdf ---\n...\n---\nMach mir 10 Quiz-Fragen..."
7. LLM antwortet mit 10 Fragen als normaler Antwort (sufficient response_type)
8. Token invalidiert, Temp-Datei gelöscht
```

---

## 7. Sicherheitsaspekte

| Risiko | Maßnahme |
|---|---|
| Arbitrary file upload | MIME-Whitelist + serverseitige Revalidierung via `finfo_file()` (nicht nur Content-Type Header) |
| Path traversal | Temp-Dateien nur in `make_temp_directory()`, Token-basierter Zugriff ohne Pfadexposition |
| Token-Missbrauch | Token enthält `userid + contextid` als Bestandteile der Signatur; anderer User kann Token nicht auflösen |
| Shell-Injection | `escapeshellarg()` für alle Shell-Aufrufe |
| Große Dateien | Max-Dateigröße-Limit + Timeout-Guard für pdftotext |
| Verwaiste Temp-Dateien | Adhoc-Task löscht Dateien ohne gültiges Token nach TTL |
| LLM-Datenschutz | Keine Bilddaten ans LLM; PDF-Text als normaler Kontext — gleiche Datenschutz-Implikation wie jeder andere Benutzertext |

---

## 8. Aufwandsschätzung

### Phase 1 — Upload-Infrastruktur (Fundament für beide Use-Cases)

| Aufgabe | Aufwand |
|---|---|
| `ai_upload_attachment` External WS | 1 Tag |
| `attachment_token_service` (Cache, Token-Lifecycle) | 0.5 Tag |
| Cleanup-Adhoc-Task | 0.25 Tag |
| JS Drop-Zone + Attachment-Tray + File-Button (Mobile) | 1 Tag |
| `ai_send_message` Extension (attachments-Parameter) | 0.5 Tag |

**Phase 1 gesamt: ~3.25 Tage**

### Phase 2 — Bild als Buchungsoption-Header

| Aufgabe | Aufwand |
|---|---|
| `booking.update_option` um `image_token`-Parameter erweitern | 1 Tag |
| Texthinweis-Injection für Bild-Token in user-Nachricht | 0.25 Tag |
| Preview: aktualisiertes Bild nach Skill-Ausführung zeigen | 0.5 Tag |
| Tests | 0.5 Tag |

**Phase 2 gesamt: ~2.25 Tage**

### Phase 3 — PDF-Text-Extraktion

| Aufgabe | Aufwand |
|---|---|
| `pdf_text_extractor` Service (pdftotext + PHP-Fallback) | 0.75 Tag |
| Token-Limit-Handling + Truncation-Hinweis | 0.25 Tag |
| Kontext-Injection in user-Nachricht vor LLM-Aufruf | 0.5 Tag |
| Tests | 0.5 Tag |

**Phase 3 gesamt: ~2 Tage**

### Gesamtübersicht

| Phase | Inhalt | Aufwand |
|---|---|---|
| 1 | Upload-Infrastruktur + JS Drop-Zone | ~3.25 Tage |
| 2 | Bild → Header-Bild via update_option | ~2.25 Tage |
| 3 | PDF → Text-Extraktion → LLM-Kontext | ~2 Tage |
| **Total** | | **~7.5 Tage** |

Phase 1 ist Pflichtfundament; Phasen 2 und 3 sind unabhängig voneinander umsetzbar.

---

## 9. Offene Fragen

1. **pdftotext Verfügbarkeit:** Soll das System einen Fehler zurückgeben wenn `pdftotext` und der PHP-Fallback nicht verfügbar sind, oder soll PDF-Upload dann komplett geblockt werden?

2. **Composer-Dependency:** `smalot/pdfparser` als PHP-Fallback — ist eine neue Composer-Dependency akzeptabel, oder soll ausschließlich auf `pdftotext` gesetzt werden?

3. **Bild-Token-Lebensdauer:** 10 Minuten TTL für den Token — ausreichend? User könnte ein Bild hochladen und dann 20 Minuten nachdenken bevor er die Nachricht schickt.

4. **Mehrere Bilder pro Nachricht:** Soll man mehrere Bilder gleichzeitig droppen können (z.B. für mehrere Optionen in einem Schritt), oder anfangs auf 1 Bild pro Nachricht begrenzen?

5. **Große PDFs:** Erste Version kürzt einfach auf `MAX_CHARS`. Für eine spätere Version: Embeddings-Pipeline (analog `explain_docs`), bei der der User gezielt Abschnitte abfragen kann, statt das gesamte Dokument auf einmal zu injizieren.
