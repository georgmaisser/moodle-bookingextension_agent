# Capability Audit 02 — Agent R0 (read-only) skills: cross-context / cross-user fidelity

Scope: the read-only ("R0") wizard skills that return Moodle user / course data.
Engine model recap: **R0 skills skip preflight entirely**, so the engine's `native_capability_guard`
(Gate 2) is never applied. They receive only **Gate 1** = the skill-use capability
`bookingextension/agent:skill_<name>` at the *ambient* context. Therefore each R0 skill MUST self-gate
**per target** inside `execute()`. Skills run in the actor's session (`$USER` == actor), so the core
`$USER`-based helpers (`user_can_view_profile`, `has_capability`, `can_access_course`) are the right
authorities.

Files audited (all under `classes/local/wizard/`):

| Skill | File | Verdict |
|---|---|---|
| core.search_users | core/skills/search_users_skill.php | **HOLE** (identity-field over-exposure) + NEEDS-VERIFICATION |
| core.get_current_user | core/skills/get_current_user_skill.php | SAFE |
| core.diagnose_notifications | core/skills/diagnose_notifications_skill.php | SAFE |
| core.diagnose_permissions | core/skills/diagnose_permissions_skill.php | SAFE |
| course.diagnose_user_in_course | course/skills/diagnose_user_in_course_skill.php | **HOLE** (enrolment-overview path ungated) |
| course.analyze_course_structure | course/skills/analyze_course_structure_skill.php | SAFE |
| course.search_courses | course/skills/search_courses_skill.php | **HOLE** (numeric-id bypasses can_view_course_info) |
| shared base | core/skills/core_skill_base.php | mixed — see per-helper notes |

---

## 1. core.search_users — **HOLE** (over-broad PII payload) + the profile gate is a partial no-op

### What execute() returns
`search_users_skill::execute()` (lines 204-262) resolves arbitrary users **site-wide** via
`search_user_candidates_for_preview()` → core `search_users(0, 0, $query, …)`
(`core_skill_base.php:547`). Core `search_users` with `courseid=0` runs a **completely unrestricted
site-wide `{user}` LIKE query — no capability check at all** (`lib/datalib.php:150-208`,
the `if (!$courseid || $courseid == SITEID)` branch). For every match it returns
`build_user_payload()` (`core_skill_base.php:344-395`), which is a **full contact/PII dossier**:
`email, idnumber, institution, department, city, country, address, phone1, phone2, lang, timezone,
lastaccess/lastlogin/firstaccess, auth, description, customprofilefields[]`, **plus every enrolled
course and every role assignment** (`build_user_courses_payload` + `build_user_roles_payload`). The same
payload is emitted both in `observation_full` (`build_user_observation_full`) and the preview table
(`get_result_preview` — name+email+id).

### The gate that exists (audit 12-F01)
Before adding a user to the payload, execute() calls `actor_can_view_user()` →
`user_can_view_profile($user)` (lines 213-217, 274-278). Users the actor may not view are dropped and
counted (`$hiddencount`).

### THE HOLE TEST
Actor = plain teacher in course A.
- **(a) user they share no course with** — `user_can_view_profile()` is the only barrier. Trace
  (`user/lib.php:1205-1275`):
  - **If `$CFG->forceloginforprofiles` is empty (Moodle default on a large fraction of sites), the
    function returns `true` for ANY non-deleted user at line 1213-1214 — before any capability or
    shared-course check.** On such a site the gate is a **no-op**: the teacher can enumerate the full
    PII dossier of *every* user on the platform by name/email fragment. This is the primary hole.
  - If `forceloginforprofiles` is on, a stranger is correctly dropped (no shared course + no
    `user:viewdetails` on the user context → `false`). So the gate's effectiveness is **config-dependent**,
    which is fragile for a security control.
- **(b) data about a course they can't access** — `build_user_courses_payload()` returns *all* of the
  target's enrolled courses + the target's roles in them, with **no `can_view_course_info` / shared-course
  filter**. So even when the actor legitimately sees the target (shared course A), the payload also leaks
  the target's enrolments and roles in courses B, C, … the actor has nothing to do with. Hole, severity
  medium (course identity + the fact a named person is enrolled there).
- **(c) identity fields** — even on the strict-config happy path where `user_can_view_profile()` returns
  true via shared course A, the payload exposes `email, idnumber, phone1/2, address, institution,
  department, custom profile fields`. Core only shows these **identity** fields under
  `moodle/site:viewuseridentity` + the `showuseridentity` site config (see `user_get_user_details`,
  `get_extra_user_fields`). `user_can_view_profile()` does **not** authorise identity fields — it only
  authorises seeing the profile *page*, which for a non-privileged viewer hides exactly these fields.
  So the skill leaks teacher-invisible identity PII even for users the actor may "see". Hole, severity
  high.

### Verdict: HOLE — the per-target gate is present but (i) a no-op under the common default config and
(ii) the wrong granularity (profile-page visibility, not identity-field / per-course visibility).

### Fix + fallback design
1. **Replace the hand-rolled payload with the core identity-aware path.** Build the returned dossier from
   `user_get_user_details($user, $course, $userfields)` (or at minimum filter the identity fields through
   `\core_user\fields::get_identity_fields($context)` / `get_extra_user_fields($context)`), so `email,
   idnumber, phone, address, department, institution, custom fields` are emitted **only** when the actor
   holds `moodle/site:viewuseridentity` in a context shared with the target. Non-privileged actors then
   get name + profile URL + the courses they *share*, nothing more.
2. **Make the search itself scope-aware instead of site-wide-then-filter.** This is the graceful
   fallback the maintainer wants:
   - If the actor holds `moodle/user:viewdetails` at **system** context (admins/managers) → keep the
     site-wide `search_users(0, 0, …)`.
   - Otherwise **fall back to the union of the actor's own courses**: enumerate
     `enrol_get_all_users_courses($actorid)` and call `search_users($courseid, 0, $query, …)` per shared
     course (or build one `get_enrolled_users`-style query over those contexts), so a plain teacher only
     ever resolves users they co-share a course with — never the whole `{user}` table. This both closes
     the `forceloginforprofiles`-off no-op and removes the dependency on that config flag.
3. **Filter the enrolledcourses/roles payload** (`build_user_courses_payload`) to courses the actor can
   see (`core_course_category::can_view_course_info`), so cross-course enrolment of the target is not
   leaked.

---

## 2. core.get_current_user — **SAFE**

`execute()` (lines 152-184) builds the payload from `global $USER` only — the actor's own record.
No cross-user surface. `build_user_payload($USER)` returning the actor's own PII to the actor is correct.
No gate needed.

---

## 3. core.diagnose_notifications — **SAFE**

Cross-user data: notification/e-mail blocker state for a target user (address present, confirmed,
suspended, emailstop, bounce threshold) and, for admins only, site mail switches + mail-task health.

Gate (lines 211-219): `$isself` always allowed; otherwise
`has_capability('moodle/user:viewalldetails', context_user::instance($targetuserid), $actorid)` — the
**strong** identity cap (managers/admins), explicitly chosen over the weaker `viewdetails` that students
hold. Site-infrastructure rows are additionally gated on `is_siteadmin($userid)` (lines 287-307).

THE HOLE TEST: a plain teacher in course A asking about a stranger → `viewalldetails` on the stranger's
user context fails → `permission_denied`. Cannot reveal another user's notification state. **No hole.**
(Email value is shown, but only past the `viewalldetails` gate, which is stricter than identity-field
visibility, so acceptable.)

---

## 4. core.diagnose_permissions — **SAFE**

Cross-user data: another user's role assignments along the context chain, and whether they hold a named
capability + the ALLOW/PREVENT/PROHIBIT overrides on their roles.

Target-context resolution (lines 221-234): explicit course → ambient context → system. Cross-user gate
(lines 247-252): `$isself` allowed; otherwise `has_capability('moodle/role:review', $targetcontext,
$actorid)`. `role:review` at the **target** context is exactly the core authority for inspecting another
user's roles/permissions (it is what `admin/roles/check.php` requires).

THE HOLE TEST:
- (a) stranger / (c) another user's permissions: teacher in course A asks about course B → target context
  is course B → `role:review` at course B fails → denied. Correct.
- The override SQL (lines 322-346) is restricted to the **target user's own roles** and to the
  context chain of the (gated) target context — no broader leak.

No hole. Self-diagnosis correctly always allowed.

---

## 5. course.diagnose_user_in_course — **HOLE** (one ungated path) + per-aspect gates otherwise SOLID

This is the skill flagged `selfcap:0`. The skill itself does **not** carry a single top-level
"may the actor view this target user in this course" gate; instead it delegates to four per-aspect
diagnosers, **each of which self-gates at the resolved course context**:

| aspect | gate (file) | cap |
|---|---|---|
| access | access_aspect_diagnoser.php:61 | `moodle/role:review` @ course |
| enrolment | enrolment_aspect_diagnoser.php:62 | `moodle/course:enrolreview` @ course |
| progress | progress_aspect_diagnoser.php:70 | `report/progress:view` @ course |
| grades | grades_aspect_diagnoser.php:71-89 | `moodle/grade:viewall` (cross-user) / `grade:view` (self) @ course |

Because every gate uses the **resolved target course's context** (`$coursecontext`), the cross-course
attack is closed: teacher in course A asking about course B resolves to course B's context, where the
actor holds none of these caps → `permission_denied`. Cross-user within the same course is correctly
gated too (`$isself` bypass + the per-aspect cap). **For the four normal aspect paths: SAFE.**

### THE HOLE — the no-course "enrolment overview" path
`execute()` lines 279-292: when **no course is named** and `aspect === 'enrolment'` and a target user was
given, the skill returns `enrolment_overview_result($overviewuser)` (lines 458-485) — the target's
**entire cross-course enrolment list** (every course id/shortname/fullname/url they are enrolled in) —
**with ZERO capability or visibility check**. `$targetuserid` here came from `resolve_userid()` →
`search_user_candidates_for_preview()`, i.e. **any user resolvable site-wide**.

THE HOLE TEST: plain teacher (or any logged-in user with the skill cap) sends
`{aspect: "enrolment", userquery: "<any name/email>"}` and **omits the course**. Result: the full list of
courses that person is enrolled in, with links — for a user they share nothing with. No `is_enrolled`,
no `role:review`, no `course:enrolreview`, no `user_can_view_profile`. This is the same class of leak as
search_users hole (b), but with **no gate at all**. Severity: medium-high.

(Note this path also bypasses the per-course `enrolreview` gate that the *with-course* enrolment aspect
enforces — it is strictly weaker than the normal path it shadows.)

### Fix + fallback design
- Gate the overview before returning it. The cheapest correct gate: require
  `user_can_view_profile($overviewuser)` (the same authority search_users uses) **and** filter the course
  list to courses the actor can see.
- **Fallback to narrower scope** (the maintainer-preferred shape): instead of "all of the target's
  courses", return only **the intersection of the target's courses and the actor's own courses**
  (`enrol_get_all_users_courses($targetid)` ∩ courses where the actor holds
  `moodle/course:enrolreview` / is enrolled). A teacher then sees "yes, this person is also in *your*
  course X" but not their unrelated enrolments. Admins/managers (`moodle/user:viewalldetails` at system,
  or `moodle/course:enrolreview` broadly) keep the full overview.

---

## 6. course.analyze_course_structure — **SAFE**

Cross-course data: a course's sections + activities (names, descriptions, links, visibility flags).

Resolution: `resolve_readonly_course_context_id()` (explicit id → unique name → ambient). The explicit-id
path is the risk (resolver does not check access), and the skill closes it with an **explicit access
gate** (lines 247-253): `can_access_course($course, $accessuser)` for the **actual** acting user, else
`permission_denied`. Below that, contents are filtered per-user by `get_fast_modinfo($course, $userid)` +
`uservisible` inside `course_structure_service::analyze()` — the skill adds no bypassing capability logic
and reports view-but-not-enter items as LOCKED.

THE HOLE TEST: teacher in course A passes `courseid` of course B they cannot access →
`can_access_course` fails → denied. Hidden/restricted activities inside an accessible course are filtered
by core modinfo. **No hole.**

---

## 7. course.search_courses — **HOLE** (numeric-id path bypasses visibility)

Two code paths, asymmetric on visibility:

- **Empty query (list-all)** → `list_course_candidates_for_preview()` (`core_skill_base.php:621-650`):
  iterates `get_courses()` and **filters each through `core_course_category::can_view_course_info()`**
  (line 630). Correct — only courses the actor may see are listed. SAFE.
- **Text query** → `search_course_candidates_for_preview()` text branch (`core_skill_base.php:588-606`):
  uses `core_course_category::search_courses(['search' => …])`, which is **visibility-filtered for the
  actor** by core. SAFE.
- **Numeric query (an id, or any query that is all digits)** → same helper, lines **574-585**: calls
  `get_course((int)$query)` and returns its `fullname/shortname/courseurl/activeenrolledcount`
  **with NO `can_view_course_info` / `can_access_course` check.** HOLE.

THE HOLE TEST: actor (any role) sends `{query: "37"}` (or `resolve_courseid` is asked to pin a numeric
id) for a **hidden course** or a course in a category they cannot see. The skill returns the course's
real fullname, shortname, deep link, and **active enrolment count** — confirming the course exists and how
many people are in it. A plain user can probe ids 1..N to enumerate hidden-course identities + headcounts.
Severity: low-medium (identity + count, not contents).

Note the same un-checked numeric `get_course()` lives in `resolve_courseid()` /
`resolve_readonly_course_context_id()` and in `count_active_course_enrolments()` — but the *consuming*
skills (analyze_course_structure §6, diagnose_user_in_course §5) re-gate with `can_access_course` /
per-aspect caps, so the leak is contained there. It is **only** in `search_courses` that the bare numeric
identity is returned to the user unguarded.

### Fix + fallback design
In `search_course_candidates_for_preview()`'s numeric branch, after `get_course()` apply the same gate
the list-all path already uses:
```php
if (empty($course->id) || !\core_course_category::can_view_course_info($course)) {
    return []; // fall through to the text search / "no results"
}
```
Graceful fallback: when the numeric id is not viewable, **fall back to treating the digits as a search
term** (text `search_courses`, which is visibility-filtered) rather than hard-failing — so a legitimate
"course 101" name search still works, but a hidden id yields no privileged identity. Admins
(`moodle/course:viewhiddencourses` at the course/category context) keep the direct id lookup because
`can_view_course_info` already returns true for them.

---

## Shared base (core_skill_base.php) — per-helper notes

- `build_user_payload` (344) / `build_user_courses_payload` (403) / `build_user_roles_payload` (457):
  **no internal gate and no identity-field filtering** — they emit full PII + all enrolments/roles. They
  are correct only for *self* (get_current_user) or *after* a sufficiently strong caller gate. Today's
  callers (search_users, the enrolment overview) gate too weakly (§1, §5). Hardening these helpers to be
  identity-aware (`get_identity_fields`/`user_get_user_details`) would fix §1(c) at the source.
- `search_user_candidates_for_preview` (525): site-wide `search_users(0,0,…)` with **no cap** — purely a
  resolver; safe only because (and to the extent that) callers gate the *output*. The numeric branch
  (`core_user::get_user`) is likewise ungated resolution.
- `search_course_candidates_for_preview` (568): text branch visibility-filtered (good); **numeric branch
  ungated** (§7).
- `list_course_candidates_for_preview` (621): correctly `can_view_course_info`-filtered.
- `resolve_userid` / `resolve_courseid` / `resolve_readonly_course_context_id`: return ids only — no data
  leak in themselves; safety depends on the downstream gate.

---

## Summary of holes (severity · file:line · fix)

1. **[HIGH] core.search_users leaks teacher-invisible identity PII** (email/idnumber/phone/address/custom
   fields + cross-course enrolments) even for visible users, and the `user_can_view_profile()` gate is a
   **no-op when `$CFG->forceloginforprofiles` is off** (common default) →
   `search_users_skill.php:204-219`, payload from `core_skill_base.php:344-395`.
   Fix: identity-aware payload (`user_get_user_details` / `get_identity_fields`) + scope the search to the
   actor's courses unless they hold `moodle/user:viewdetails` at system.
2. **[MED-HIGH] course.diagnose_user_in_course enrolment-overview path is completely ungated** — any actor
   can list any named user's full cross-course enrolments by omitting the course →
   `diagnose_user_in_course_skill.php:281-287` + `:458-485`.
   Fix: gate on `user_can_view_profile`; fallback = intersect with the actor's own
   `course:enrolreview` courses.
3. **[LOW-MED] course.search_courses numeric-id path bypasses `can_view_course_info`** — enumerate hidden
   courses' identity + active enrolment count by id → `core_skill_base.php:574-585`.
   Fix: add `can_view_course_info` to the numeric branch; fallback to text search.

SAFE: core.get_current_user, core.diagnose_notifications, core.diagnose_permissions,
course.analyze_course_structure, and the four per-aspect diagnosers behind course.diagnose_user_in_course
(access/enrolment/progress/grades — each gated at the resolved course context, closing the cross-course
and cross-user attacks for those paths).
