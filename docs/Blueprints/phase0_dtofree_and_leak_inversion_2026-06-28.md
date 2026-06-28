# Phase 0 — DTO-freier Skill-Contract + 5 Leak-Inversionen (Detail-Umsetzungsplan)

**Datum:** 2026-06-28
**Status:** Detailplanung (keine Code-Änderung)
**Kontext:** Voraussetzung für die engine-agnostischen Skills (siehe `local_wizard_extraction_plan_2026-06-28.md` §5b). Unabhängig vom Engine-Cut umsetzbar und committbar.
**Sicherheits-Prinzip:** Die Engine konsumiert Preflight an **genau einem Punkt** (`preflight_pipeline.php:215`, liest die `preflight_result_v2`-Public-Props + `to_array()`). Diese Grenze bleibt **unverändert** — die `base_skill` wickelt weiter den DTO. Alle Änderungen sind skill-seitig + im `base_skill`-Wrapper. Migration **inkrementell** (Skills koexistieren konvertiert/unkonvertiert), kein Big-Bang.

---

## Teil A — DTO-freier Preflight/Prompt-Contract

### Ist-Zustand (verifiziert)
- ~20 Skills `extends booking_skill_base` (mod_booking) → `extends base_skill` (Engine, `bookingextension_agent`).
- Jeder überschreibt `preflight(array,int,int): preflight_result_v2` und ruft `preflight_result_v2::ok($prepared)` / `::invalid($issues)` / `::confirmable($prepared,$issues)`. `$issues` ist schon ein **Array** strukturierter Objekte.
- Hilfsmethoden in `booking_skill_base` geben ebenfalls DTOs zurück: `require_booking_instance_scope()`, `require_native_capability()`, `apply_service_preflight()`.
- `get_prompt_contract(): skill_prompt_contract` — nur **1** Override (`create_slotbooking_option_skill.php:137`), übergibt ohnehin nur ein Array an den Konstruktor.
- Engine liest: `status`, `issuecodes`, `blockinglayer`, `preparedinput`, `issues` + `to_array()` (`preflight_pipeline.php:215-260`, **einziger** Konsumpunkt).

### Ziel-Contract (primitiv)
Konkrete Skills implementieren **eine** primitive Template-Methode statt `preflight()`:
```php
// Rückgabe-Shape (rein primitiv, kein Engine-Typ):
// ['status' => 'pass'|'invalid'|'confirmable',
//  'prepared_input' => array,        // bei pass/confirmable
//  'issues' => array<array> ]        // bei invalid/confirmable
protected function run_preflight(array $input, int $contextid, int $userid): array;
```
`base_skill` (Engine) bekommt das **finale** DTO-Wrapping:
```php
final public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
    $r = $this->run_preflight($input, $contextid, $userid);
    return match ($r['status'] ?? 'pass') {
        'invalid'     => preflight_result_v2::invalid($r['issues'] ?? []),
        'confirmable' => preflight_result_v2::confirmable($r['prepared_input'] ?? $input, $r['issues'] ?? []),
        default       => preflight_result_v2::ok($r['prepared_input'] ?? $input),
    };
}
```
Primitive Helfer ersetzen die DTO-Factories am Skill-Rand (in `base_skill`/`booking_skill_base`), z. B.:
`pass(array $prepared): array`, `invalid(array $issues): array`, `confirmable(array $prepared, array $issues): array`, und booking-spezifisch `deny_capability(...): array`, `require_booking_instance_scope(...): ?array` (gibt jetzt ein Issue-Array statt DTO oder `null`).

### Schritte A
- **A.1 (Fundament, additiv, nichts bricht):** in `base_skill`
  - `run_preflight()` mit Default `return $this->pass($input);` einführen.
  - `preflight()` als **nicht-finalen** Wrapper auf `run_preflight()` legen (final erst in A.3).
  - primitive Helfer `pass/invalid/confirmable` ergänzen. `get_prompt_contract()`-Default bleibt.
  - *Test-Anker:* gesamte agent- + mod_booking-Suite grün (Default-`run_preflight` = altes `::ok`-Verhalten; Skills überschreiben weiter `preflight()` direkt → koexistiert).
- **A.2 (Skill-für-Skill-Konvertierung, je 1 kleiner Commit):** pro Skill
  - `preflight()`-Body → `run_preflight()`, `::ok/::invalid/::confirmable(...)` → primitive `pass/invalid/confirmable(...)`-Returns; `preflight()`-Override entfernen.
  - `booking_skill_base`-Helfer mitkonvertieren (Rückgabe DTO → primitive Issue-Arrays).
  - *Test-Anker:* die existierenden `*_skill`-Tests (Struktur/Preflight) + der Charakterisierungs-Lauf je Skill. Reihenfolge: erst die einfachen (nur `check_structure` → `get_option_details_skill`, `search_options_skill`), dann die komplexen (`update_option_skill`, `bulk_update_options_skill`, `configure_booking_instance_skill`).
- **A.3 (Abschluss):** wenn **kein** Skill mehr `preflight()` überschreibt
  - `base_skill::preflight()` → `final`. Grep-Gate: kein `function preflight(` in `*/skills/*`.
  - `get_prompt_contract()`: `create_slotbooking` auf primitive `prompt_contract_payload(): array` umstellen, `base_skill::get_prompt_contract()` → `final` (wrappt das Array in `skill_prompt_contract`).
  - *Test-Anker:* Grep-Gate (0 Overrides) + volle Suite + ein Real-LLM-Smoke (nach Georg-Go).

---

## Teil B — Die 5 Leak-Inversionen (interleaved)

Reihenfolge so, dass Preflight-nahe Leaks während der A.2-Konvertierung des jeweiligen Skills mit erledigt werden.

### LEAK 1 — `privacy_anonymizer::looks_like_anon_token()`  *(redundant → löschen)*
- *Sites:* `update_option_skill.php:315-320`, `update_option_trainer_skill.php:242-250` (bulk: nur Import, ungenutzt).
- *Befund:* Die Engine deanonymisiert Command-Input **vor** dem Preflight: `executor.php:125` (`deanonymize_command_input`) und `preflight_pipeline.php:164` (`…_for_active_user`). Der Skill-Check ist überflüssig.
- *Inversion:* Check ersatzlos entfernen (der Skill sieht ohnehin schon deanonymisierten Input). Falls ein „war anonymisiert"-Signal gebraucht wird: als Input-Flag `_was_anonymized` vom Pipeline setzen, nicht im Skill detektieren.
- *Wann:* in A.2 beim Konvertieren genau dieser Skills. *Test-Anker:* ein Update-via-anon-token-Thread (Executor löst auf) bleibt grün; bulk-Import von `privacy_anonymizer` entfernen.

### LEAK 5 — `attachment_token_service::resolve()` (Header-Bild)
- *Site:* `booking_skill_mutation_execute_service.php:1401-1406` — `new attachment_token_service()->resolve($token,$userid,$contextid)` → `{path, filename}` → Draft-Area.
- *Inversion:* Token-Auflösung in die **Engine-Input-Normalisierung** ziehen: die Engine löst `headerimage_token` vor dem Skill auf und übergibt `_resolved_headerimage_path` + `_resolved_headerimage_filename` im prepared input. Der Mutation-Service liest nur noch den Pfad. *(Passt zu „Executor bleibt clean" + Draft-Area-Vorgabe.)*
- *Wann:* eigener Schritt B.5 (Engine-seitig). *Test-Anker:* die Header-Bild-Tests (`headerimage`-Set-Data/Source/Postcondition).

### LEAK 2 — `conversation_store` (Thread-Metadaten)
- *Sites:* `booking_skill_support.php:2567/2605/2625/2678` — Lesen/Schreiben `lastworkedoptionid`, `lastpreviewoptionids` (+ ts).
- *Inversion (zweiseitig):*
  - **Lesen:** die Pipeline hydratisiert die Metadaten **in den Skill-Input** (z. B. `input['_thread']['lastpreviewoptionids']`), der Support liest aus dem Input statt aus dem Store.
  - **Schreiben:** der Skill gibt „merke dir X" als Daten im Result zurück (`result['remember'] = [...]`); die Engine persistiert post-execute. Kein `conversation_store` im Skill.
- *Wann:* B.2 (berührt update_option-Preview-Auflösung → nach A.2 dieser Skills). *Test-Anker:* Preview-Selektions-/last-option-Auflösungstests (Thread-322-Pfad).

### LEAK 3 — `skill_discovery::get_skill_instances()/get_last_diagnostics()`
- *Sites:* `skill_provider.php:52,64`, `booking_skill_support.php:183`.
- *Inversion:* Der `skill_provider` ist die **Contract-Implementierung** — er soll seine Skills nicht selbst discovern, sondern über einen Contract-Helfer/Registry-Hook geliefert bekommen; `booking_skill_support` bekommt den Katalog **injiziert** (Konstruktor/Setter) statt `skill_discovery` zu rufen.
- *Wann:* B.3 (provider-/support-Ebene, skill-unabhängig). *Test-Anker:* Skill-Registry-/Discovery-Tests (Katalog identisch vor/nach).

### LEAK 4 — `skill_registry_factory::get_default()`
- *Site:* `list_option_properties_skill.php:206-208` — zieht `create_option_skill`/`update_option_skill` aus der Registry, um deren JSON-Schemas zu listen.
- *Inversion:* Die benötigten Geschwister-Schemas via Input/Context injizieren (Engine pre-fetcht und übergibt), oder ein Contract-Helfer `get_sibling_schema(name): array`. Der Skill greift nie in die Registry.
- *Wann:* B.4 (bei A.2-Konvertierung dieses Skills). *Test-Anker:* `list_option_properties`-Test (gleiche Property-Liste).

---

## Verifikations-Gates (Definition of Done Phase 0)
1. `grep -rn "function preflight(" mod/booking/classes/local/wizard/**/skills/` → **0** (alle über `run_preflight`).
2. `grep -rn "function get_prompt_contract(" …/skills/` → **0**.
3. In den Skills **kein** `use bookingextension_agent\local\wizard\(privacy_anonymizer|conversation_store|skill_discovery|skill_registry_factory|services\\attachment\\attachment_token_service)` mehr.
4. In den Skills **kein** `: preflight_result_v2` / `: skill_prompt_contract` in Signaturen; kein `new preflight_result_v2(`/`preflight_result_v2::` außerhalb `base_skill`.
5. `preflight_result_v2`/`skill_prompt_contract` aus `services\` nach `dto\`/`contract\` verschoben (Hygiene §4.2 alt-Blueprint).
6. Volle `mod_booking_testsuite` + agent-Suite grün; Real-LLM-Smoke (nach Georg-Go).

## Risiken
- **`booking_skill_base`-Helfer** geben heute DTOs zurück und werden von vielen Skills genutzt → in A.2 zentral mitkonvertieren, sonst Mischzustand. Ein Helfer nach dem anderen, Tests je Schritt.
- **Leak 5 (Header-Bild)** ist der einzige Engine-seitige Eingriff (Input-Normalizer) → isoliert halten, separat committen/revertierbar.
- **Leak 2 Schreibpfad** (Metadaten-Persistenz post-execute) ändert das Timing der Persistenz minimal → mit Preview-Tests absichern.
- Reihenfolge strikt: A.1 (additiv) → A.2 (skillweise, inkl. Leak 1/4) → B.2/B.3/B.5 → A.3 (final). Erst ganz am Schluss `final`.
