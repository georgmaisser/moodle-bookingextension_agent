# Task-Provider Contract: Identity, Capability, Activation

**Pflichtfelder pro Task:**
- `taskname` (string, stabiler Primärschlüssel)
- `version` (int, für Breaking Changes)
- `capability` (string[], deklarativ, z.B. ["mod/booking:readoption"])
- `activation` (bool|callable, ob Task grundsätzlich aktiv ist)

**Versionsregel (Pflicht):**
- Jede Task-Version wird gegen eine zentrale Supported-Version-Policy geprüft.
- Veraltete oder nicht unterstützte Versionen dürfen nicht stillschweigend laufen.
- Standard-Issue-Codes:
  - `TASK_VERSION_UNSUPPORTED`
  - `TASK_VERSION_DEPRECATED`

**Optionale Felder:**
- `alias_of` (string, falls Alias)
- `deprecated_since` (string, Version/Datum)
- `deny_reason` (string, falls nicht erlaubt)

**Validierungsregeln:**
- Jeder Task muss einen eindeutigen `taskname` besitzen.
- Kein Task darf denselben Namen wie ein anderer (inkl. Alias) haben.
- Pflichtfelder müssen gesetzt sein, optionale Felder dürfen fehlen.
- `capability` muss als Array von Strings vorliegen.
- `activation` muss bool oder Callback sein.
- `version` muss in der Supported-Version-Policy zugelassen sein.

**Beispiel (gültig):**
```php
[
  'taskname' => 'booking.create_option',
  'version' => 1,
  'capability' => ['mod/booking:writeoption'],
  'activation' => true,
]
```

**Beispiel (ungültig):**
```php
[
  'taskname' => '', // fehlt
  'capability' => 'mod/booking:writeoption', // kein Array
]
```

**Deny-Reasons (standardisiert):**
- `not_registered`
- `inactive`
- `missing_capability`
- `context_invalid`
- `runtime_disabled`
- `task_version_unsupported`

**Evaluationslogik (Reihenfolge):**
1. Global deny
2. Task inactive
3. Missing capability
4. Context invalid
5. Allow

**Jede Änderung am Contract muss durch den Validator und CI abgedeckt sein.**
