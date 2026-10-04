---
title: Troubleshooting
slug: troubleshooting
order: 80
summary: Empty reports, reports that only fail on a schedule, timeouts, missing files, and where the errors go.
---

Every failed run carries its reason — with the template and line number where there is one — on its
detail page under **Reportr → Runs**. Start there. Everything is also logged to
`storage/logs/reportr.log`, whoever ran the build; turn on **Debug logging** in the settings for
full stack traces.

## "The report template ran but never wrote any rows"

The template rendered without calling `report.build()` or `report.addRow()`. Usually a `{% if %}`
that was never true, or a `report.build(rows)` written without `{% do %}` in front of it.

A report that wrote its headings and no data rows is *not* this error — it succeeds with zero
rows.

## A report fails only on a schedule

A scheduled run is a queue job. There is no current user and no web request, so anything in the
template that reads `currentUser` or `craft.app.request` finds nothing.

Parameters are answered from their defaults on a schedule. A parameter with no default arrives as
`null`, and a template that assumes an answer fails. Give every parameter of a scheduled report a
default — a relative one, like `-7 days`, is usually what you meant.

## A scheduled report never runs

- Check `php craft reportr/schedule` — it shows each report's next run.
- Without the cron entry, schedules only fire when somebody is using the control panel. Add
  `* * * * * php craft reportr/schedule/run`.
- `schedule/run` queues due reports. If nothing runs your queue, add `--inline`.
- If the site's timezone changed, run `php craft reportr/schedule/refresh`.

## "This run stopped without finishing"

The queue job was reclaimed or the process was killed — usually Craft's 300-second queue limit on a
report that grew. Raise **Job timeout** in the settings (the default is an hour). If the build ran
out of memory instead, lower **Batch size** or raise **Memory limit**.

Runs like this are found and marked failed automatically. `php craft reportr/runs/sweep` does it on
demand.

## "File missing"

Hover it: the message names the filesystem and path it looked in. On ephemeral hosting — Heroku,
scaled containers, read-only images — files written to the local disk disappear on the next deploy
or restart. Point Reportr at a Craft filesystem in the settings; new runs will go there, and old
runs keep pointing at where they really are.

## "The formatting function … is not defined"

An advanced report names a function that is in neither `config/reportr.php` nor, if
**Read Lab Reports' formatting functions** is on, `config/labreports.php`. Check the spelling —
names are case-sensitive.

## XLSX isn't in the format list

The XLSX writer needs PHP's `zip` extension. A report already set to XLSX on a server without it
fails with a message saying so; install `ext-zip` or choose another format.

## Someone can't pick a section or element type

People who aren't admins can only build reports over sources they can view in the control panel,
and never *All entries* if that includes a section they can't open. See
[Configuration](configuration#what-someone-who-isnt-an-admin-may-report-on).

## Accented characters are garbled in Excel

Make sure the CSV **byte-order mark** option is on (it is by default) — without it, Excel on
Windows reads UTF-8 as Windows-1252. Or switch the report to XLSX, which has no such problem.
