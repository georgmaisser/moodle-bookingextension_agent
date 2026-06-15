# One-Click-AI-Setup — Trial- & Provider-Onboarding

**Status:** 🟢 In Umsetzung — Provisioning-Scheibe (Schritt 1–4) implementiert; UI (5–6) + Python-Änderungen offen
**Angelegt:** 2026-06-15
**Aktualisiert:** 2026-06-15 — O1/O2 entschieden; G1 (alle drei Modell-Aliasse im Trial) und G2
(bestehenden Key zurückgeben) entschieden; R1 verifiziert (OpenAI erlaubt Endpoint-Override).
Backend-Provisioning umgesetzt — siehe **§8 Umsetzungsstatus**.
**Thema:** Geführtes, voraussetzungs-adaptives Setup, das Nutzer:innen vom „nichts konfiguriert"-Zustand
zu einem **voll funktionsfähigen** Agent bringen — ohne stille Abweichungen.

---

## Zweck

Heute verspricht der Trial-Button ein One-Click-Setup, liefert es aber nicht: Es wird **kein**
LiteLLM-Key erzeugt und **keine** Provider-Instanz angelegt. Dieses Dokument beschreibt den
Ist-Zustand exakt (mit Bruchstellen), das Zielbild und die konkrete Umsetzung in klar abgegrenzten
Schritten. Leitidee: **Je nach aktuellen Voraussetzungen (welcher AI-Provider ist installiert?)
werden Nutzer:innen deterministisch zu einem lauffähigen System geführt.**

---

## 1. Ist-Zustand (verifiziert)

### 1.1 Der Trial-Button erzeugt keinen Key

`classes/external/request_trial_key.php:59-91` macht **nur**:
1. Readiness-/Capability-Checks (`moodle/site:config`),
2. legt eine zufällige **Nonce** im Cache an (`cache 'trialnonce'`),
3. gibt sofort `success=true` + „token received" zurück.

→ Das JS (`amd/src/aiinstructions.js:2475-2492`) zeigt daraufhin die grüne Erfolgsmeldung
(„neuer Key erstellt"). **Tatsächlich existiert kein Key** — daher taucht in LiteLLM nichts auf.
Das deckt sich exakt mit der Beobachtung.

### 1.2 „Yes, activate AI" findet keinen Provider

`classes/external/activate_trial_context.php:63-145`:
- **aktiviert nur bestehende** Provider-Instanzen (`aiprovider_wunderbyte\provider`, oder eine
  `aiprovider_openai\provider`-Instanz mit Name `Wunderbyte`) — `enable_provider_instance()`,
- legt **keine** Instanz an und hinterlegt **keinen** Key,
- prüft danach `get_runtime_provider_status()` → `provideractive`.

Da keine Instanz existiert, ist `provideractive=false` → Rückgabe
„There is no active text generation provider" (`aiready_check_provider_active_todo`).
Erst danach würden die Kurs-/Modul-Toggles (`course.enableaitools`, `course_modules.enableaitools`)
gesetzt.

### 1.3 Der echte Key-Minter ist nicht verdrahtet

`classes/local/wbagent/wunderbyte_trial_endpoint.py` ist ein **eigenständiger FastAPI-Service**:
- Route **POST `/api/moodle-trial`** (`:167`) mit `{wwwroot, nonce}`,
- Origin-Check per Back-Channel auf `{wwwroot}/mod/booking/bookingextension/agent/trial_challenge.php?token={nonce}`
  (`:74-97`, **derzeit deaktiviert**, `return True` in `:80`),
- mintet Key via LiteLLM `POST /key/generate` (`:128-162`): `key_alias=wunderbyte-privat-{site_id}`,
  `max_budget`, `budget_duration`, `allowed_routes=["llm_api_routes","/key/info"]`,
- **gibt zurück:** `{apikey, endpoint, model}` (`endpoint` = LiteLLM-Base-URL, `model` = Trial-Modell-Alias).

**Lücke:** Kein PHP-Code ruft diesen Endpoint auf. Die Kette **Nonce → Key → Provider-Instanz**
bricht nach Schritt „Nonce" ab. `trial_challenge.php` existiert (echo-back der Nonce), wird aber
nie genutzt.

### 1.4 Vorhandene Bausteine (nutzbar)

- **core_ai Manager** (`ai/classes/manager.php`):
  - `create_provider_instance(string $classname, string $name, bool $enabled=false, ?array $config=null, ?array $actionconfig=null): provider` (`:405-430`),
    schreibt nach Tabelle `ai_providers` (`provider`, `name`, `enabled`, `config` JSON, `actionconfig` JSON).
  - `get_provider_instances(?array $filter): array`, `enable_provider_instance()`, `update_provider_instance()`.
- **aiprovider_wunderbyte** ist **installiert** (`ai/provider/wunderbyte/`): Config-Feld `apikey`;
  Endpoint pro Action in `actionconfig['settings']['endpoint']`; Usage via GET `/key/info`.
  Capability `aiprovider/wunderbyte:viewusage` definiert (`ai/provider/wunderbyte/db/access.php:31-37`).
- **aiprovider_openai** ist installiert (`ai/provider/openai/`): Config `apikey` (+ optional `orgid`).
- **Konstanten** (`aiready.php`): `WB_PROVIDER_CLASS='aiprovider_wunderbyte\provider'`,
  `WB_LEGACY_PROVIDER_CLASS='aiprovider_openai\provider'`, `WB_LEGACY_PROVIDER_NAME='Wunderbyte'`.
  → Es existiert bereits das Muster „OpenAI-Instanz mit Name *Wunderbyte*, gegen den LiteLLM-Proxy".

---

## 2. Zielbild (One-Click, voraussetzungs-adaptiv)

Beim Klick auf **„I want to start my free trial now"** läuft eine **Vorab-Prüfung** und es wird eine
**Todo-/Status-Liste** angezeigt, die die Nutzer:innen deterministisch zum Ziel führt.

### 2.1 Entscheidungsbaum

```
Klick „Start free trial"
        │
        ▼
[Check] Ist aiprovider_wunderbyte installiert?
        │
   ┌────┴─────────────────────────────────────────────┐
   │ JA                                                │ NEIN
   ▼                                                   ▼
✓ Todo: „Wunderbyte-Provider installiert"       [Check] Ist irgendein anderer
   │                                              text-generation-Provider installiert?
   │                                                   │
   │                                         ┌─────────┴───────────┐
   │                                         │ JA                  │ NEIN
   │                                         ▼                     ▼
   │                              Hinweis + 2 Optionen:     Hinweis:
   │                              (A) Wunderbyte-Provider    „Bitte installieren Sie für beste
   │                                  installieren (Link)     Ergebnisse den Wunderbyte-Provider:
   │                              (B) Mit Standard-Provider    <github-link>"
   │                                  fortfahren              (kein Fortfahren möglich, bis ein
   │                                  (geringerer Skill-       Provider da ist)
   │                                  Umfang)
   ▼                                         │
[Provision] Trial-Key holen + Instanz       ▼ (bei B)
  am WUNDERBYTE-Provider anlegen      [Provision] Trial-Key holen + Instanz
   │                                   am OPENAI-Provider anlegen (Endpoint→LiteLLM)
   └───────────────┬───────────────────────────┘
                   ▼
        [Activate] Provider-Instanz enablen
                   + Kurs-/Modul-Toggles setzen
                   ▼
        [Verify] get_runtime_provider_status() == aktiv?
                   ▼
        ✓ „AI ist einsatzbereit" — Panel neu laden
```

**Empfohlener Default:** Wenn `aiprovider_wunderbyte` fehlt, aber installierbar ist, soll der
Wunderbyte-Pfad (höchster Skill-Umfang) **empfohlen** werden; „Standard-Provider" ist die
bewusste Abkürzung mit dokumentierter Einschränkung.

### 2.2 Begründung „geringerer Skill-Umfang"

Der volle Funktionsumfang nutzt Wunderbyte-spezifische core_ai-Actions
(`planner_decide`, `generate_agent_reply`, …), die der generische OpenAI-Provider nicht als
eigene Actions bereitstellt. Der Standard-Pfad fällt auf generische Text-Actions zurück → weniger
Skills. (→ **Offene Frage O3:** exakte Skill-Differenz dokumentieren/messen.)

---

## 3. Umsetzung — Schritte & Code

### Schritt A — Provider-Detection als WS „setup status"

**Neu:** `classes/external/get_setup_status.php` (oder Erweiterung von `request_trial_key`) liefert
eine strukturierte Checkliste statt nur einer Nonce:

```php
return [
  'wunderbyte_provider_installed' => \core_component::get_plugin_directory('aiprovider','wunderbyte') !== null,
  'other_textgen_provider_available' => /* core_ai: existiert ein Provider, der generate_text kann */,
  'recommended_path' => 'wunderbyte' | 'openai' | 'install_wunderbyte',
  'install_url' => 'https://github.com/Wunderbyte-GmbH/moodle-aiprovider_wunderbyte',
  'checks' => [ {key,label,status: done|todo|info, actionurl?} ... ],
];
```

- Plugin-Installations-Check: `\core_component::get_plugin_directory('aiprovider','wunderbyte')`
  bzw. `array_key_exists('wunderbyte', \core_component::get_plugin_list('aiprovider'))`.
- „anderer Provider verfügbar": über `\core_ai\manager` prüfen, ob ein **anderer** Provider-Typ
  (nicht wunderbyte) `generate_text`/`generate_agent_reply` unterstützt bzw. installiert ist.

> Diese Detection ist die Grundlage der Todo-Liste; sie ersetzt das heutige „sofort success".

### Schritt B — Nonce → Key-Exchange in PHP (die fehlende Verdrahtung)

**Neu:** Service-Klasse `classes/local/wbagent/services/trial/trial_provisioner.php` mit
`provision(int $contextid, string $strategy): array` (`strategy ∈ {wunderbyte, openai}`):

1. Nonce erzeugen + cachen (wie heute in `request_trial_key`).
2. **Server-seitiger POST** (`\core\http_client` / curl) an den Trial-Endpoint
   `POST {trial_base_url}/api/moodle-trial` mit `{wwwroot: $CFG->wwwroot, nonce}`.
   - Der Python-Service ruft zur Verifikation `trial_challenge.php?token={nonce}` zurück
     (Origin-Proof) → **Challenge im Python-Service wieder aktivieren** (`:80`).
3. Antwort `{apikey, endpoint, model}` entgegennehmen.
4. **Provider-Instanz anlegen** (Schritt C).
5. Bei Fehler: sprechende, lokalisierte Fehlermeldung (Timeout, Endpoint nicht erreichbar,
   Nonce abgelehnt, Budget/Key-Limit) — **nicht** stilles „success".

**Neue Admin-Settings** (`settings.php`):
- `trial_endpoint_base_url` (Default z. B. `https://trial.wunderbyte.at` — **O1 klären**),
- ggf. `trial_request_timeout`.

### Schritt C — Provider-Instanz programmatisch anlegen

```php
$manager = \core\di::get(\core_ai\manager::class);

if ($strategy === 'wunderbyte') {
    $instance = $manager->create_provider_instance(
        classname: 'aiprovider_wunderbyte\\provider',
        name: 'Wunderbyte',
        enabled: true,
        config: ['apikey' => $apikey],
        actionconfig: /* je Action settings.endpoint = $endpoint, model = $model */,
    );
} else { // openai (OpenAI-kompatibel gegen LiteLLM-Proxy)
    $instance = $manager->create_provider_instance(
        classname: 'aiprovider_openai\\provider',
        name: 'Wunderbyte',            // bestehendes Legacy-Muster (WB_LEGACY_PROVIDER_NAME)
        enabled: true,
        config: ['apikey' => $apikey],
        actionconfig: /* endpoint/base-url → $endpoint, model → $model */,
    );
}
```

**Konkrete `actionconfig`-Vorlage für die Wunderbyte-Trial-Instanz** (O1 entschieden — Endpoint
immer `llm.wunderbyte.at`, Modelle auf die Trial-Aliasse gemappt; `providerid` wird von core_ai mit
der **ID der neu erzeugten Instanz** befüllt, NICHT hartkodiert):

```json
{
  "aiprovider_wunderbyte\\aiactions\\generate_embeddings": {
    "enabled": true,
    "settings": { "endpoint": "https://llm.wunderbyte.at/v1/embeddings",
                  "model": "wunderbyte-embeddings", "dimensions": 1536 }
  },
  "aiprovider_wunderbyte\\aiactions\\planner_decide": {
    "enabled": true, "modelsettings": [],
    "settings": { "endpoint": "https://llm.wunderbyte.at/v1/chat/completions",
                  "model": "wunderbyte-privat-mini",
                  "systeminstruction": "Act as a compact planner and return a structured routing decision as plain JSON." }
  },
  "aiprovider_wunderbyte\\aiactions\\generate_agent_reply": {
    "enabled": true, "modelsettings": [],
    "settings": { "endpoint": "https://llm.wunderbyte.at/v1/chat/completions",
                  "model": "wunderbyte-privat",
                  "systeminstruction": "Compose the final user-facing response in the requested language." }
  },
  "core_ai\\aiactions\\generate_text": {
    "enabled": true, "modelsettings": [],
    "settings": { "endpoint": "https://llm.wunderbyte.at/v1/chat/completions",
                  "model": "wunderbyte-privat",
                  "systeminstruction": "[[action_generate_text_instruction]]" }
  }
}
```

**Modell-Mapping (Vorschlag, zu bestätigen):** Haupt-Chat (`generate_text`, `generate_agent_reply`)
→ `wunderbyte-privat`; kompakter Planner (`planner_decide`) → `wunderbyte-privat-mini`;
Embeddings (`generate_embeddings`) → `wunderbyte-embeddings`. (Referenz war `MiniMax-M2.7-infer` /
`text-embedding-3-small`; für den Trial werden die Aliasse verwendet, die der gemintete Key freigibt.)

**OpenAI-Standard-Pfad (O2 entschieden):** Trial-LiteLLM-Key in einer `aiprovider_openai`-Instanz,
Base-URL/Endpoint auf `https://llm.wunderbyte.at/v1/...` gesetzt. Da der Endpoint ohnehin der
LiteLLM-Proxy ist, ist dieser Pfad technisch derselbe Proxy — Unterschied ist nur die
Provider-Klasse und der dadurch reduzierte Action-/Skill-Umfang. **R1 bleibt zu verifizieren:**
erlaubt `aiprovider_openai` das Überschreiben der Base-URL pro Action (analog Wunderbyte)?

- **actionconfig**-Form je Provider exakt prüfen (`hook_listener`/`provider`-Form-Felder):
  Wunderbyte liest Endpoint aus `actionconfig['settings']['endpoint']` (`provider.php:168-193`).
- **Idempotenz:** Existiert bereits eine `name='Wunderbyte'`-Instanz, nur `update_provider_instance()`
  + `enable_provider_instance()` statt Duplikat anlegen.
- **Wichtig (Risiko R1):** Ob `aiprovider_openai` eine **eigene Base-URL** (statt api.openai.com)
  zulässt, muss verifiziert werden — der Trial-Key ist ein **LiteLLM**-Key, kein echter OpenAI-Key.
  Falls nicht überschreibbar, ist der „Standard-Pfad" nur mit einem **eigenen** OpenAI-Key der
  Nutzer:innen sinnvoll (→ **O2**).

### Schritt D — Aktivierung (bestehend, leicht angepasst)

`activate_trial_context.php` bleibt zuständig für Kurs-/Modul-Toggles + finalen
`get_runtime_provider_status()`-Check. Da Schritt C die Instanz bereits enabled anlegt, greift die
vorhandene „enable bestehende Instanz"-Logik weiterhin als Sicherheitsnetz. Der „no active provider"-
Pfad wird nur noch erreicht, wenn Provisioning real fehlschlug → dann mit **diagnostischer** Meldung.

### Schritt E — Todo-Listen-UI

- Template `templates/aiinstructions.mustache` (Onboarding-Karte) um eine **Status-Checkliste**
  erweitern: pro Schritt `done|todo|info` + optionaler Aktions-Link.
- JS (`aiinstructions.js`): `requestTrialKey()` → `loadSetupStatus()`; rendert die Checkliste und
  schaltet die passenden Buttons frei:
  - „Wunderbyte-Provider installieren" (Link, neuer Tab),
  - „Mit Standard-Provider fortfahren" (nur wenn anderer Provider vorhanden),
  - „Trial starten/aktivieren" (nur wenn ein gangbarer Pfad gewählt).
- Erfolgsmeldung erst **nach** echtem Provisioning, nicht nach der Nonce.

---

## 4. Offene Entscheidungen (vor Umsetzung klären)

- **O1 — Trial-Endpoint-URL & Hosting:** ✅ **ENTSCHIEDEN (2026-06-15):** Der Trial läuft **immer**
  über `https://llm.wunderbyte.at` (LiteLLM-Proxy). Endpoint als Default-Konstante; die
  Provider-Instanz wird mit der actionconfig-Vorlage aus Schritt C angelegt (Trial-Modell-Aliasse).
  Offen bleibt nur: erreicht der Origin-Check (`trial_challenge.php`) Moodles hinter Firewall? → R4.
  Antwort: wenn es eine firewall gibt, soll das zurückgemeldet werden, inkl: Sie sind hinter einer Firewall. Um einen Trial Token zu bekommen, melden Sie sich bitte bei info@wunderbyte.at
- **O2 — Standard-/OpenAI-Pfad-Semantik:** ✅ **ENTSCHIEDEN (2026-06-15):** Variante (a) —
  Trial-LiteLLM-Key in einer OpenAI-kompatiblen Instanz gegen den Proxy. Verbleibendes
  Verifikat: R1 (Base-URL-Override in `aiprovider_openai`).
- **O3 — Skill-Differenz:** Welche Skills entfallen real ohne Wunderbyte-Provider? Liste + Hinweis-Text.
Antwort: die Liste kommt später. Wir müssen dafür noch ein wenig testen, wie viele skills wir ohne embeddings sinnvoll anbieten können.
- **O4 — Mehrfach-Trial / Bestehende Instanz:** Verhalten, wenn schon eine (ggf. abgelaufene)
  Trial-Instanz existiert — erneuern, blockieren, oder neue anlegen?
  Antwort: unser llm.wunderbyte.at gibt nur eine Trial pro url heraus.
- **O5 — Berechtigung:** Setup bleibt `moodle/site:config` (nur Admin)? Oder soll auch Manager-Rolle
  den Trial starten dürfen?
  Antworten: Machen wir auch manager: eigene capability requesttrial. Für Admin spräche, dass man den wunderbyte aiprovider installieren muss, aber wir nehmen uns dadurch viele potentielle Testerinnen und Tester.

---

## 5. Teststrategie

- **Unit/Integration:** `trial_provisioner` mit gemocktem HTTP-Client (Erfolg, Timeout, 4xx, ungültige
  Nonce). Idempotenz von Schritt C. `create_provider_instance` → DB-Record korrekt (`config`,
  `actionconfig`, `enabled`).
- **Detection-Matrix:** (WB installiert) × (anderer Provider ja/nein) × (Instanz existiert ja/nein)
  → erwarteter `recommended_path` + Checkliste.
- **End-to-End (manuell):** frische Seite ohne Provider → Trial → LiteLLM zeigt neuen Key
  (`key_alias=wunderbyte-privat-{site_id}`) → `get_runtime_provider_status().provideractive==true`
  → Agent antwortet.
- **Negativ:** „no active provider" darf nur bei echtem Provisioning-Fehler erscheinen, immer mit Ursache.

---

## 6. Risiken & Sicherheit

- **R1 (OpenAI-Base-URL):** s. Schritt C / O2 — sonst funktioniert der Standard-Pfad nicht mit Trial-Key.
- **R2 (Origin-Challenge):** Der deaktivierte Challenge-Check (`:80 return True`) muss vor Produktion
  **reaktiviert** werden, sonst kann jede Site mit gültigem `wwwroot`-Format Keys anfordern.
- **R3 (Key-Speicherung):** Der Key liegt als Klartext in `ai_providers.config` (Moodle-Standard für
  AI-Provider) — akzeptiert, aber dokumentieren (Budget-begrenzt, ablaufend).
- **R4 (Erreichbarkeit):** Self-hosted Moodle hinter Firewall → Back-Channel-Challenge schlägt fehl;
  alternativer Verifikationsweg oder klarer Fehlertext nötig.

---

## 7. Betroffene Dateien (Überblick)

| Bereich | Datei | Änderung |
|---|---|---|
| Detection-WS | `classes/external/get_setup_status.php` (neu) | Checkliste + recommended_path |
| Provisioning | `classes/local/wbagent/services/trial/trial_provisioner.php` (neu) | Nonce→Key→Instanz |
| Trial-WS | `classes/external/request_trial_key.php` | ruft Provisioner statt nur Nonce |
| Aktivierung | `classes/external/activate_trial_context.php` | diagnostische Fehlerpfade |
| Settings | `settings.php` | `trial_endpoint_base_url`, Timeout |
| Python | `classes/local/wbagent/wunderbyte_trial_endpoint.py` | Challenge reaktivieren (`:80`) |
| UI | `templates/aiinstructions.mustache`, `amd/src/aiinstructions.js` | Todo-Liste + Pfad-Buttons |
| WS-Registrierung | `db/services.php` | neue Funktion(en) eintragen |
| Strings | `lang/en`, `lang/de` | neue Labels/Hinweise |

---

> **Nächster Schritt:** O1–O5 klären (v. a. O1 Trial-Endpoint-URL und O2 OpenAI-Pfad-Semantik),
> danach Schritte A→E inkrementell umsetzen — beginnend mit B+C (echtes Provisioning), weil das die
> eigentliche Funktionslücke schließt; die Todo-Listen-UI (A+E) baut darauf auf.

---

## 8. Umsetzungsstatus (2026-06-15)

### Entscheidungen (alle offenen Punkte beantwortet)
- **O1:** Endpoint immer `https://llm.wunderbyte.at` (Admin-Setting `trial_endpoint_base_url`).
- **O2:** Standard-Pfad = Trial-Key in OpenAI-kompatibler Instanz gegen den Proxy. **R1 verifiziert:**
  `aiprovider_openai` liest `actionconfig[...]['settings']['endpoint']` (`abstract_processor.php:42`),
  Endpoint also überschreibbar — aber **keine** Embeddings-Action → Standard-Pfad ist systembedingt „ohne Embeddings".
- **O3:** Skill-Differenz-Liste kommt später (Test, wie viel ohne Embeddings sinnvoll ist).
- **O4:** Eine Trial pro URL. **G2 entschieden:** Python soll den **bestehenden** Key zurückgeben (statt 409),
  damit Re-Setup möglich ist.
- **O5:** Eigene Capability `bookingextension/agent:requesttrial`, **Manager + Admin**.
- **G1 entschieden:** Trial-Key erhält **alle drei** Aliasse (`wunderbyte-privat`, `-mini`, `-embeddings`).
- **R4:** Firewall/Unerreichbarkeit → Meldung via bestehendem String `aitrial_support_firewall`
  (verweist auf info@wunderbyte.at).

### ✅ Umgesetzt (Backend-Provisioning, Schritt 1–4)
- `db/access.php` — neue Capability `requesttrial` (CONTEXT_SYSTEM, manager CAP_ALLOW).
- `settings.php` — Setting `trial_endpoint_base_url` (Default `https://llm.wunderbyte.at`).
- `classes/local/wbagent/services/trial/trial_provisioner.php` (**neu**) — Kern:
  Nonce cachen → `POST {base}/api/moodle-trial` → `{apikey,endpoint}` → `create/update_provider_instance()`
  mit 3-Alias-actionconfig (Wunderbyte) bzw. `generate_text`-only (OpenAI-Fallback) → enablen.
  Fehler-Mapping: Verbindungsfehler/403 → Firewall-Text, 409 → „already exists", sonst → „provision failed".
  Strategie-Autoerkennung: Wunderbyte > OpenAI > (keiner → Installations-Hinweis).
- `classes/external/request_trial_key.php` — ruft jetzt den Provisioner (echtes Provisioning statt nur Nonce);
  Gate auf `requesttrial`.
- `classes/external/activate_trial_context.php` — Gate auf `requesttrial` (Manager dürfen aktivieren).
- `lang/en` + `lang/de` — Capability-, Setting- und Flow-Strings (`aitrial_provider_created`,
  `aitrial_provider_required`, `aitrial_already_exists`, `aitrial_provision_failed`, …).
- `version.php` — Bump `2026061501 → 2026061502` (Capability + Setting = Upgrade nötig).

### ⏳ Offen
- **Python-Service** (`wunderbyte_trial_endpoint.py`, läuft separat an `llm.wunderbyte.at`):
  G1 (`models`-Liste = 3 Aliasse), G2 (bei Duplicate bestehenden Key zurückgeben statt 409),
  R2 (Origin-Challenge `:80 return True` reaktivieren). **Ohne Redeploy mintet der Service weiter nur
  `wunderbyte-privat` und antwortet bei bereits genutzter URL mit 409.**
- **LiteLLM:** Aliasse `wunderbyte-privat-mini` und `wunderbyte-embeddings` müssen existieren; Trial-Budget muss Embeddings abdecken.
- **UI (Schritt 5–6):** `get_setup_status`-WS + Todo-Liste + Pfad-Buttons (`aiinstructions.mustache`/`.js`).
  Aktuell läuft die Auto-Erkennung serverseitig; die explizite „Standard-Provider fortfahren"-Wahl im UI fehlt noch.
- **`db/services.php`:** neue WS-Funktion `get_setup_status` (sobald UI-Schritt kommt).
