# PR #40 - Additional Configuration Options

- **Author:** [Petar Fedorovsky](https://github.com/ideaconnect/symfony-nats-messenger/pull/40) (sofascore)
- **Branch:** `main` (contributor fork) → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/40
- **Status:** Open - local review completed 2026-08-04; all findings fixed on the internal branch
  `pr-40-fixes` (15 commits on top of `eded4d1`), pending decision on how to land them
- **Reviewed at:** commit `eded4d1`, 12 files, +1039 / -63

## What the PR Proposes

1. **Eleven new stream options** surfacing JetStream stream settings the client already supports:
   `stream_retention` (`limits`|`interest`|`workqueue`), `stream_discard` (`old`|`new`),
   `stream_duplicate_window` (seconds), `stream_max_message_size` (bytes), `stream_max_consumers`,
   `stream_compression` (`none`|`s2`), `stream_description`, and the access-policy flags
   `stream_deny_delete`, `stream_deny_purge`, `stream_allow_direct`, `stream_allow_rollup_headers`.
   Enum-backed values are validated with an allowlist error message, like `retry_handler`.

2. **Three new consumer options:** `max_ack_pending`, `inactive_threshold` (seconds), and
   `replay_policy` (`instant`|`original`).

3. **`auto_setup` option** (default `false`) - the transport provisions the stream and consumer once,
   lazily, on the first `send()`/`get()` instead of requiring `messenger:setup-transports`.

4. **`TypeCoercion::boolValue()`** centralizing the `mixed → bool` policy previously inlined in
   `NatsTransportConfigurationBuilder::toBool()`.

5. **`stream_retention` treated as immutable on an existing stream**, mirroring the pre-existing
   `stream_storage` handling: written only at creation, server value preserved on update.

## Review Verdict

**Request changes.** The implementation quality is high - PHPStan level max is clean, 323 unit tests /
883 assertions pass, statement coverage is 99.67%, and every option-to-client mapping was verified
field-by-field against the client source and a live server (including the non-obvious
`duplicateWindow()` seconds→nanoseconds and `inactiveThreshold()` ms→nanoseconds conversions and the
abbreviated `allow_rollup_hdrs` wire name). Two issues block the merge; both are on the stream/consumer
**update** path, and both are invisible to the current CI matrix because it only runs the newest
`nats:alpine` image.

## Blocking Issues

### B1 - `max_consumers = -1` on the update path breaks `setup()` for existing users

**Where:** `src/NatsTransport.php:839-840`, in `buildUpdatedStreamConfiguration()`

```php
$updatedConfiguration['max_msg_size']  = $this->configuration->streamMaxMessageSize() ?? -1;
$updatedConfiguration['max_consumers'] = $this->configuration->streamMaxConsumers()   ?? -1;
```

Neither key is written on `main` (`git show origin/main:src/NatsTransport.php | grep max_consumers`
returns nothing), and `StreamConfiguration::toArray()` only emits explicitly-set keys - so before this
PR the `array_merge($serverConfiguration, ...)` overlay always echoed the server's own values back and
the fields were effectively untouched. The PR makes both authoritative, with no way to opt out.

Reproduced against real servers using a stream pre-created with `max_consumers=3` (as an operator would
via nats CLI or Terraform), comparing the payload `main` sends with the payload this PR sends:

| nats-server | `main` payload | PR payload |
|---|---|---|
| 2.10.29 | OK | **rejected** - `stream configuration update can not change MaxConsumers` |
| 2.11.17 | OK | **rejected** - same |
| 2.12.12 | OK | accepted, `max_consumers` silently `3` → `-1` |
| 2.14.2 | OK | accepted, `max_consumers` silently `3` → `-1` |

Two distinct failures, both requiring **zero opt-in** from the user:

- **Hard failure on `^2.9`-`2.11`.** `messenger:setup-transports`, which worked before, now throws
  permanently. `README.md` declares support for `**NATS Server**: ^2.9`. With `auto_setup=true` the same
  failure fires from every `send()`/`get()`, taking the transport fully down.
- **Silent limit removal on 2.12+.** A live stream's operator-set `max_msg_size` / `max_consumers` are
  rewritten to `-1` on the next `setup()`, even when none of the new options are configured. This
  contradicts the documented `setup()` contract ("merges subjects, preserves server fields, never
  blindly overwrites") in `CLAUDE.md` and `README.md`.

CI runs `nats:alpine` (2.14.2), where the update is permitted - which is why the suite is green.

**Fix:** make both keys tri-state on the update path, exactly like the existing
`hasExplicitStreamReplicas()` guard at `src/NatsTransport.php:858`:

```php
if ($this->configuration->streamMaxConsumers() !== null) {
    $updatedConfiguration['max_consumers'] = $this->configuration->streamMaxConsumers();
} elseif (array_key_exists('max_consumers', $serverConfiguration)) {
    $updatedConfiguration['max_consumers'] = $serverConfiguration['max_consumers'];
}
```

Ideally also skip the write entirely when the desired value already equals the server's, so 2.9-2.11
never sees a change at all. Then extend `testSetupUpdateResetsUnsetStreamLimitsToUnlimited` to cover
both keys.

### B2 - `replay_policy` is immutable on an existing durable consumer

**Where:** `src/NatsTransport.php:490-493`, applied unconditionally before `addConsumer()`

```php
$replayPolicy = $this->configuration->replayPolicy();
if ($replayPolicy !== null) {
    $consumerConfiguration->replayPolicy($replayPolicy);
}
```

`addConsumer()` issues `CONSUMER.DURABLE.CREATE`, which is an update for an already-existing durable.
NATS refuses to change the replay policy. Reproduced on 2.10 (same result on 2.12 and 2.14):

```
1. existing deployment: durable consumer, no replay_policy set
   created, replay_policy = instant
2. operator adds ?replay_policy=original and re-runs setup
   ERROR: replay policy can not be updated
```

Removing the option again does **not** recover: the consumer would then be `original`, the transport
omits the field, the server defaults it back to `instant`, and the update is rejected in the other
direction. The consumer has to be deleted. `setup()` has already updated the stream before
`addConsumer()` throws, so the failure is partial. With `auto_setup=true`, every `send()`/`get()` throws.

The PR handles this exact hazard class for `stream_retention` (`src/NatsTransport.php:846-852`, plus a
README warning); `replay_policy` got neither the guard nor a caveat.

**Fix:** at minimum, document the immutability next to the existing retention/storage warning in
`README.md` and `docs/CHANGELOG.md`. Better: probe the live consumer and only send `replay_policy` when
the durable does not exist or already matches, or fail with a message naming the option and telling the
operator to recreate the consumer.

## Non-Blocking Issues

### Test quality - four surviving mutants

`NatsTransport` reports 100% line coverage, so the coverage gate catches none of these:

| Behavior | Surviving mutation |
|---|---|
| auto_setup runs **before** the operation | Moving `autoSetupIfEnabled()` after `publish()` / `fetchBatch()` keeps the suite green |
| Retention preserved on update (`:850`) | Deleting the branch keeps the suite green - the only update test sets no `stream_retention`, so `array_merge` already preserves it |
| Documented "retried on the next call" (`:521-527`) | Swapping `$this->setup();` and `$this->autoSetupDone = true;` keeps the suite green |
| The four tri-state boolean flags | Three of four share the value `true` in `testSetupPassesNewStreamPolicyOptions`, so `denyDelete($this->configuration->denyPurge())` survives |

Also missing: any functional/Behat scenario for `auto_setup` or the 14 new fields - the two things a mock
cannot assert are provisioning against a genuinely missing stream and the update-path rejections above.

### `auto_setup` semantics

- **Never re-provisions.** The flag latches per instance, `close()` does not reset it, and `get()`
  swallows the 404 a deleted consumer produces. Combined with this PR's own `inactive_threshold`, a
  low-traffic worker idles past the threshold, NATS reaps the consumer, and `get()` returns `[]` forever
  with no log. Symfony's AMQP transport re-runs setup on a 404 for precisely this reason.
- **Shared-stream flip-flop.** `setup()` is authoritative about stream limits and now runs on the hot
  path per process. With the README's own two-transport `events` stream example and `auto_setup=true` on
  both, the shared stream's `max_age` oscillates between the two transports' values and never converges.
- Provisioning failure is re-attempted on every `send()`/`get()` with no backoff.
- `README.md:723` tells operators to "let `auto_setup` recreate it" after deleting a stream - running
  workers never will, because the flag has already latched. Add "restart the workers".

### Validation and error quality

- `stream_max_message_size` has no int32 upper bound; `3000000000` fails at setup with a raw Go
  unmarshal error instead of a build-time message.
- Sentinel policy is inconsistent: `stream_max_message_size=0` is accepted and silently means unlimited,
  the semantically identical `stream_max_consumers=0` is rejected, and `max_ack_pending=-1` (valid to
  the server) is rejected by the builder.
- `stream_duplicate_window=0` passes validation but the server substitutes its 2-minute default - `0`
  does not disable de-duplication.
- `stream_description` is unvalidated; over 4096 characters fails inside `setup()`.
- `assertDuplicateWindowNotExceedingMaxAge()` returns early on `null`, so it does not cover the case the
  transport's own update path produces (a server-side default window larger than a newly-lowered
  `max_age`). Pre-existing gap, but the CHANGELOG wording reads as if it is fully covered.

### API shape and docs

- `TypeCoercion::boolValue()`'s new `$default` parameter is dead (no caller, no test) and its docblock
  promises behavior the code does not implement: `boolValue('maybe', true)` returns `false`. It is also
  missing from the Type Coercion table in `docs/TESTS.md`.
- New accessors on `NatsTransportConfiguration` drop the `stream` prefix every pre-existing stream
  accessor carries - `retention()`, `discard()`, `duplicateWindowSeconds()`, `compression()` - while the
  same PR keeps it on `streamMaxMessageSize()`/`streamMaxConsumers()`/`streamDescription()`. The `?bool`
  getters named as imperatives (`denyDelete()`) read as mutators. No class in `src/` is `@internal`, so
  this is SemVer-frozen once released; renaming is free now.
- `compression` is a raw `?string` with the allowlist duplicated in three unlinked places. The client
  has no enum, but this project's answer to that elsewhere is a local enum (`Options/RetryHandler.php`).
- `docs/CHANGELOG.md` puts `stream_storage` immutability under `### Changed`, but that logic is
  byte-identical on `main`; only `stream_retention` is new. The "mirroring the Symfony AMQP transport"
  claim for `auto_setup` overstates parity - AMQP defaults it to `true` and self-heals on 404.
- **Scope pollution:** `tests/functional/config/reference.php` carries ~35 changed lines of regenerated
  Symfony framework-bundle `@psalm-type` doc blocks (workflow, mailer, html_sanitizer, rate_limiter),
  none transport-related. Revert with `git checkout origin/main -- tests/functional/config/reference.php`.

## What Is Good

- **The client boundary is correct.** Every setter was checked against the client source and a live
  server: `duplicateWindow(int $seconds)` does its own ×1e9, `inactiveThreshold(int $ms)` does ×1e6 and
  is fed via `TypeCoercion::secondsToMs` exactly like the established `ack_wait` precedent, `compression`
  really is string-typed in this client, and the raw snake_case keys on the update path all match the
  client's output - including `allow_rollup_hdrs`.
- **Option naming is careful.** `stream_allow_rollup_headers` expands the wire abbreviation the same way
  `stream_max_messages` expands `max_msgs`; the `stream_`/unprefixed split cleanly separates stream from
  consumer options; `inactive_threshold` in seconds matches `ack_wait`/`nak_delay`.
- **The mutation profile is mostly strong.** Removing the `maxAckPending`, `replayPolicy`,
  `duplicateWindow`, `compression`, `description` blocks, the `max(1, ...)` clamp, the
  `nullableBoolOption` null branch, the `get()` auto-setup call, the once-only guard, and the
  duplicate-window validation are all killed by existing tests.
- **Docs discipline held.** All 20 newly-referenced test method names exist exactly once under `tests/`;
  every new test is mapped in `docs/TESTS.md`; every new `TransportOption` case has a `DEFAULT_OPTIONS`
  entry; tests use attribute metadata throughout.
- **Concurrency is sound.** A losing `addStream` falls into `getExistingStream()` and updates;
  `CONSUMER.CREATE` is an idempotent upsert. Skipping auto-setup in `ack()`/`reject()` is correct - those
  are only reachable from an envelope this instance already provisioned for.
- The retention immutability guard shows the author understood the update-path hazard class; B1 and B2
  are essentially "apply your own pattern in two more places".

## Next Steps

1. Ask the contributor to fix **B1** (`src/NatsTransport.php:839-840`) - non-negotiable, it breaks
   existing users on the NATS versions the README claims to support.
2. Ask for **B2** - at minimum a README + CHANGELOG immutability warning for `replay_policy` alongside
   the existing retention/storage note.
3. **Add a NATS 2.10 (or 2.9) job to the functional CI matrix.** Both blockers are invisible on
   `nats:alpine`. This is the durable fix - without it, the next option touching an immutable field
   reintroduces the same class of bug.
4. Ask for the four mutation-killing tests: auto-setup call ordering, retention-preserved-on-update,
   retry-after-failed-setup, and distinct values for the four boolean flags.
5. Fix directly before merging (trivial): revert `tests/functional/config/reference.php`; move the
   `stream_retention` bullet from `### Changed` to `### Added` in `docs/CHANGELOG.md`; add "(restart the
   workers)" to the `README.md` stream-recreation note.
6. Decide the accessor naming question now, while it is free: either rename to
   `streamRetention()`/`streamDiscard()`/`streamCompression()` with predicate-style booleans, or mark
   `NatsTransportConfiguration` `@internal` explicitly.
7. Defer to follow-up issues: the `duplicate_window` update-path clamp, `auto_setup` 404 self-healing,
   the sentinel-policy cleanup, `boolValue()`'s docblock/`$default`, a `StreamCompression` enum, and a
   `nats_auto_setup.feature` scenario.

## Verification Performed

- `composer test` on `pr-40-review` (`eded4d1`): PHPStan level max clean, 323 tests / 883 assertions pass.
- `composer test:unit` + `composer coverage:check`: 99.67% statements (610/612); the two uncovered lines
  (`NatsTransportConfigurationBuilder::requiredString()` throw, `IgbinarySerializer.php:32`) pre-date
  this PR.
- Live JetStream API probes against `nats:2.10-alpine` (2.10.29), `nats:2.11-alpine` (2.11.17),
  `nats:2.12-alpine` (2.12.12) and `nats:alpine` (2.14.2), comparing the exact `STREAM.UPDATE` and
  `CONSUMER.DURABLE.CREATE` payloads `main` and this PR produce.

> **Note:** running the unit suite from a git worktree requires a real `vendor/` inside that worktree
> (`cp -r` plus `composer dump-autoload`). Symlinking the main checkout's `vendor/` makes Composer's
> autoloader resolve `IDCT\NatsMessenger\` to the main checkout's `src/`, so the worktree's tests
> silently run against the wrong source and report spurious failures.

## Resolution

Every finding above was addressed on the internal branch `pr-40-fixes`, which keeps the contributor's
commit `eded4d1` as its base and adds 15 commits on top, one per item so each can be reviewed, reverted
or cherry-picked on its own.

### Blockers

| Commit | What |
|--------|------|
| `77c3c24` | `max_consumers` is no longer written on the update path unless `stream_max_consumers` is configured. It joins `storage` and `retention` as a field the transport does not manage, since NATS refuses to change it up to 2.11. `max_msg_size` stays authoritative: it was verified mutable on 2.10 and matches the existing contract shared with `max_bytes` and `max_msgs`. |
| `4666c12` | `setup()` looks the durable consumer up first and writes `replay_policy` only when the consumer does not exist yet or already uses the requested value. The lookup runs only when the option is configured. |

### Deferred items, also done

| Commit | What |
|--------|------|
| `79c6848` | `auto_setup` re-provisions on a 404 from the pull (one attempt, one retry) and `close()` clears the done-flag. 408 stays a plain empty result; nothing changes when `auto_setup` is off. |
| `3845cde` | The update path clamps an inherited `duplicate_window` down to a lowered finite `max_age`, the case the build-time validator cannot see. |
| `cc02c3e` | `stream_compression` is backed by a local `StreamCompression` enum and validated through the shared `normalizeEnumOption()` path. |
| `5cb956e` | Three Behat scenarios for `auto_setup`, including a consumer deleted from JetStream behind the transport's back. |

### Cheap fixes

| Commit | What |
|--------|------|
| `3cf1d63` | Reverted the regenerated `tests/functional/config/reference.php`. |
| `eac396a` | CHANGELOG corrections: `stream_storage` immutability moved out of Changed (it is unchanged from `main`), duplicate-window validation moved to Added, and the AMQP "mirroring" claim reworded. |
| `8f25f73` | `TypeCoercion::boolValue()` honours its documented `$default` via an explicit falsy-token list, with the data-provider test its four siblings already had. |
| `b646e40` | `stream_max_message_size` and `stream_duplicate_window` require a positive integer, `stream_max_message_size` is capped at int32, `stream_description` is length-checked. |
| `e21b604` | `normalizeEnumOption()` docblock completed. |

### Tests and API

| Commit | What |
|--------|------|
| `d9bd184` | The four surviving mutants killed: auto-setup call ordering, retry-after-failed-setup, retention-preserved-on-update, and a data provider that gives each tri-state boolean flag a unique signature. Each mutation was re-applied afterwards to confirm the suite now fails. |
| `042b5d3` | The eight stream accessors renamed to carry the `stream` prefix the rest of the class uses. Consumer accessors deliberately left unprefixed. |
| `a3da092` | Coverage for the rethrow path of the re-provisioning retry. |

### The durable fix

`ad6a460` adds a `functional-oldest-nats` CI job running the suite against `nats:2.10-alpine`, and the
scenario that makes it worth having: the pre-existing "the NATS stream already exists" step creates a
bare stream, which leaves `max_consumers` at the server default, so writing the sentinel is not a change
and nothing is caught. The new scenario creates the stream with a consumer limit the way an operator
would.

Two guards keep the job honest. `NATS_IMAGE` is set at job level rather than on the start step, because
the Behat context starts NATS itself with a plain `docker compose up -d` whenever it finds port 4222
closed, and that child process would otherwise recreate the containers from the default image. A step
after the suite re-reads the server version and fails the job if it is not 2.10.x. This is not
hypothetical: it happened while developing the branch, and a run that appeared to prove something about
2.10 was actually executing against 2.14.

### Verification of the branch

- `composer test`: PHPStan level max clean, 369 tests / 963 assertions passing.
- `composer coverage:check`: 99.70% statements (660/662). Both uncovered statements pre-date this work.
- Functional suite against `nats:alpine` (2.14.2): 43 scenarios, 341 steps, all passing.
- Functional suite against `nats:2.10-alpine` (2.10.29), `@delayed` excluded, server version confirmed
  before and after the run: 40 scenarios, 308 steps, all passing.
- With `77c3c24` reverted on the same 2.10 server, the new scenario fails with `Failed to setup NATS
  stream 'stream': stream configuration update can not change MaxConsumers`.

### Left for the maintainer

`tests/functional/config/reference.php` is regenerated by `composer test:functional:setup`, which is how
it ended up in the original PR and why it needed reverting twice while this branch was built. It will
keep appearing in unrelated diffs until it is either excluded from version control or regenerated
deliberately as its own commit.
