---
title: Formats and storage
slug: formats-and-storage
order: 40
summary: Seven export formats, formula-injection protection, any Craft filesystem, filename patterns and retention.
---

## Export formats

| Format | Notes |
| --- | --- |
| **CSV** | UTF-8 with a byte-order mark by default, because Excel on Windows reads one without as Windows-1252 and mangles every accented name |
| **TSV** | Tab separated |
| **XLSX** | A real Excel workbook, written without PhpSpreadsheet. Bold headers, numbers stored as numbers, leading zeros kept as text so postcodes survive. Needs `ext-zip` |
| **JSON** | An array of objects keyed by the column headings, or positional arrays |
| **NDJSON** | One object per line, for feeding something else |
| **XML** | Element names derived from the headings and sanitised so the document parses |
| **HTML** | A self-contained table with its CSS inline, for emailing to a person |

The format and its options are per report:

| Option | Applies to | Default |
| --- | --- | --- |
| Delimiter | CSV — any single character | `,` |
| Enclosure | CSV | `"` |
| Byte-order mark | CSV, TSV | On |
| Header row | CSV, TSV | On |
| Sheet name | XLSX | `Report` |
| Root and row element names | XML | `rows`, `row` |
| Pretty-print | JSON | Off |
| Keyed objects | JSON, NDJSON | On |
| List separator | Every format | `, ` |
| Neutralise formulas | CSV, TSV | On |

The command line can override the format for one run, without changing the report:

```sh
php craft reportr/reports/build --report=orders --format=xlsx
```

Your own formats can be registered in `config/reportr.php` — see
[Configuration](configuration#your-own-export-formats).

### Formula injection

A cell beginning `=`, `+`, `-` or `@` runs as a formula when a spreadsheet opens the file. An export
of anything a member of the public typed — a contact form, a review, a username — is therefore a way
to run code on the machine of whoever opens the report.

In CSV and TSV, Reportr prefixes those cells with an apostrophe, which spreadsheets hide. Negative
numbers are exempt. XLSX needs no prefix: Reportr writes text as a typed string cell, which a
spreadsheet never evaluates. Turn **Neutralise spreadsheet formulas** off only if something downstream needs the raw text and nobody
will open the file in a spreadsheet.

## Where files are stored

By default, `storage/reportr`. You can point the whole site — or one report — at any **Craft
filesystem** instead: S3, DigitalOcean Spaces, anything with a Flysystem adapter.

On Heroku, a scaled container or a read-only image, the application disk does not survive a
restart, and every generated report eventually turns into "file missing". A run records **which
filesystem** it was written to as well as the path within it, so changing the default later does
not orphan the archive, and a missing file says where it went looking.

Builds always write to a local temporary file and move the finished file into place afterwards. An
object store has no notion of appending, and a build that fails halfway must not leave a truncated
file where a downstream system is watching for one.

A subfolder can be set per report. It can't step outside the filesystem with `..`.

The local folder gets an `.htaccess` deny and an empty `index.html` when it is created. Keep it
outside the web root anyway — downloads go through Reportr, which checks permissions first.

## Filenames

The default is `{handle}-{datetime}`. The filename is frequently the interface — a finance system
watching a folder for `orders-2026-08.csv` cares about the name — so it is a pattern, set per report.

| Token | Gives |
| --- | --- |
| `{handle}` | The report's handle |
| `{title}` | The report's title, slugified |
| `{id}` | The report's ID |
| `{date}` | `2026-08-27` |
| `{time}` | `071500` |
| `{datetime}` | `20260827-071500` |
| `{timestamp}` | Unix seconds |
| `{Y}` `{m}` `{d}` `{H}` `{i}` `{s}` | The parts, as PHP's `date()` formats them |
| `{param:name}` | A parameter's answer, slugified. A date gives `Y-m-d` |

Times are in the site's timezone. The extension is added for you. An unknown token is left in the
name rather than blanked, so a typo shows up in the filename instead of producing `report--.csv`.

## Retention

Keep the last N runs, or runs from the last N days — site-wide in the settings, or per report. Zero
keeps everything, which is the default.

Deletions are hard, files and all: a retention policy whose deletions sit in the trash still
occupies the disk, which is usually the point. To see what would go first:

```sh
php craft reportr/runs/prune --dry-run
```

Deleting a report does not delete its runs. They keep the report's title and stay downloadable.
