# Design constraint: no deny path on the probe until drift and authorisation are separated

**Status:** constraint on future work. This is not a fix, and nothing here is
pending. It exists so the next person who resumes the probe work does not
re-derive the same design.

**Scope of the constraint:** the availability probe in
`src/Availability/OpenCodeProviderAvailability.php` and the credential
classifier in `src/Availability/ConnectionDiagnostics.php`.

**Landing status (verified against `origin/main` at `8a14a54b`):** this note was
first written when none of the probe work had landed, and that framing is now
wrong in one direction and right in the other. **The drift-recovery half has
since shipped** — the `probe_model_unavailable` state, its factory, its two
bucket memberships, and the `isProbeModelDrift()` predicate are all on `main`
today. **The deny half has not** — the filter, its eligibility predicate, the
exported drift-status constant, and the settled-state set are all still absent.
The constraint below is unchanged by either fact; only the framing was.

| Half | Symbol | On `main` today |
|---|---|---|
| Drift recovery | `ConnectionDiagnostics::PROBE_MODEL_UNAVAILABLE_STATE` | **SHIPPED** — `src/Availability/ConnectionDiagnostics.php:56` |
| Drift recovery | `ConnectionDiagnostics::probeModelUnavailable()` | **SHIPPED** — `:597` |
| Drift recovery | `COULD_NOT_BE_CHECKED_STATES` membership | **SHIPPED** — `:91` |
| Drift recovery | `PERSISTENT_UNCHECKABLE_STATES` membership | **SHIPPED** — `:111` |
| Drift recovery | `ConnectionDiagnostics::isProbeModelDrift()` | **SHIPPED** — `:334`, called at `:290` and `:311` |
| Deny path | `duoport_probe_deny_unrecognized` | **NOT SHIPPED** |
| Deny path | `ConnectionDiagnostics::isDenyEligibleStatus()` | **NOT SHIPPED** |
| Deny path | `ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES` | **NOT SHIPPED** |
| Deny path | `OpenCodeProviderAvailability::SETTLED_STATES` | **NOT SHIPPED** |

The deny-path rows are each backed by a grep over the whole tree at `8a14a54b`
that returned nothing: `git grep -n isDenyEligibleStatus origin/main`,
`git grep -n PROBE_MODEL_DRIFT_STATUSES origin/main`,
`git grep -n SETTLED_STATES origin/main`, and
`git grep -n duoport_probe_deny_unrecognized origin/main -- src/ tests/`. (The
last name does appear once on `main`, in the prose of
`PROBE-VERDICT-BOUNDARY.md:72` — describing the filter as abandoned, not as
shipped code.)

The deny filter and its supporting symbols lived only on the closed branch
`fix/issue-128-probe-deny-flag` (PR #131, closed unmerged, preserved on the
remote at `8156d0c` for reference). The drift-recovery symbols above are *not*
from that branch: they landed on `main` independently, and the two halves must
not be conflated when reading the sections below.

---

## 1. What was attempted

An opt-in feature filter, `duoport_probe_deny_unrecognized`, defaulting to
false, that would let a site operator downgrade an unrecognised probe response
from "could not be checked" to a definitive `invalid_key`, so a revoked key stops
reading as connected through the last-known-good window.

The shipped posture stays fail-open. The filter was opt-in precisely so that
adding it was not itself the security decision.

## 2. Symbols the probe work introduced

Commit `ea2c8c4` ("fix: autofix iteration 1") added all of the following. They
are named here so a reimplementation can be checked against a single list. Each
row is marked against `origin/main` at `8a14a54b`, with the file:line for the
ones that shipped:

| Symbol | What it does | Status on `main` |
|---|---|---|
| `ConnectionDiagnostics::isProbeModelDrift()` | Predicate deciding that a verdict means the probe model was refused, not the credential; triggers a retry with a second reviewed paid model. | **SHIPPED** — `src/Availability/ConnectionDiagnostics.php:334`, called at `:290` and `:311`. Note the class: it lives on `ConnectionDiagnostics`, not on `OpenCodeProviderAvailability` as an earlier draft of this note said. |
| inline `array( 400, 404 )` | The drift-status list, written as a literal inside `isProbeModelDrift()`. **Superseded at the branch tip:** commit `8156d0c` replaced the literal with the exported constant `ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES`, read by both `isProbeModelDrift()` and `ConnectionDiagnostics::isDenyEligibleStatus()`. | **NOT SHIPPED** as an exported constant — `PROBE_MODEL_DRIFT_STATUSES` returns nothing from `git grep -n PROBE_MODEL_DRIFT_STATUSES origin/main`. The status list itself exists on `main` only as a literal inside `isProbeModelDrift()`. |
| `OpenCodeProviderAvailability::SETTLED_STATES` | `array( 'probe_model_unavailable' )`; the verdict set that is a settled drift signal. | **NOT SHIPPED** — `git grep -n SETTLED_STATES origin/main` returns nothing. |
| `ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES` | Bucket holding `probe_model_unavailable` and `unknown`, so neither clears last-known-good. | **SHIPPED** — `src/Availability/ConnectionDiagnostics.php:91`, now including `self::PROBE_MODEL_UNAVAILABLE_STATE`. |
| `ConnectionDiagnostics::probeModelUnavailable()` | Factory for the `probe_model_unavailable` verdict. | **SHIPPED** — `src/Availability/ConnectionDiagnostics.php:597`; the state constant is at `:56`, and `PERSISTENT_UNCHECKABLE_STATES` at `:111` also carries it. |
| `ConnectionDiagnostics::isDenyEligibleStatus()` | The deny path's eligibility predicate, which returns false for listed drift statuses. | **NOT SHIPPED** — `git grep -n isDenyEligibleStatus origin/main` returns nothing. |
| `duoport_probe_deny_unrecognized` | The opt-in feature filter itself. | **NOT SHIPPED** — `git grep -n duoport_probe_deny_unrecognized origin/main -- src/ tests/` returns nothing. |

## 3. The defect class: one fact, two copies

`isProbeModelDrift()` recognised probe-model drift at **400 and 404**. The deny
path recognised the same fact by carrying its **own** copy of the same list.

That single duplication produced three separate review findings in one session,
each of them a *different* instance of the same shape — a deny path intercepting
a state that recovery still needs:

1. the model-side 401 (`probe_model_unavailable`), which is the primary drift-retry signal;
2. `unknown` at 400/404, the second shape `isProbeModelDrift()` recovers from;
3. the scope itself — the guard also reached 1xx/3xx, which are not authorisation signals at all.

The two copies were never both wrong in the same commit, which is what let each
pass its own tests. What connects them is that both encode "which statuses mean
the model drifted", and nothing in the type system or the test suite ties the two
encodings together.

**The correct shape:** one exported constant, read by both call sites, with a
ratchet asserting that both reference the constant and that neither holds its own
literal. In this codebase that constant belongs beside the state vocabulary it
qualifies — `ConnectionDiagnostics`, which already owns the single home for what a
probe verdict means. Then a status added to the drift set is automatically denied
by the exemption, and no second edit can forget it.

**Implemented at the branch tip:** commit `8156d0c` ships exactly this shape —
`ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES` (`array( 400, 404 )`), read by
`ConnectionDiagnostics::isProbeModelDrift()` and by
`ConnectionDiagnostics::isDenyEligibleStatus()` (which returns false for listed
statuses), with `tests/Unit/ConnectionDiagnosticsTest.php` iterating the constant
directly (`test_deny_filter_never_reaches_the_drift_statuses`), so a newly added
status is covered automatically. That shape exists only on the closed branch:
`PROBE_MODEL_DRIFT_STATUSES` and `isDenyEligibleStatus()` are both absent from
`main` at `8a14a54b`, while `isProbeModelDrift()` itself has since landed there
(`:334`) still carrying its own literal status list. So the duplication this
section describes is not a thing of the past on `main` — the recovery half
shipped, and the second copy is the half that never did.

The asymmetry that makes this worth enforcing: the retry path failing is a
**liveness** bug (a site cannot recover), while the deny path failing closed is a
**correctness** bug. They are not symmetric, so they will not stay in step by
convention.

## 4. Why the line was closed

Because the set of states recovery needs is not finite and not knowable in
advance.

A deny path has to be told which responses it must not intercept. That list was
found by review, three times, and each finding was a real defect that would have
shipped had the review not caught it. Adding a fourth exemption would most
likely have satisfied the next review and left a fifth shape undiscovered.

**A guard built by enumerating the things it must not break is not a guard. It is
a list that rots.** Every future change to drift recovery — a new gateway shape,
a new retriable status, a new settled verdict — silently widens the set the
deny path must respect, and nothing fails when it happens to.

## 5. The prerequisite

**Drift recovery and authorisation have to be separated as a design change
before any deny path is safe.**

Concretely, a deny path becomes viable only once drift is a first-class
dimension of the verdict rather than a predicate inferred afterwards from
`state` plus `status`. While drift is recovered by pattern-matching a
classification that a later step may rewrite, there is no safe way for an
unrelated later step to decide what to do with the same response: the two steps
are answering different questions from the same evidence, and the second one
overwrites the first.

Suggested direction for whoever picks this up: classify drift at the point the
response is first interpreted, carry it on the verdict itself, and have any
tightening posture read that field instead of re-deriving it. Then the deny path
has a stable contract to be written against, and the retry contract is a property
of the verdict rather than a coincidence of status codes.

**This prerequisite is now partially met, and the unmet half is the load-bearing
one.** What shipped on `main` is the *recovery* side: `probe_model_unavailable`
is a first-class verdict state (`:56`), the factory exists (`:597`), and the
buckets carry it (`:91`, `:111`). What did not ship is the separation itself —
drift is still recovered by `isProbeModelDrift()` (`:334`), a predicate that
infers drift from `state` plus `status` after the fact, which is precisely the
shape this section says a deny path cannot safely be written against. Landing the
recovery half therefore did not satisfy the prerequisite; it made the state
vocabulary richer while leaving the inference in place.

## 6. The flag: abandoned, not shipped disabled

`duoport_probe_deny_unrecognized` was written, tested, and proven defective. It
was **deliberately abandoned rather than merged disabled.**

This is recorded so that nobody finds the name, assumes it was never considered,
and re-derives it. The reasoning is not "the filter is unfinished": the filter
worked as designed and the design was wrong. Shipping it defaulted-off would have
advertised a capability that does not work, behind a docblock most sites never
read, with a flag an operator could switch on believing it was a safe
tightening. An unusable control that looks usable is worse than no control.

Do not reintroduce this filter, in this form or under a different name, without
section 5 first.

## 7. Checklist for a future implementation

Checked boxes below mean *satisfied on the closed branch `8156d0c`*, **not** on
`main`. Neither checked item is true of `main` at `8a14a54b`, because both depend
on `PROBE_MODEL_DRIFT_STATUSES`, which is absent there.

- [ ] Drift is carried on the verdict as a first-class dimension, not re-derived from `state` + `status`. — **not met on `main`**: `isProbeModelDrift()` (`:334`) is still an after-the-fact predicate.
- [x] The drift-status set is **one exported constant**, read by every consumer. — satisfied at `8156d0c` only (`PROBE_MODEL_DRIFT_STATUSES`, both call sites verified); absent from `main`.
- [x] A ratchet asserts each call site references that constant and holds no literal copy. — satisfied at `8156d0c` only (deny tests iterate the constant itself); absent from `main`.
- [ ] The ratchet has a mutation proof per call site: break one side, watch exactly one test fail.
- [ ] Any tightening posture is tested in both directions — that it denies what it claims, and that every recovery shape still reaches its recovery path.
- [ ] The gate is behavioural, not source-level only: a test that runs the probe end to end and asserts the retry still happens.
