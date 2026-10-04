# Reportr — Craft CMS 5 Plugin

## Project Overview

Reportr is a replacement for **Masuga's Lab Reports** (`masugadesign/lab-reports-craft-cms`), which
stopped taking new features in July 2024. Distributed as `justinholtweb/craft-reportr`.
**$29 per site, $19/year renewal. Single edition** — no Lite/Pro split; everything is on.

The whole strategy is *compatible replacement, then everything they never shipped*. A site should
be able to move in an afternoon: templates unchanged, formatting functions unchanged, one importer
run. Every feature beyond that maps to a specific line in their issue tracker or their README's
"Planned Features" list, and the code comments say which.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies. `ext-zip` is *suggested*, for XLSX only
- Two element types, two tables, no project config

## Architecture

### Namespace & package

- Namespace: `justinholtweb\reportr`
- Package: `justinholtweb/craft-reportr`
- Handle: `reportr`

### Two elements, and why the naming is what it is

- `elements\Report` — the **configuration**. Native titles, plus its own `handle`.
- `elements\Run` — one **execution** and the file it produced.

Lab Reports called these `ConfiguredReport` and `Report`, and its *template variable* for the
second one is `report`. Reportr keeps that variable name, which is why `build()`, `addRow()` and
`addRows()` live on `Run` rather than on `Report` — where they would otherwise obviously go. That
is compatibility bought at the cost of tidiness, and it is worth it.

### The load-bearing split: element, session, writer

- `elements\Run` is a database row.
- `build\Session` is the machinery — it owns the writer, the batching, the row counting and the
  parameter values, and exists only while a build is in flight. `Run` forwards to it.
- `writers\*` each own one file format and stream.

Everything that can produce a report — CP, console, scheduler, Twig — goes through
`services\Runner::execute()`. A second code path is how a plugin ends up behaving differently on a
schedule than when somebody presses the button.

### Column names that are decisions

- **`runStatus`, not `status`** — `status` is `Element::getStatus()`; a column of that name is
  shadowed by the getter. `RunQuery::statusCondition()` maps the element status onto it.
- **`initiator`, not `trigger`** — `TRIGGER` is reserved in MySQL.
- **`reportId` is `SET NULL` and the run copies `reportTitle`** — deleting a configuration must not
  delete or break its history. Lab Reports' `getUiLabel()` dereferenced the configured report with
  no null check, so tidying up a configuration fatalled the index.
- **`fsHandle` + `path`, not a filename** — a run has to know *which filesystem* it went to, or
  changing the default orphans the archive. This is their issue #8.

### Parameters are the headline

`models\Parameter` is Lab Reports' issue #1 and the top item on their roadmap. A parameter is
declared on the report and answered at run time; the answer reaches the template already typed
(`DateTime`, elements, `int`/`float`), because a template that has to parse what a form control
produced will get it wrong the day the parameter is empty. Defaults may be relative — that is what
makes a scheduled report worth scheduling.

### Scheduling stores the next run

`reportr_reports.nextRunAt` is computed on save and advanced by a **conditional update** that only
succeeds if the row still holds the value it was read with. A cron entry and the CP fallback both
running is the normal state of affairs, and without the claim they both fire.

## Who may report on what

`helpers\Access` + `Report::validateAccess()`. Non-admins: an element type + source they can view
in the CP (Craft filters entry/category/asset index sources by the signed-in user; users, orders
and product types are checked by permission), never `*` where that would reach hidden sources, and
no new or changed `twig:` column — `renderObjectTemplate()` is **not** a sandbox. Only what changed
is checked, so editing an admin's report doesn't need the admin's reach. `QueryBuilder` never reads
password-like segments and only orders by a column name (Yii passes `(…)` through unquoted).

## Traps found while building this

- **`Session::finish()` clears the "opened" flag**, and the runner asks *after* closing whether the
  template wrote anything. A single `$opened` flag reported every successful report as an empty one
  ("never wrote any rows"). There is a separate `$everOpened`.
- **`afterRun()` must not `saveElement()` the report.** It used to, to bump `runCount`, which
  persisted whatever else was on the element in memory — so `--format=csv` on one console run
  permanently rewrote the report. Two columns, written straight to the row, with the increment in
  SQL.
- **`DateTimeHelper::toDateTime($value, false, true)` reads a CP date input as UTC.** Craft's date
  field posts wall-clock time in the site's timezone with no offset; assuming UTC moves the answer
  by the server's distance from Greenwich, which west of it is a different day. Pass
  `assumeSystemTimeZone: true`.
- **`StringHelper::toWords()` returns an array in Craft 5**, so feeding it to `toTitleCase()` is a
  `TypeError`. Split camel case by hand.
- **`Element::sortOptions()` prepends `'id' => 'ID'` and mixes in list entries** whose `orderBy` is
  a closure. A test that iterates the *values* orders by the label and gets "Column 'ID' in ORDER BY
  is ambiguous" — which is the test's bug, not the plugin's. The attribute is the key.
- **`getAttributeHtml()`, not the protected `attributeHtml()`**, when walking an element's columns:
  the public one fires the event other plugins use to add columns to every element type, and
  calling the protected one reports their columns as your failures.
- **Craft always prepends `'id'` to sort options**, so an element with a sub-table that also has an
  `id` column is fine only because `ElementQuery` disambiguates it. Do not add `id` yourself.
- `fputcsv()`'s fifth argument (`escape`) must be passed explicitly as `''` — PHP's backslash
  escaping is not part of any CSV dialect and is deprecated as a default in 8.4.
- **XLSX with inline strings, never a shared-string table.** The table is smaller and requires
  holding every distinct string until close, which trades exactly the wrong way for a large export.
- **Twig filters must be prefixed.** A later extension silently replaces a filter of the same name,
  so `cell`, `column` and `values` are `reportCell`, `reportColumn`, `reportValues`.
- **A build resolves its source in `CONTEXT_FIELD`, never `CONTEXT_INDEX`.** Craft builds index
  sources around the signed-in user and adds `editable: true`, so a scheduled or console build —
  nobody signed in — aborted with zero rows, and a web-queue build ran with the reach of whoever's
  request happened to run the queue. Access is settled at save; the build runs what was saved.
- **Parameters reach a query only through `QueryBuilder::QUERY_PARAMS`, by method, and ID params
  are intersected with the source's.** Writing `$query->{$name}` let a parameter called `where`,
  `orderBy`, `sectionId` or `editable` inject SQL or swap the source for a hidden one.
- **`requireAdmin(false)` on the settings and import controllers**, `requireAdmin()` only on the
  settings save. The default also demands `allowAdminChanges`, which 403'd the importer and the
  schedule refresh on production, where the Lab Reports history actually lives.
- **A GET to the run URL only renders the form.** It used to start a parameterless report, which
  made any link a CSRF.
- **Duplicate needs `beforeValidate()`**: Craft validates the copy, the handle collides, and the
  action fails outright. The copy is also disabled, so a scheduled report doesn't mail twice.
- **An import option is a lightswitch, not a checkbox**: an unticked checkbox posts nothing, and the
  controller's default of `true` won.
- **Parameter labels and instructions are encoded before markdown** (`Parameter::safeLabel` /
  `safeInstructions`) — Craft's field instructions render raw HTML, and a non-admin writes them.

See `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps — the nested-form
corruption, the typed-`int` `''` TypeError, `Craft::configure()` assigning straight to element-query
properties, the soft-delete handle squat, and the buffered project config all apply here and are
handled.

## Testing

No local PHP on this Mac. Everything runs in the plugin-testing container.

```sh
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-reportr/tests/integration/checks.php     # 103 checks
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-reportr/tests/integration/security.php   # 29, editors and a viewer over HTTP
docker exec -w /sites/craft-reportr ddev-phpstan-runner-web \
  bash -c 'vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'

docker exec ddev-plugin-testing-web bash -c \
  'find /var/www/craft-reportr/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The suite is self-cleaning: it writes its own report templates and a `config/reportr.php`, builds
**real Lab Reports tables** to import from, and removes all of it on a shutdown hook. It needs a
site with some entries on it.

Two harness notes, neither of them the plugin's fault:

- Something on this machine stops the ddev containers at intervals. `ddev start` fights it; plain
  `docker start ddev-plugin-testing-db ddev-plugin-testing-web` and a readiness loop does not.
- `craft-csr`'s `Ticket::getStatus()` has an incompatible return type and fatals every **web**
  request in the harness (console is fine). Disable it while checking CP screens.
- The harness **ends an admin session after a few minutes**, and a save that bounces to the login
  screen (302) looks exactly like a save that was refused. `security.php` signs the admin in again
  for its late checks and proves each refusal with a control save that must succeed;
  `REPORTR_DEBUG=1` prints the status of every save that came back empty. If every admin save
  fails, reset the password through the element (see the shared harness memory).

## Coding conventions

Deliberately the family's, not michtio's skill, where the two differ: the main class is `Plugin`,
services are declared in `config()` and read as `->runner`, permission handles are camelCase, and
files declare `strict_types`. Don't "fix" these in one plugin.

- `Craft::t('reportr', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — secondary actions post with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Every element-query param is typed `mixed` and normalised in `beforePrepare()`
- A failed report is a **run with a reason on it**, never an exception that escapes into the queue
  log. `Runner::execute()` does not throw.
