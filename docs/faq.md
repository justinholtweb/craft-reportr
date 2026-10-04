---
title: FAQ
slug: faq
order: 90
summary: Short answers about price, Lab Reports, charts, big exports, S3, permissions and dependencies.
---

## What does it cost?

$29 per site, including a year of updates, then $19 a year to keep getting them. One edition;
everything is included.

## Do I need Lab Reports installed?

No. Reportr is a standalone plugin. Lab Reports only needs to be installed while you run the
importer, because the importer reads its tables.

## Does the importer change my Lab Reports data?

No. It only reads Lab Reports' tables, and it copies generated files rather than moving them. You
can compare the two side by side and uninstall Lab Reports when you're satisfied. Running the
import twice skips what is already there.

## Will my Lab Reports templates work?

Yes, unchanged. The `report` variable, `build()`, `addRow()`, `addRows()`, `filePath()` and
`fileExists()` all behave the same, and functions in `config/labreports.php` keep working. Front-end
templates need `craft.labreports` changed to `craft.reportr`. See
[Migrating from Lab Reports](migrating-from-lab-reports).

## Can it draw charts?

Not inside a report, deliberately: a report is data, and the thing that draws it is a spreadsheet.
Each run's page does plot row counts over the last twenty runs, and the HTML format is there for a
report meant to be read rather than imported.

## How big can a report get?

Advanced and query reports stream: rows are fetched in batches and written straight to disk, so
the limit is disk, not memory. Exports of hundreds of thousands of rows are normal. Basic reports
hold their rows in memory, so they suit up to a few thousand.

## Can reports be stored on S3?

Yes — on any Craft filesystem. Set one site-wide in the settings or per report. Each run remembers
which filesystem it went to, so changing the default later doesn't lose the history.

## Can editors build reports without being admins?

Yes, over content they can already see in the control panel. They can't report on users, orders or
products without the matching permissions, can't use *All entries* if it reaches sections hidden
from them, and can't add the `twig:` columns, which are admin-only. Downloading output is a separate
permission from seeing that a report exists.

## Does it run on Craft 4?

No. Reportr needs Craft 5.3 or later and PHP 8.2 or later.

## Does it use PhpSpreadsheet?

No. Reportr has no runtime dependencies beyond Craft. The XLSX writer is its own, streams instead of
building the workbook in memory, and only needs PHP's `zip` extension.

## Does it send data anywhere?

No. Reportr makes no outbound requests. Files go where you tell it to store them, and emails go
through your site's own mailer, to the recipients you list.
