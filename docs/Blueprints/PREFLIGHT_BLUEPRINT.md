Zwei Diagramme: erst die Gesamtarchitektur des Preflight-Systems als Strukturdiagramm, dann der detaillierte Ablauf mit allen Zuständen.

pix/preflight_flow_with_backoff.svg

Das ist die Struktur der drei Schichten. Jetzt der konkrete Ablauf mit allen Entscheidungspfaden und dem Backoff-Mechanismus:

pix/preflight_target_architecture.svg

Hier die konkreten Implementierungsziele, die direkt aus dieser Architektur folgen:

Schicht 1 – Schema-Validation (Implementierungsziel)
Ein synchroner, I/O-freier Validator der gegen ein zentrales command_schema.json läuft – einmal geladen, gecacht, nie neu geladen pro Request. Schlägt er fehl, gibt er sofort hard_block mit issue_code: schema_error zurück. Keine Weiterleitung, kein Retry. Zieldauer: unter 5 ms. In Moodle konkret: ein PreflightSchemaValidator-Service der im DI-Container als Singleton lebt.
Schicht 2 – Domain-Checks (Implementierungsziel)
Die drei Checks (Conflict, Permission, Precondition) laufen parallel als Promise.all mit einem gemeinsamen Timeout von 500 ms. Jeder Check gibt einen eigenen CheckResult zurück mit status: pass | soft_block | hard_block und einem issue_code. Der Conflict Detector fragt dabei den Queue-State ab – er muss read-only sein und darf nie selbst etwas schreiben. Das ist wichtig weil Preflight auch im autoconfirmmode läuft: Seiteneffekte hier würden den Modus unterlaufen.
Schicht 3 – Execution Gate mit Backoff (Implementierungsziel)
Das ist der Kern der fehlenden Logik. Das Gate liest retry_count aus dem Queue-Item und entscheidet:

backoff_ms = base_ms × 2^retry_count + random(0, jitter_ms)
Konkrete Startwerte für deinen Moodle-Kontext: base_ms = 500, jitter_ms = 200, max_retries = 4. Nach dem vierten Versuch gibt das Gate hard_block mit issue_code: max_retries_exceeded zurück – unabhängig davon ob der eigentliche Check ein retry_hint hatte. Der backoff_ms-Wert wird als Attribut in den Queue-Eintrag geschrieben, der Scheduler liest ihn vor dem nächsten Pickup.
PreflightResult-Kontrakt (Implementierungsziel)
Alle drei Ausgabepfade deines bestehenden Diagramms (PR-Node) müssen auf diesen einheitlichen Typ mappen:

typescript
type PreflightResult = {
  status: 'pass' | 'soft_block' | 'hard_block' | 'retry_hint';
  issue_codes: string[];
  blocking_layer: 1 | 2 | 3 | null;
  retry_after_ms: number | null;
  retry_count: number;
  duration_ms: number;
}
retry_hint ist kein Terminal-State – er geht zurück in den Execution Gate der dann über Backoff oder hard_block entscheidet. Das trennt "was der Check sagt" von "was der Loop tut", was in deinem aktuellen Diagramm noch vermischt ist.
Audit Logger (Implementierungsziel)
Jeder Preflight-Durchlauf schreibt einen unveränderlichen Log-Eintrag – auch bei pass. Das ist notwendig damit du nachvollziehen kannst warum ein Job im autoconfirmmode durchgelassen wurde ohne blocked_confirmation zu erzeugen. Ohne diesen Log ist autoconfirmmode forensisch blind.