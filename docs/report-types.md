---
title: Report types
slug: report-types
order: 20
summary: Basic and advanced reports are Twig templates; query reports are built in the control panel with no template at all.
---

Reportr has three report types. The first two are the ones Lab Reports had, and run its templates
unchanged. The third needs no template.

| Type | You write | Rows held in memory | Good for |
| --- | --- | --- | --- |
| Basic | A template that builds an array | All of them | Up to a few thousand rows, anything bespoke |
| Advanced | A template that hands over a query, plus a PHP function | One batch | Big exports |
| Query | Nothing — pick columns in the control panel | One batch | "Export the members who joined last month" |

## Basic reports

A Twig template that builds an array of rows and hands the lot over.

**The first row is the column headings.** Reportr writes it as a header rather than as data, so CSV
output is identical to Lab Reports' while JSON and XML get real keys instead of `column1`.

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

`report.addRow(row)` and `report.addRows(rows)` are there too, for a template that would rather
write as it goes.

## Advanced reports

A template that hands over the column headings and an **unexecuted** element query, plus — chosen
on the report — the name of a PHP function that turns one element into one row. Reportr walks the
query in batches and streams each row to disk, so the report's size is bounded by disk rather than
by memory.

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

Functions in `config/labreports.php` are read as well, unless you turn that off in the settings.

## Query reports

No template at all. Choose an element type, one of that type's own index sources, a status and an
order, then pick the columns from a list.

Column keys are a three-prefix mini-language:

| Key | Gives you |
| --- | --- |
| `attr:title` | An attribute on the element |
| `attr:author.email` | Dotted, to walk into a related element |
| `field:bookAuthor` | A custom field, flattened to one cell |
| `field:bookAuthor.title` | One property of every related element, joined |
| `field:priceTable.amount` | One column out of a Table field |
| `field:specs.0.price` | One row of one, by index |
| `twig:{{ object.title\|upper }} ({{ object.id }})` | An object template, for anything else — admins only |

An unprefixed key (`title`) is read as an attribute. A key pointing at something the element does
not have gives an empty cell, not a failed report — which matters on a source holding more than one
entry type.

Each column also takes a heading and a format — auto, text, number, date, date and time, yes/no or
list — so a date comes out as a date and a postcode keeps its leading zero.

### What a query report can't do

- **A `twig:` column is real Twig, not a sandbox.** It can reach `craft.app` like any template, so
  only an admin can add or change one. Anyone who can manage reports can keep or remove an admin's.
- **No column reads a credential.** A user's password hash, verification code and similar are never
  read, however the dotted path reaches them.
- **Order by must be a column name.** Anything else is ignored rather than run as SQL.

Who may build a query report over which element types is covered in
[Configuration](configuration#permissions).
