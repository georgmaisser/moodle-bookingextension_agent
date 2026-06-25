# Blueprint: Dynamic Skill Discovery (Tool Retrieval RAG)

## 1. Overview & Motivation
As the capabilities of the agentic framework grow, statically loading all available tools (skills) into the LLM context for the `Selection` phase becomes unscalable. It wastes tokens, increases latency, and degrades the LLM's reasoning ability.
Currently, the `Discovery` phase uses `family_signal_ranker` and `family_embeddings_retrieval_service` to provide a bounded set of relevant skills. However, in follow-up queries or abrupt context switches (e.g., user suddenly asking "Can you download the certificate?" after discussing bookings), the initially provided tools might not cover the new intent. 

**Dynamic Skill Discovery** solves this by providing a universal fallback skill: `core.search_skills`. When the LLM realizes the tools provided in its payload don't match the user's intent, it executes `core.search_skills` to query the embedding database and retrieve the right tools on the fly.

## 2. Architecture & Flowchart Integration
This feature leverages the existing multi-step loop capabilities of `agent_runtime` and requires **zero** changes to the orchestrator or core runtime logic.

### 2.1 The Special Skill: `core.search_skills`
- **Definition**: A lightweight, always-available core skill (loaded via `core_family_set`).
- **Schema**: 
  - `query` (string): The search term or intent description to find the right tool.
- **Description in Prompt**: *"If none of the provided skills match the user's request, use core.search_skills with a descriptive query to search the tool registry for additional capabilities."*

### 2.2 The Explicit Agent Loop
Instead of hiding the search in the orchestrator, the agent executes it visibly like any other tool.

**Flow:**
1. **Turn 1 (Selection)**: LLM doesn't find a matching tool in its context payload. It selects `core.search_skills` and formulates a search query (e.g., "download certificate").
2. **UI Feedback**: The frontend naturally shows the step bubble: `Searching capabilities...`.
3. **Execution**: The `core.search_skills` executor runs, querying the `family_embeddings_retrieval_service` using the LLM's query.
4. **Observation Generation**: The executor returns the schemas of the discovered tools as an observation.
5. **Turn 2 (Selection)**: The `agent_runtime` loop continues. The newly discovered tools are injected into the LLM's context via the observation history. The LLM now selects the correct tool (e.g., `mod_booking.download_certificate`) and proceeds.

## 3. Implementation Steps

1. **Add `core.search_skills` to Core Family**
   - Create `classes/local/wizard/skills/core/search_skills.php`.
   - Ensure it is loaded in the `core_family_set`.

2. **Implement the Executor Logic**
   - The executor for `core.search_skills` will directly interface with `family_embeddings_retrieval_service`.
   - It will format the resulting tools into a compact schema representation to be stored as a `result_payload` observation.

3. **No Changes to Core Pipeline**
   - `orchestrator.php` and `agent_runtime.php` remain untouched. The framework naturally supports this "tool that finds tools" pattern via its existing observation-feedback loop.

4. **Telemetry & Debugging**
   - The step is naturally logged in the thread history and `planner_trace_history`.

## 4. Risks & Mitigations
- **Infinite Loops**: The LLM might repeatedly call `core.search_skills` if no tool exists for the user's query.
  - *Mitigation*: Hardcode a limit of 1 tool-search per orchestrator run. If the second selection still yields `core.search_skills` or fails, gracefully degrade to generating a text response: *"I cannot perform that action."*
- **Increased Latency**: An extra LLM round-trip adds 1-2 seconds.
  - *Mitigation*: The step-polling UI (`Searching capability registry...`) masks this latency by keeping the user engaged. 

## 5. Summary
"Tool Retrieval RAG" transforms the agent from a static router into a dynamic problem solver. By keeping the retrieval loop isolated inside the `orchestrator` and preventing the `core.search_skills` command from leaking into the `queue_manager` or `agent_runtime` execution pipeline, the architecture remains clean, deterministic, and highly responsive.
