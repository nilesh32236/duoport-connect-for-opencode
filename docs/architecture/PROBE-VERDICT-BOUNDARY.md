# Probe verdict boundary: four constraints from a failed three-cycle branch

**Status:** constraint on future work, not a fix and not a plan. Nothing here is pending.

**Subject:** `src/Availability/ConnectionDiagnostics.php` (the verdict vocabulary) and
`src/Availability/OpenCodeProviderAvailability.php` (what does with a verdict).

This records what three review cycles of PR #134 cost, because the findings were
cheap and the patterns behind them are not. That branch is closed unmerged and
preserved on the remote (`fix/probe-unknown-fallback-and-drift-recovery`); only
the `unknown` bucket fix was extracted, as its own small PR.

---

## 1. The one root cause behind almost everything

**An unbucketed state fell the unsafe way by omission.**

The probe's verdict vocabulary has three buckets: keyed, could-not-be-checked,
and definitively negative. `unknown` — the verdict every unrecognised response
classifies to — was in *none* of them. The decision path then did:

```php
if ( KEYED ) { return true; }
if ( COULD_NOT_BE_CHECKED ) { return $this->readLastGood(); }
return false;   // <- `unknown` landed here
```

and a sibling site cleared last-known-good from a bare `else`, so any state
nobody had classified cleared the flag and failed **closed**. A 400 or 404 — what
OpenCode returns after it renames or retires a model — disconnected working keys
on the default branch.

**The constraint:** membership of a bucket must never be what decides a
credential outcome. Write the decision as positive membership tests on both
sides, and let everything unclassified fall through to the fail-open verdict:

```php
if ( in_array( $state, KEYED_STATES, true ) ) { return true; }
if ( in_array( $state, DEFINITIVE_NEGATIVE_STATES, true ) ) { return false; }
return $this->readLastGood();   // bucketed OR not, including no bucket at all
```

Testing "is this in the could-not-be-checked bucket?" is the trap: it sends an
unbucketed state to `return false`. Failing open **by omission** is the only
safe direction for a state nobody has classified yet.

The settings status line has the same rule in a different medium — see §4.

## 2. A deny path on the probe is not safe, and enumerating exemptions is not a guard

An opt-in filter, `duoport_probe_deny_unrecognized`, was written to downgrade an
unrecognised response to a definitive `invalid_key`. It was abandoned rather than
merged disabled.

A deny path must be told which responses it must **not** intercept, because those
are the ones drift recovery needs. Three review cycles found three instances of
the same shape — a deny path intercepting a state recovery still needs:

1. the model-side 401, which is the primary drift-retry signal;
2. `unknown` at 400/404, the second shape `isProbeModelDrift()` recovers from;
3. and the scope itself, which also reached 1xx/3xx — a redirect is not an
   authorisation signal.

The set of such states is not finite: it grows every time drift recovery grows.
**A guard built by enumerating the things it must not break is not a guard, it is
a list that rots.** A fourth exemption would most likely have satisfied the next
review and left a fifth shape undiscovered.

**The constraint:** drift recovery and authorisation must be separated as a
design change before any deny path is viable. Concretely, drift should be a
first-class dimension of the verdict, carried on the verdict itself, rather than
a predicate re-derived afterwards from `state` plus `status`. While drift is
recovered by pattern-matching a classification that a later step may rewrite,
there is no safe way for an unrelated later step to decide what to do with the
same response: two steps answering different questions from the same evidence,
where the second overwrites the first.

**Do not reintroduce that filter, renamed or not**, without that redesign first.
It was written, tested, and proven defective. Shipping it defaulted-off would
have advertised a capability that does not work.

## 3. One fact, one list — and a lock ordering with a ratchet

Two rules that look like style and are not.

**One fact, one list.** The drift statuses lived in two places at once: inside
`isProbeModelDrift()`, and again inside the deny-path work. Two copies of one
fact is what let the three findings in §2 through review in a single session —
neither copy was wrong in the same commit as the other. When a fact is consumed
in more than one place, publish it once and read it from there.

**One lock ordering, and ratchet it.** The stampede lock and the verdict cache
must be written in one order on *every* exit: cache the verdict, then release
the lock. Releasing first leaves an instant in which neither the lock nor the
cached verdict covers the request, and a caller landing there starts a full
duplicate probe round — two requests for one logical check.

This is not visible in the final state. Both orders produce identical outcomes,
so only a recording of the **call order** can see it. PR #134 established the
invariant in `probe()` and added an ordering test, then left
`store_verify_verdict()` with the inverse ordering — a review finding had named
both as identical, and one was fixed and one was not. The constraint: **when an
ordering invariant is established in one place, every place with the same
sequence is suspect until checked**, and the check is an assertion on order, not
on outcome.

## 4. The user-facing line is a verdict, not a boolean

`isConfigured()` returns a boolean and discards the verdict behind it. Rendering
that boolean as "connected" presents a preserved last-known-good flag as a
current, verified connection — for up to the 30-day flag lifetime, with no
indication the current probe could not verify anything.

**The constraint:** drive any user-facing status line from the verdict state, not
the boolean, and use positive membership on both sides so that a state nobody has
classified cannot reach the plain "connected" branch. A useful shape:

| Verdict | Rendered |
|---|---|
| in `KEYED_STATES` | `connected` |
| in `DEFINITIVE_NEGATIVE_STATES` | `not connected` |
| anything else, flag set | `connected · could not verify` |
| anything else, no flag | `not connected` |

A preserved flag is not a current connection, and the operator is the person who
has to tell the difference.

## 5. The shape that appeared three times in one session

Not a bug, a *pattern*. Three separate findings were the same mistake:

1. a docblock claiming a contract the code did not have;
2. a test that passed against rejected code;
3. a `catch` clause left behind by a signature change, so a settings page hit a
   `TypeError` under `strict_types=1`.

Each is a partial edit: two of three call sites updated, the third assumed. The
constraint is procedural, not technical — **when a signature or return shape
changes, enumerate the call sites before the change and re-enumerate after it.**
A `catch` block is a return-value producer like any other, and it is the one
least likely to be grepped.

## 6. Process note

Three cycles produced six findings, then four, then eight. The count going *up*
in the last cycle is the signal that the branch had grown past what one review
could hold, not that the reviewer became less accurate. The extract that followed
— the one live bug, alone, with the test that was written before the fix and run
against unfixed `main` — is what this branch was worth.
