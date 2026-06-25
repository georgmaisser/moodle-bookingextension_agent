# Observability: Root-Cause-Analyse-Abfragen

Metriken werden in `m_local_wizard_ai_messages.structuredjson` (JSON) und
`m_local_wizard_ai_threads.metadatajson` gespeichert.

## consistency_gate_fail_rate

Anteil der Sync-Schritte, bei denen das Konsistenz-Gate einen Fehler erkannt hat.

```sql
SELECT
  COUNT(*) AS total_sync,
  SUM(JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.sync_gate_status')) = 'failed') AS gate_fails,
  ROUND(100 * SUM(JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.sync_gate_status')) = 'failed') / COUNT(*), 2) AS fail_rate_pct,
  JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.sync_gate_reason')) AS reason,
  COUNT(*) AS count_by_reason
FROM m_local_wizard_ai_messages
WHERE role = 'assistant'
  AND JSON_EXTRACT(structuredjson, '$.sync_gate_status') IS NOT NULL
GROUP BY reason
ORDER BY count_by_reason DESC;
```

## postcondition_fail_rate_by_task

Anteil fehlgeschlagener Postconditions pro Task.

```sql
SELECT
  JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.attempted_skills[0]')) AS task,
  COUNT(*) AS total,
  SUM(JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.postcondition_status')) = 'failed') AS pc_fails,
  ROUND(100 * SUM(JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.postcondition_status')) = 'failed') / COUNT(*), 2) AS fail_rate_pct
FROM m_local_wizard_ai_messages
WHERE role = 'assistant'
  AND JSON_EXTRACT(structuredjson, '$.postcondition_status') IS NOT NULL
GROUP BY task
ORDER BY pc_fails DESC;
```

## stale_narrative_override_count

Wie oft hat das Konsistenz-Gate eine veraltete Assistant-Narration zurückgewiesen.

```sql
SELECT DATE(FROM_UNIXTIME(timecreated)) AS day, COUNT(*) AS overrides
FROM m_local_wizard_ai_messages
WHERE role = 'assistant'
  AND JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.sync_gate_reason')) = 'SYNC_FACT_CONFLICT_REJECTED'
GROUP BY day
ORDER BY day DESC;
```

## Schnellcheck: letzte 20 Gate-Entscheidungen für einen Thread

```sql
SELECT
  id,
  JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.sync_gate_status')) AS gate_status,
  JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.sync_gate_reason')) AS gate_reason,
  JSON_UNQUOTE(JSON_EXTRACT(structuredjson, '$.postcondition_status')) AS pc_status,
  LEFT(content, 120) AS message_preview
FROM m_local_wizard_ai_messages
WHERE threadid = :threadid AND role = 'assistant'
ORDER BY id DESC
LIMIT 20;
```

## Debug-Log: SYNC_* issue_codes in LLM-Calls

```sql
SELECT id, threadid, source, LEFT(responsetext, 200) AS resp
FROM m_local_wizard_ai_llm_debug
WHERE responsetext LIKE '%SYNC_%REJECTED%'
ORDER BY id DESC
LIMIT 20;
```
