# Release Notes for Reportr

## 5.0.0 — 2026-08-27

Initial release.

Reportr is a replacement for [Lab Reports](https://github.com/masugadesign/lab-reports-craft-cms),
which stopped taking new features. It keeps that plugin's template contract exactly — the same
`report` variable, the same `build()` signatures, the same formatting-function config — and then
implements the things its issue tracker and its own roadmap asked for.

### Compatible with Lab Reports

- Basic and advanced report templates run unchanged
- `config/labreports.php`'s `functions` array is read alongside Reportr's own, so advanced reports
  keep working without anything being moved
- An importer brings report configurations and their whole run history across, with the files;
  idempotent, and it never modifies the Lab Reports tables
- `craft.reportr` carries the old `configuredReports`, `generatedReports`, `formatFunctionNames`
  and `formatFunctionOptions` methods, and the `configuredReportId` / `dateGenerated` query params

### New

- **Run-time parameters** — text, number, date, date and time, yes/no, dropdown, checkboxes,
  entries, users, categories, assets, tags and site. Answers arrive in the template already typed.
  Defaults may be relative (`-7 days`), which is what makes a scheduled report worth scheduling
- **A third report type** built in the control panel, with no template at all: an element type, one
  of its own index sources, and columns picked from a list
- **Seven export formats** — CSV, TSV, XLSX, JSON, NDJSON, XML and HTML, plus a registry for your
  own. The XLSX writer is dependency-free and streams
- **Storage on any Craft filesystem**, recorded per run, so reports survive ephemeral hosting and a
  changed default does not orphan the archive
- **Scheduling** — hourly, daily, weekly or monthly, in the site's timezone, claimed atomically so
  two schedulers cannot double-fire a report
- **Email delivery**, including failure-only
- **Retention** — keep the last N runs, or N days, per report or site-wide
- **Preview** — run with a row cap and see the rows, writing nothing
- **A run history** recording the parameters, duration, peak memory, row count and what started it,
  with a sparkline of rows over the last runs
- Permissions, split so that seeing a report exists is not the same as downloading its output
- Spreadsheet formula injection neutralised in delimited exports by default
- A filename pattern with tokens, including parameter values

### Fixed, relative to Lab Reports

- **#1** Dynamic report parameters — the top item on its roadmap, open since 2021
- **#2** Craft version constraint no longer pinned below a minor release
- **#3** Related and Table field data reachable from a column, with `|reportCell`, `|reportColumn`
  and `|reportValues` and dotted column keys
- **#4** `{% import %}` and `{% include %}` work inside report templates — the runner sets the
  template *mode*, not the templates *path*
- **#5** Report configurations can be deleted, and their past runs survive it
- **#6** Nothing accumulates in memory; the log buffer, query logging and the cycle collector are
  all handled during a build
- **#7** The queue job's TTR is a setting, defaulting to an hour instead of Craft's 300 seconds
- **#8** Report files can live on a real filesystem, and a run records which one, so "Unavailable
  (File Missing)" is neither inevitable nor unexplained
