# B.2/B.3/B.5 — Detaillierter Implementierungsplan (Engine-Service-Grenze)

**Datum:** 2026-06-29
**Status:** Planung — ready-to-execute Schnitt. Kein Code geändert.
**Basis:** `skill_engine_service_boundary_2026-06-29.md` (Status quo + Architektur + Flowchart). Entscheidungen gelockt: echte Interfaces, DI, `base_skill`-Accessoren, kein SDK-Plugin, nachhaltig vor schnell.
**Verifikation je Schnitt:** Agent-Suite (deckt Header-Bild + remember-preview deterministisch). Final: gated Real-LLM/Feature-Tests (Georg-Go).

---

## 0. Designentscheidung: „bounded DI", kein Komplett-Umbau

`booking_skill_support` ist 2940 Z., 42 public + 32 private static, 5 Instanzmethoden. Die Engine-Kopplung ist **gebündelt**:
- thread_memory: 4 private static Wrapper (`resolve/remember_last_option`, `resolve/remember_last_preview`) + ~7 interne Aufrufer.
- skill_catalog: die Instanzseite cacht `skill_discovery::get_skill_instances('mod_booking')` (Z. 183).
- attachment: nur `booking_skill_mutation_execute_service` (1 Klasse, 6 Instanziierungsstellen).

→ **Nachhaltig, aber bounded:** Engine-Dienste werden als **Parameter durch die wenigen gekoppelten Methoden gereicht** (thread_memory) bzw. in die **Instanz injiziert** (skill_catalog) bzw. via **Konstruktor-DI** (attachment in den Mutation-Service). KEIN Umschreiben der 42 reinen Static-Helfer. „Instanzbasiert mit DI" = für die gekoppelten Teile, nicht für die ganze 2940-Z.-Klasse (das wäre über-engineered — siehe Leitplanke).

---

## 1. FOUNDATION (Slice 0 — additiv, kein Verhaltenswechsel)

Neue Contract-Interfaces (Sub-Namespace `interfaces\`, Teil der Contract-Surface):

**`interfaces/attachment_resolver.php`**
```php
interface attachment_resolver {
    /** @return array{path:string,filename:string} */
    public function resolve(string $token, int $userid, int $contextid): array;
}
```
**`interfaces/thread_memory.php`** (abstrahiert Thread weg — Consumer braucht nur key/value pro user+context)
```php
interface thread_memory {
    /** @return mixed null when no active thread / key unset */
    public function get_value(int $userid, int $contextid, string $key);
    public function set_value(int $userid, int $contextid, string $key, $value, int $bookingid = 0): void;
}
```
**`interfaces/skill_catalog.php`**
```php
interface skill_catalog {
    /** @return array<string,skill_interface> keyed by skill name */
    public function instances(string $component): array;
    /** @return array<int,string> */
    public function diagnostics(): array;
}
```

Engine-seitige Implementierungen/Adapter (im Agent, später 1:1 in local_wizard):
- `attachment_token_service implements attachment_resolver` — `resolve()` passt schon signaturgleich, nur `implements` ergänzen.
- **Adapter** `services/attachment/conversation_thread_memory.php implements thread_memory` — wrappt `conversation_store`: `get_value` → `get_active_thread()` (null→null) + `get_thread_metadata_value()`; `set_value` → `get_or_create_thread()` + `set_thread_metadata_value()`.
- **Adapter** `services/skill_catalog_discovery.php implements skill_catalog` — wrappt static `skill_discovery::get_skill_instances()/get_last_diagnostics()`.

`base_skill`-Accessoren (gleiches Muster wie `pass()/invalid()`; base_skill IST die aktive Engine → liefert deren Impl):
```php
protected function attachments(): attachment_resolver { return new attachment_token_service(); }
protected function thread_memory(): thread_memory { return new conversation_thread_memory(); }
protected function skill_catalog(): skill_catalog { return new skill_catalog_discovery(); }
```
*Verifikation Slice 0:* rein additiv → Agent-Suite unverändert grün. Commit (agent-repo).

---

## 2. SLICE B.5 — attachment (Header-Bild)

- **Engine:** Slice 0 deckt `attachment_token_service implements attachment_resolver` + `base_skill::attachments()`.
- **`booking_skill_mutation_execute_service`:** Konstruktor-DI:
  ```php
  public function __construct(private ?attachment_resolver $attachments = null) {}
  ```
  `apply_headerimage_token_to_data()`: `new attachment_token_service()` → `$this->attachments?->resolve($token,$userid,$contextid)` (wenn null → wie „kein Token", defensiv). Import `attachment_token_service` raus, `attachment_resolver` (Interface) rein.
- **6 Instanziierungsstellen** `new booking_skill_mutation_execute_service()`:
  - 5 Skills (bulk_update, update_option, update_option_trainer, create_option, **booking_skill_base**:641): → `new booking_skill_mutation_execute_service($this->attachments())`. Da alle `extends booking_skill_base extends base_skill`, ist `$this->attachments()` da.
  - **booking_skill_support:207** (statisch, kein `$this`): die umgebende Methode bekommt einen `?attachment_resolver $attachments = null`-Parameter, der vom aufrufenden Skill (`$this->attachments()`) durchgereicht wird. (Aufruferkette dort prüfen — ggf. 1–2 Methoden threaden.)
- *Verifikation:* Header-Bild-Tests (`bookingoptionimage`-Set-Data/Source/Postcondition) im Agent-Suite-Lauf; final gated Real-LLM Header-Bild-Flow.
- *Done-Gate:* kein `attachment_token_service` mehr in `mod_booking/classes/local/wizard/**`.

---

## 3. SLICE B.2 — thread_memory („letzte (Preview-)Option merken")

- **Engine:** Slice 0 deckt `conversation_thread_memory` + `base_skill::thread_memory()`.
- **`booking_skill_support`:** die 4 privaten Wrapper auf den injizierten `thread_memory` umstellen:
  - `resolve_last_option_for_user(cmid,userid,thread_memory)` → `$memory->get_value($userid, ctx, 'lastworkedoptionid')` statt `new conversation_store()`.
  - analog `remember_last_option`, `resolve_last_preview_option_ids`, `remember_last_preview_options` → `get_value/set_value`.
  - `thread_memory` als Parameter durch die ~7 Aufrufer threaden (294/306 in `resolve_single_option`, 2697, 2847/2865/2877/2888 — das sind die public-static Einstiegspunkte, die Skills rufen). Diese Einstiegspunkte bekommen `?thread_memory $memory = null`; der Skill übergibt `$this->thread_memory()`.
  - Import `conversation_store` raus.
- **`resolve_contextid_from_cmid`** bleibt (rein booking).
- *Verifikation:* Preview-Selektions-/last-option-Tests (Thread-322-Pfad) im Agent-Suite-Lauf; final gated.
- *Done-Gate:* kein `conversation_store` mehr in `mod_booking/classes/local/wizard/**`.

---

## 4. SLICE B.3 — skill_catalog (Skill-Enumeration)

- **Engine:** Slice 0 deckt `skill_catalog_discovery` + base-Accessor (oder Provider-Wiring).
- **`booking_skill_support`:** der Instanzteil (`get_skill_instances()` Z.183) bekommt `skill_catalog` injiziert (Konstruktor der Instanz) → `$this->catalog->instances('mod_booking')` statt `skill_discovery::get_skill_instances('mod_booking')`. Die 5 Instanzmethoden + die `$support`-Aufrufer (235,1765) bleiben, bekommen den injizierten Support.
- **`skill_provider`:** `get_skills()`/`get_discovery_diagnostics()` → `(new skill_catalog_discovery())->instances/diagnostics('mod/booking')`. *(skill_provider ist der engine-seitige Adapter; akzeptabel, dass er die Engine-Impl direkt nimmt — er IST die Brücke. Wichtig: er nennt das Interface, nicht `skill_discovery` direkt.)*
- Import `skill_discovery` raus (mod_booking-Seite).
- *Verifikation:* Skill-Registry-/Discovery-Tests (Katalog identisch vor/nach).
- *Done-Gate:* kein `skill_discovery` mehr in `mod_booking/classes/local/wizard/**`.

---

## 5. Reihenfolge & Commits
1. **Slice 0 (Foundation)** — additiv, 1 Commit (agent-repo). Agent-Suite grün.
2. **B.5** — agent (Service implements) + booking (Mutation-Service-DI). Agent-Suite + Header-Bild-Tests.
3. **B.2** — booking (thread_memory threading). Agent-Suite + Preview-Tests.
4. **B.3** — booking (skill_catalog) + skill_provider. Agent-Suite + Registry-Tests.
5. **Final:** gated Real-LLM/Feature-Suite (Georg-Go) über Header-Bild + remember-preview + Discovery.

booking-Commits **pfad-limitiert** (`git commit -F msg -- <files>`) wegen paralleler Agents im booking-Repo ([[feedback_git_commit_shared_index]]).

## 6. Gesamt-Done-Gate Phase 0
- `grep -rn "bookingextension_agent\\local\\wizard\\(conversation_store|skill_discovery|services\\attachment\\attachment_token_service)" mod/booking/classes/local/wizard/` → **0**.
- Skills/Support nennen nur Contract-Interfaces + base_skill-Accessoren.
- Agent-Suite grün + gated Feature-Tests grün.

## 7. Offene Mikro-Punkte (bei Umsetzung klären)
- Exakte Aufruferkette `booking_skill_support:207` (Mutation-Service) — wie viele Methoden müssen `attachment_resolver` threaden? (1× lesen bei B.5.)
- `thread_memory`-Einstiegspunkte: sind 294/306/2697/2847/2865/2877/2888 alle public-static von Skills gerufen, oder gibt es interne Ketten? (1× lesen bei B.2.)
- `skill_provider` get_component liefert 'mod/booking' — Komponenten-String für `instances()` konsistent halten.
