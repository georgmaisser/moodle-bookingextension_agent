# Third-Party Task Provider Guide

Stand: 2026-05-25
Scope: mod/booking/bookingextension/agent

## Ziel
Dieses Dokument beschreibt das minimale Onboarding fuer einen neuen Task-Provider ohne Framework-Aenderung.

## 1. Provider anlegen
Erstellen Sie eine Klasse unter Ihrem Plugin-Namespace:
- Pfad: classes/local/wbagent/task_provider.php
- Interface: bookingextension_agent\\local\\wbagent\\interfaces\\task_provider_interface

Pflichtmethoden:
- get_component(): string
- get_tasks(): array
- get_contextual_prompt_packs(): array
- get_issue_code_provider(): ?issue_code_provider_interface
- get_prompt_guidance(): array

## 2. Task anlegen
Erstellen Sie Ihre Task-Klasse unter:
- classes/local/wbagent/<domain>/tasks/<my_task>_task.php

Task-Vertrag:
- Implementiert task_interface (typisch ueber base_task/booking_task_base).
- get_name() muss namespaced sein: <namespace>.<task>
- get_schema() muss mindestens version, properties, required liefern.
- get_prompt_contract() liefert explizite Planner-Felder:
  - intent
  - anchors
  - minimal_input
  - example_input
  - namespace
  - version
  - capabilities
  - context_scopes
- preflight() gibt preflight_result_v2 zurueck.
- execute() arbeitet mit prepared_input.

## 3. Namespace- und Alias-Regeln
- Tasknamen sind strikt namespaced: <namespace>.<task>
- Alias-Ziele (alias_of) muessen ebenfalls namespaced sein.
- Alias muss im gleichen Namespace liegen.
- Alias-Version muss zur Ziel-Task-Version passen.

Reservierte Namespaces:
- booking
- core

Nur bookingextension_agent darf reservierte Namespaces registrieren.
Third-Party-Provider muessen eigene Namespaces verwenden (z.B. entities, shopping_cart, myplugin).

## 4. Versionierung
- Jede Task hat eine numerische Version > 0 im Schema.
- Aenderungen am Input-/Output-Vertrag erfordern eine neue Version.
- Alias nur auf gleiche Version mappen.

## 5. Capability-Kontrakt
- Pro Task wird eine deterministische Capability aus component+taskname gebildet.
- Capability-Gating wird zentral im Framework ausgewertet.
- Task-Ausfuehrung erfolgt nur bei erfolgreicher Runtime/Active/Context/Capability-Pruefung.

## 6. Minimalbeispiel (Skeleton)
```php
<?php
namespace local_myplugin\\local\\wbagent\\mydomain\\tasks;

use bookingextension_agent\\local\\wbagent\\base_task;
use bookingextension_agent\\local\\wbagent\\services\\preflight_result_v2;
use bookingextension_agent\\local\\wbagent\\services\\task_prompt_contract;

final class my_lookup_task extends base_task {
    public function __construct() {
        parent::__construct(true);
    }

    public function get_name(): string {
        return 'myplugin.lookup_thing';
    }

    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Lookup thing by query.',
            'readonly' => true,
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
            ],
            'required' => ['query'],
            'governance' => ['active' => true],
        ];
    }

    public function get_prompt_contract(): task_prompt_contract {
        return new task_prompt_contract([
            'intent' => 'search',
            'anchors' => ['thing'],
            'minimal_input' => ['query'],
            'example_input' => ['query' => 'test'],
            'namespace' => 'myplugin',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
        ]);
    }

    public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
        return preflight_result_v2::ok($input);
    }

    public function execute(array $preparedinput, int $contextid, int $userid): array {
        return ['task' => $this->get_name(), 'ok' => true, 'results' => []];
    }
}
```

## 7. Fehlerisolation
- Provider-/Task-Fehler duerfen den Registry-Build anderer Provider nicht blockieren.
- Bei Fehlern werden Diagnosen gesammelt; funktionierende Provider bleiben aktiv.

## 8. Checkliste vor Freigabe
- Taskname namespaced und eindeutig.
- Prompt-Contract vollstaendig und explizit.
- Schema-Version gesetzt und konsistent.
- preflight/execute strikt getrennt.
- Capabilities und Kontextpfad getestet.
