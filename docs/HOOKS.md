# Hooks

Public WordPress hooks exposed by the DuoPort Connector for OpenCode.

Every hook documented here is a supported extension point. Anything not listed
is private and may change in any release.

---

## Filters

### `duoport_probe_deny_unrecognized`

Decides what the availability probe does with an HTTP response that matches no
known rule.

**Default: `false` (filter not applied — the fail-open posture ships).**

```php
add_filter( 'duoport_probe_deny_unrecognized', '__return_true' );
```

**Arguments**

| # | Name | Type | Description |
|---|---|---|---|
| 1 | `deny` | `bool` | The value being filtered. Always `false`. Return `true` to deny. |
| 2 | `$status` | `int` | HTTP status code of the response. |
| 3 | `$error_type` | `string` | The upstream error type, lowercased and stripped of separators, so `ModelError`, `model_error` and `model-error` are all `modelerror`. Empty string when the response carried no type. |
| 4 | `$state` | `string` | The fail-open state that would otherwise be returned. Currently always `unknown`. |

The classifier reads only the error type and never passes response bodies to the
filter, so a callback cannot accidentally log credentials.

#### What it does

Returning `true` downgrades an **unrecognised 4xx** from `unknown` (could-not-be-checked,
still reported as configured) to `invalid_key` (definitive, clears last-known-good).

This is a security control, and **enabling it changes how the plugin judges the
validity of a key**. The shipped posture deliberately fails open: an upstream status
the plugin has no rule for is treated as "could not be checked", so a working key
keeps its last-known-good state and the settings screen does not flap to
"Not connected" during a transient upstream change. Turning this filter on trades
that bounded, temporary false "Connected" for a shorter exposure window on a
genuinely revoked key. Decide which risk you prefer before enabling it — do not
enable it as a default and assume it is inert.

#### What it does NOT cover

The filter is never applied outside this scope. A callback cannot widen the posture
by returning `true` for any of the following, because the filter is not consulted at all:

- **A model-side 401** (a 401 the gateway attributes to the requested model). It
  classifies as `probe_model_unavailable`, not `unknown`.
- **400 and 404.** These are the statuses `OpenCodeProviderAvailability::isProbeModelDrift()`
  treats as recoverable probe-model drift; a renamed or retired probe model answers
  at one of them. The list is published as
  `ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES`.
- **1xx and 3xx.** A redirect surfaced by a gateway, or an informational response,
  is not an authorization judgement. Denying on it would cause the false disconnect
  the flag exists to prevent.
- **5xx and transport failures.** They classify as `uncheckable` before the filter
  is reached. The flag makes no claim about them in either direction.

Definitive and quota verdicts are likewise untouched: a real bad key (`invalid_key`),
an exhausted balance (`no_credits`), rate limiting (`rate_limited`) and a Zen
free-tier stop (`free_tier_limit`) classify identically whether or not you enable it.

#### Why the exclusions are not removable

Each excluded bucket is a recovery path, and denying it converts a temporary
misclassification into a permanent one:

| Excluded | What denying it would do |
|---|---|
| model-side 401 | `invalid_key` is not a settled state, so a site that opted in could never recover from a retired probe model: last-known-good destroyed, no path back. |
| 400 / 404 | Same shape. The probe retries these with a second reviewed, paid model; denying them removes that retry, so a renamed model presents as a revoked credential. |
| 1xx / 3xx | A gateway redirect is not evidence about the credential. Denying produces exactly the false disconnect the flag is meant to reduce. |

#### Example: deny a known-bad status only

```php
// Deny 402 (payment required) as a bad key, but leave every other
// unrecognised 4xx fail-open.
add_filter(
	'duoport_probe_deny_unrecognized',
	static function ( bool $deny, int $status ): bool {
		return 402 === $status;
	},
	10,
	2
);
```

#### Testing a change

`tests/Unit/ConnectionDiagnosticsTest.php` holds the regression guards for every
statement on this page, including the non-vacuity proof that a 400/404 still takes
the probe-model drift-retry path with the filter enabled
(`tests/Unit/ProbeSemanticsTest.php::test_probe_model_drift_retries_with_the_deny_flag_enabled`).
Run `vendor/bin/phpunit` after changing the classifier.