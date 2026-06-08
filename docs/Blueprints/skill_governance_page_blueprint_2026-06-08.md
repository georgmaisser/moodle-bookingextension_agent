# Skill Governance & Analysis Page Blueprint

**Date:** 2026-06-08  
**Author:** Antigravity  
**Status:** Completed  

---

## 1. Motivation & Goal

Currently, skill activation is managed directly inside the Moodle Admin settings page (`bookingextension_agent/settings.php`). This listing is non-interactive, cluttered, and does not provide administrative insights into skill definition details or embedding collisions. 

We will:
1. **Deprecate the inline skill checkbox list** from the main admin settings page.
2. **Introduce a dedicated, standalone Skill Governance & Analysis page** at `/mod/booking/bookingextension/agent/skill_governance.php` that is linked from the Moodle admin tree.
3. **Enhance this page with detailed diagnostics**, collapsibles showing metadata (examples, triggers, prompts), capability checks, embedding collision analysis, and a trigger button for the ad-hoc skill catalog rebuild task.

---

## 2. Architecture & Key Features

### 2.1 Moodle Linkage & Navigation
- The page will register as an `admin_externalpage` inside Moodle.
- In `settings.php`, the long list of checkboxes will be replaced with a heading and a direct link button:
  * URL: `/mod/booking/bookingextension/agent/skill_governance.php`
  * Permissions required: `moodle/site:config` (system context).

### 2.2 Skill Status and Configuration Toggles
- Fetch all registered skills via `skill_registry_factory::get_default()`.
- Display a table containing all skills with checkable columns to enable/disable them.
- Save settings via Moodle standard config writes:
  ```php
  set_config($settingname, $value, 'bookingextension_agent');
  ```
  where `$settingname` is computed via `skill_registry::get_skill_toggle_setting_name($skillname)`.
- Provide bulk "Enable All" and "Disable All" toggles at the top of the table.

### 2.3 Detailed Skill Metadata Collapsibles
For each skill, a bootstrap collapsible row (`<div class="collapse">`) will expose:
- **Description:** Taken from `$meta['schema']['description']`.
- **Target Capability:** The capability required to run the skill.
- **Example Input:** Pretty-printed JSON of `$skill->get_example_input()`.
- **Message Triggers:** List of user triggers from `$skill->get_message_triggers()`.
- **Contextual Guidance:** Prompt packs from `$skill->get_contextual_prompt_packs()`.

### 2.4 Embedding Collision Analysis
- Instantiate `skill_selection_debug_service` to execute `analyze_collisions()`.
- Retrieve similarity scores and collision risk classifications (`ok`, `warning`, `high`).
- Highlight high-collision pairs directly next to affected skills (e.g. *"Warning: High collision risk with booking.update_option_trainer (Similarity: 0.88)"*).
- Display a warning badge at the top of the page indicating the total count of high-risk collisions.

### 2.5 Catalog Rebuild Action
- Add a POST action button "Rebuild Skills Catalog".
- Clicking the button initiates:
  ```php
  $task = new \bookingextension_agent\task\rebuild_skill_catalog_embeddings_adhoc();
  \core\task\manager::queue_adhoc_task($task, true);
  ```
- Print a success notification confirming that the ad-hoc task was queued and will run on the next cron execution.

---

## 3. Code Reuse & Anti-Duplication Principles

To keep the codebase lean and maintainable, we must strictly reuse existing classes and logic:
1. **Skill Discovery**: Do not query the DB or scan plugin files directly. Always use `skill_registry_factory::get_default()` to get the current list of skills and their registered schemas/contracts.
2. **Embedding Collision Analyzer**: Do not duplicate the cosine similarity logic, the lexical/vector ranking system, or classification thresholds. Directly instantiate `bookingextension_agent\local\wbagent\services\debug\skill_selection_debug_service` and use its `analyze_collisions()` method.
3. **Rebuild Task Queueing**: Do not write new ad-hoc task dispatch logic. Queue the existing `rebuild_skill_catalog_embeddings_adhoc` task.
4. **UI Components**: Rely on Moodle's built-in `html_writer`, `html_table`, and core Bootstrap collapsible widgets instead of writing raw custom HTML widgets or duplicate styling scripts.

---

## 4. Implementation Steps & Checklist

- [x] **Task 1: Deprecate Legacy Settings & Add Navigation Link**
  - [x] Remove individual skill checkbox settings from `bookingextension_agent/settings.php`.
  - [x] Retain the "enable all" toggle callback service or move it to the new page.
  - [x] Add an `admin_externalpage` node in `settings.php` for `bookingextension_agent_skillgovernance` pointing to `skill_governance.php`.
  - [x] Add a clean settings heading and description linking to the new page.

- [x] **Task 2: Create Standalone `skill_governance.php` Page**
  - [x] Define the entry file at `/mod/booking/bookingextension/agent/skill_governance.php`.
  - [x] Enforce security checks: `require_login()`, `require_capability('moodle/site:config', context_system::instance())`.
  - [x] Set page context, layout (`admin`), titles, and headers.
  - [x] Handle POST actions for saving configuration settings (toggled checkboxes).
  - [x] Handle POST actions for triggering the catalog rebuild ad-hoc task.

- [x] **Task 3: Integrate Collision Analysis & Diagnostics**
  - [x] Import `skill_selection_debug_service`.
  - [x] Run `analyze_collisions()` to collect similarity values for all skill pairs.
  - [x] Map active collisions back to their respective skills.
  - [x] Set thresholds for warning (e.g. similarity >= 0.75) and high risk (similarity >= 0.85).

- [x] **Task 4: Build UI/UX Page Layout**
  - [x] Implement a Bootstrap 4 card layout.
  - [x] Add search/filter box to filter skills dynamically via client-side JavaScript.
  - [x] Render the main list of skills with:
    - Activation checkbox.
    - Skill name and component.
    - Required capability.
    - Collision status badge (Success: Clear, Danger: Collision Risk).
  - [x] Create the HTML template or code blocks for the collapsible skill details.
  - [x] Format Example Input and Guidance rules as Markdown or syntax-highlighted blocks.

- [x] **Task 5: Verification & Quality Assurance**
  - [x] Verify that saving changes updates the `config_plugins` database table correctly.
  - [x] Run PHPUnit tests and confirm that setting updates do not trigger regressions in skill discovery.
  - [x] Validate that the "Rebuild Skills Catalog" button successfully adds the ad-hoc task to the queue.
  - [x] Compile and lint any new JS helpers.
  - [x] Conduct a code review to verify zero logic duplication (e.g. similarity classification, registry retrieval, ad-hoc execution).
