---
title: Migrating from Lab Reports
slug: migrating-from-lab-reports
order: 15
summary: Install alongside, import, one find-and-replace, then uninstall Lab Reports.
---

# Migrating from Lab Reports

The short version: install Reportr, run the importer, change `craft.labreports` to `craft.reportr`
in any front-end templates, and check the reports list. Your report templates and your PHP
formatting functions do not change.

## 1. Install Reportr alongside Lab Reports

```sh
composer require justinholtweb/craft-reportr
php craft plugin/install reportr
```

Leave Lab Reports installed for now. The importer reads its tables and never writes to them, so
nothing is at risk, and you can compare the two side by side.

## 2. Dry-run the import

```sh
php craft reportr/import/lab-reports --dry-run
```

Or **Reportr → Import** in the control panel, which offers the same thing with a **Dry run**
button. It lists what it would create and changes nothing.

## 3. Import

```sh
php craft reportr/import/lab-reports
```

What comes across:

| Lab Reports | Reportr |
| --- | --- |
| `ConfiguredReport` | A **Report** element, with a handle slugified from its title |
| `reportType` basic / advanced | The same two types |
| `template`, `formatFunction` | Unchanged |
| `Report` (generated) | A **Run**, with its original date, row count and user |
| The generated file | Copied into Reportr's storage |
| A run left at `in_progress` | Imported as **failed**, with a note — in Lab Reports that state meant the build died |
| A report in Craft's trash | Not imported. Somebody deleted it on purpose |

The import is idempotent: everything it creates remembers the row it came from, and running it
again skips what is already there. So a dry run on staging, a real run on staging, and then the
same on production is a safe sequence.

## 4. Your templates

Nothing to do. Reportr's basic and advanced types are call-compatible:

```twig
{# Works in both plugins, unchanged. #}
{% set rows = [['ID', 'Title']] %}
{% for entry in craft.entries.all() %}
    {% set rows = rows|merge([[entry.id, entry.title]]) %}
{% endfor %}
{% do report.build(rows) %}
```

The `report` variable is the same object shape: `build()`, `addRow()`, `addRows()`, `filePath()`,
`fileExists()`, `getConfiguredReport()`, `getUser()`, `getDownloadUrl()`.

One behavioural difference, and it is an improvement: Reportr treats the first row of a basic
report's array as the **column headings** and writes it as a header rather than as data. CSV output
is byte-identical; JSON and XML exports get real keys instead of `column1`. It also means
`totalRows` counts data rows, where Lab Reports counted the header as one of them.

## 5. Your formatting functions

Nothing to do either. Reportr reads the `functions` array from `config/labreports.php` as well as
its own `config/reportr.php`. Reportr's own definitions win on a name collision, so you can move
them one at a time.

When you have moved them all, turn **Read Lab Reports' formatting functions** off in
**Reportr → Settings**.

## 6. Front-end templates

One find-and-replace:

```twig
{# Before #}
{% set reports = craft.labreports.configuredReports.reportType('advanced').all() %}
{% set runs = craft.labreports.generatedReports.configuredReportId(6).all() %}

{# After — the old method and param names still work #}
{% set reports = craft.reportr.configuredReports().type('advanced').all() %}
{% set runs = craft.reportr.runs().configuredReportId(6).all() %}
```

`configuredReports()`, `generatedReports()`, `formatFunctionNames()` and `formatFunctionOptions()`
are all present, and `RunQuery` still accepts `configuredReportId` and `dateGenerated`.

Note the parentheses: Reportr's variable methods take an optional criteria array, so they are
called rather than accessed as properties.

## 7. Your cron entries

```
# Before
0 6 * * * cd /path/to/project && php craft labreports/reports/build --reportId=43248

# After — the handle is stable across environments
0 6 * * * cd /path/to/project && php craft reportr/reports/build --report=monthly-orders
```

`--reportId` still works if you would rather not touch them yet.

Better still: put the schedule on the report itself and replace every per-report cron entry with
one line.

```
* * * * * cd /path/to/project && php craft reportr/schedule/run
```

## 8. Then uninstall Lab Reports

```sh
php craft plugin/uninstall labreports
```

This drops its tables. Do it only after you have looked at the imported reports and run one or two
of them — and keep `config/labreports.php` if you have not moved your formatting functions.

## Things worth changing once you are across

- **Reports on ephemeral hosting.** If you were losing files on Heroku or a scaled container, point
  Reportr at a Craft filesystem in its settings. That is the fix for the problem, not a workaround
  for it.
- **Reports that were timing out.** Raise the job timeout. Craft's queue default is 300 seconds;
  Reportr's is 3600.
- **Reports that differ only by a hard-coded date.** Give one of them a date parameter and delete
  the rest.
- **CSVs people open in Excel.** Switch the format to XLSX.
