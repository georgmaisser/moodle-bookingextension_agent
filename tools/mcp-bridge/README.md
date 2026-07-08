# wizard-mcp-bridge

A ~150-line stdio MCP bridge that exposes the Booking Wizard agent skills to
Claude (Claude Code, Claude Desktop) as MCP tools. It translates `tools/list`
and `tools/call` into Moodle web service calls against the token-capable MCP
facade (`*_mcp_list_tools` / `*_mcp_call_tool`). All authorization, governance
and preflight decisions happen server-side in Moodle — the bridge is a dumb
pipe.

## Moodle setup (admin, once)

1. Site administration → Server → Web services: **enable web services** and
   the **REST protocol**.
2. Site administration → Server → External services: enable
   **Booking AI Agent MCP** and add the connecting user as an
   **authorised user** (the service is restricted on purpose).
3. Grant the connecting user, via a role at the system context (or the target
   course):
   - `bookingextension/agent:mcpaccess`
   - `bookingextension/agent:useaiinstructions` (editing teachers and
     managers have it by default)
   - the per-skill capabilities (`bookingextension/agent:skill_*`) the user
     should be able to call — teachers/managers hold the defaults.
4. Create a **token** for that user, bound to the *Booking AI Agent MCP*
   service.

Which tools appear is governed server-side: read-only skills minus the
default exclusions (see `mcpexposedskills` / the skill governance page), each
further filtered by the user's actual capabilities.

## Client setup

```bash
cd tools/mcp-bridge && npm install
```

Claude Code:

```bash
claude mcp add wizard -- env \
  MOODLE_URL=https://your-moodle.example.com \
  MOODLE_WSTOKEN=YOUR_TOKEN \
  node /path/to/tools/mcp-bridge/index.mjs
```

Claude Desktop (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "wizard": {
      "command": "node",
      "args": ["/path/to/tools/mcp-bridge/index.mjs"],
      "env": {
        "MOODLE_URL": "https://your-moodle.example.com",
        "MOODLE_WSTOKEN": "YOUR_TOKEN"
      }
    }
  }
}
```

Optional environment:

- `MOODLE_CONTEXTID` — ambient context for tool calls (default `1`, the
  system context). Point it at a course context id to scope the session.
- `MOODLE_WS_PREFIX` — skip engine auto-discovery (`bookingextension_agent`
  or `local_wizard`). Without it the bridge probes `local_wizard` first, so
  it keeps working unchanged after the engine extraction.

## Notes

- The bridge generates a fresh idempotency key per call; Moodle refuses
  accidental double execution when a request is retried with the same key.
- Mutating skills never execute directly: the first call returns a preview
  with a `queueitemid` and a `confirmationcode`, and the server-injected
  `confirm_pending_action` tool executes the action once the human agreed.
  Both steps are ordinary tool calls, so the bridge needs no special code.
  Mutations additionally require the site setting *Allow mutating tools over
  MCP* — without it only read-only tools are served.
