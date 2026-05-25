# wbagent/examples

This folder contains reference tasks for third-party developers.

Goals:
- Demonstrate minimal task contracts that are easy to copy and adapt.
- Show one ideal task per target scenario:
  - Scenario A: deterministic readonly task.
  - Scenario B: deterministic multistep task.
  - Scenario C: parent task with spawn_commands and a spawned child task.

Discovery behavior:
- The framework discovers classes under `classes/local/wbagent/*/tasks`.
- Because these examples live under `examples/tasks`, they are discovered automatically
  without touching the framework registry.

Task names provided:
- `examples.readonly_example`
- `examples.multistep_example`
- `examples.spawn_parent_example`
- `examples.spawn_child_example`

Adaptation guide:
1. Copy one example class into your own component namespace.
2. Change `get_name()` to your plugin namespace, e.g. `local_demo.my_task`.
3. Update `get_schema()` and `get_prompt_contract()` metadata.
4. Keep `check_structure()` pure (no DB access).
5. Move DB lookups to `preflight()`.
6. Keep `execute()` focused on prepared input and deterministic results.

Notes:
- The examples avoid writes by design so they are safe in test environments.
- Markers like `[SCENARIO-A]` are intentional and used by real-LLM tests as
  deterministic evidence that the expected example task was executed.
