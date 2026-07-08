#!/usr/bin/env python3
"""Deterministic one-way generator: bookingextension_agent -> local_wizard.

The agent plugin is the single source of truth; the local_wizard plugin is a
generated build artifact and must NEVER be edited by hand. Re-running this
script reproduces the target byte-for-byte from the current source tree.

Usage:
    python3 tools/wizard_sync/generate_local_wizard.py --target /path/to/local/wizard
    python3 tools/wizard_sync/generate_local_wizard.py --target ... --dry-run
    python3 tools/wizard_sync/generate_local_wizard.py --target ... --force

Safety model:
  * A manifest (.wizard_sync_manifest.json) with a SHA-256 per generated file
    is written into the target. On the next run every manifest file is checked
    against the target: a mismatch means someone hand-edited the artifact and
    the run aborts (exit 2) unless --force is given. Strict one-way stays
    enforceable.
  * Files present in the old manifest but no longer generated are deleted
    (stale cleanup). Files in the target that were never in a manifest are
    reported but left alone.

Built-in verification (exit 1 on violation):
  * No residual identity token (bookingextension / bx_agent, any case) may
    survive in any generated text file.
  * Every table name in db/install.xml must be <= 28 characters.
  * Every __DIR__-relative config.php require must climb exactly to the new
    webroot (plugin depth changes from 4 to 2 directory levels).
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

PLUGIN_ROOT = Path(__file__).resolve().parents[2]
MANIFEST_NAME = ".wizard_sync_manifest.json"
OVERLAY_ROOT = Path(__file__).resolve().parent / "overlays"

# Identity token map, applied in one pass with longest-match-first semantics so
# earlier replacements can never corrupt the input of later ones.
# NOTE on the doubled namespace: bookingextension_agent\local\wizard\... maps to
# local_wizard\local\wizard\... on purpose. The frankenstyle root changes, the
# physical classes/local/wizard/ directory stays.
TOKEN_MAP = {
    # Path form first (longest): dirroot strings, install.xml PATH, comments.
    "mod/booking/bookingextension/agent": "local/wizard",
    # Frankenstyle component: namespaces, get_string/get_config components,
    # webservice function names, AMD module names, temp-dir paths.
    "bookingextension_agent": "local_wizard",
    # Table prefix (also covers privacy:metadata:bx_agent_* string keys).
    "bx_agent_": "local_wizard_",
    # Capability prefix (bookingextension/agent:...) and leftover path forms.
    "bookingextension/agent": "local/wizard",
    # Hyphen form: CSS classes, DOM ids, data attributes.
    "bookingextension-agent": "local-wizard",
    # Split plugintype/name argument pairs (upgrade_plugin_savepoint et al.).
    "'bookingextension', 'agent'": "'local', 'wizard'",
    # Uppercase constant form (none today; future-proofing).
    "BOOKINGEXTENSION_AGENT": "LOCAL_WIZARD",
    # Admin tree parent: the agent hangs below mod_booking's folder, the
    # standalone plugin below the stock local-plugins node.
    "modbookingfolder": "localplugins",
}

# Never synced into the artifact.
EXCLUDE_DIRS = {".git", ".github", ".claude", "node_modules", "tools", "__pycache__"}
EXCLUDE_FILE_NAMES = {".DS_Store", MANIFEST_NAME}
EXCLUDE_FILES = {
    # Subplugin registration class: implements mod_booking's bookingextension
    # plugininfo interface, meaningless and load-fatal in a standalone plugin.
    "classes/agent.php",
}

# Plugin-relative paths shipped from tools/wizard_sync/overlays/ instead of a
# token-transformed copy. A path missing in the source tree is ADDED to the
# artifact (additive overlay). Overlays are wizard-final, may name the agent
# deliberately, and are exempt from the residual-token check.
OVERLAY_FILES = {
    # Agent-specific upgrade history (renames of pre-release table names) is
    # wrong for a freshly installed artifact; ships as a documented no-op.
    "db/upgrade.php",
    # Takeover migration: adopt the bundled agent's table data, settings and
    # role assignments when the wizard is installed next to it. Exists ONLY in
    # the artifact — the agent never migrates from anyone.
    "db/install.php",
}

# Engine-universal files copied verbatim: they intentionally name BOTH engine
# components (e.g. the scaffold's engine-alias-layer templates, whose emitted
# resolver must prefer local_wizard and fall back to bookingextension_agent on
# every site). Token-transforming them would break that semantics; the
# residual-token check skips them for the same reason.
VERBATIM_PREFIXES = (
    "classes/local/wizard/services/scaffold/templates/engine_layer/",
)

# Source plugin sits 4 directory levels below the webroot, the artifact only 2.
SOURCE_DEPTH_TO_WEBROOT = 4
TARGET_DEPTH_TO_WEBROOT = 2

CONFIG_REQUIRE_RE = re.compile(
    r"(__DIR__\s*\.\s*(['\"]))((?:/\.\.)+)(/config\.php\2)"
)
RESIDUAL_TOKEN_RE = re.compile(r"bookingextension|bx_agent", re.IGNORECASE)
TABLE_NAME_RE = re.compile(r'<TABLE NAME="([^"]+)"')
TABLE_NAME_LIMIT = 28

_token_pattern = re.compile(
    "|".join(re.escape(k) for k in sorted(TOKEN_MAP, key=len, reverse=True))
)


def apply_tokens(text: str) -> str:
    """Apply the identity token map in a single longest-match-first pass."""
    return _token_pattern.sub(lambda m: TOKEN_MAP[m.group(0)], text)


def fix_config_require_depth(text: str, rel_path: Path) -> str:
    """Rewrite __DIR__-relative config.php requires to the new plugin depth."""
    updirs = len(rel_path.parent.parts) + TARGET_DEPTH_TO_WEBROOT

    def repl(match: re.Match) -> str:
        return match.group(1) + "/.." * updirs + match.group(4)

    return CONFIG_REQUIRE_RE.sub(repl, text)


def transform_version_php(text: str) -> str:
    """Drop the dependency block: the artifact must install without mod_booking."""
    return re.sub(
        r"\n\$plugin->dependencies\s*=\s*\[[^\]]*\];\n", "\n", text, count=1
    )


def transform_services_php(text: str) -> str:
    """Rename the external service: its display name must be site-unique.

    Both engines install side by side; a shared service name violates the unique
    index on external_services (found by the coexistence test). The shortname is
    component-derived and already covered by the token map.
    """
    return text.replace("'Booking AI Agent' =>", "'Booking Wizard' =>")


def transform_lang_file(text: str) -> str:
    """Rewrite plugin-name-derived capability string keys.

    Moodle resolves the display string of capability local/wizard:x under the
    key 'wizard:x' (derived from the plugin NAME, not the component), so the
    agent's 'agent:x' keys must become 'wizard:x'. The short form exists only
    in lang files; verify() rejects any survivor.
    """
    return text.replace("$string['agent:", "$string['wizard:")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def iter_source_files():
    """Yield plugin-relative paths of all files to consider, sorted."""
    for path in sorted(PLUGIN_ROOT.rglob("*")):
        if not path.is_file():
            continue
        rel = path.relative_to(PLUGIN_ROOT)
        if any(part in EXCLUDE_DIRS for part in rel.parts):
            continue
        if rel.name in EXCLUDE_FILE_NAMES:
            continue
        if str(rel) in EXCLUDE_FILES:
            continue
        yield rel


def generate_content(rel: Path) -> tuple[Path, bytes]:
    """Return (target-relative path, generated bytes) for one source file."""
    rel_str = str(rel)
    target_rel = Path(apply_tokens(rel_str))

    if rel_str in OVERLAY_FILES:
        overlay = OVERLAY_ROOT / rel_str
        return target_rel, overlay.read_bytes()

    if rel_str.startswith(VERBATIM_PREFIXES):
        return target_rel, (PLUGIN_ROOT / rel).read_bytes()

    raw = (PLUGIN_ROOT / rel).read_bytes()
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        if RESIDUAL_TOKEN_RE.search(raw.decode("latin-1")):
            raise SystemExit(
                f"ERROR: binary file {rel} contains identity tokens; "
                "cannot transform."
            )
        return target_rel, raw

    text = apply_tokens(text)
    text = fix_config_require_depth(text, target_rel)
    if rel_str == "version.php":
        text = transform_version_php(text)
    if rel_str == "db/services.php":
        text = transform_services_php(text)
    if rel.parts and rel.parts[0] == "lang":
        text = transform_lang_file(text)
    return target_rel, text.encode("utf-8")


def verify(outputs: dict) -> list:
    """Run built-in checks over the generated outputs; return error strings."""
    errors = []
    for rel, data in outputs.items():
        if str(rel).startswith(VERBATIM_PREFIXES) or str(rel) in OVERLAY_FILES:
            continue
        try:
            text = data.decode("utf-8")
        except UnicodeDecodeError:
            continue
        for match in RESIDUAL_TOKEN_RE.finditer(text):
            line = text.count("\n", 0, match.start()) + 1
            errors.append(f"residual token '{match.group(0)}' in {rel}:{line}")
        for match in CONFIG_REQUIRE_RE.finditer(text):
            expected = len(Path(rel).parent.parts) + TARGET_DEPTH_TO_WEBROOT
            actual = match.group(3).count("/..")
            if actual != expected:
                errors.append(
                    f"config.php require in {rel} climbs {actual} levels, "
                    f"expected {expected}"
                )
        if Path(rel).parts and Path(rel).parts[0] == "lang" and "$string['agent:" in text:
            errors.append(f"unmapped capability string key ($string['agent:...]) in {rel}")
        if str(rel) == "db/install.xml":
            for name in TABLE_NAME_RE.findall(text):
                if len(name) > TABLE_NAME_LIMIT:
                    errors.append(
                        f"table name '{name}' is {len(name)} chars "
                        f"(limit {TABLE_NAME_LIMIT})"
                    )
                if not name.startswith("local_wizard_"):
                    errors.append(f"table name '{name}' misses artifact prefix")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument(
        "--target", required=True,
        help="directory of the generated local_wizard plugin (e.g. .../local/wizard)",
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="report planned writes/deletes without touching the target",
    )
    parser.add_argument(
        "--force", action="store_true",
        help="overwrite hand-edited target files (one-way sync discards them)",
    )
    args = parser.parse_args()

    target = Path(args.target).resolve()
    if target == PLUGIN_ROOT or PLUGIN_ROOT in target.parents:
        raise SystemExit("ERROR: target must lie outside the source plugin.")

    for rel in sorted(OVERLAY_FILES):
        if not (OVERLAY_ROOT / rel).is_file():
            raise SystemExit(f"ERROR: overlay file missing: overlays/{rel}")

    outputs = {}
    for rel in iter_source_files():
        target_rel, data = generate_content(rel)
        outputs[str(target_rel)] = data

    # Additive overlays: ship overlay files that have no source counterpart.
    for rel in sorted(OVERLAY_FILES):
        if rel not in outputs:
            outputs[rel] = (OVERLAY_ROOT / rel).read_bytes()

    errors = verify(outputs)
    if errors:
        for err in errors:
            print(f"VERIFY-FAIL: {err}", file=sys.stderr)
        return 1

    manifest_path = target / MANIFEST_NAME
    old_manifest = {}
    if manifest_path.is_file():
        old_manifest = json.loads(manifest_path.read_text())

    # Hand-edit detection: target files must match the manifest they were
    # generated with, otherwise someone violated the one-way rule.
    edited = []
    for rel, digest in old_manifest.items():
        existing = target / rel
        if existing.is_file() and sha256(existing.read_bytes()) != digest:
            edited.append(rel)
    if edited and not args.force:
        for rel in edited:
            print(f"HAND-EDITED: {rel}", file=sys.stderr)
        print(
            "ERROR: target differs from its manifest. local_wizard is a "
            "generated artifact; port changes to bookingextension_agent, "
            "then re-run (or --force to discard).",
            file=sys.stderr,
        )
        return 2

    written = unchanged = 0
    stale = [rel for rel in old_manifest if rel not in outputs]
    for rel, data in sorted(outputs.items()):
        dest = target / rel
        if dest.is_file() and sha256(dest.read_bytes()) == sha256(data):
            unchanged += 1
            continue
        written += 1
        if args.dry_run:
            print(f"would write {rel}")
            continue
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(data)

    for rel in stale:
        path = target / rel
        if args.dry_run:
            if path.is_file():
                print(f"would delete stale {rel}")
        elif path.is_file():
            path.unlink()

    if not args.dry_run:
        manifest = {rel: sha256(data) for rel, data in sorted(outputs.items())}
        target.mkdir(parents=True, exist_ok=True)
        manifest_path.write_text(json.dumps(manifest, indent=1) + "\n")

    untracked = []
    if target.is_dir():
        for path in target.rglob("*"):
            if not path.is_file():
                continue
            rel = str(path.relative_to(target))
            if rel != MANIFEST_NAME and rel not in outputs:
                untracked.append(rel)
    for rel in sorted(untracked)[:20]:
        print(f"note: untracked file in target (left alone): {rel}")

    mode = "DRY-RUN" if args.dry_run else "OK"
    print(
        f"{mode}: {len(outputs)} files generated "
        f"({written} written, {unchanged} unchanged, {len(stale)} stale removed)"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
