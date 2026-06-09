# File Drop & Attachment — Implementierungsplan
**Datum:** 2026-06-08  
**Author:** Georg Maisser / Claude Code  
**Status:** Bereit zur Implementierung — kein Code verändert

---

## 1. Ziele & Designentscheidungen

**Zwei Use-Cases:**

| Use-Case | Pfad |
|---|---|
| Bild droppen → Header einer Buchungsoption setzen | Upload → Token → Skill löst Token auf → Moodle File API |
| PDF droppen → LLM arbeitet mit dem Inhalt | Upload → PHP-Textextraktion → Text in user-Nachricht injiziert |

**Leitprinzip: vollständige Skill-Agnostik**  
Das Framework (Orchestrator, Runtime, conversation_store) weiß nichts von Attachments. Jeder Skill kann in seinem Schema ein `attachment_token`-Feld deklarieren und es selbst auflösen. Es gibt keine Sonderbehandlung einzelner Skills im Framework. Neue Skills eines unbekannten Plugins können dasselbe Token-System nutzen.

**Das LLM bekommt niemals Binärdaten.**  
- Bilder: LLM sieht nur einen Texthinweis `[Anhang: datei.jpg (Token: tok_abc)]`
- PDFs: LLM sieht den extrahierten Text als normalen String

---

## 2. Betroffene Dateien — Vollständige Inventur

### 2.1 Neue Dateien (zu erstellen)

| Datei | Klasse | Zweck |
|---|---|---|
| `classes/external/ai_upload_attachment.php` | `ai_upload_attachment` | Neuer WS-Endpunkt für Upload |
| `classes/local/wbagent/services/attachment/attachment_token_service.php` | `attachment_token_service` | Token-Lifecycle (erstellen, auflösen, invalidieren) |
| `classes/local/wbagent/services/attachment/attachment_processor.php` | `attachment_processor` | PDF-Text-Extraktion + Nachricht augmentieren |
| `classes/local/wbagent/services/attachment/pdf_text_extractor.php` | `pdf_text_extractor` | PHP: pdftotext shell + smalot/pdfparser Fallback |
| `classes/task/cleanup_attachment_temp_files_adhoc.php` | `cleanup_attachment_temp_files_adhoc` | Temp-Dateien nach TTL löschen |

### 2.2 Geänderte Dateien

| Datei | Was ändert sich |
|---|---|
| `classes/external/ai_send_message.php` | Neuer Parameter `attachments` (PARAM_RAW, optional); Aufruf `attachment_processor::augment_message()` vor `store->add_message()` |
| `db/services.php` | Neuer WS-Eintrag `bookingextension_agent_ai_upload_attachment` |
| `lang/en/bookingextension_agent.php` | Neue String-Keys für Upload-Fehler / Status |
| `lang/de/bookingextension_agent.php` | Übersetzungen |
| `amd/src/aiinstructions.js` | Drop-Zone, Attachment-Tray, `sendMessage()` erweitert |
| Template (HTML des Chat-Widgets) | Attachment-Tray-Container, File-Input-Button |

### 2.3 Skill-seitige Änderungen (exemplarisch — nicht im Framework)

Wenn `booking.update_option` Bilder unterstützen soll:
- `mod_booking/classes/local/wbagent/options/skills/update_option_skill.php` → Schema + execute() erweitern

Das ist ein **Beispiel**, nicht Teil des Framework-Werks.

---

## 3. Neue Datei: `attachment_token_service.php`

**Pfad:** `classes/local/wbagent/services/attachment/attachment_token_service.php`  
**Namespace:** `bookingextension_agent\local\wbagent\services\attachment`

### Klasse: `attachment_token_service`

**Storage:** Moodle Cache API, `cache_definition` in `db/caches.php` (neue Einträge nötig), application-level, key = Token-String, TTL = 600s.

**Methoden:**

```php
public function create(int $userid, int $contextid, string $tmppath, string $mime, string $filename): string
```
- Generiert Token: `sha1($userid . ':' . $contextid . ':' . $tmppath . ':' . microtime(true))`
- Schreibt in Cache: `['userid'=>$userid, 'contextid'=>$contextid, 'path'=>$tmppath, 'mime'=>$mime, 'filename'=>$filename, 'expires'=>time()+600]`
- Gibt Token zurück

```php
public function resolve(string $token, int $userid, int $contextid): array
```
- Liest aus Cache
- Prüft `expires`, `userid`, `contextid` (Security: anderer User kann Token nicht auflösen)
- Wirft `\moodle_exception('attachment_token_invalid')` wenn ungültig
- Gibt `['path'=>..., 'mime'=>..., 'filename'=>...]` zurück

```php
public function invalidate(string $token): void
```
- Löscht Cache-Eintrag
- Löscht physische Temp-Datei via `unlink()`

```php
public function cleanup_expired(): void
```
- Iteriert alle Cache-Keys, löscht verwaiste Temp-Dateien
- Aufgerufen aus `cleanup_attachment_temp_files_adhoc::execute()`

**Temp-Verzeichnis:** `make_temp_directory('bookingextension_agent/uploads')`

**Neue DB-Cache-Definition** in `db/caches.php`:
```php
$definitions = [
    'attachment_tokens' => [
        'mode' => cache_store::MODE_APPLICATION,
        'ttl'  => 600,
    ],
];
```

---

## 4. Neue Datei: `pdf_text_extractor.php`

**Pfad:** `classes/local/wbagent/services/attachment/pdf_text_extractor.php`  
**Namespace:** `bookingextension_agent\local\wbagent\services\attachment`

### Klasse: `pdf_text_extractor`

**Konstanten:**
```php
const MAX_CHARS = 15000;   // ~3750 Token
```

**Methoden:**

```php
public function is_available(): bool
```
- Gibt `true` zurück wenn `pdftotext` im PATH verfügbar ODER `smalot/pdfparser` geladen ist

```php
public function extract(string $filepath): string
```
1. Versucht `pdftotext -enc UTF-8 <filepath> -` via `exec()`
2. Fallback: `\Smalot\PdfParser\Parser->parseFile()->getText()`
3. Fallback (wenn beide nicht verfügbar): `throw new \moodle_exception('ai_pdf_extraction_unavailable')`
4. Text wird auf `MAX_CHARS` gekürzt; wenn gekürzt: `\n\n[Dokument auf erste ~3750 Token beschränkt]` anhängen

**Sicherheit:**
- `escapeshellarg()` für alle Shell-Aufrufe
- `set_time_limit(30)` vor Shell-Aufruf
- `proc_open()` statt `exec()` wenn `exec()` disabled: Fallback auf PHP-Parser

---

## 5. Neue Datei: `attachment_processor.php`

**Pfad:** `classes/local/wbagent/services/attachment/attachment_processor.php`  
**Namespace:** `bookingextension_agent\local\wbagent\services\attachment`

### Klasse: `attachment_processor`

**Zweck:** Nimmt die user-Nachricht + Attachment-Token-Array, verarbeitet alle Attachments und gibt die augmentierte Nachricht zurück. Dies ist der **einzige Punkt**, an dem Attachments in den Nachrichtenfluss eingreifen.

**Methoden:**

```php
public function augment_message(string $message, array $attachments, int $userid, int $contextid): string
```

`$attachments` = `[['token'=>'tok_abc', 'type'=>'image'], ['token'=>'tok_def', 'type'=>'pdf'], ...]`

**Für jedes Attachment:**

→ **`type: 'image'`:**
```
Prepend to message:
"[Anhang: {filename} — Attachment-Token: {token}]\n"
```
Das LLM sieht den Token-String, kann ihn als skill-Input übergeben.
Token bleibt im Cache; der Skill löst ihn später auf.

→ **`type: 'pdf'`:**
```php
$extractor = new pdf_text_extractor();
$text = $extractor->extract($resolved['path']);
// Token sofort invalidieren und Datei löschen — PDF wird nicht nochmal als Datei gebraucht
$tokensvc->invalidate($token);
// Augmentieren:
"--- DOKUMENT: {filename} ---\n{$text}\n--- ENDE DOKUMENT ---\n\n{$message}"
```
Der Text ersetzt die Datei vollständig; Token wird verbraucht.

**Rückgabe:** Augmentierte Nachricht als String (drop-in für das existierende `$message`).

---

## 6. Neue Datei: `ai_upload_attachment.php`

**Pfad:** `classes/external/ai_upload_attachment.php`  
**Namespace:** `bookingextension_agent\external`  
**Klasse:** `ai_upload_attachment extends external_api`

### `execute_parameters()`
```php
new external_function_parameters([
    'contextid' => new external_value(PARAM_INT, 'Module context id.'),
])
```
Die Datei selbst kommt als `$_FILES['file']` (multipart POST) — nicht über externe Moodle-Parameter.

### `execute(int $contextid): array`

1. `require_sesskey()`
2. `validate_parameters()`
3. Authorization: `$authz->require_use_capability($USER->id, $contextid)` — gleiche Capability wie `ai_send_message`
4. `$_FILES['file']` auslesen; wenn leer: Error
5. MIME-Validierung via `finfo_file()` (Server-seitig, nicht nur Content-Type):
   - Erlaubt: `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `application/pdf`
   - Bei unbekanntem Typ: `['success'=>false, 'message'=>get_string('ai_upload_invalid_type')]`
6. Größenprüfung: max konfigurierbar via `get_config('bookingextension_agent', 'max_upload_bytes')`, Default 10 MB Bild / 20 MB PDF
7. Temp-Datei verschieben: `move_uploaded_file($_FILES['file']['tmp_name'], $tmpdir . '/' . $safename)`
   - `$safename` = `uniqid('wbagent_', true) . '.' . $ext` (keine Nutzereingaben im Dateinamen)
8. Token erstellen via `attachment_token_service::create()`
9. Thumbnail für Bilder:
   - GD: `imagecreatefromjpeg/png/webp()` → resize auf max 120×80 → base64-PNG
   - `$thumbhtml = '<img src="data:image/png;base64,{$b64}" class="booking-ai-thumb">'`
10. PDF: kein Thumbnail, nur Icon + Dateiname
11. Rückgabe:
```php
[
    'success'          => true,
    'attachment_token' => $token,
    'attachment_type'  => $type,   // 'image' | 'pdf'
    'display_name'     => $originalname,
    'thumbnail_html'   => $thumbhtml,
    'message'          => '',
]
```

### `execute_returns()`
```php
new external_single_structure([
    'success'          => new external_value(PARAM_BOOL),
    'attachment_token' => new external_value(PARAM_ALPHANUMEXT, '', VALUE_OPTIONAL, ''),
    'attachment_type'  => new external_value(PARAM_ALPHA, '', VALUE_OPTIONAL, ''),
    'display_name'     => new external_value(PARAM_TEXT, '', VALUE_OPTIONAL, ''),
    'thumbnail_html'   => new external_value(PARAM_RAW, '', VALUE_OPTIONAL, ''),
    'message'          => new external_value(PARAM_TEXT, '', VALUE_OPTIONAL, ''),
])
```

---

## 7. Änderung: `db/services.php`

Neuer Block nach dem letzten bestehenden Eintrag (aktuell Zeile ~108):

```php
'bookingextension_agent_ai_upload_attachment' => [
    'classname'     => 'bookingextension_agent\external\ai_upload_attachment',
    'methodname'    => 'execute',
    'description'   => 'Upload an attachment for use with the AI agent.',
    'type'          => 'write',
    'capabilities'  => 'bookingextension/agent:useaiinstructions',
    'ajax'          => true,
    'loginrequired' => true,
],
```

Außerdem in den Service-Bundle `'Booking AI Agent'` aufnehmen (aktuell Zeile ~120 im `$functions`-Array).

---

## 8. Änderung: `classes/external/ai_send_message.php`

### `execute_parameters()` — Zeile 66–77

Neuer optionaler Parameter hinzufügen:
```php
'attachments' => new external_value(
    PARAM_RAW,
    'Optional JSON array of attachment tokens: [{"token":"tok_abc","type":"image"}, ...]',
    VALUE_DEFAULT,
    '[]'
),
```

### `execute()` — Zeile 87

Signatur erweitern:
```php
public static function execute(int $contextid, string $message, int $threadid = 0, string $attachments = '[]'): array
```

**Neuer Block zwischen Zeile 187 (nach Privacy-Precheck) und Zeile 190 (vor `store->add_message`):**

```php
// Process attachments: PDF text injection + image token hints.
$attachmentlist = json_decode($attachments, true);
if (!empty($attachmentlist) && is_array($attachmentlist)) {
    $processor = new attachment_processor();
    $message = $processor->augment_message($message, $attachmentlist, (int)$USER->id, $contextid);
}

$store->add_message($threadid, 'user', $message);
```

**Kein weiterer Eingriff nötig.** Die augmentierte `$message` enthält für PDFs den injizierten Text, für Bilder den Token-Hinweis. Der Rest des Flows (`run_loop`, Orchestrator, LLM-Aufruf) bleibt unverändert.

### `validate_parameters()` — Zeile 92–95

`attachments` zum Params-Array hinzufügen.

---

## 9. Neue Datei: `cleanup_attachment_temp_files_adhoc.php`

**Pfad:** `classes/task/cleanup_attachment_temp_files_adhoc.php`  
**Klasse:** `cleanup_attachment_temp_files_adhoc extends \core\task\adhoc_task`

`execute()`:
- `attachment_token_service::cleanup_expired()` aufrufen
- Temp-Verzeichnis scannen: alle Dateien älter als 600s löschen (als Sicherheitsnetz auch ohne Cache-Kontext)

**Scheduling:** Alle 10 Minuten via `db/tasks.php` (scheduled task), alternativ nur on-demand queued.  
Empfehlung: `db/tasks.php` scheduled task alle 15 Minuten; minimaler Overhead.

---

## 10. Änderungen in `db/caches.php`

Wenn noch keine `db/caches.php` existiert, neu erstellen:
```php
defined('MOODLE_INTERNAL') || die();
$definitions = [
    'attachment_tokens' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'ttl'        => 600,
        'datasource' => null,
    ],
];
```

---

## 11. Neue Lang-Strings

**`lang/en/bookingextension_agent.php`** (nach bestehenden Einträgen):
```php
$string['ai_upload_invalid_type']         = 'File type not supported. Please upload an image (JPEG, PNG, WebP) or PDF.';
$string['ai_upload_file_too_large']       = 'File is too large. Maximum size: {$a}.';
$string['ai_upload_no_file']              = 'No file received. Please try again.';
$string['ai_pdf_extraction_unavailable']  = 'PDF text extraction is not available on this server.';
$string['ai_pdf_truncated']               = 'Document was truncated to the first ~{$a} characters.';
$string['ai_attachment_token_invalid']    = 'The attachment reference is invalid or has expired (max. 10 minutes). Please re-upload.';
```

**`lang/de/bookingextension_agent.php`:**
```php
$string['ai_upload_invalid_type']         = 'Dateiformat nicht unterstützt. Bitte ein Bild (JPEG, PNG, WebP) oder PDF hochladen.';
$string['ai_upload_file_too_large']       = 'Datei zu groß. Maximale Größe: {$a}.';
$string['ai_upload_no_file']              = 'Keine Datei empfangen. Bitte erneut versuchen.';
$string['ai_pdf_extraction_unavailable']  = 'PDF-Textextraktion ist auf diesem Server nicht verfügbar.';
$string['ai_pdf_truncated']               = 'Dokument wurde auf die ersten ~{$a} Zeichen gekürzt.';
$string['ai_attachment_token_invalid']    = 'Der Datei-Verweis ist ungültig oder abgelaufen (max. 10 Minuten). Bitte erneut hochladen.';
```

---

## 12. JavaScript: `amd/src/aiinstructions.js`

### 12.1 Neue globale Variable (nach Zeile 34)

```js
let pendingAttachments = [];   // [{token, type, displayName}]
```

### 12.2 Neue Funktion: `handleFileDrop(files)`

Aufgerufen nach Drop-Event und File-Input-Change.

```js
const handleFileDrop = async (files) => {
    for (const file of [...files]) {
        // 1. Client-Validierung
        const allowedTypes = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
        if (!allowedTypes.includes(file.type)) {
            showUploadError(file.name + ': ' + M.str.bookingextension_agent.ai_upload_invalid_type);
            continue;
        }
        // 2. Lade-Indikator im Tray
        const trayItem = addAttachmentTrayPlaceholder(file.name);
        // 3. FormData + XMLHttpRequest zu bookingextension_agent_ai_upload_attachment
        const formData = new FormData();
        formData.append('contextid', currentContextId);
        formData.append('file', file);
        formData.append('sesskey', M.cfg.sesskey);
        try {
            const resp = await uploadFileToWs(formData);
            if (resp.success) {
                // Token merken, Thumbnail eintragen
                pendingAttachments.push({
                    token: resp.attachment_token,
                    type: resp.attachment_type,
                    displayName: resp.display_name,
                });
                updateAttachmentTrayItem(trayItem, resp.thumbnail_html, resp.display_name);
            } else {
                removeTrayItem(trayItem);
                showUploadError(resp.message);
            }
        } catch (e) {
            removeTrayItem(trayItem);
            Notification.exception(e);
        }
    }
};
```

**`uploadFileToWs(formData)`**: Nutzt `fetch()` (kein Moodle Ajax hier, da multipart), direkt gegen `M.cfg.wwwroot + '/lib/ajax/service.php'` — oder besser: eigenen Moodle-Upload-Endpunkt.

**Hinweis:** Moodle-WebServices unterstützen keine direkten File-Uploads über `Ajax.call()`. Hier muss `fetch()` mit `FormData` gegen einen Moodle-seitigen Controller oder den WS-Handler direkt genutzt werden. Alternative: Moodle Draft-File-Upload-API via `\repository_upload\privacy\provider` oder direkter `fetch()` gegen eine eigene PHP-Seite (außerhalb der External API). Die sauberste Moodle-Lösung ist eine eigene `upload.php`-Seite in `classes/external/` als separater HTTP-Endpunkt (kein WS-Framework).

**→ Empfehlung: Eigene `upload.php` im Plugin-Root** (`/local/bookingextension_agent/upload.php` oder innerhalb der Booking-Ordnerstruktur), die `require_sesskey()` + `require_login()` sichert, dann die Datei verarbeitet und JSON zurückgibt. Keine External-API-Klasse, aber sicherer als raw POST.

### 12.3 Drop-Zone-Events

Folgende Events an `#booking-ai-input-area` hängen (analog zum bestehenden Resize-Handler, aber für Files):

```js
const setupDropZone = (inputArea) => {
    inputArea.addEventListener('dragover', (e) => {
        if ([...e.dataTransfer.types].includes('Files')) {
            e.preventDefault();
            inputArea.classList.add('booking-ai-dropzone--active');
        }
    });
    inputArea.addEventListener('dragleave', () => {
        inputArea.classList.remove('booking-ai-dropzone--active');
    });
    inputArea.addEventListener('drop', (e) => {
        inputArea.classList.remove('booking-ai-dropzone--active');
        if (e.dataTransfer.files.length > 0) {
            e.preventDefault();
            handleFileDrop(e.dataTransfer.files);
        }
    });
};
```

### 12.4 Erweiterung `sendMessage()`

Aktuell (Zeile ~1982):
```js
Ajax.call([{
    methodname: 'bookingextension_agent_ai_send_message',
    args: { contextid: currentContextId, message: sanitizedMessage, threadid: currentThreadId },
}])
```

Erweitern zu:
```js
Ajax.call([{
    methodname: 'bookingextension_agent_ai_send_message',
    args: {
        contextid:   currentContextId,
        message:     sanitizedMessage,
        threadid:    currentThreadId,
        attachments: JSON.stringify(
            pendingAttachments.map(a => ({ token: a.token, type: a.type }))
        ),
    },
}])
```

Nach erfolgreichem Response:
```js
pendingAttachments = [];
clearAttachmentTray();
```

### 12.5 Attachment-Tray HTML-Manipulation

```js
const clearAttachmentTray = () => {
    const tray = document.getElementById('booking-ai-attachment-tray');
    if (tray) { tray.innerHTML = ''; tray.classList.add('d-none'); }
};
```

---

## 13. Template-Änderungen (HTML)

Der Chat-Widget-Template (Mustache oder PHP-Template) bekommt:

```html
<!-- Innerhalb #booking-ai-input-area, VOR der Textarea -->
<div id="booking-ai-attachment-tray" class="booking-ai-attachment-tray d-none">
    <!-- JS füllt hier Thumbnails ein -->
</div>

<!-- Textarea und Buttons existieren bereits -->

<!-- File-Input-Button (sichtbar auf Mobile) -->
<label class="booking-ai-attach-label ms-1" title="{{#str}}ai_attach_file, bookingextension_agent{{/str}}">
    <input type="file" id="booking-ai-file-input"
           accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
           multiple class="d-none">
    <span class="icon-attachment" aria-hidden="true">📎</span>
</label>
```

---

## 14. Skill-Agnostik: Verwendung für Skill-Autoren

Ein Skill, der ein Bild verarbeiten will, deklariert in seinem Schema:
```php
'image_token' => [
    'type'        => 'string',
    'description' => 'Attachment token for an uploaded image file.',
    'required'    => false,
],
```

In `execute()`:
```php
use bookingextension_agent\local\wbagent\services\attachment\attachment_token_service;

$imagetoken = trim((string)($input['image_token'] ?? ''));
if ($imagetoken !== '') {
    $tokensvc = new attachment_token_service();
    $attachment = $tokensvc->resolve($imagetoken, $userid, $contextid);
    // $attachment = ['path' => '/tmp/...', 'mime' => 'image/jpeg', 'filename' => 'header.jpg']
    // ... Datei in Moodle File API speichern ...
    $tokensvc->invalidate($imagetoken);
}
```

**Das Framework (Orchestrator, Runtime, Executor) weiß nichts davon.** Jeder Skill entscheidet selbst, was er mit einem Token macht.

---

## 15. Aufwandsschätzung

### Phase 1 — Server-Fundament (alle Phasen abhängig davon)

| Aufgabe | Datei(en) | Aufwand |
|---|---|---|
| `db/caches.php` anlegen | `db/caches.php` | 0.25 T |
| `attachment_token_service` implementieren | neue Serviceklasse | 0.5 T |
| `pdf_text_extractor` implementieren (shell + PHP-Fallback) | neue Serviceklasse | 0.75 T |
| `attachment_processor` implementieren | neue Serviceklasse | 0.5 T |
| Upload-Endpunkt implementieren (PHP-Seite + Validierung + Token + Thumbnail) | `ai_upload_attachment.php` o.ä. | 1.0 T |
| `ai_send_message` erweitern (Parameter + augment_message) | `ai_send_message.php` | 0.5 T |
| `db/services.php` + `db/tasks.php` + Cleanup-Task | 3 kleine Dateien | 0.5 T |
| Lang-Strings (en + de) | lang-Dateien | 0.25 T |

**Phase 1 gesamt: ~4.25 Tage**

### Phase 2 — Frontend

| Aufgabe | Datei(en) | Aufwand |
|---|---|---|
| Drop-Zone JS + Events | `aiinstructions.js` | 0.75 T |
| `handleFileDrop()` + `uploadFileToWs()` | `aiinstructions.js` | 0.75 T |
| `sendMessage()` Erweiterung + pendingAttachments | `aiinstructions.js` | 0.5 T |
| Attachment-Tray HTML + CSS | Template + CSS | 0.5 T |
| Mobile File-Input-Button | Template | 0.25 T |

**Phase 2 gesamt: ~2.75 Tage**

### Phase 3 — Erster Skill (exemplarisch: booking.update_option + Bild)

| Aufgabe | Datei(en) | Aufwand |
|---|---|---|
| Schema-Erweiterung + execute()-Logik | `update_option_skill.php` | 1.0 T |
| Moodle Draft-File-API + bookingoptionimage-Speicherung | (innerhalb Skill) | 0.5 T |
| Test | | 0.5 T |

**Phase 3 gesamt: ~2 Tage**

### Gesamtübersicht

| Phase | Aufwand |
|---|---|
| Phase 1: Server-Fundament | ~4.25 T |
| Phase 2: Frontend | ~2.75 T |
| Phase 3: Erster Skill (exemplarisch) | ~2.0 T |
| **Total** | **~9 Tage** |

---

## 16. Offene Entscheidungen vor Implementierungsstart

1. **Upload-Endpunkt-Typ:**  
   Moodle External API (`external_api`) unterstützt keine direkten Multipart-File-Uploads. Optionen:
   - **A) Eigene `upload.php` im Plugin** (mit `require_sesskey()` + `require_login()`): sauber, einfach  
   - **B) Moodle Repository Upload API** (`/repository/upload/lib.php`): komplexer, aber Moodle-konform  
   - **Empfehlung: Option A** — minimal, sicher, klar testbar

2. **Composer-Dependency für PDF-Fallback:**  
   `smalot/pdfparser` als Fallback wenn `pdftotext` nicht verfügbar. Ist eine neue Composer-Dependency akzeptabel?  
   Alternative: Kein PHP-Fallback, nur Fehlermeldung wenn `pdftotext` nicht gefunden.

3. **Token-TTL:**  
   10 Minuten (600s) — ausreichend? Falls User längere Pausen macht: Token abgelaufen → Hinweis "Bitte Datei erneut hochladen". Alternativ: 30 Minuten (1800s).

4. **Max. Attachments pro Nachricht:**  
   Empfehlung: 3 Bilder **oder** 1 PDF pro Nachricht. Oder einfach: alles erlaubt, Größenlimit als einzige Schranke?
