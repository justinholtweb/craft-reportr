---
title: Console and Twig
slug: console-and-twig
order: 70
summary: Every console command and option, the craft.reportr variable, the element queries, and the three filters for getting awkward fields into a cell.
---

## Console commands

### Reports

```sh
php craft reportr/reports                                         # list every report
php craft reportr/reports/build --report=monthly-orders           # build one, here and now
php craft reportr/reports/build --report=orders --params='{"since":"-30 days"}'
php craft reportr/reports/build --report=orders --format=xlsx --queue
php craft reportr/reports/preview --report=orders --rows=10       # print rows, write nothing
```

| Option | On | |
| --- | --- | --- |
| `--report`, `-r` | `build`, `preview` | A report **handle** or ID |
| `--reportId` | `build` | Lab Reports' option name, so old crontabs keep working |
| `--params`, `-p` | `build`, `preview` | Parameter answers, as JSON |
| `--format` | `build` | Override the export format for this run only |
| `--queue` | `build` | Queue the build instead of running it |
| `--rows` | `preview` | Rows to print |

Use a handle in crontabs: it survives a database refresh and means something to whoever reads the
crontab next.

`build` runs in the foreground by default. A cron entry that only queues a job looks as if it
succeeded whether or not the report was ever built, and on a site with no queue runner it never is.
`--queue` is there for sites that do run one.

### Schedules

```sh
php craft reportr/schedule                 # what is scheduled, and when it next runs
php craft reportr/schedule/run             # queue everything due — the cron entry
php craft reportr/schedule/run --inline    # build everything due, in this process
php craft reportr/schedule/run --dry-run   # show what is due, change nothing
php craft reportr/schedule/refresh         # recalculate next runs, after a timezone change
```

### Runs

```sh
php craft reportr/runs --limit=20 --report=orders
php craft reportr/runs/prune --dry-run           # apply retention; --report= for one report
php craft reportr/runs/sweep                     # mark stalled runs failed
```

### Import

```sh
php craft reportr/import/lab-reports --dry-run
php craft reportr/import/lab-reports --without-runs    # configurations only
php craft reportr/import/lab-reports --without-files   # history, but not the files
```

## Twig

`craft.reportr` is available in every template.

```twig
{% set reports = craft.reportr.reports().type('query').all() %}
{% set report = craft.reportr.report('monthly-orders') %}           {# handle or ID #}
{% set runs = craft.reportr.runs().report('monthly-orders').dateFinished('>= ' ~ lastMonth).all() %}
{% set run = craft.reportr.run(123) %}
{% set run = craft.reportr.queue('monthly-orders', { since: '-30 days' }) %}
```

`queue()` queues rather than builds — a page that blocks for the length of an export is a page that
times out. It returns the queued run, whose detail page shows progress. Whoever can load the
template can trigger it, so keep it behind your own checks on a front-end page.

| Query | Params |
| --- | --- |
| `reports()` | `handle`, `type` (`basic`, `advanced`, `query`), `isScheduled` |
| `runs()` | `report` (handle, ID or report), `reportId`, `filename`, `initiator` (`cp`, `console`, `schedule`, `template`), `userId`, `dateFinished`, `status` (`queued`, `running`, `finished`, `error`) |

Both are ordinary element queries, so `.limit()`, `.orderBy()` and the rest work as usual.

The Lab Reports names are all still there — `configuredReports()`, `generatedReports()`,
`formatFunctionNames()`, `formatFunctionOptions()`, and the `configuredReportId` and
`dateGenerated` params — so front-end templates need only `craft.labreports` changed to
`craft.reportr`.

### Filters

Getting related and table-field data into a single column is fiddly in every direction: a relation
field is a query, a Table field is a list of hashes, a checkboxes field is a list of objects with
`value` on them. Three filters cover it:

```twig
{{ entry.relatedBooks|reportCell }}                     {# "Dune, Neuromancer, Snow Crash" #}
{{ entry.priceTable|reportColumn('amount')|reportCell }} {# "12.50, 14.00" #}
{{ entry.authors|reportColumn('email')|reportCell }}
{{ entry.checkboxField|reportValues|join(', ') }}       {# the values, not the labels #}
```

| Filter | Does |
| --- | --- |
| `reportCell(separator = ', ', dateFormat = 'Y-m-d H:i')` | Anything a Craft field can hold, as one string. Elements become their titles (their string form); dates are formatted; lists are joined |
| `reportColumn(key)` | One key out of a list of rows or elements |
| `reportValues` | The raw values of a multi-select field, without its labels |

`reportCell` treats an element as a value, not a container. A naive "flatten anything iterable"
helper explodes an entry into its own attribute values, because every Craft element is iterable.

The filters are prefixed so that no other plugin's `cell` or `column` filter can silently replace
them.

### Inside a report template

A basic or advanced report template gets:

| Variable | |
| --- | --- |
| `report` | The run being built — `build()`, `addRow()`, `addRows()`, `filePath()`, `fileExists()` |
| `run` | The same object, under a name that says what it is |
| `config` | The report configuration — its title, handle and settings |
| `params` | The parameter answers, already typed — see [Parameters](parameters) |

`report` is the run rather than the configuration because that is what every Lab Reports template
calls the object it builds rows on. `report.getConfiguredReport()` still returns the configuration.
