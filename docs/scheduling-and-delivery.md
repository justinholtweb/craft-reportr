---
title: Scheduling and delivery
slug: scheduling-and-delivery
order: 50
summary: Run reports hourly, daily, weekly or monthly from one cron line, and email the result — or only the failures.
---

## Scheduling

Set a frequency on the report — hourly, daily, weekly or monthly — and when:

| Frequency | You choose |
| --- | --- |
| Hourly | Minutes past the hour |
| Daily | A time |
| Weekly | A day of the week and a time |
| Monthly | A day of the month and a time |

Then add one cron entry for the whole site:

```
* * * * * cd /path/to/project && php craft reportr/schedule/run
```

Every minute is right, even though most minutes do nothing. The check is a single indexed
comparison against a stored timestamp, and a coarser interval turns "07:00" into "some time in the
07:00 hour".

`schedule/run` queues each report that is due, so it needs a queue runner — which every Craft site
has, one way or another. On a site where the queue only runs from control-panel traffic, add
`--inline` to build due reports in the cron process itself:

```
* * * * * cd /path/to/project && php craft reportr/schedule/run --inline
```

`php craft reportr/schedule` lists what is scheduled and when it next runs.

### Without cron

There is a control-panel fallback, on by default and modelled on Craft's own
`runQueueAutomatically`: **Fire schedules from control-panel traffic** in the settings. It has the
same caveat — a site whose control panel nobody opens for a week will not run its Monday report.
The cron entry is the reliable way.

### Details that are handled

- **Times are wall-clock in the site's timezone**, so the Monday report at 07:00 stays at 07:00
  across a daylight-saving change. After changing the site's timezone, run
  `php craft reportr/schedule/refresh`.
- **"The 31st" in a 30-day month means the 30th**, not the 1st of the month after.
- **A due report is claimed before it is queued**, with a conditional update that only one process
  can win. A cron entry and the control-panel fallback both running — the combination people
  actually end up with — cannot fire the same report twice.
- **A build that dies** — an out-of-memory fatal, a killed container, a reclaimed job — is swept up
  and marked failed with the reason, rather than sitting at *Running* for ever.

Scheduled runs answer every parameter from its default. See [Parameters](parameters#relative-defaults)
for relative defaults like `-7 days`, which is what makes a schedule useful.

## Email delivery

Each report has a **Delivery** setting:

| When | Sends |
| --- | --- |
| Never | Nothing — the default |
| Every run | After each run, whatever happened |
| Successful runs only | After a run that produced a file |
| Failed runs only | After a run that failed, with the reason |

**Failed runs only** is the one worth knowing about. The run that matters most is the one that did
not work, and a report that only emails on success is one whose Monday export can be broken for
five weeks before anybody notices.

- **The file is attached** if it is under **Largest email attachment** (10 MB by default). Above
  that, or with attaching turned off, the email links to the run in the control panel instead —
  so the recipient needs an account that may download it.
- **One message per recipient.** An export of customer data should not also disclose who else
  receives it.
- **Recipients** go one per line, or separated by commas. An environment variable such as
  `$REPORT_RECIPIENTS` is stored as written and resolved when the email is sent, so the addresses
  stay out of the database.
- **The subject** defaults to "Monthly orders — 1,204 rows" or "Monthly orders — report failed",
  and can be your own with `{report}`, `{status}` and `{rows}` tokens.

Email goes through Craft's own mailer, so it uses whatever transport the site is set up with.
