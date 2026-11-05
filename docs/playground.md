# Tutorial: the playground

A guided tour of the durable runtime without creating an application: the repository's own playground, a demo workflow that fails on purpose, a real database queue, and every recovery path in turn.

The repository ships a playground (via Orchestra Testbench), the package's experimental Artisan commands, and a traffic generator plus a live view, so you can watch the durable runtime work without creating a Laravel application:

```bash
git clone https://github.com/jhaskell13/laraflow.git
cd laraflow
composer install
composer playground     # creates a SQLite playground database and runs the migrations
```

> Re-run `composer playground` after pulling changes: it resets the disposable playground database, which is quicker than reasoning about what a snapshot contains. (Your *application's* database needs no such thing — the schema is frozen and upgrades are ordinary `php artisan migrate` runs. See [docs/upgrading.md](upgrading.md).)

The demo workflow simulates an order: **reserve inventory → charge payment → send receipt**. With `--fail`, the payment step throws on its first attempt only, so every failure path below recovers on the next attempt.

The playground runs a **real database queue**, not Laravel's `sync` driver, so dispatched executions genuinely wait for a worker.

## A full walkthrough

Each block stands alone; run them in order for the complete tour. Execution ids increment, so adjust them if you skip around (`laraflow:list` shows them).

**1 · Durable execution, start to finish**

```bash
vendor/bin/testbench laraflow:demo          # inline: three steps, checkpointed
vendor/bin/testbench laraflow:show 1        # steps, attempts, outputs, context
```

**2 · Asynchronous execution on a real queue**

```bash
vendor/bin/testbench laraflow:demo --queue  # returns immediately; nothing has run
vendor/bin/testbench laraflow:list          # pending, 0/3 steps
vendor/bin/testbench queue:work --stop-when-empty
vendor/bin/testbench laraflow:list          # completed, 3/3
```

**3 · Failure and recovery from a checkpoint**

```bash
vendor/bin/testbench laraflow:demo --fail   # payment fails; execution is failed, 1/3 done
vendor/bin/testbench laraflow:execute 3     # resumes: step 1 skipped, payment succeeds on attempt 2
```

**4 · Retry policy: a failure that heals itself**

Retries are off by default, so turn them on for the demo:

```bash
export LARAFLOW_RETRY_MAX_ATTEMPTS=3 LARAFLOW_RETRY_BASE_DELAY=5 LARAFLOW_RETRY_JITTER=0

vendor/bin/testbench laraflow:demo --fail   # now `retrying`, not `failed` — note the due time
vendor/bin/testbench laraflow:show 4 --attempts
```

The history shows both attempts and the retry the failure scheduled:

```
| Step             | Attempt | Outcome   | Started             | Duration | Scheduled retry     | Error               |
| ReserveInventory | 1       | completed | 2026-10-23 23:29:24 | 11 ms    |                     |                     |
| ChargePayment    | 1       | failed    | 2026-10-23 23:29:24 | 3 ms     | 2026-10-23 23:29:29 | Payment gateway ... |
```

The backoff is enforced, and overriding it is explicit:

```bash
vendor/bin/testbench laraflow:execute 4         # refused: "not due until 23:29:29"
vendor/bin/testbench laraflow:execute 4 --now   # the deliberate override
```

**5 · Retries delivered by the queue**

```bash
vendor/bin/testbench laraflow:demo --fail --queue
vendor/bin/testbench queue:work --stop-when-empty   # step fails, retry scheduled, job SUCCEEDS
vendor/bin/testbench laraflow:list                  # retrying
```

A scheduled retry is not a failed job — `failed_jobs` stays empty — and a delayed job now waits for the due time. After the backoff elapses:

```bash
vendor/bin/testbench queue:work --stop-when-empty   # the retry runs and completes
```

**6 · Retries delivered without any queue**

`laraflow:recover` is the contractual delivery path; the delayed job is only the fast path.

```bash
export LARAFLOW_RETRY_DISPATCH_DELAYED=false
vendor/bin/testbench laraflow:demo --fail
vendor/bin/testbench laraflow:recover          # "Nothing to recover" — not due yet
# ...wait out the backoff...
vendor/bin/testbench laraflow:recover --sync   # reason "retry due"; runs it here
```

**7 · Cancellation**

```bash
vendor/bin/testbench laraflow:demo --fail
vendor/bin/testbench laraflow:cancel 7 --reason="customer withdrew the order"
vendor/bin/testbench laraflow:execute 7        # refused: cancelled is terminal
vendor/bin/testbench laraflow:recover          # and invisible to recovery
```

**8 · Operator views**

```bash
vendor/bin/testbench laraflow:list --status=retrying
vendor/bin/testbench laraflow:summary               # counts, and what needs attention
vendor/bin/testbench laraflow:recover --dry-run     # what recovery would do; changes nothing
```

**9 · Correlation, filtering, and paging**

Executions can record where they came from. Run a few with identifiers attached:

```bash
vendor/bin/testbench playground:simulate --count=6 --rate=6 --work=200 --tenants=acme,globex
```

Then find them the way an application would — every one of these filters is served by an index:

```bash
vendor/bin/testbench laraflow:list --tenant=acme
vendor/bin/testbench laraflow:list --correlation=sim-success-001
vendor/bin/testbench laraflow:list --since="-5 minutes" --limit=3   # prints the cursor for the next page
vendor/bin/testbench laraflow:show 1                                # correlation, tenant, duration, attempts
```

`laraflow:list` reports how many of the matching set it showed and prints the command that continues it, so a bounded page never looks like the whole truth.

**10 · Watching it happen**

The demo workflow finishes in milliseconds, which is fine for proving a point and useless for watching one. The playground ships a workflow with real duration and a traffic generator to drive it.

Open two terminals. In the first, start a worker and leave it running:

```bash
vendor/bin/testbench queue:work --sleep=0
```

In the second, turn on retries and deadlines so every scenario is distinguishable, then generate traffic:

```bash
export LARAFLOW_RETRY_MAX_ATTEMPTS=3 LARAFLOW_RETRY_BASE_DELAY=4 LARAFLOW_RETRY_JITTER=0 LARAFLOW_STEP_TIMEOUT=2

vendor/bin/testbench playground:simulate --count=20 --rate=3 --work=300 \
    --mix=success:4,retry:3,fail:2,hang:1
```

Now watch it, in a third terminal or after the generator returns:

```bash
vendor/bin/testbench playground:watch
```

```
  Laraflow · playground                                  2026-10-25 20:08:43

  pending 7   running 1   completed 1   failed 0   retrying 1   cancelled 0
  ────────────────────────────────────────────────────────────────────────
  Id    Workflow           Status     Steps  Age      Detail
  10    SimulatedWorkflow  pending    0/3    3s       queued 3s
  3     SimulatedWorkflow  running    1/3    1s       ChargeCard
  2     SimulatedWorkflow  retrying   1/3    1s       retry due in 3s
  1     SimulatedWorkflow  completed  3/3    1s       took 1.2s

  ────────────────────────────────────────────────────────────────────────
  queue: 7 waiting · 1 reserved  ·  1 retry(s) due — run: testbench laraflow:recover
```

Things worth watching for:

- **Queue depth rising and falling** as the generator outruns the worker and the worker catches up.
- **`retry due in 3s` counting down**, then the footer offering `laraflow:recover` — the contractual delivery path. Stop the worker before a retry comes due and recover it by hand to see that it needs no queue at all.
- **`(overdue)`** beside a running step from the `hang` scenario: it ignored its deadline. Laraflow does not interrupt running PHP, so nothing acts on it while the claim is fresh — kill the worker with Ctrl-C and run `laraflow:recover` to watch the attempt be classified as a timeout.
- **Kill the worker mid-step** at any time. The execution stays `running` with an aging claim; `laraflow:recover` picks it up once it is stale, and the completed steps are skipped.

The generator's scenarios are `success`, `retry` (fails once, heals), `fail` (fails terminally), and `hang` (ignores its deadline); `--mix` weights them. `playground:watch --once` prints a single frame, which is useful in a script.

**11 · Retention**

Nothing is removed until you say so:

```bash
vendor/bin/testbench laraflow:prune     # refuses, exit code 1: no bounds configured
```

A scheduled prune that silently did nothing would be indistinguishable from one that was working, so an unconfigured run is reported as the misconfiguration it is. Give it a bound:

```bash
vendor/bin/testbench laraflow:prune --prune-attempts-after=1 --delete-after=7 --dry-run
```

```
   INFO  Nothing would be pruned: no finished execution is past any configured bound.
```

That is the correct answer, and it is the point: bounds count in days **from when the execution finished**, and everything here finished seconds ago. Nothing you just made is old enough. Age some of it and ask again:

```bash
vendor/bin/testbench tinker --execute="DB::table('laraflow_workflow_executions')
    ->whereNotNull('finished_at')
    ->update(['finished_at' => now()->subDays(30)]);"

vendor/bin/testbench laraflow:prune --prune-attempts-after=1 --delete-after=7 --dry-run
vendor/bin/testbench laraflow:prune --prune-attempts-after=1 --delete-after=7 --force
```

The dry run predicts the real run exactly — compare the two reports. Note what survives: a `failed` execution past the delete bound is still there, because failed work can still be recovered and is only ever removed by `--delete-failed-after`, which you did not pass.

**12 · Connectors**

Two connectors ship — GitHub and Slack — and `laraflow:connectors` shows how each is configured without printing a credential:

```bash
vendor/bin/testbench laraflow:connectors
vendor/bin/testbench laraflow:connectors --check    # exits non-zero if a connector cannot be built
```

```
| Connector | Connection | Base URL                | API version | Timeout | Retries | Rate limit | Credentials |
| github    | default    | https://api.github.com  | 2022-11-28  | 30s     | off     | off        | none        |
| slack     | default    | https://slack.com/api   | unversioned | 30s     | off     | off        | none        |
```

`playground:call` sends one real request through a connector and shows what the framework did with it — the mapped error, the wire attempts, the duration, and the events it announced. With no token configured, the first request is where the missing credential is reported:

```bash
vendor/bin/testbench playground:call github /user
```

```
   ERROR  ConnectorConfigurationException: laraflow.integrations.github.connections.default.credentials.token is not set. Set LARAFLOW_GITHUB_TOKEN or configure it directly.
```

Give it a token and the same command talks to GitHub. A deliberately wrong path shows an error being **returned**, mapped, and announced rather than thrown:

```bash
export LARAFLOW_GITHUB_TOKEN=ghp_your_token
vendor/bin/testbench playground:call github /user
vendor/bin/testbench playground:call github /repos/no-such-owner/no-such-repo
```

```
  Event ................................ ConnectorRequestSent
    status ............................................. 404
    successful .......................................... no
    attempts ............................................. 1
    duration ......................................... 212 ms

  Connector .............................. github [default]
  Request ............ GET /repos/no-such-owner/no-such-repo
  Status ............................................... 404
  Mapped error ............................ NotFoundException
  Message  Connector [github:default] received 404 for GET /repos/no-such-owner/no-such-repo: Not Found
```

Note the message: the path, never the query string, and nothing the authenticator added.

---

The `laraflow:*` commands are registered in any application that installs the package; they are experimental, and documented in [docs/public-api.md](public-api.md). The `playground:*` commands are **not** part of the package — they live in this repository's `workbench/` directory, because a traffic generator has no business being installable into an application.
