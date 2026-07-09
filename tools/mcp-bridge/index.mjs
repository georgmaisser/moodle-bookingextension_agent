#!/usr/bin/env node
/**
 * stdio MCP bridge for the Booking Wizard agent skills.
 *
 * Speaks MCP to a local Claude client (Claude Code, Claude Desktop) and
 * translates tools/list + tools/call into Moodle web service calls against
 * the token-capable MCP facade (bookingextension_agent_mcp_* functions).
 *
 * The bridge holds no logic and no privileges: every listing and every call
 * is decided server-side (capabilities, governance, licence, preflight).
 *
 * Environment:
 *   MOODLE_URL        Site root, e.g. https://moodle.example.com (required)
 *   MOODLE_WSTOKEN    Web service token for the "Booking AI Agent MCP" service (required)
 *   MOODLE_CONTEXTID  Ambient context id for tool calls (default: 1 = system context)
 *   MOODLE_WS_PREFIX  Skip auto-discovery and force a function prefix
 *                     (e.g. bookingextension_agent or local_wizard)
 */

import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { randomUUID } from "node:crypto";

const MOODLE_URL = (process.env.MOODLE_URL ?? "").replace(/\/+$/, "");
const MOODLE_WSTOKEN = process.env.MOODLE_WSTOKEN ?? "";
const MOODLE_CONTEXTID = Number(process.env.MOODLE_CONTEXTID ?? 1);

if (!MOODLE_URL || !MOODLE_WSTOKEN) {
  console.error("wizard-mcp-bridge: set MOODLE_URL and MOODLE_WSTOKEN");
  process.exit(1);
}

/** Call one Moodle web service function over REST. */
async function moodleWs(wsfunction, params = {}) {
  const body = new URLSearchParams({
    wstoken: MOODLE_WSTOKEN,
    wsfunction,
    moodlewsrestformat: "json",
    ...params,
  });
  const response = await fetch(`${MOODLE_URL}/webservice/rest/server.php`, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body,
  });
  if (!response.ok) {
    throw new Error(`Moodle HTTP ${response.status}`);
  }
  const payload = await response.json();
  if (payload && typeof payload === "object" && payload.exception) {
    throw new Error(`${payload.errorcode ?? payload.exception}: ${payload.message ?? ""}`);
  }
  return payload;
}

/**
 * Resolve the active engine's function prefix (dual-track safety).
 *
 * local_wizard supersedes the bundled bookingextension_agent when installed,
 * so the bridge probes for its functions first and falls back to the bundled
 * engine. MOODLE_WS_PREFIX overrides the probe.
 */
async function resolvePrefix() {
  if (process.env.MOODLE_WS_PREFIX) {
    return process.env.MOODLE_WS_PREFIX;
  }
  const info = await moodleWs("core_webservice_get_site_info");
  const names = new Set((info.functions ?? []).map((f) => f.name));
  for (const candidate of ["local_wizard", "bookingextension_agent"]) {
    if (names.has(`${candidate}_mcp_call_tool`)) {
      return candidate;
    }
  }
  throw new Error(
    "No MCP facade functions available for this token. Enable the 'Booking AI Agent MCP' " +
      "service, authorise the user and mint the token for that service."
  );
}

const prefix = await resolvePrefix();

/** Fetch the server-side tool definitions for the configured context. */
async function fetchTools() {
  const result = await moodleWs(`${prefix}_mcp_list_tools`, {
    contextid: String(MOODLE_CONTEXTID),
  });
  const payload = JSON.parse(result.toolsjson ?? "{}");
  if (payload.error) {
    throw new Error(`Agent not ready: ${payload.error.code ?? "unknown"} ${payload.error.message ?? ""}`);
  }
  return payload.tools ?? [];
}

/** Map the facade's tool-result JSON to an MCP tool result. */
function toMcpResult(resultjson) {
  const result = JSON.parse(resultjson ?? "{}");
  return {
    content: Array.isArray(result.content) && result.content.length ? result.content : [{ type: "text", text: "" }],
    structuredContent: result.structuredContent ?? undefined,
    isError: Boolean(result.isError),
  };
}

// One bridge process is one MCP session (one stdio connection = one client). A stable per-process
// id keeps this session's pending confirmations isolated from other clients on the same token.
// Two Claude instances start two bridge processes, hence two sessions and two server-side threads.
const SESSION_ID = randomUUID().replaceAll("-", "");

const server = new Server(
  { name: "wizard-mcp-bridge", version: "0.1.0" },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: await fetchTools(),
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  try {
    const result = await moodleWs(`${prefix}_mcp_call_tool`, {
      contextid: String(MOODLE_CONTEXTID),
      toolname: name,
      argsjson: JSON.stringify(args ?? {}),
      idempotencykey: randomUUID().replaceAll("-", ""),
      sessionid: SESSION_ID,
    });
    return toMcpResult(result.resultjson);
  } catch (error) {
    return {
      content: [{ type: "text", text: `Bridge error: ${error.message}` }],
      isError: true,
    };
  }
});

await server.connect(new StdioServerTransport());
console.error(`wizard-mcp-bridge: connected (${prefix}, context ${MOODLE_CONTEXTID})`);
