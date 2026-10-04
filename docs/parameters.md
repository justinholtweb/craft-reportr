---
title: Parameters
slug: parameters
order: 30
summary: Questions a report asks before it runs, answered in the control panel, on the command line or from Twig — and arriving already typed.
---

A parameter is a question the report asks before it runs. Declare it on the report; answer it in
the control panel, on the command line, or from Twig.

The answers arrive in the template as `params.<name>`, **already the right type**. A template that
has to parse what a form control produced will get it wrong the day the parameter is empty.

```twig
{% set query = craft.entries.section('orders') %}

{% if params.since %}
    {% set query = query.postDate('>= ' ~ params.since|date('Y-m-d')) %}
{% endif %}

{% if params.customer %}
    {% set query = query.relatedTo(params.customer) %}
{% endif %}
```

## Types

| Type | Arrives as |
| --- | --- |
| Text | `string` |
| Number | `int` or `float` |
| Date | `DateTime` |
| Date and time | `DateTime` |
| Yes/no | `bool` |
| Dropdown | The chosen value |
| Checkboxes | A list of the chosen values |
| Entries, users, categories, assets, tags | An element, or a list of them |
| Site | The site's ID, as an `int` — pass it to `.siteId()` |

An unanswered parameter is `null` — a yes/no is `false` — so `{% if params.since %}` is the whole
test. A site can be answered by handle on the command line.

## Relative defaults

A default may be relative — `-7 days`, `first day of last month`, `today`. It is worked out **at
each run**, not when somebody typed it, which is what makes a scheduled report worth scheduling: a
`since` parameter defaulting to `-7 days` means the last seven days, every Monday.

Dates are read in the site's timezone, so a date entered in the control panel is the day you meant
wherever the server is.

## On a query report

A parameter named after one of a fixed set of query params — `postDate`, `expiryDate`, `dateCreated`, `dateUpdated`, `lastLoginDate`, `relatedTo`, `search`, `level`, `kind`, `sectionId`, `typeId`, `authorId`, `authorGroupId`, `groupId`, `volumeId` and `folderId` — is applied to the query
automatically, through the query's own method. It can only **narrow** what the report's source
shows: a `sectionId` answer outside the source's sections matches nothing, and `relatedTo` is added
to the source's relations rather than replacing them. Other names are ignored there and are still
available to a `twig:` column.

Names that are query machinery — `where`, `orderBy`, `join`, `select`, `editable`, `drafts`,
`trashed` and the like — are reserved, and a report won't save with a parameter called one.

## Answering them

**In the control panel**, **Run report** asks each question before it starts.

**On the command line**, as JSON:

```sh
php craft reportr/reports/build --report=orders --params='{"since":"-30 days","region":"north"}'
```

**From Twig**:

```twig
{% set run = craft.reportr.queue('orders', { since: '-30 days' }) %}
```

**On a schedule**, each parameter takes its default. Nobody is there to answer a required parameter
with no default, so the run fails and its detail page names the parameter — give scheduled reports
defaults. The control panel and `reportr/reports/build` ask before anything is queued; a schedule
and `craft.reportr.queue()` find out when the run starts.

The answers are recorded on the run, so its detail page shows what it was asked, and a
`{param:name}` token puts an answer in the filename (see
[Formats and storage](formats-and-storage#filenames)).
