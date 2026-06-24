# User Specific Memory Blueprint

**Date:** 2026-06-08  
**Author:** Antigravity  
**Status:** Planning  

---

## 1. Motivation & Goal

Allowing users to persist personalized settings, instructions, or facts (e.g., *"Ich bevorzuge Buchungen am Vormittag"* or *"My employee ID is 12345"*) enables the AI agent to provide highly personalized assistance. 

To achieve this:
1. **Dynamic persistence**: We will introduce a database-backed memory system for each Moodle user.
2. **User-controlled memory skills**: We will create skills like `agent.remember_fact` (e.g. *"merk dir das:..."*) and `agent.forget_fact` to manage stored memories.
3. **Automated Context Injection**: Memory records will be fetched and injected into the planning/execution environment on each LLM interaction to guide the planner's decisions.
4. **Limits & Safeguards**: We will enforce strict limit thresholds per user (fact count, character count) to prevent database bloat and prompt length inflation.

---

## 2. Database Schema & Persistence

A new database table `{bookingextension_agent_mem}` will store memory records per user.

### 2.1 Moodle Table definition (`db/install.xml`)
```xml
<TABLE NAME="bookingextension_agent_mem" COMMENT="Stores user-specific memories and preferences for the AI agent">
    <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" COMMENT="User who owns this memory"/>
        <FIELD NAME="memory_text" TYPE="text" NOTNULL="true" COMMENT="The preference text or instruction"/>
        <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0"/>
    </FIELDS>
    <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="userid_fk" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
    </KEYS>
    <INDEXES>
        <INDEX NAME="userid_idx" UNIQUE="false" FIELDS="userid"/>
    </INDEXES>
</TABLE>
```

### 2.2 Database Upgrade Step (`db/upgrade.php`)
A new upgrade step will be registered inside `xmldb_bookingextension_agent_upgrade()`:
```php
if ($oldversion < 2026060801) {
    // Define table bookingextension_agent_mem to be created.
    $table = new xmldb_table('bookingextension_agent_mem');

    // Adding fields...
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('memory_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

    // Adding keys...
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

    // Adding indexes...
    $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);

    // Conditionally launch create table.
    if (!$dbman->table_exists($table)) {
        $dbman->create_table($table);
    }

    // Savepoint.
    upgrade_plugin_savepoint(true, 2026060801, 'bookingextension_agent');
}
```

---

## 3. Limits & Budget Strategy

To ensure database queries remain cheap and user memories do not exceed the context length of LLMs:
- **Maximum facts per user:** 15.
- **Maximum characters per single fact:** 500.
- **Maximum total memory size per user:** 4096 characters.

These checks will be implemented in a new `bookingextension_agent\local\wizard\services\user_memory_service` class. If a user exceeds these thresholds, the `remember_fact` skill will inform the user of the budget limits and suggest forgetting outdated records.

---

## 4. AI Memory Management Skills

Three new skills will be introduced inside `bookingextension_agent` and registered in the skill catalog:

### 4.1 `agent.remember_fact`
- **Description:** "Merkt sich eine Angabe, Präferenz oder Anweisung des Benutzers für zukünftige Interaktionen." (Remembers a preference, fact, or instruction for future interactions).
- **Parameters:**
  ```json
  {
    "type": "object",
    "properties": {
      "fact": {
        "type": "string",
        "description": "The exact preference or instruction to remember. Keep it brief and factual."
      }
    },
    "required": ["fact"]
  }
  ```
- **Example Trigger Phrase:** *"merk dir das: Ich bevorzuge Buchungen am Vormittag"*
- **Execution logic:**
  1. Validate `fact` length (< 500 chars).
  2. Retrieve user's existing records. Check total limit count (< 15) and total size (< 4096 characters).
  3. If within limits, save fact into `{bookingextension_agent_mem}`.
  4. Return a confirmation message (e.g. *"Ich habe mir gemerkt: Ich bevorzuge Buchungen am Vormittag."*).

### 4.2 `agent.forget_fact`
- **Description:** "Vergisst eine zuvor gespeicherte Angabe oder Anweisung." (Forgets a previously saved preference or instruction).
- **Parameters:**
  ```json
  {
    "type": "object",
    "properties": {
      "query": {
        "type": "string",
        "description": "Search term or phrase to locate the memory statement to be deleted."
      }
    },
    "required": ["query"]
  }
  ```
- **Example Trigger Phrase:** *"vergiss: Ich bevorzuge Buchungen am Vormittag"*
- **Execution logic:**
  1. Retrieve user's records from `{bookingextension_agent_mem}`.
  2. Perform a fuzzy/substring match using the `query` parameter on all memory entries.
  3. Delete matched records.
  4. Return confirmation listing the deleted entry, or a warning if no match was found.

### 4.3 `agent.list_memories`
- **Description:** "Listet alle gespeicherten Angaben und Präferenzen über den Benutzer auf." (Lists all stored user preferences and memories).
- **Parameters:** (None)
- **Example Trigger Phrase:** *"was weißt du über mich?"*, *"zeige meine gespeicherten Einstellungen"*
- **Execution logic:**
  1. Retrieve all records from `{bookingextension_agent_mem}` for the current user.
  2. Format them as a numbered bullet point list.
  3. Return the formatted list. If empty, return a message indicating no memories are stored yet.

---

## 5. Automated Context Injection Pipeline

Memory data must be injected automatically on each planner iteration to guide the model.

### 5.1 Orchestration Hook
We will hook into the prompt building pipeline in `bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder.php`.

During bundle generation:
1. Retrieve all stored memory statements for the current user ID:
   ```php
   $memories = $DB->get_fieldset_select('bookingextension_agent_mem', 'memory_text', 'userid = :userid', ['userid' => $userid]);
   ```
2. If memory statements exist, format them into a markdown text block:
   ```markdown
   ### User Personal Preferences & Context:
   You must respect the following user-specific memories/rules when planning or executing skills:
   - User noted: "Ich bevorzuge Buchungen am Vormittag."
   - User noted: "My employee ID is 12345."
   ```
3. Append this block to the **System Context / Instruction** section of the prompt bundle payload.

---

## 6. Implementation Plan & Checklist

- [ ] **Task 1: Database Migration & Schema Setup**
  - [ ] Add the `{bookingextension_agent_mem}` table definition in [db/install.xml](file:///var/www/moodle/public/mod/booking/bookingextension/agent/db/install.xml).
  - [ ] Implement database upgrade logic inside `db/upgrade.php` to deploy the table safely.
  - [ ] Bump version details in [version.php](file:///var/www/moodle/public/mod/booking/bookingextension/agent/version.php).

- [ ] **Task 2: Core Memory Service (`user_memory_service`)**
  - [ ] Create class `bookingextension_agent\local\wizard\services\user_memory_service` under `classes/local/wizard/services/user_memory_service.php`.
  - [ ] Implement `add_memory($userid, $text)` with limit checks (fact count < 15, single length < 500, total length < 4096).
  - [ ] Implement `remove_memory_by_query($userid, $query)` to perform substring deletion matching.
  - [ ] Implement `get_all_memories($userid)` to fetch memory records.

- [ ] **Task 3: AI Memory Skills Integration**
  - [ ] Implement skill `agent.remember_fact` inside a new skill class under `classes/local/wizard/options/skills/remember_fact.php`.
  - [ ] Implement skill `agent.forget_fact` under `classes/local/wizard/options/skills/forget_fact.php`.
  - [ ] Implement skill `agent.list_memories` under `classes/local/wizard/options/skills/list_memories.php`.
  - [ ] Register the new skills in the skill catalog mapping (e.g. `classes/local/wizard/skill_registry.php`).

- [ ] **Task 4: Prompt Context Injection Hook**
  - [ ] Integrate memory fetching in `bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder.php`.
  - [ ] Construct the memory block context string.
  - [ ] Inject the memory block directly into the planner system prompts prior to LLM submission.

- [ ] **Task 5: User Interface (Optional / Extension)**
  - [ ] Add a clean section or card on the [Skill Governance Page](file:///var/www/moodle/public/mod/booking/bookingextension/agent/skill_governance.php) or admin tools showing active database user memories to allow admin review/cleanup.
