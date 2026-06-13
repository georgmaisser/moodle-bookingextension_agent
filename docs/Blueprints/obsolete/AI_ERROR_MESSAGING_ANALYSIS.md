# Plan: Präzise Fehlermeldungen für die AI-Kommunikation

Dieses Dokument beschreibt die Umsetzung von präzisen und hilfreichen Fehlermeldungen, wenn es nicht möglich ist, ein LLM zu kontaktieren oder Berechtigungen fehlen, ohne harten Absturz der Moodle-Benutzeroberfläche.

## Implementierungs-Checkliste

- [x] **Task 1: Neue Sprachstrings hinzufügen**
  - Sprachstrings in `lang/de/bookingextension_agent.php` und `lang/en/bookingextension_agent.php` für alle spezifischen Fehlerzustände und detaillierten Provider-Fehler bereitstellen.
- [x] **Task 2: AI-Bereitstellungsstatus granularer erfassen**
  - In `orchestrator::get_runtime_provider_status()` einen neuen Rückgabewert `failurereason` etablieren und Exceptions im Catch-Block als `exception_thrown` kennzeichnen.
- [x] **Task 3: Provider-Fehler im Orchestrator klassifizieren**
  - In `orchestrator::build_provider_error_result()` und `orchestrator::build_empty_provider_result()` den korrekten `error_class` Key (z. B. `auth_failed`, `quota_exceeded`, `transient_io`) setzen, damit der Finalizer nicht versucht, einen Synchronizer-LLM-Call aufzurufen.
- [x] **Task 4: Detaillierte Provider-Fehlermeldungen im Interface ausgeben**
  - Wenn der AI-Provider fehlschlägt, soll die rohe Fehlermeldung (z. B. "Quota exceeded") zusätzlich zur lokalisierten Meldung im Interface zurückgegeben werden (z. B. in Form von "Das Kontingent der KI ist erschöpft. Details: [rohe Fehlermeldung]").
- [x] **Task 5: Finalizer-Templates erweitern**
  - In `finalization_template_service` die neuen Fehlermeldungen registrieren und dynamisch mit den rohen Provider-Fehlern anreichern.
- [x] **Task 6: Berechtigungen und Readiness im API-Layer weich prüfen**
  - In `ai_send_message::execute()` harte Exceptions bei Berechtigungsfehlern (`require_use_capability`) durch weiche JSON-Error-Antworten ersetzen und detaillierte `failurereason` Mappings anwenden.
- [x] **Task 7: Weiche Berechtigungsprüfung für `ai_confirm_run` umsetzen**
  - Auch in `ai_confirm_run::execute()` die Berechtigung weich prüfen und einen strukturierten JSON-Error statt einer harten Exception werfen, um UI-Crashes bei abgelaufenen Sessions zu verhindern.
- [x] **Task 8: Admin-Readiness UI verfeinern**
  - In `aiready::export_for_template()` den neuen `failurereason` Key nutzen, um Administratoren im Einstellungs-Panel exakt anzuzeigen, was konfiguriert oder behoben werden muss.
- [x] **Task 9: Flowchart aktualisieren**
  - Das Diagramm in `AGENT_IMPLEMENTATION_FLOWCHART.mmd` um das Readiness Gate im Entry-Layer erweitern.

---

## 1. Identifizierte Fehlerpunkte (Failure Points)

### A. Vor dem LLM-Aufruf (in `ai_send_message::execute`)
1. **Core AI Subsystem fehlt**: `class_exists('\core_ai\manager') == false`.
   *-> Fehler-Code: `subsystem_missing` (Moodle Core AI nicht installiert/zu alt).*
2. **Kein Provider konfiguriert**: Moodle AI vorhanden, aber keine Instanzen (`providerconfigured == false`).
   *-> Fehler-Code: `no_provider` (Kein AI-Provider in Moodle-Admin konfiguriert).*
3. **Provider konfiguriert, aber inaktiv**: Instanzen existieren, sind aber deaktiviert (`provideractive == false`).
   *-> Fehler-Code: `provider_inactive` (AI-Provider ist deaktiviert).*
4. **Fehlende Actions**: Der Provider unterstützt Generate Text oder Planner Decide nicht.
   *-> Fehler-Code: `actions_missing` (Provider unterstützt benötigte Aktionen nicht).*
5. **Kurs-Einstellung blockiert**: AI-Tools im Kurs deaktiviert (`courseenabled == false`).
   *-> Fehler-Code: `course_disabled` (AI für diesen Kurs deaktiviert).*
6. **Aktivitäts-Einstellung blockiert**: AI-Tools im Modulkontext deaktiviert (`contextenabled == false`).
   *-> Fehler-Code: `context_disabled` (AI in dieser Aktivität deaktiviert).*
7. **Fehlende Berechtigung (Capability)**: `bookingextension/agent:useaiinstructions` fehlt.
   *-> Fehler-Code: `permission_denied` (Keine Berechtigung zur Nutzung).*

### B. Während des LLM-Aufrufs (in `agent_runtime::run_loop`)
Wenn der LLM-Aufruf fehlschlägt, greift der `ai_error_classifier` und mappt Fehler auf Konstanten wie `provider_timeout`, `transient_io`, `auth_failed`, `quota_exceeded`. Diese müssen sofort über das `template_only`-Finalisierungs-Verfahren ausgegeben werden. Der rohe Provider-Fehler muss hierbei ausgelesen und an die Template-Meldung angehängt werden.

---

## 2. Detaillierte Code-Änderungen

### Sprachstrings (`lang/de/bookingextension_agent.php` & `lang/en/bookingextension_agent.php`)
Wir benötigen neue bzw. aktualisierte Strings für die präzisen Fehler.

### `bookingextension_agent\external\ai_send_message` & `ai_confirm_run`
Ersetzung von `$authz->require_use_capability(...)` durch `$authz->can_use(...)` und Rückgabe strukturierter Fehlermeldungen bei `false`.

### `bookingextension_agent\local\wbagent\orchestrator`
1. In `get_runtime_provider_status()` einen detaillierten `failurereason` String im Rückgabe-Array setzen.
2. In `build_provider_error_result()` den Key `error_class` setzen, damit der Finalizer die Synchronizer-LLM-Polishing-Phase überspringt.
