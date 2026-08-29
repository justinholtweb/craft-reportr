# Reportr for Craft CMS

Content and data reports for Craft CMS. Write a Twig template, or build a report in the control
panel and never write one at all — then run it by hand, on a schedule, or from a cron entry, and
get a CSV, an Excel workbook, JSON, NDJSON, XML, TSV or an HTML table out of it.

Reportr is a **drop-in replacement for [Lab Reports]**. Existing report templates run unchanged,
the PHP formatting functions in your `config/labreports.php` keep working, and there is an
importer that brings your report configurations and their whole run history across.

[Lab Reports]: https://github.com/masugadesign/lab-reports-craft-cms

- [Why this exists](#why-this-exists)
- [Requirements](#requirements)
- [Installation](#installation)
- [Coming from Lab Reports](#coming-from-lab-reports)
- [Report types](#report-types)
  - [Basic reports](#basic-reports)
  - [Advanced reports](#advanced-reports)
  - [Query reports](#query-reports)
- [Parameters](#parameters)
- [Export formats](#export-formats)
- [Where files are stored](#where-files-are-stored)
- [Filenames](#filenames)
- [Scheduling](#scheduling)
- [Email delivery](#email-delivery)
- [Retention](#retention)
- [Big reports](#big-reports)
- [Console commands](#console-commands)
- [Twig](#twig)
- [Permissions](#permissions)
- [Settings](#settings)
- [Config file](#config-file)
- [Events](#events)
- [Troubleshooting](#troubleshooting)
- [Licence and price](#licence-and-price)

## Why this exists

Lab Reports is a good plugin that stopped getting new features. Its issue tracker and its own
README's "Planned Features" list read like a specification, and Reportr implements it:

| What was asked for | Where |
| --- | --- |
| Dynamic report parameters | [Parameters](#parameters) — its issue #1, and the top item on its roadmap |
| Other export formats | [Export formats](#export-formats) — its second roadmap item |
| Reports vanishing on ephemeral hosting | [Where files are stored](#where-files-are-stored) — its issue #8 |
| Timeouts on reports that grew | [Big reports](#big-reports) — its issue #7 |
| Memory exhaustion | [Big reports](#big-reports) — its issue #6 |
| No way to delete a report | Delete works, and past runs survive it — its issue #5 |
| `{% import %}` failing inside a report template | Fixed — its issue #4 |
| Getting related and table-field data into a column | [Twig](#twig) filters and dotted column keys — its issue #3 |
| Craft version constraints blocking updates | `craftcms/cms: ^5.3.0`, open-ended — its issue #2 |
| Graphing tools | Partly — each run's page plots rows over the last twenty runs, and the HTML format is there for a report meant to be read rather than imported. Reportr does not draw charts *inside* a report, and that is deliberate: a report is data, and the thing that draws it is a spreadsheet |

Plus the things nobody had filed yet: a point-and-click report type, scheduling without a crontab,
email delivery, retention, a preview, and a run history that records what each run was asked and
how long it took.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- `ext-zip`, only if you want the XLSX format

No other runtime dependencies. Nothing is fetched over the network at any point.

## Installation

```sh
composer require justinholtweb/craft-reportr
php craft plugin/install reportr
```

## Coming from Lab Reports

**Your templates do not need changing.** Reportr's basic and advanced report types are
call-compatible with Lab Reports: the same `report` variable, the same `report.build(rows)` and
`report.build(headings, query)` signatures, and the same `addRow()`, `addRows()`, `filePath()` and
`fileExists()` methods on it.

**Your formatting functions do not need moving.** Reportr reads the `functions` array from
`config/labreports.php` as well as its own `config/reportr.php`, unless you turn that off in the
settings. Names in Reportr's own file win on a collision.

To bring the configurations across, go to **Reportr → Import** in the control panel, or:

```sh
php craft reportr/import/lab-reports --dry-run
php craft reportr/import/lab-reports
```

The import:

- never modifies the Lab Reports tables, so you can look at the result before uninstalling anything
- is idempotent — everything it creates remembers which row it came from, and a second run skips it
- brings the run history across with its original dates, and copies the generated files
- reproduces the old filename pattern (`<slug>-<YmdHis>`), so anything watching a folder for those
  names keeps matching
- imports a run left at `in_progress` — which in Lab Reports meant the build died — as **failed**,
  with a note saying so, rather than as a spinner that never stops

Front-end templates need one find-and-replace: `craft.labreports` becomes `craft.reportr`. The
method names (`configuredReports`, `generatedReports`, `formatFunctionNames`,
`formatFunctionOptions`) are all still there, and so are the query params `configuredReportId` and
`dateGenerated`.

## Report types

### Basic reports

A Twig template that builds an array of rows and hands the lot over. Everything is in memory at
once, which is fine up to a few thousand rows and is what most reports actually are.

**The first row is the column headings.** Reportr writes it as a header rather than as data, so
CSV output is identical to Lab Reports' while JSON and XML get real keys instead of `column1`.

```twig
{% set rows = [[
    'ID',
    'Title',
    'Published',
    'Author',
]] %}

{% set entries = craft.entries.section('books').with(['bookAuthor']).orderBy('title').all() %}

{% for entry in entries %}
    {% set rows = rows|merge([[
        entry.id,
        entry.title,
        entry.postDate|date('Y-m-d'),
        entry.bookAuthor|reportCell,
    ]]) %}
{% endfor %}

{% do report.build(rows) %}
```

### Advanced reports

A Twig template that hands over the column headings and an **unexecuted** element query, plus the
name of a PHP function that turns one element into one row. Reportr walks the query in batches and
streams each row to disk, so the report's size is bounded by disk rather than by memory.

```twig
{% set columns = ['ID', 'Title', 'Published', 'Author', 'Cover'] %}

{# No .all() — hand over the query itself. #}
{% set query = craft.entries.section('books').orderBy('title') %}

{% do report.build(columns, query) %}
```

```php
// config/reportr.php
return [
    'functions' => [
        'bookDump' => function($entry) {
            $author = $entry->getFieldValue('bookAuthor')->one();
            $cover = $entry->getFieldValue('coverImage')->one();

            return [
                (int)$entry->id,
                $entry->title,
                $entry->postDate->format('Y-m-d'),
                $author?->title,
                $cover?->getUrl(),
            ];
        },
    ],
];
```

### Query reports

No template at all. Choose an element type, one of that type's own index sources, a status and an
order, then pick the columns from a list. The type that exists so that "export the members who
joined last month" is not a deployment.

Column keys are a three-prefix mini-language:

| Key | Gives you |
| --- | --- |
| `attr:title` | An attribute on the element |
| `attr:author.email` | Dotted, to walk into a related element |
| `field:bookAuthor` | A custom field, flattened to one cell |
| `field:bookAuthor.title` | One property of every related element, joined |
| `field:priceTable.amount` | One column out of a Table field |
| `field:specs.0.price` | One row of one, by index |
| `twig:{{ object.title\|upper }} ({{ object.id }})` | An object template, for anything else |

An unprefixed key (`title`) is read as an attribute. A key pointing at something the element does
not have gives an empty cell, not a failed report — which matters on a source holding more than
one entry type.

## Parameters

A parameter is a question the report asks before it runs. Declare them on the report; answer them
in the control panel, on the command line, or from Twig. The answers arrive in the template as
`params.name`, **already the right type**: a date parameter is a `DateTime`, an entries parameter
is an entry (or a list of them), a number is an `int` or a `float`.

```twig
{% set query = craft.entries.section('orders') %}

{% if params.since %}
    {% set query = query.postDate('>= ' ~ params.since|date('Y-m-d')) %}
{% endif %}

{% if params.customer %}
    {% set query = query.relatedTo(params.customer) %}
{% endif %}
```

Types: text, number, date, date and time, yes/no, dropdown, checkboxes, entries, users,
categories, assets, tags, and site.

**Defaults may be relative**, and that is what makes a scheduled report worth scheduling: a `since`
parameter defaulting to `-7 days` means the last seven days *from each run*, not from the day
somebody typed it.

On a query report, a parameter whose name matches an element-query parameter — `sectionId`,
`postDate`, `authorId` — is applied to the query automatically, with no configuration at all.

From the command line:

```sh
php craft reportr/reports/build --report=orders --params='{"since":"-30 days","region":"north"}'
```

## Export formats

| Format | Notes |
| --- | --- |
| **CSV** | UTF-8 with a byte-order mark by default, because Excel on Windows reads one without as Windows-1252 and mangles every accented name |
| **TSV** | Tab separated |
| **XLSX** | A real Excel workbook, written without PhpSpreadsheet. Bold headers, numbers stored as numbers, leading zeros kept as text so postcodes survive |
| **JSON** | An array of objects keyed by the column headings, or positional arrays |
| **NDJSON** | One object per line, for feeding something else |
| **XML** | Element names derived from the headings and sanitised so the document parses |
| **HTML** | A self-contained table with its CSS inline, for emailing to a person |

Any single character can be the CSV delimiter, and the format's options (delimiter, enclosure, BOM,
sheet name, XML element names, pretty-printing) are per report.

**Formula injection is neutralised by default.** A cell beginning `=`, `+`, `-` or `@` executes as
a formula when a spreadsheet opens the file, so an export of anything a member of the public typed
is a way to run code on the machine of whoever opens the report. Reportr prefixes those cells with
an apostrophe, which spreadsheets hide. Negative numbers are exempt. There is a switch if something
downstream needs the raw text.

## Where files are stored

By default, `storage/reportr`. You can point a report — or the whole site — at any **Craft
filesystem** instead: S3, DigitalOcean Spaces, anything with a Flysystem adapter.

That is not a nicety. On Heroku, on a scaled container, or on a read-only image, the application
disk does not survive a restart, and Lab Reports' issue #8 is somebody watching every generated
report turn into "Unavailable (File Missing)". A run in Reportr records **which filesystem** it was
written to as well as the path within it, so changing the default does not orphan the archive, and
a missing file says where it went looking.

Builds always write to a local temporary file and move the finished file into place afterwards —
an object store has no notion of appending, and a build that fails halfway must not leave a
truncated file where a downstream system is watching for it.

The local folder gets an `.htaccess` and an empty `index.html` on creation. Keep it outside the web
root anyway.

## Filenames

The default is `{handle}-{datetime}`. The filename is frequently the interface — a finance system
watching a dropbox for `orders-2026-08.csv` cares about the name — so it is a pattern:

`{handle}` `{title}` `{id}` `{date}` `{time}` `{datetime}` `{timestamp}` `{Y}` `{m}` `{d}` `{H}`
`{i}` `{s}` `{param:name}`

The extension is added for you. An unknown token is left visible rather than blanked, so a typo
shows up in the filename instead of producing `report--.csv`.

## Scheduling

Set a frequency on the report — hourly, daily, weekly or monthly — and a time. Then, for a site
that means it:

```
* * * * * cd /path/to/project && php craft reportr/schedule/run
```

One minute is right even though most minutes do nothing: the check is a single indexed comparison
against a stored timestamp, and a coarser interval turns "07:00" into "some time in the 07:00 hour".

There is also a control-panel fallback, on by default and modelled on Craft's own
`runQueueAutomatically`. It has the same caveat: a site whose control panel nobody opens for a week
will not run its Monday report. The cron entry is the reliable way.

Two details that are easy to get wrong and are handled here: times are wall-clock in the site's
timezone, so "the Monday report at 07:00" stays at 07:00 across a daylight-saving boundary; and
"the 31st" in a 30-day month means the 30th, not the 1st of the month after.

The next run is *claimed* before the job is queued, in a conditional update. Two schedulers running
at once — a cron entry and the control-panel fallback, which is exactly the combination people end
up with — cannot both fire the same report.

## Email delivery

Per report: never, every run, successful runs only, or **failed runs only**. The file is attached
if it is under the size limit, and a link is sent instead if it is not. One message per recipient,
so an export of customer data does not also disclose who else receives it.

Failure-only delivery is the setting worth knowing about. The run that matters most is the one that
did not work, and a report that only emails on success is one whose Monday export can be broken for
five weeks before anybody notices.

Recipient fields accept environment variables, so `$REPORT_RECIPIENTS` keeps addresses out of
project config and out of database backups.

## Retention

Keep the last N runs, or runs from the last N days, per report or site-wide. Deletions are hard —
files and all — because a retention policy whose deletions sit in the trash still occupies the
disk, which is usually the point.

## Big reports

Nothing accumulates in memory. Rows go straight to a writer and the writer straight to a file
handle; the advanced and query types walk their query in batches and free each batch before
fetching the next. Three things that leak on a long build and are not the report's own data are
handled too: Yii's in-memory log buffer is flushed each batch, query logging and profiling are
switched off for the duration (unless debug mode is on), and the cycle collector is run explicitly.

Two settings matter when a report grows past what it used to be:

- **Job timeout** — Craft's queue reclaims a job after 300 seconds by default, which is where
  `exceeded the timeout of 300 seconds` comes from. Reportr's default is an hour.
- **Batch size** — rows fetched at a time. Lower it if a report runs out of memory; raise it for
  speed on a machine with room.

A build that dies anyway — an out-of-memory fatal, a killed container, a reclaimed job — is swept up
by Craft's garbage collection and marked failed with the reason, rather than sitting at "Running"
for ever.

Each run records how long it took and what it peaked at, so growth is visible before it is a
problem.

## Console commands

```sh
php craft reportr/reports                                    # list every report
php craft reportr/reports/build --report=monthly-orders      # build one
php craft reportr/reports/build --report=orders --params='{"since":"-30 days"}'
php craft reportr/reports/build --report=orders --format=xlsx --queue
php craft reportr/reports/preview --report=orders --rows=10  # print rows, write nothing

php craft reportr/schedule                                   # what is scheduled, and when
php craft reportr/schedule/run                               # run anything due (the cron entry)
php craft reportr/schedule/run --dry-run
php craft reportr/schedule/refresh                           # after a timezone change

php craft reportr/runs --limit=20
php craft reportr/runs/prune --dry-run
php craft reportr/runs/sweep                                 # mark stalled runs failed

php craft reportr/import/lab-reports --dry-run
```

`--report` takes a **handle**. Lab Reports' `--reportId=43248` still works, but a handle survives a
database refresh and means something to whoever reads the crontab next.

`build` runs in the foreground by default. A cron entry that only queues a job looks like it
succeeded whether or not the report was ever built, and on a site with no queue runner it never is.
`--queue` is there for sites that do run one.

## Twig

```twig
{% set reports = craft.reportr.reports().type('query').all() %}
{% set runs = craft.reportr.runs().report('monthly-orders').dateFinished('>= ' ~ lastMonth).all() %}
{% set report = craft.reportr.report('monthly-orders') %}
{% set run = craft.reportr.queue('monthly-orders', { since: '-30 days' }) %}
```

Three filters, all of which exist because getting related and table-field data into a column is
fiddly in every direction — a relation field is a query, a Table field is a list of hashes, a
checkboxes field is a list of objects with `value` on them:

```twig
{{ entry.relatedBooks|reportCell }}                  {# "Dune, Neuromancer, Snow Crash" #}
{{ entry.priceTable|reportColumn('amount')|reportCell }}
{{ entry.checkboxField|reportValues|join(', ') }}
```

`|reportCell` takes anything a Craft field can hold and gives back one string. It treats an element
as a value, not a container — a naive "flatten anything iterable" helper explodes an entry into its
own attribute values, because every Craft element is `Traversable`.

## Permissions

- **View report configurations** → create/edit/delete reports, run reports
- **View runs** → download report files, delete runs

The two are deliberately separate. A report's *output* is the data: somebody who may see that the
"Members export" exists is not thereby somebody who may download every member's email address.

## Settings

**Reportr → Settings**, or `config/reportr.php` (which wins).

| Setting | Default | |
| --- | --- | --- |
| `fsHandle` | none | Craft filesystem for report files |
| `fsSubpath` | `reports` | Subfolder within it |
| `storageFolder` | `storage/reportr` | Local fallback |
| `jobTtr` | `3600` | Seconds a queued build may take |
| `batchSize` | `100` | Rows fetched at a time |
| `memoryLimit` | `512M` | Applied for the build and put back. Never lowers the server's own |
| `timeLimit` | `0` | Seconds, for the build. 0 leaves PHP alone |
| `previewRows` | `25` | |
| `retentionRuns` | `0` | 0 keeps everything |
| `retentionDays` | `0` | 0 keeps everything |
| `maxAttachmentMb` | `10` | Above this, delivery emails carry a link |
| `runScheduleAutomatically` | `true` | Fire schedules from control-panel traffic |
| `readLabReportsConfig` | `true` | Also read `config/labreports.php`'s functions |
| `debug` | `false` | More logging, stack traces on failed runs |

## Config file

`config/reportr.php` also holds the two things a settings screen cannot:

```php
return [
    'functions' => [
        // Formatting functions for advanced reports.
    ],
    'writers' => [
        // Your own export formats.
        'fixed' => \modules\reports\FixedWidthWriter::class,
    ],
];
```

A writer implements `justinholtweb\reportr\writers\WriterInterface`. The awkward format is always
the one somebody else's finance system insists on, so there is a way to add it without a fork.

## Events

```php
use justinholtweb\reportr\events\RunEvent;
use justinholtweb\reportr\services\Runner;
use yii\base\Event;

Event::on(Runner::class, Runner::EVENT_BEFORE_RUN, function(RunEvent $event) {
    // $event->report, $event->run
    // $event->isValid = false; to stop it
});

Event::on(Runner::class, Runner::EVENT_AFTER_RUN, function(RunEvent $event) {
    // Fires whether the run succeeded or failed.
});
```

## Troubleshooting

**"The report template ran but never wrote any rows."** The template rendered without calling
`report.build()` or `report.addRow()`. Usually a `{% if %}` that was never true, or a missing
`{% do %}`.

**A report fails only on a schedule.** Anything that reads the current user or the current request
will not find one in a queue job. Parameters are answered from their defaults on a scheduled run,
so a required parameter with no default has no answer.

**`exceeded the timeout of 300 seconds`.** Raise the job timeout in Reportr's settings.

**A run says "File missing".** Hover it: the message names the filesystem it looked on. On
ephemeral hosting, point Reportr at a real filesystem.

**Errors go to `storage/logs/reportr.log`**, and every failed run carries its reason — with the
template and line number — on its detail page. Turn on debug mode for full stack traces.

## Licence and price

Reportr is **$29 per site**. The first year of updates is included; after that, **$19 a year** keeps
them coming. It is not free software — the [licence](LICENSE.md) covers development and testing,
and a production site needs a licence.
