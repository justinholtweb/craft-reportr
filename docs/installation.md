---
title: Installation
slug: installation
order: 10
summary: Requirements, install, what Reportr adds to your site, and a first report in five minutes.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- `ext-zip`, only if you want the XLSX format

No other runtime dependencies, and nothing is fetched over the network at any point.

## Install

```sh
composer require justinholtweb/craft-reportr
php craft plugin/install reportr
```

Or search for **Reportr** in the Plugin Store.

## What install adds

- Two database tables: `reportr_reports` (the configurations) and `reportr_runs` (one row per
  execution and the file it produced).
- A **Reportr** section in the control panel: Reports, Runs, Import and Settings.
- A folder for generated files, `storage/reportr`, created the first time a report is built.

Reportr writes nothing to project config. Reports are content, like entries — they are created on
each environment, or brought across from Lab Reports with the importer.

## Licence and price

**$29 per site**, including a year of updates; **$19 a year** after that keeps them coming. There is
one edition — every feature on this site is in it.

Reportr uses the Craft License: you can develop and test with it freely, and a production site
needs a licence.

## Your first report

The quickest report needs no template:

1. **Reportr → Reports → New report.**
2. Give it a title and choose the **Query** type.
3. Pick an element type and source — say *Entries* and one section.
4. Add a few columns: `title`, `postDate`, `author.email`.
5. Save, then **Run report**.

The run appears under **Runs** with its row count, duration and a download link. From there, see
[Report types](report-types) for templates, [Parameters](parameters) for asking questions at run
time, and [Scheduling and delivery](scheduling-and-delivery) to have it turn up in an inbox every
Monday.

Coming from Lab Reports? Start with [Migrating from Lab Reports](migrating-from-lab-reports)
instead — your templates run unchanged.
