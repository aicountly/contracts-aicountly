# Cron and background jobs

Contracts owns its own automation. There is no separate AICOUNTLY automation
product, and nothing here calls one.

Two mechanisms:

- **Sweeps** run on a schedule and look at the whole estate — contracts nearing
  expiry, obligations falling due, approvals sitting past their date.
- **Queues** hold work that must not block a web request — AI extraction,
  document analysis, bulk recalculation, report generation.

## Entry point

```bash
php database/cron.php <task> [--env=production] [--company=123] [--dry-run]
```

| Task | Does | Suggested schedule |
|---|---|---|
| `daily` | expiry + obligations + renewals + approvals | once a night |
| `expiry` | expiry and notice-deadline alerts; marks lapsed contracts expired | nightly |
| `obligations` | obligation and milestone due/overdue status, reminders, escalation | nightly |
| `renewals` | opens renewal reviews that have become due | nightly |
| `approvals` | escalates and re-reminds overdue approval steps | nightly |
| `jobs` | drains the general job queue | every 5–10 minutes |
| `ai` | drains the AI job queue, reaps crashed workers | every 5 minutes |
| `cleanup` | expires stale upload sessions, purges rate-limit windows | nightly |

`--dry-run` reports what would happen and sends nothing. `--company` limits a
run to one tenant, which is what you want when investigating one company's
alerts.

The script **refuses to run over HTTP** (`PHP_SAPI !== 'cli'` → 404). Without
that check the whole automation surface would be reachable at
`https://contracts.aicountly.com/api/database/cron.php` by anyone who guessed
the path.

## cPanel cron lines

Replace `<user>` with the cPanel account and `<docroot>` with the document root
(usually `public_html` or a subdomain folder).

```cron
# Nightly sweeps — 02:15 server time
15 2 * * *  cd /home/<user>/<docroot>/api && /usr/local/bin/php database/cron.php daily >> ~/logs/contracts-daily.log 2>&1

# Housekeeping — 03:10
10 3 * * *  cd /home/<user>/<docroot>/api && /usr/local/bin/php database/cron.php cleanup >> ~/logs/contracts-cleanup.log 2>&1

# Queues — every 5 minutes
*/5 * * * * cd /home/<user>/<docroot>/api && /usr/local/bin/php database/cron.php jobs >> ~/logs/contracts-jobs.log 2>&1
*/5 * * * * cd /home/<user>/<docroot>/api && /usr/local/bin/php database/cron.php ai   >> ~/logs/contracts-ai.log 2>&1
```

Check the PHP CLI path with `which php` over SSH — cPanel accounts often need
the full `/opt/cpanel/ea-php82/root/usr/bin/php` form rather than
`/usr/local/bin/php`.

Redirecting to a log matters: cron mails on non-empty output, and these tasks
print a summary line on every run. A non-zero exit code is reserved for a run
that hit an error, which is what should actually page someone.

## Idempotency

Every task is safe to run twice. This is not a nicety — cPanel cron is not
exactly-once, a task can be triggered manually while the scheduled run is in
flight, and an operator investigating an alert will re-run it.

Three mechanisms, layered:

**1. Run keys.** Each task records itself in `contract_job_runs` against a key
of `<task>:<date>:<company>`. `beginRun()` returns null when that key already
exists, and the task is skipped. The queue-drain tasks use a minute-resolution
key because they are *meant* to run many times a day.

**2. Conditional transitions.** Status changes are `UPDATE ... WHERE status =
<the old one>`. Running the sweep again finds nothing left to change.

**3. Notification dedupe.** Every notification carries a `dedupe_key`, and
`contract_notifications` has a unique index on
`(environment, cmp_id, recipient_uuid, dedupe_key)`. The same warning on the
same day is written once however many times the sweep runs.

The key encodes the **threshold**, not the date:

```
notice:4417:30      contract 4417, the 30-day band
expiry:4417:7       contract 4417, the 7-day band
obligation_due:88:14
```

`ExpirySweep::thresholdFor()` maps a day count to the smallest ladder step at or
above it. With a ladder of 90/60/30/15/7, a contract 29 days out is in the "30"
band today and moves to "15" a fortnight later — so each band notifies exactly
once, and a nightly re-run in between says nothing.

Two deliberate exceptions repeat daily, because they describe a situation that
is actively blocking something:

- an **overdue obligation** — the key includes the overdue day count, capped at
  30 so it eventually stops
- an **overdue approval** — the key includes the date

## Reminder ladders

Per company, in `contract_settings`:

| Setting | Default | Used by |
|---|---|---|
| `expiry_alert_days` | `90,60,30,15,7` | expiry and notice-deadline alerts |
| `obligation_alert_days` | `14,7,1` | obligation and milestone reminders |
| `approval_escalation_days` | `3` | approval escalation |

## The job queue

PostgreSQL-backed rather than Redis or SQS. The ecosystem runs on cPanel with
cron; adding a broker would be infrastructure nobody asked for, and
`SELECT ... FOR UPDATE SKIP LOCKED` gives correct concurrent dequeue at this
volume.

`contract_jobs` carries status, priority, attempts, `max_attempts`,
`available_at`, `locked_at`, `locked_by`, the error, and an optional
`idempotency_key` with a partial unique index over queued and running rows.

- **Claiming** takes a batch with `FOR UPDATE SKIP LOCKED`, so two workers never
  serve the same job.
- **Failure** backs off exponentially into `available_at`, and the job becomes
  `dead` only after `max_attempts`.
- **A crashed worker** leaves `locked_at` set with no process behind it.
  `reapStale()` releases those back to `queued` after
  `CONTRACTS_JOB_STALE_SECONDS` (default 900), which is why a killed cron loses
  time rather than work.

`ai_jobs` is a separate table with the same mechanics, because its work costs
real money per call, its retries need to be more conservative, and it carries
provider, model and token counts that a general job has no use for.

## Environment variables

```env
CONTRACTS_JOB_BATCH=25
CONTRACTS_AI_BATCH=5
CONTRACTS_JOB_STALE_SECONDS=900
```

## Watching it

```bash
# what ran, and how it went
php -r '...'   # or:
psql -c "SELECT task, run_key, started_at, finished_at, processed, notified, errors
         FROM contract_job_runs ORDER BY started_at DESC LIMIT 20;"

# stuck jobs
psql -c "SELECT id, job_type, attempts, error_message
         FROM contract_jobs WHERE status IN ('failed','dead') ORDER BY id DESC LIMIT 20;"
```

`GET /api/health` reports migration state and integration configuration, which
is the first thing to check when a sweep reports zero across the board.
