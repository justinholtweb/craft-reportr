---
title: Configuration
slug: configuration
order: 60
summary: Every setting, the config file, your own export formats, permissions and who may report on what, and events.
---

## Settings

**Reportr → Settings**, or `config/reportr.php` — which wins, and is how you vary a setting between
environments.

| Setting | Default | |
| --- | --- | --- |
| `fsHandle` | none | Craft filesystem for report files. Empty uses the local folder |
| `fsSubpath` | `reports` | Subfolder within that filesystem. `..` is never followed |
| `storageFolder` | empty | Local folder for report files. Empty means `storage/reportr`. Accepts an environment variable |
| `jobTtr` | `3600` | Seconds a queued build may take before the queue reclaims it |
| `batchSize` | `100` | Rows fetched at a time by advanced and query reports |
| `memoryLimit` | `512M` | Applied for the build and put back afterwards. Never lowers the server's own |
| `timeLimit` | `0` | Seconds, for the build. `0` leaves PHP's own limit alone |
| `previewRows` | `25` | Rows shown by **Preview** |
| `retentionRuns` | `0` | Keep the last N runs of each report. `0` keeps everything |
| `retentionDays` | `0` | Keep runs from the last N days. `0` keeps everything |
| `maxAttachmentMb` | `10` | Above this, delivery emails carry a link instead of the file |
| `runScheduleAutomatically` | `true` | Fire due schedules from control-panel traffic |
| `readLabReportsConfig` | `true` | Also read the `functions` in `config/labreports.php` |
| `debug` | `false` | More logging, and stack traces on failed runs |

The filesystem, subfolder and both retention settings can each be overridden on a single report.

## The config file

`config/reportr.php` also holds the two things a settings screen cannot:

```php
<?php

return [
    // Any setting from the table above.
    'jobTtr' => 7200,

    'functions' => [
        // Formatting functions for advanced reports: one element in, one row out.
        'bookDump' => fn($entry) => [$entry->id, $entry->title],
    ],

    'writers' => [
        // Your own export formats.
        'fixed' => \modules\reports\FixedWidthWriter::class,
    ],
];
```

When `readLabReportsConfig` is on, functions in `config/labreports.php` are available too. A name
in Reportr's own file wins on a collision.

### Your own export formats

The awkward format is always the one somebody else's finance system insists on. A writer implements
`justinholtweb\reportr\writers\WriterInterface`; extending `BaseWriter` gets you the file handle,
the byte count and value stringifying for free.

```php
namespace modules\reports;

use justinholtweb\reportr\writers\BaseWriter;

class FixedWidthWriter extends BaseWriter
{
    public static function extension(): string
    {
        return 'txt';
    }

    public static function mimeType(): string
    {
        return 'text/plain';
    }

    public function writeRow(array $row): bool
    {
        $cells = array_map(fn($v) => str_pad(mb_substr($v, 0, 20), 20), $this->stringifyRow($row));

        return $this->write(implode('', $cells) . "\n");
    }
}
```

Writers stream: they get one row at a time and should never hold the file in memory. The key in
`writers` (`fixed`) is what appears in the report's format list and what `--format` takes.

## Permissions

| Permission | Lets someone |
| --- | --- |
| **View report configurations** | See the reports list and each report's settings |
| ↳ **Create, edit and delete reports** | Build and change reports, within the limits below |
| ↳ **Run reports** | Run a report, answering its parameters |
| **View runs** | See the run history |
| ↳ **Download report files** | Download what a run produced |
| ↳ **Delete runs** | Delete runs and their files |

Reports and runs are deliberately separate. A report's *output* is the data: somebody who may see
that the "Members export" exists is not thereby somebody who may download every member's email
address.

Settings and the Lab Reports import are for admins.

### What someone who isn't an admin may report on

A report reads content in bulk, so building one takes what reading that content in the control
panel takes:

- **Entries, categories and assets** — a source they can view. Not *All*, which includes sources
  they can't.
- **Users** — permission to view users.
- **Commerce orders** — permission to manage orders. **Products and variants** — a product type
  they can view.
- **Anything else** — addresses, another plugin's elements — needs an admin.
- **`twig:` columns** — only an admin can add or change one, because a column template is real
  Twig, not a sandbox.
- **Columns that reach a user** — an author, an uploader, a Users field — need permission to view
  users.
- **Type, template, formatting function, storage location and filename pattern** — admin only.
- **Recipients and attachments** — changing who a report is emailed to, or whether the file goes
  with it, needs **Download report files**.

Only what changed is checked: someone can rename or reschedule a report an admin built over users
without needing the users permission, and without being able to change its `twig:` columns.

**Running** a report runs what its builder chose, whoever presses the button. Give **Run reports**
to people who may see that report's output.

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
    // Fires whether the run succeeded or failed. $event->run->runStatus says which.
});
```

Every way of running a report — the control panel, the console, the scheduler and Twig — goes
through the same runner, so these fire for all of them.
