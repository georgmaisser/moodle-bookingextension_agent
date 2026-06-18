# One-Click-AI-Setup — Trial- & Provider-Onboarding

**Status:** 🟢 In Umsetzung — Backend-Provisioning (Schritt 1–4) implementiert; Python-Referenzkopie:
Origin-Challenge aktiv + 3 Modell-Aliasse umgesetzt, **nur G2 (Duplicate → bestehenden Key) offen**;
UI (Schritt 5–6) offen. Aktueller Stand siehe **§8** (Update 2026-06-18).
**Angelegt:** 2026-06-15
**Aktualisiert:** 2026-06-17 — Provider-Erkennung von **Name** auf **Endpoint** umgestellt: eine
Instanz gilt nur dann als Wunderbyte-Trial/-Abo, wenn ihr Action-Endpoint tatsächlich auf
`*.wunderbyte.at` zeigt (Helper in `agent_access_service`). Die Konstanten `WB_*_PROVIDER_NAME/CLASS`
in `aiready.php` sind entfernt. Details siehe **§8** und die markierten Stellen in §1.2/§1.4/§3.
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
- **aktiviert nur bestehende** Provider-Instanzen — `enable_provider_instance()`,
  > **Aktualisiert 2026-06-17:** Die Auswahl erfolgte ursprünglich über Klasse + Name `Wunderbyte`.
  > Inzwischen werden die zu aktivierenden Instanzen **endpoint-basiert** ermittelt
  > (`agent_access_service::find_wunderbyte_llm_instances()` — alle Instanzen, deren
  > Action-Endpoint auf `*.wunderbyte.at` zeigt, inkl. deaktivierter).
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
- **Erkennung „ist eine Wunderbyte-Instanz vorhanden?"** läuft **endpoint-basiert**
  (`agent_access_service::instance_targets_wunderbyte_llm()` / `find_wunderbyte_llm_instances()`):
  eine Instanz zählt nur, wenn ihr `actionconfig['settings']['endpoint']` auf `*.wunderbyte.at`
  zeigt — unabhängig von Provider-Name oder -Klasse. Ein `aiprovider_wunderbyte` mit fremdem
  Endpoint wird damit **bewusst nicht** als Trial/Abo-Äquivalent gewertet.
  > **Aktualisiert 2026-06-17:** Die früheren Konstanten `WB_PROVIDER_CLASS`,
  > `WB_LEGACY_PROVIDER_CLASS`, `WB_LEGACY_PROVIDER_NAME='Wunderbyte'` (Namens-/Klassen-Heuristik)
  > sind entfernt; der Name dient nur noch als Anzeige-Label beim Anlegen (`INSTANCE_NAME`).

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

**Trial-Endpoint:** ~~Admin-Setting `trial_endpoint_base_url`~~ → **hardcodiert** als
`trial_provisioner::BASE_URL = https://llm.wunderbyte.at` (Stand 2026-06-18; das ursprünglich
angelegte Setting wurde wieder entfernt, das Upgrade räumt die Altconfig per `unset_config` weg).
Kein separates Timeout-Setting.

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
        name: 'Wunderbyte',            // nur Anzeige-Label (INSTANCE_NAME), KEIN Match-Key mehr
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
- **Idempotenz:** Existiert bereits eine Instanz der passenden Provider-Klasse, deren Endpoint auf
  `*.wunderbyte.at` zeigt (Match über `find_wunderbyte_llm_instances()` + Klasse, **nicht** über den
  Namen), nur `update_provider_instance()` + `enable_provider_instance()` statt Duplikat anlegen.
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
| Settings | `settings.php` | ~~`trial_endpoint_base_url`~~ → hardcodiert (`trial_provisioner::BASE_URL`); Setting entfernt |
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
- ~~`settings.php` — Setting `trial_endpoint_base_url`~~ → **2026-06-18 wieder entfernt**; der Endpoint
  ist jetzt **hardcodiert** (`trial_provisioner::BASE_URL = https://llm.wunderbyte.at`), das Upgrade
  entfernt die Altconfig per `unset_config`.
- `classes/local/wbagent/services/trial/trial_provisioner.php` (**neu**) — Kern:
  Nonce cachen → `POST {base}/api/moodle-trial` → `{apikey,endpoint}` → `create/update_provider_instance()`
  mit 3-Alias-actionconfig (Wunderbyte) bzw. `generate_text`-only (OpenAI-Fallback) → enablen.
  Fehler-Mapping: Verbindungsfehler/403 → Firewall-Text, 409 → „already exists", sonst → „provision failed".
  Strategie-Autoerkennung: Wunderbyte > OpenAI > (keiner → Installations-Hinweis).
- `classes/external/request_trial_key.php` — ruft jetzt den Provisioner (echtes Provisioning statt nur Nonce);
  Gate auf `requesttrial`; zusätzlich **GDPR-Consent-Gate** (Pflicht-Zustimmung vor Provisioning) +
  `trial_consent_given`-Event.
- `classes/external/activate_trial_context.php` — Gate auf `requesttrial` (Manager dürfen aktivieren).
- `lang/en` + `lang/de` — Capability-, Setting- und Flow-Strings (`aitrial_provider_created`,
  `aitrial_provider_required`, `aitrial_already_exists`, `aitrial_provision_failed`, …).
- `version.php` — Bump `2026061501 → 2026061502` (Capability + Setting = Upgrade nötig).

### ⏳ Offen
- **Python-Service** (`wunderbyte_trial_endpoint.py`, läuft separat an `llm.wunderbyte.at`):
  In der **Referenzkopie** inzwischen umgesetzt — **G1** (`TRIAL_MODELS` = alle 3 Aliasse, Default
  `wunderbyte-privat,-mini,-embeddings`), **R2** (Origin-Challenge **aktiv**: `_verify_origin` wird
  aufgerufen und lehnt mit 403 ab), plus C2-Caps (per-IP + globales Fenster) und One-time-`max_budget`
  (kein `budget_duration`) mit `duration`-Ablauf. **Weiterhin offen: G2** — bei bereits vergebener URL
  antwortet der Service mit **409** statt den bestehenden Key zurückzugeben (`:357-364`); Re-Setup
  erfordert daher aktuell das Löschen des alten Keys (siehe `trial_service.md` → „Reset a site's trial").
  ⚠️ Die **laufende** Instanz übernimmt Code-Änderungen erst nach `docker compose up -d --build`
  (nicht per `restart`); der Deploy-Stand der Live-Instanz ist separat zu bestätigen.
- **LiteLLM:** Aliasse `wunderbyte-privat-mini` und `wunderbyte-embeddings` müssen existieren; Trial-Budget muss Embeddings abdecken.
- **UI (Schritt 5–6):** weitgehend erledigt — siehe „Stand 2026-06-18" unten. Onboarding-Karte gebaut,
  Detection via `aiready.php`-Render-Daten; ein separater `get_setup_status`-WS ist nicht nötig. Offen nur
  optional: expliziter Zwei-Button-Pfadwechsel.

### 🔄 Aktualisierung 2026-06-17 — Endpoint- statt Namenserkennung
- **Problem:** Provider-Erkennung lief teils über den Anzeige-Namen `Wunderbyte` (fragil beim
  Umbenennen; ein `aiprovider_wunderbyte` mit fremdem Endpoint wäre fälschlich als PRO-Äquivalent
  gewertet worden).
- **Umgesetzt:** Zwei wiederverwendbare Helfer in `agent_access_service` —
  `instance_targets_wunderbyte_llm($instance)` (prüft `actionconfig`-Endpoints einer Instanz, auch
  deaktiviert) und `find_wunderbyte_llm_instances($enabledonly=false)`. Damit umgestellt:
  `aiready.php` (`$haswunderbyteprovider`), `trial_provisioner.php` (Upsert-Match = Endpoint + Klasse)
  und `activate_trial_context.php` (Aktivierung endpoint-basiert). Konstanten `WB_*` in `aiready.php`
  entfernt; `INSTANCE_NAME` bleibt nur als Anzeige-Label.
- **Unverändert:** Der PRO-Access-Gate (`agent_access_service::has_full_access()` /
  `runs_on_wunderbyte_llm()`) war bereits endpoint-basiert.
- **Hinweis:** Host-Match ist Suffix `*.wunderbyte.at` (nicht exakt `llm.`). Bei Bedarf verengen.

### 🔄 Aktualisierung 2026-06-18 — Doku-Sync mit dem Code
Beim Review wurden Doku und Code abgeglichen; korrigiert wurde:
- **Endpoint hardcodiert:** Das Setting `trial_endpoint_base_url` wurde wieder entfernt; der Endpoint
  ist jetzt die Konstante `trial_provisioner::BASE_URL = https://llm.wunderbyte.at`, das Upgrade
  räumt die Altconfig per `unset_config` weg. (Angepasst in §3 Schritt B, §7, §8 „Umgesetzt" sowie in
  `operations/trial_service.md`.)
- **Python-Referenzkopie verifiziert:** G1 (3 Aliasse) und R2 (Origin-Challenge aktiv) sind umgesetzt;
  C2-Caps + One-time-Budget ebenfalls. **G2 (Duplicate → bestehenden Key statt 409) bleibt offen.**
- **GDPR-Consent:** `request_trial_key` hat ein Pflicht-Consent-Gate + `trial_consent_given`-Event
  (in §8 „Umgesetzt" ergänzt).

**Stand 2026-06-18 — neu eingeordnet:**
1. **Onboarding-UI (Schritt A+E): weitgehend ERLEDIGT** (Blueprint war hier veraltet). Die Onboarding-Karte
   (`templates/aiinstructions.mustache`) rendert bereits Provider-Check, Install-Hinweis + Link, den
   Fallback-Note für Pfad B (`aitrial_fallback_note`) und Start-/Aktivierungs-Button. Die Detection
   liefert `aiready.php::export_for_template()` als **Render-Daten** (`wunderbyte_provider_installed`,
   `using_standard_fallback`, `no_provider_installed`, `provider_install_url`, `readiness_checks`) —
   ein separater `get_setup_status`-WS ist damit nicht nötig. **Offen nur optional:** ein *expliziter*
   Zwei-Button-Pfadwechsel (Wunderbyte installieren vs. bewusst mit Standard-Provider fortfahren) statt
   der heutigen Auto-Erkennung + Install-Link/Start-Button.
2. **„Trial aufgebraucht" / Duplicate (ex-G2): ENTSCHIEDEN + UMGESETZT 2026-06-18.** Kein Auto-Reissue:
   der Service bleibt bei 409; die Moodle-Meldung `aitrial_already_exists` zeigt jetzt „Testversion
   aufgebraucht" + Kauf-/Abo-Link (showroom-URL als **versteckter** Markdown-Link, Klartext-URL aus
   `aitrial_pro_license_url`). Re-Setup über Key-Löschung bleibt der Reset-Weg (`trial_service.md`).
3. **Sicherheitsreste** (aus `trial_service.md` / Security-Review): H3 (Challenge beweist Kontrolle, nicht
   Zustimmung), M1 (Key als Klartext in `ai_providers.config`), M2 (kein Key-Leak in Debug-Logs).
4. **O3:** Skill-Differenz ohne Embeddings/Wunderbyte-Provider — wird **empirisch über Real-LLM-Tests**
   entschieden (wie groß darf die Skill-Liste sein, ohne dass es zu viel wird); Pfad-B-Hinweistext bleibt
   bis dahin generisch.
