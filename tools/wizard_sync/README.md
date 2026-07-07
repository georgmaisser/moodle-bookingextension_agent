# wizard_sync — one-way generator for local_wizard

`bookingextension_agent` is the single source of truth. The standalone
`local_wizard` plugin is a **generated build artifact**: never edit it by hand,
port every change to the agent and regenerate.

## Usage

```bash
python3 tools/wizard_sync/generate_local_wizard.py --target /path/to/local/wizard
```

Options: `--dry-run` (report only), `--force` (discard hand-edits in the target).

The script needs only Python 3 (stdlib), no Moodle bootstrap, and is fully
deterministic: same source tree in, byte-identical artifact out.

## What it does

1. **Token map** (single pass, longest match first): frankenstyle
   `bookingextension_agent` → `local_wizard`, table prefix `bx_agent_` →
   `local_wizard_`, capability/path form `bookingextension/agent` →
   `local/wizard`, hyphen form (CSS/DOM), split savepoint args, webroot path
   `mod/booking/bookingextension/agent` → `local/wizard`, admin parent
   `modbookingfolder` → `localplugins`. File and directory names are mapped
   with the same rules (`lang/en/bookingextension_agent.php` →
   `lang/en/local_wizard.php`). In lang files the plugin-name-derived
   capability keys `$string['agent:…']` become `$string['wizard:…']` (Moodle
   resolves capability display strings under the plugin NAME, not the
   component). Known cosmetic consequence: key renames unsort the generated
   lang files; irrelevant at runtime, but a future artifact CI needs the
   sorting sniff relaxed (or a re-sort step here).
2. **config.php require depth**: the plugin moves from 4 to 2 directory levels
   below the webroot; every `__DIR__ . '/../../ … /config.php'` climb is
   rewritten to the correct depth for its file.
3. **version.php**: the `mod_booking` dependency block is removed — the
   artifact must install without mod_booking.
4. **Overlays** (`tools/wizard_sync/overlays/…` ships verbatim instead of a
   transformed copy): `db/upgrade.php` (agent upgrade history does not apply;
   documented no-op while pre-production).
5. **Excluded**: `.git`, `.github` (CI is agent-specific), `.claude`,
   `node_modules`, `tools/`, and `classes/agent.php` (mod_booking subplugin
   registration, load-fatal standalone).
6. **Built-in verification** (non-zero exit on failure): no residual
   `bookingextension`/`bx_agent` token anywhere, install.xml table names
   `local_wizard_*` and ≤ 28 chars, config.php require depths correct.
7. **Manifest** (`.wizard_sync_manifest.json` in the target): SHA-256 per
   generated file. The next run refuses to overwrite hand-edited files
   (exit 2) unless `--force` is given, and deletes files that are no longer
   generated.

## Source-side invariants the generator relies on

- Table name suffix after `bx_agent_` stays ≤ 15 chars (verified: 28-char
  limit after prefix swap).
- Coexistence logic goes through
  `authorization_service::primary_engine_takes_over()` (symmetric via the
  `ENGINE_COMPONENT`/`PRIMARY_ENGINE` constants) — never probe
  `local_wizard` directly, the literal maps onto itself.
- Booking-coupled tests call
  `mod_booking_dependency::require_installed()` first in `setUp()`; test base
  classes from mod_booking only via conditional `class_alias`.
- mod_booking classes are referenced at runtime only (string FQCN +
  `class_exists`), never via `use` + static call (see `wb_license`,
  `aiready`).
- New PHP entry scripts must build their config.php require as
  `__DIR__ . '/../…/config.php'` (the depth fixer only rewrites that shape).
