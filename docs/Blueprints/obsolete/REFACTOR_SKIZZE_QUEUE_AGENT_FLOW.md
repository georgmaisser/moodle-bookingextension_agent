# Refactor-Skizze Queue + Agent Flow (aggressiv)

Ziel: Redundanzen und Altpfade entfernen, Source-of-Truth vereinheitlichen, Lifecycle-Logik klar schneiden.

Scope: mod/booking/bookingextension/agent

## Leitprinzipien

- Ein Lifecycle, eine Wahrheit pro Verantwortung.
- States und Events strikt trennen.
- Confirmation und Retry nicht in mehreren Klassen parallel modellieren.
- Nicht aktiv verwendete Pfade entfernen statt konservieren.

---

## A) Zielarchitektur (kompakt)

1. Source of Truth fuer mutierende Arbeit: Queue-Item.
2. Source of Truth fuer Benutzer-Absicht: pending_intent nur als Pointer auf queue_item_id (kein zweiter Command-Speicher).
3. Decision-Service orchestriert nur Routing und Policy, keine tiefe Queue-/Persistenz-Mechanik.
4. Confirm-Run fuehrt nur bestaetigte Queue-Items aus, ohne zweite Intent-Logik.
5. Retry-Budget als zentrales DTO ueber Runtime + Queue + Execution.

---

## B) Konkreter Umbauplan

## Phase 1: Intent- und Confirmation-Lifecycle entflechten

1. pending_intent auf Pointer-Modell reduzieren
- Behalten: confirmationcode, queue_item_ids, user/context-binding, ttl.
- Entfernen: doppelte command-payloads im pending_intent (wenn queue_authoritative=true).
- Folge: Queue ist alleinige Wahrheit fuer auszufuehrende mutating Commands.

2. Decision-Service aufstellen als reine Pipeline
- Schrittfolge fest:
  - classify -> safety -> route -> enqueue -> preflight -> persist pending pointer
- Entfernen von gemischten Seiteneffekten ausserhalb dieser Reihenfolge.

3. Confirm-Run vereinfachen
- consume pending intent nur als Token-/Berechtigungscheck.
- aktive Queue-Aufloesung und Ausfuehrung rein queue-getrieben.
- kein stilles Umschalten auf alternative Wahrheiten.

## Phase 2: Queue-State-Maschine entschaerfen

1. READY entfrachten
- Beibehalten als technischer pickup-state.
- Zwingend reason_code bei jeder to_ready-Transition.
- Events separat als transition_reason persistieren.

2. SKIP-Semantik festziehen
- SKIP bleibt non-failure terminal.
- Fehleraggregation darf SKIP nicht als failed zaehlen.
- Monitoring getrennt: skipped_count vs failed_count.

3. RUNNING-Slot robust
- try_mark_running bleibt atomar.
- slot-occupied darf nicht still wieder READY schreiben ohne reason.

## Phase 3: Retry globalisieren

1. attempt_budget_dto einfuehren
- Felder:
  - total_attempts
  - loop_attempts
  - preflight_retries
  - execution_retries
  - queue_retries
  - hard_limit
  - exhausted_reason

2. einheitlicher Abbruchvertrag
- Ein zentraler exhausted-Rueckgabepfad fuer alle Layer.
- Keine impliziten Layer-eigenen Endentscheidungen ohne Budget-Update.

## Phase 4: Spawn-Realitaet bereinigen

1. Entweder aktivieren oder entfernen
- Option A: spawn runtime-fertig machen (enqueue + limits + tests).
- Option B: spawn aus Runtime-Diagramm und produktivem Pfad entfernen, nur Contract-Vorbereitung behalten.

2. Falls aktiviert: harte Limits
- max_spawn_depth
- max_children_per_node
- max_spawned_items_per_run
- hard-fail bei Grenzverletzung

---

## C) Loeschkandidaten (nicht minimal-invasiv)

Hinweis: Diese Liste ist bewusst aggressiv und auf Redundanzabbau ausgelegt.

1. Doppelte Intent-Datenhaltung entfernen
- Alle Pfade, die Commands im pending_intent als zweite Wahrheit fuellen/lesen, wenn Queue bereits autoritativ ist.

2. Decision-/Confirm-Ueberschneidungen entfernen
- Doppelte Pending-Absicherungen in beiden Services konsolidieren.
- Doppelte Fallback-Routen auf no_pending_intent reduzieren auf einen klaren Pfad.

3. Spawn-Altpfad entfernen, falls nicht aktiviert
- Runtime-Branches/Legend-Pfade, die spawn als aktiv ausgeben, aber kein produktiver enqueue-loop existiert.

4. Zustand/Event-Vermischungen entfernen
- READY-Uebergaenge ohne reason_code.
- implizite status-Umdeutung ohne zentralen transition contract.

---

## D) Konkrete Dateiziele fuer den Umbau

1. classes/local/wbagent/services/decision/agent_decision_service.php
- auf Pipeline-Verantwortung reduzieren
- redundante persistenznahe und queue-nahe Verzweigungen abbauen

2. classes/local/wbagent/services/confirm_run_service.php
- auf bestaetigen + ausfuehren fokussieren
- doppelte intent-/retry-/fallback-Pfade reduzieren

3. classes/local/wbagent/services/pending_intent_service.php
- Pointer-Modell erzwingen

4. classes/local/wbagent/conversation_store.php
- pending_intent Speicherstruktur vereinfachen

5. classes/local/wbagent/services/queue_transition_service.php
- reason_code Pflicht je Transition
- state/event Trennung

6. classes/local/wbagent/queue/queue_manager.php
- ready/running/retry Übergangsgruende konsistent speichern

7. classes/local/wbagent/agent_runtime.php
- attempt_budget_dto zentral integrieren

---

## E) Reihenfolge fuer Implementierung

1. Pending-Intent Pointer-Modell + Tests.
2. Decision/Confirm-Verantwortungen schneiden.
3. Queue-Transition reason_code contract durchziehen.
4. Retry-Budget DTO zentralisieren.
5. Spawn: aktivieren mit Limits oder komplett entfernen.

---

## F) Risiken bei aggressivem Aufraeumen

1. API-Kompatibilitaet
- response payload Felder (pending_confirmation_code, commands, queueitemid) koennen implizit von UI erwartet werden.

2. Migrationsrisiko bestehender Threads
- alte pending_intent-Strukturen im metadatajson.

3. Testluecken
- contract tests fuer edge cases (retry_waiting, stale queue_item_id, slot contention) muessen vor Loeschwelle stehen.

---

## G) Entscheidungen, die ich vor Loeschungen von dir brauche

1. Spawn-Strategie
- Soll ich spawn runtime-seitig jetzt konsequent entfernen (Option B), oder als echtes Feature fertig ausbauen (Option A)?

2. Pending-Intent-Harteinigung
- Darf ich Commands im pending_intent grundsaetzlich entfernen und nur queue_item_ids erlauben?

3. API-Haerte
- Darf ich response payloads bereinigen, wenn Felder nur historisch sind, aber aktuell nicht strikt noetig?

Wenn du diese 3 Punkte freigibst, setze ich den aggressiven Refactor in einem Zug um.
