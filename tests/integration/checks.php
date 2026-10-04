<?php
/**
 * Reportr integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-reportr/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: real elements saved through Craft, real report templates
 * rendered by Twig, real files written to real storage, and a real Lab Reports database imported
 * out of tables built for the occasion.
 *
 * Self-cleaning. Every element, table, file and config file it creates is removed at the end,
 * including on failure, so running it against a working site leaves nothing behind.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\models\Column;
use justinholtweb\reportr\models\Delivery;
use justinholtweb\reportr\models\FormatOptions;
use justinholtweb\reportr\models\Parameter;
use justinholtweb\reportr\models\QuerySpec;
use justinholtweb\reportr\models\Schedule;
use justinholtweb\reportr\Plugin;
use justinholtweb\reportr\writers\DelimitedWriter;
use justinholtweb\reportr\writers\HtmlWriter;
use justinholtweb\reportr\writers\JsonWriter;
use justinholtweb\reportr\writers\NdjsonWriter;
use justinholtweb\reportr\writers\XlsxWriter;
use justinholtweb\reportr\writers\XmlWriter;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Reportr is not installed.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$templatesPath = Craft::$app->getPath()->getSiteTemplatesPath() . '/_reportr-checks';
$configPath = Craft::$app->getPath()->getConfigPath() . '/reportr.php';
$wroteConfig = false;
$scratch = Craft::$app->getPath()->getTempPath() . '/reportr-checks';
$createdElementIds = [];
$createdLegacyElementIds = [];
$createdLegacyTables = false;

FileHelper::createDirectory($templatesPath);
FileHelper::createDirectory($scratch);

// The formatting-function registry reads `config/reportr.php`, so an advanced report cannot be
// exercised without one. Written before anything touches the config service, because both Craft
// and the plugin memoise the file for the life of the request.
if (!file_exists($configPath)) {
    file_put_contents($configPath, <<<'CONFIG'
    <?php

    return [
        'functions' => [
            'reportrCheckDump' => function($element) {
                return [(int)$element->id, (string)$element->title];
            },
            'reportrCheckBadReturn' => function($element) {
                return 'not an array';
            },
        ],
    ];
    CONFIG);
    $wroteConfig = true;
}

file_put_contents($templatesPath . '/basic.twig', <<<'TWIG'
{% set rows = [['ID', 'Title']] %}
{% for entry in craft.entries.limit(5).all() %}
    {% set rows = rows|merge([[entry.id, entry.title]]) %}
{% endfor %}
{% do report.build(rows) %}
TWIG);

file_put_contents($templatesPath . '/params.twig', <<<'TWIG'
{% set rows = [['Word', 'Number']] %}
{% set rows = rows|merge([[params.word, params.count]]) %}
{% do report.build(rows) %}
TWIG);

file_put_contents($templatesPath . '/macros.twig', <<<'TWIG'
{% macro cell(value) %}{{ value|upper }}{% endmacro %}
TWIG);

// Lab Reports issue #4: an `{% import %}` inside a report template threw "template not found",
// because the old plugin set the templates *path* rather than the template *mode*.
file_put_contents($templatesPath . '/imports.twig', <<<'TWIG'
{% import '_reportr-checks/macros' as helpers %}
{% do report.build([['Heading'], [helpers.cell('shouted')|trim]]) %}
TWIG);

file_put_contents($templatesPath . '/advanced.twig', <<<'TWIG'
{% do report.build(['ID', 'Title'], craft.entries.limit(4)) %}
TWIG);

file_put_contents($templatesPath . '/silent.twig', <<<'TWIG'
{# Renders fine and writes nothing at all, which is the commonest mistake there is. #}
{% set unused = 1 %}
TWIG);

$cleanup = static function() use (&$createdElementIds, &$createdLegacyElementIds, $templatesPath, $configPath, &$wroteConfig, $scratch, &$createdLegacyTables) {
    foreach (array_reverse($createdElementIds) as $id) {
        try {
            $element = Craft::$app->getElements()->getElementById($id, null, null, ['status' => null, 'trashed' => null]);

            if ($element !== null) {
                Craft::$app->getElements()->deleteElement($element, true);
            }
        } catch (Throwable) {
            // Best effort.
        }
    }

    if ($createdLegacyTables) {
        $db = Craft::$app->getDb();

        try {
            $db->createCommand()->dropTableIfExists('{{%labreports_reports}}')->execute();
            $db->createCommand()->dropTableIfExists('{{%labreports_configured_reports}}')->execute();
        } catch (Throwable) {
        }
    }

    // The stand-in Lab Reports elements are raw `elements` rows of a type nothing can instantiate,
    // so `deleteElement()` cannot reach them — `getElementById()` returns null and they would sit
    // there for ever. Deleted as rows, which is how they were made.
    if ($createdLegacyElementIds !== []) {
        try {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%elements}}', ['id' => $createdLegacyElementIds])
                ->execute();
        } catch (Throwable) {
        }
    }

    FileHelper::removeDirectory($templatesPath);
    FileHelper::removeDirectory($scratch);

    if ($wroteConfig && file_exists($configPath)) {
        FileHelper::unlink($configPath);
    }
};

register_shutdown_function($cleanup);

/** Save an element and remember it for the clean-up. */
$track = static function($element) use (&$createdElementIds) {
    if ($element->id !== null && !in_array($element->id, $createdElementIds, true)) {
        $createdElementIds[] = $element->id;
    }

    return $element;
};

$makeReport = static function(array $attributes) use ($track): Report {
    $report = new Report();
    $report->title = $attributes['title'] ?? 'Check report';
    $report->handle = $attributes['handle'] ?? ('check-' . bin2hex(random_bytes(4)));
    $report->type = $attributes['type'] ?? Report::TYPE_BASIC;
    $report->template = $attributes['template'] ?? '_reportr-checks/basic';
    $report->format = $attributes['format'] ?? 'csv';

    foreach ($attributes as $key => $value) {
        if (!in_array($key, ['title', 'handle', 'type', 'template', 'format'], true)) {
            $report->$key = $value;
        }
    }

    if (!Craft::$app->getElements()->saveElement($report)) {
        throw new RuntimeException('Could not save the fixture report: ' . Json::encode($report->getErrors()));
    }

    $track($report);

    return $report;
};

// ---------------------------------------------------------------------------

echo "Reportr integration checks\n";

section('Parameters');

check('a text parameter falls back to its default', function() {
    $param = Parameter::fromArray(['name' => 'word', 'type' => 'text', 'default' => 'fallback']);

    return $param->normalize(null) === 'fallback';
});

check('a number parameter reads a localised string without changing its magnitude', function() {
    $param = Parameter::fromArray(['name' => 'total', 'type' => 'number']);
    $value = $param->normalize('1,234.5');

    return abs($value - 1234.5) < 0.0001 ? true : 'got ' . var_export($value, true);
});

check('an integer parameter stays an integer', function() {
    $param = Parameter::fromArray(['name' => 'count', 'type' => 'number']);

    return $param->normalize('42') === 42;
});

check('a date parameter accepts a relative expression', function() {
    $param = Parameter::fromArray(['name' => 'since', 'type' => 'date', 'default' => '-7 days']);
    $value = $param->normalize(null);

    if (!$value instanceof DateTime) {
        return 'got ' . get_debug_type($value);
    }

    $days = (new DateTime('now'))->diff($value)->days;

    return $days === 7 ? true : "resolved {$days} days away";
});

check('a date parameter accepts the array Craft’s date input posts', function() {
    $param = Parameter::fromArray(['name' => 'when', 'type' => 'date']);
    $value = $param->normalize(['date' => '2026-03-04']);

    return $value instanceof DateTime && $value->format('Y-m-d') === '2026-03-04'
        ? true
        : 'got ' . var_export($value, true);
});

check('a boolean parameter reads the last value of Craft’s hidden-plus-checkbox pair', function() {
    $param = Parameter::fromArray(['name' => 'flag', 'type' => 'boolean']);

    return $param->normalize(['', '1']) === true && $param->normalize(['']) === false;
});

check('an unasked boolean is false rather than null', function() {
    $param = Parameter::fromArray(['name' => 'flag', 'type' => 'boolean']);

    return $param->normalize(null) === false;
});

check('a select parameter refuses a value outside its options', function() {
    $param = Parameter::fromArray(['name' => 'kind', 'type' => 'select', 'options' => "a: Apples\nb: Bananas"]);

    return $param->normalize('a') === 'a' && $param->normalize('c') === null;
});

check('options parse from the one-per-line text the edit screen posts', function() {
    $param = Parameter::fromArray(['name' => 'kind', 'type' => 'select', 'options' => "a: Apples\nb"]);

    return $param->options === ['a' => 'Apples', 'b' => 'b'] ? true : Json::encode($param->options);
});

check('a checkbox parameter drops values it was never offered', function() {
    $param = Parameter::fromArray(['name' => 'kinds', 'type' => 'multiselect', 'options' => 'a,b,c']);

    return $param->normalize(['a', 'z', 'c']) === ['a', 'c'];
});

check('a parameter name that would break Twig is repaired', function() {
    return Parameter::normalizeName('2024 total!') === 'p2024_total';
});

check('an element parameter resolves to elements, not IDs', function() {
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries on this site to test with';
    }

    $param = Parameter::fromArray(['name' => 'entry', 'type' => 'entries']);
    $value = $param->normalize([$entry->id]);

    return $value instanceof Entry && $value->id === $entry->id ? true : 'got ' . get_debug_type($value);
});

check('a multiple element parameter returns a list', function() {
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries on this site to test with';
    }

    $param = Parameter::fromArray(['name' => 'entries', 'type' => 'entries', 'multiple' => true]);
    $value = $param->normalize([$entry->id]);

    return is_array($value) && count($value) === 1;
});

check('serialising an element parameter gives back its ID', function() {
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries on this site to test with';
    }

    $param = Parameter::fromArray(['name' => 'entry', 'type' => 'entries']);

    return $param->serialize($param->normalize([$entry->id])) === $entry->id;
});

section('Schedules');

check('a daily schedule lands on the right time tomorrow at the latest', function() {
    $schedule = Schedule::fromArray(['frequency' => 'daily', 'time' => '06:00']);
    $next = $schedule->nextOccurrence(new DateTime('2026-03-04 07:00:00', new DateTimeZone(Craft::$app->getTimeZone())));
    $local = $next->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

    return $local->format('Y-m-d H:i') === '2026-03-05 06:00' ? true : $local->format('c');
});

check('a daily schedule due later today does not skip a day', function() {
    $schedule = Schedule::fromArray(['frequency' => 'daily', 'time' => '18:00']);
    $next = $schedule->nextOccurrence(new DateTime('2026-03-04 07:00:00', new DateTimeZone(Craft::$app->getTimeZone())));
    $local = $next->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

    return $local->format('Y-m-d H:i') === '2026-03-04 18:00' ? true : $local->format('c');
});

check('a weekly schedule finds the next matching weekday', function() {
    // 2026-03-04 is a Wednesday; the next Monday is the 9th.
    $schedule = Schedule::fromArray(['frequency' => 'weekly', 'weekday' => 1, 'time' => '09:00']);
    $next = $schedule->nextOccurrence(new DateTime('2026-03-04 12:00:00', new DateTimeZone(Craft::$app->getTimeZone())));
    $local = $next->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

    return $local->format('Y-m-d H:i') === '2026-03-09 09:00' ? true : $local->format('c');
});

check('day 31 of a 30-day month clamps rather than overflowing into the next', function() {
    $schedule = Schedule::fromArray(['frequency' => 'monthly', 'monthday' => 31, 'time' => '00:30']);
    $next = $schedule->nextOccurrence(new DateTime('2026-04-05 12:00:00', new DateTimeZone(Craft::$app->getTimeZone())));
    $local = $next->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

    return $local->format('Y-m-d') === '2026-04-30' ? true : $local->format('c');
});

check('the next occurrence is always strictly in the future', function() {
    $now = new DateTime('2026-03-04 06:00:00', new DateTimeZone(Craft::$app->getTimeZone()));
    $schedule = Schedule::fromArray(['frequency' => 'daily', 'time' => '06:00']);
    $next = $schedule->nextOccurrence($now);

    return $next > $now ? true : 'returned ' . $next->format('c');
});

check('an unscheduled report has no next occurrence', function() {
    return Schedule::fromArray(['frequency' => 'never'])->nextOccurrence() === null;
});

section('Delivery');

check('recipients are split, lower-cased for uniqueness and validated', function() {
    $delivery = Delivery::fromArray(['when' => 'always', 'recipients' => "a@example.com, A@example.com\nnope\nb@example.com"]);

    return $delivery->recipients === ['a@example.com', 'b@example.com'] ? true : Json::encode($delivery->recipients);
});

check('delivery is off without recipients even when it says "always"', function() {
    return Delivery::fromArray(['when' => 'always'])->getIsEnabled() === false;
});

check('failure-only delivery sends on failure and not on success', function() {
    $delivery = Delivery::fromArray(['when' => 'failure', 'recipients' => 'a@example.com']);

    return $delivery->shouldSendFor(false) === true && $delivery->shouldSendFor(true) === false;
});

check('an environment variable is stored as written and resolved only when sending', function() {
    putenv('REPORTR_TEST_RECIPIENTS=ops@example.com, finance@example.com');
    $_SERVER['REPORTR_TEST_RECIPIENTS'] = 'ops@example.com, finance@example.com';

    try {
        $delivery = Delivery::fromArray(['when' => 'always', 'recipients' => "\$REPORTR_TEST_RECIPIENTS\nc@example.com"]);

        return $delivery->recipients === ['$REPORTR_TEST_RECIPIENTS', 'c@example.com']
            && $delivery->getResolvedRecipients() === ['ops@example.com', 'finance@example.com', 'c@example.com']
            ? true
            : Json::encode([$delivery->recipients, $delivery->getResolvedRecipients()]);
    } finally {
        putenv('REPORTR_TEST_RECIPIENTS');
        unset($_SERVER['REPORTR_TEST_RECIPIENTS']);
    }
});

section('Paths and columns');

check('a stored path that steps out with .. resolves to nothing', function() use ($plugin) {
    $run = new Run(['path' => '../../config/db.php']);

    return $plugin->storage->localPath($run) === null && $plugin->storage->exists($run) === false;
});

check('a keyed row — a Table field’s — is read by column, across every row', function() use ($plugin) {
    $walk = new ReflectionMethod($plugin->queries, 'walk');
    $rows = [['amount' => 5, 'name' => 'a'], ['amount' => 7, 'name' => 'b']];

    return $walk->invoke($plugin->queries, $rows, 'amount') === [5, 7] ? true : Json::encode($walk->invoke($plugin->queries, $rows, 'amount'));
});

check('a reserved parameter name is refused, any other kept', function() {
    return Parameter::isReservedName('orderBy') && Parameter::isReservedName('where') && !Parameter::isReservedName('since');
});

check('instructions are shown as text, not markdown or HTML', function() {
    $param = Parameter::fromArray(['name' => 'x', 'instructions' => '<b>hi</b> [a](javascript:1)']);
    $html = \craft\helpers\Cp::parseMarkdown((string)$param->getSafeInstructions());

    return !str_contains($html, '<b>') && !str_contains($html, 'href=') && str_contains($html, 'javascript:1') ? true : $html;
});

section('Format options');

check('a named delimiter becomes the character', function() {
    return FormatOptions::fromArray(['delimiter' => 'tab'])->delimiter === "\t";
});

check('a multi-character delimiter is trimmed to one, because fputcsv throws otherwise', function() {
    return strlen(FormatOptions::fromArray(['delimiter' => '||'])->delimiter) === 1;
});

check('an XML name a parser would reject is repaired', function() {
    return FormatOptions::sanitizeXmlName('Total (£)') === 'Total'
        && FormatOptions::sanitizeXmlName('2026') === 'value'
        && FormatOptions::sanitizeXmlName('xmlThing') === 'value';
});

check('a sheet name is trimmed to what Excel accepts', function() {
    $name = FormatOptions::sanitizeSheetName('A [very] long: sheet/name that goes well past thirty-one characters');

    return mb_strlen($name) === 31 && !str_contains($name, '[') ? true : $name;
});

section('Writers');

$writerRows = [
    ['Widget', '12', 'yes'],
    ['Grüße, "quoted"', '3.5', 'no'],
    ['=1+1', '0117', 'maybe'],
];

check('CSV writes a header row and quotes what needs quoting', function() use ($scratch, $writerRows) {
    $path = $scratch . '/out.csv';
    $writer = new DelimitedWriter($path, FormatOptions::fromArray(['bom' => false]));
    $writer->open(['Name', 'Count', 'Flag']);

    foreach ($writerRows as $row) {
        $writer->writeRow($row);
    }

    $writer->close();
    $csv = file_get_contents($path);

    return str_starts_with($csv, "Name,Count,Flag\n")
        && str_contains($csv, '"Grüße, ""quoted"""')
            ? true
            : var_export($csv, true);
});

check('a leading = is neutralised so opening the file does not run a formula', function() use ($scratch, $writerRows) {
    $path = $scratch . '/formula.csv';
    $writer = new DelimitedWriter($path, FormatOptions::fromArray(['bom' => false]));
    $writer->open(['Name']);
    $writer->writeRow(['=cmd|calc']);
    $writer->close();

    return str_contains(file_get_contents($path), "'=cmd|calc");
});

check('a negative number is not mistaken for a formula', function() use ($scratch) {
    $path = $scratch . '/negative.csv';
    $writer = new DelimitedWriter($path, FormatOptions::fromArray(['bom' => false]));
    $writer->open(['Amount']);
    $writer->writeRow(['-42.50']);
    $writer->close();

    $lines = array_values(array_filter(explode("\n", file_get_contents($path))));

    return end($lines) === '-42.50' ? true : var_export($lines, true);
});

check('the byte-order mark is written when asked for', function() use ($scratch) {
    $path = $scratch . '/bom.csv';
    $writer = new DelimitedWriter($path, FormatOptions::fromArray(['bom' => true]));
    $writer->open(['Name']);
    $writer->writeRow(['x']);
    $writer->close();

    return str_starts_with(file_get_contents($path), "\xEF\xBB\xBF");
});

check('JSON is a valid array of objects keyed by the headings', function() use ($scratch, $writerRows) {
    $path = $scratch . '/out.json';
    $writer = new JsonWriter($path, new FormatOptions());
    $writer->open(['Name', 'Count', 'Flag']);

    foreach ($writerRows as $row) {
        $writer->writeRow($row);
    }

    $writer->close();
    $decoded = json_decode(file_get_contents($path), true);

    return is_array($decoded)
        && count($decoded) === 3
        && ($decoded[0]['Name'] ?? null) === 'Widget'
            ? true
            : var_export($decoded, true);
});

check('NDJSON is one decodable object per line', function() use ($scratch, $writerRows) {
    $path = $scratch . '/out.ndjson';
    $writer = new NdjsonWriter($path, new FormatOptions());
    $writer->open(['Name', 'Count', 'Flag']);

    foreach ($writerRows as $row) {
        $writer->writeRow($row);
    }

    $writer->close();
    $lines = array_values(array_filter(explode("\n", file_get_contents($path))));

    if (count($lines) !== 3) {
        return count($lines) . ' lines';
    }

    foreach ($lines as $line) {
        if (!is_array(json_decode($line, true))) {
            return 'undecodable line: ' . $line;
        }
    }

    return true;
});

check('XML parses, and a heading a parser would reject does not break it', function() use ($scratch, $writerRows) {
    $path = $scratch . '/out.xml';
    $writer = new XmlWriter($path, new FormatOptions());
    $writer->open(['Total (£)', 'Count', 'Flag']);

    foreach ($writerRows as $row) {
        $writer->writeRow($row);
    }

    $writer->close();

    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string(file_get_contents($path));
    libxml_use_internal_errors($previous);

    return $document !== false && count($document->row) === 3 ? true : 'did not parse';
});

check('XML strips the control characters it cannot represent', function() use ($scratch) {
    $path = $scratch . '/control.xml';
    $writer = new XmlWriter($path, new FormatOptions());
    $writer->open(['Name']);
    $writer->writeRow(["bad\x0Bvalue"]);
    $writer->close();

    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string(file_get_contents($path));
    libxml_use_internal_errors($previous);

    return $document !== false && (string)$document->row[0]->Name === 'badvalue';
});

check('HTML is a table with an escaped cell', function() use ($scratch) {
    $path = $scratch . '/out.html';
    $writer = new HtmlWriter($path, new FormatOptions());
    $writer->open(['Name']);
    $writer->writeRow(['<script>alert(1)</script>']);
    $writer->close();

    $html = file_get_contents($path);

    return str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>');
});

check('XLSX is a zip with the parts Excel requires', function() use ($scratch, $writerRows) {
    if (!XlsxWriter::isSupported()) {
        return 'ext-zip is not installed in this container';
    }

    $path = $scratch . '/out.xlsx';
    $writer = new XlsxWriter($path, FormatOptions::fromArray(['sheetName' => 'Checks']));
    $writer->open(['Name', 'Count', 'Flag']);

    foreach ($writerRows as $row) {
        $writer->writeRow($row);
    }

    $writer->close();

    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        return 'not a readable zip';
    }

    $wanted = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'];

    foreach ($wanted as $part) {
        if ($zip->locateName($part) === false) {
            $zip->close();

            return "missing {$part}";
        }
    }

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string($sheet);
    libxml_use_internal_errors($previous);

    return $document !== false && str_contains($sheet, 'Widget') ? true : 'sheet did not parse';
});

check('XLSX leaves a leading zero alone rather than turning a postcode into a number', function() use ($scratch) {
    if (!XlsxWriter::isSupported()) {
        return 'ext-zip is not installed in this container';
    }

    $path = $scratch . '/zeros.xlsx';
    $writer = new XlsxWriter($path, new FormatOptions());
    $writer->open(['Code']);
    $writer->writeRow(['0117']);
    $writer->close();

    $zip = new ZipArchive();
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    return str_contains($sheet, 't="inlineStr"') && str_contains($sheet, '0117');
});

check('the spreadsheet column names run A, Z, AA, AB', function() {
    return XlsxWriter::columnName(0) === 'A'
        && XlsxWriter::columnName(25) === 'Z'
        && XlsxWriter::columnName(26) === 'AA'
        && XlsxWriter::columnName(27) === 'AB';
});

check('an element is stringified by its title, not exploded into its attributes', function() use ($scratch) {
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries on this site to test with';
    }

    $path = $scratch . '/element.csv';
    $writer = new DelimitedWriter($path, FormatOptions::fromArray(['bom' => false]));
    $writer->open(['Entry']);
    $writer->writeRow([$entry]);
    $writer->close();

    $csv = file_get_contents($path);

    return str_contains($csv, (string)$entry->title) && !str_contains($csv, 'siteSettingsId')
        ? true
        : var_export($csv, true);
});

section('Filenames');

check('the default filename carries the handle and a timestamp', function() use ($plugin, $makeReport) {
    $report = $makeReport(['handle' => 'check-filenames', 'title' => 'Check filenames']);
    $name = $plugin->reports->buildFilename($report, 'csv', [], new DateTime('2026-08-27 14:05:06'));

    return $name === 'check-filenames-20260827-140506.csv' ? true : $name;
});

check('tokens including a parameter are interpolated', function() use ($plugin, $makeReport) {
    $report = $makeReport(['handle' => 'check-tokens', 'title' => 'Check tokens', 'filenameFormat' => 'orders-{Y}-{m}-{param:region}']);
    $name = $plugin->reports->buildFilename($report, 'xlsx', ['region' => 'North West'], new DateTime('2026-08-27'));

    return $name === 'orders-2026-08-north-west.xlsx' ? true : $name;
});

check('a filename cannot escape its folder', function() use ($plugin, $makeReport) {
    $report = $makeReport(['handle' => 'check-escape', 'title' => 'Check escape', 'filenameFormat' => '../../{param:name}']);
    $name = $plugin->reports->buildFilename($report, 'csv', ['name' => '../../../etc/passwd']);

    return !str_contains($name, '..') && !str_contains($name, '/') ? true : $name;
});

check('an unknown token is left visible rather than blanked', function() use ($plugin, $makeReport) {
    $report = $makeReport(['handle' => 'check-unknown', 'title' => 'Check unknown', 'filenameFormat' => 'x-{nope}']);

    return $plugin->reports->buildFilename($report, 'csv') === 'x-{nope}.csv';
});

section('Report elements');

check('a report saves and reads back with its JSON columns intact', function() use ($makeReport) {
    $report = $makeReport(['handle' => 'check-roundtrip', 'title' => 'Check round trip']);
    $report->setParams([['name' => 'word', 'type' => 'text', 'label' => 'Word']]);
    $report->setSchedule(Schedule::fromArray(['frequency' => 'daily', 'time' => '05:30']));
    $report->setDelivery(Delivery::fromArray(['when' => 'always', 'recipients' => 'a@example.com']));
    Craft::$app->getElements()->saveElement($report);

    // Read from the database, not from Craft's identity map: a column with no property or setter
    // only fails on the way *back in*, which is exactly the path a re-read after save skips.
    Craft::$app->getElements()->invalidateCachesForElement($report);
    /** @var Report|null $fresh */
    $fresh = Report::find()->id($report->id)->status(null)->one();

    if ($fresh === null) {
        return 'could not read the report back';
    }

    return count($fresh->getParams()) === 1
        && $fresh->getSchedule()->time === '05:30'
        && $fresh->getDelivery()->recipients === ['a@example.com']
            ? true
            : 'params=' . count($fresh->getParams()) . ' time=' . $fresh->getSchedule()->time;
});

check('saving a scheduled report stores its next run', function() use ($makeReport) {
    $report = $makeReport(['handle' => 'check-nextrun', 'title' => 'Check next run']);
    $report->setSchedule(Schedule::fromArray(['frequency' => 'daily', 'time' => '05:30']));
    Craft::$app->getElements()->saveElement($report);

    return $report->nextRunAt instanceof DateTime;
});

check('disabling a report clears its next run', function() use ($makeReport) {
    $report = $makeReport(['handle' => 'check-disabled', 'title' => 'Check disabled']);
    $report->setSchedule(Schedule::fromArray(['frequency' => 'daily']));
    Craft::$app->getElements()->saveElement($report);
    $report->enabled = false;
    Craft::$app->getElements()->saveElement($report);

    return $report->nextRunAt === null;
});

check('a duplicate handle is refused', function() use ($makeReport) {
    $makeReport(['handle' => 'check-unique', 'title' => 'Check unique']);

    $second = new Report();
    $second->title = 'Another';
    $second->handle = 'check-unique';

    return Craft::$app->getElements()->saveElement($second) === false
        && $second->hasErrors('handle');
});

check('a trashed report gives its handle back', function() use ($makeReport, $track) {
    $report = $makeReport(['handle' => 'check-parked', 'title' => 'Check parked']);
    Craft::$app->getElements()->deleteElement($report);

    $replacement = new Report();
    $replacement->title = 'Replacement';
    $replacement->handle = 'check-parked';
    $replacement->template = '_reportr-checks/basic';
    $saved = Craft::$app->getElements()->saveElement($replacement);

    if ($saved) {
        $track($replacement);
    }

    return $saved ? true : Json::encode($replacement->getErrors());
});

check('a report with no template says why it cannot run', function() {
    $report = new Report();
    $report->type = Report::TYPE_BASIC;

    return $report->getBlockingProblem() !== null;
});

check('an advanced report naming a function that does not exist says so', function() {
    $report = new Report();
    $report->type = Report::TYPE_ADVANCED;
    $report->template = '_reportr-checks/advanced';
    $report->formatFunction = 'noSuchFunction';

    $problem = $report->getBlockingProblem();

    return $problem !== null && str_contains($problem, 'noSuchFunction') ? true : var_export($problem, true);
});

section('Running');

check('a basic report writes a CSV with a header and rows', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-run-basic', 'title' => 'Check run basic']);
    $run = $plugin->runner->run($report, [], Run::INITIATOR_CONSOLE);
    $track($run);

    if (!$run->getIsFinished()) {
        return 'run failed: ' . $run->statusMessage;
    }

    $path = $run->filePath();

    if ($path === null || !is_file($path)) {
        return 'no file at ' . var_export($path, true);
    }

    $csv = file_get_contents($path);

    return str_contains($csv, 'ID,Title') ? true : var_export(substr($csv, 0, 200), true);
});

check('the header row is not counted as data', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-run-count', 'title' => 'Check run count']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    $expected = min(5, (int)Entry::find()->count());

    return $run->totalRows === $expected ? true : "counted {$run->totalRows}, expected {$expected}";
});

check('a template can import a macro from beside it', function() use ($plugin, $makeReport, $track) {
    // Lab Reports issue #4. Its runner set the templates *path*, which left Twig's loader in the
    // control-panel namespace, so an import inside a report template threw "template not found".
    $report = $makeReport(['handle' => 'check-run-import', 'title' => 'Check import', 'template' => '_reportr-checks/imports']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    if (!$run->getIsFinished()) {
        return 'run failed: ' . $run->statusMessage;
    }

    return str_contains((string)file_get_contents((string)$run->filePath()), 'SHOUTED');
});

check('parameters reach the template already typed', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-run-params', 'title' => 'Check run params', 'template' => '_reportr-checks/params']);
    $report->setParams([
        ['name' => 'word', 'type' => 'text'],
        ['name' => 'count', 'type' => 'number'],
    ]);
    Craft::$app->getElements()->saveElement($report);

    $run = $track($plugin->runner->run($report, ['word' => 'hello', 'count' => '7'], Run::INITIATOR_CONSOLE));

    if (!$run->getIsFinished()) {
        return 'run failed: ' . $run->statusMessage;
    }

    return str_contains((string)file_get_contents((string)$run->filePath()), 'hello,7');
});

check('the answers are stored on the run', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-run-stored', 'title' => 'Check stored params', 'template' => '_reportr-checks/params']);
    $report->setParams([['name' => 'word', 'type' => 'text']]);
    Craft::$app->getElements()->saveElement($report);

    $run = $track($plugin->runner->run($report, ['word' => 'stored'], Run::INITIATOR_CONSOLE));

    return ($run->getParams()['word'] ?? null) === 'stored' ? true : Json::encode($run->getParams());
});

check('an advanced report walks a query through its formatting function', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport([
        'handle' => 'check-run-advanced',
        'title' => 'Check advanced',
        'type' => Report::TYPE_ADVANCED,
        'template' => '_reportr-checks/advanced',
        'formatFunction' => 'reportrCheckDump',
    ]);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    if (!$run->getIsFinished()) {
        return 'run failed: ' . $run->statusMessage;
    }

    $expected = min(4, (int)Entry::find()->count());

    return $run->totalRows === $expected ? true : "counted {$run->totalRows}, expected {$expected}";
});

check('a formatting function returning the wrong shape fails the run with a readable reason', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport([
        'handle' => 'check-run-badfn',
        'title' => 'Check bad function',
        'type' => Report::TYPE_ADVANCED,
        'template' => '_reportr-checks/advanced',
        'formatFunction' => 'reportrCheckBadReturn',
    ]);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    return $run->runStatus === Run::STATUS_ERROR
        && str_contains((string)$run->statusMessage, 'must return an array')
            ? true
            : $run->runStatus . ': ' . $run->statusMessage;
});

check('a template that writes nothing fails with the reason, not a missing file', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-run-silent', 'title' => 'Check silent', 'template' => '_reportr-checks/silent']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    return $run->runStatus === Run::STATUS_ERROR
        && str_contains((string)$run->statusMessage, 'never wrote any rows')
            ? true
            : $run->runStatus . ': ' . $run->statusMessage;
});

check('a missing template fails the run rather than the request', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-run-missing', 'title' => 'Check missing', 'template' => '_reportr-checks/nope']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    return $run->runStatus === Run::STATUS_ERROR && $run->statusMessage !== null;
});

check('every format produces a file the run can find again', function() use ($plugin, $makeReport, $track) {
    foreach (array_keys($plugin->formats->all()) as $format) {
        if (!$plugin->formats->isSupported($format)) {
            continue;
        }

        $report = $makeReport([
            'handle' => 'check-format-' . $format,
            'title' => 'Check ' . $format,
            'format' => $format,
        ]);

        $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

        if (!$run->getIsFinished()) {
            return "{$format}: " . $run->statusMessage;
        }

        if (!$run->fileExists()) {
            return "{$format}: the file is missing afterwards";
        }

        if (!str_ends_with((string)$run->filename, '.' . $plugin->formats->extension($format))) {
            return "{$format}: wrong extension on {$run->filename}";
        }
    }

    return true;
});

check('a query walked in small batches produces every row exactly once', function() use ($plugin, $makeReport, $track) {
    $total = (int)Entry::find()->count();

    if ($total < 5) {
        return 'not enough entries on this site to test batching';
    }

    // Batches of two over an advanced report: the paging, the per-batch clone and the
    // "did the last batch fill?" test all have to be right or this comes back short, long, or
    // with duplicates.
    $report = $makeReport([
        'handle' => 'check-batching',
        'title' => 'Check batching',
        'type' => Report::TYPE_ADVANCED,
        'template' => '_reportr-checks/advanced',
        'formatFunction' => 'reportrCheckDump',
        'batchSize' => 2,
    ]);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    if (!$run->getIsFinished()) {
        return 'run failed: ' . $run->statusMessage;
    }

    $expected = min(4, $total);

    if ($run->totalRows !== $expected) {
        return "wrote {$run->totalRows} rows, expected {$expected}";
    }

    // And no duplicates: a paging bug that re-reads a batch would still count correctly if the
    // loop terminated early, so the IDs are checked as well as the total.
    $lines = array_values(array_filter(explode("\n", (string)file_get_contents((string)$run->filePath()))));
    array_shift($lines);
    $ids = array_map(static fn(string $line) => explode(',', $line)[0], $lines);

    return count($ids) === count(array_unique($ids)) ? true : 'duplicate rows: ' . implode(',', $ids);
});

check('a limit on the query is respected even when the batch is bigger', function() use ($plugin, $makeReport, $track) {
    if ((int)Entry::find()->count() < 5) {
        return 'not enough entries on this site to test with';
    }

    $report = $makeReport([
        'handle' => 'check-limit',
        'title' => 'Check limit',
        'type' => Report::TYPE_QUERY,
        'template' => null,
        'batchSize' => 500,
    ]);

    $report->setQuerySpec(QuerySpec::fromArray([
        'elementType' => Entry::class,
        'limit' => 3,
        'columns' => [['key' => 'attr:id', 'heading' => 'ID']],
    ]));
    Craft::$app->getElements()->saveElement($report);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    return $run->totalRows === 3 ? true : "wrote {$run->totalRows} rows, expected 3";
});

check('a query report walked one row at a time still gets them all', function() use ($plugin, $makeReport, $track) {
    $total = min(5, (int)Entry::find()->count());

    if ($total < 3) {
        return 'not enough entries on this site to test with';
    }

    $report = $makeReport([
        'handle' => 'check-batch-one',
        'title' => 'Check batch of one',
        'type' => Report::TYPE_QUERY,
        'template' => null,
        'batchSize' => 1,
    ]);

    $report->setQuerySpec(QuerySpec::fromArray([
        'elementType' => Entry::class,
        'limit' => $total,
        'columns' => [['key' => 'attr:id', 'heading' => 'ID']],
    ]));
    Craft::$app->getElements()->saveElement($report);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    return $run->totalRows === $total ? true : "wrote {$run->totalRows} rows, expected {$total}";
});

check('a preview writes nothing and records no run', function() use ($plugin, $makeReport) {
    $report = $makeReport(['handle' => 'check-preview', 'title' => 'Check preview']);
    $before = (int)Run::find()->status(null)->count();
    $result = $plugin->runner->preview($report, [], 2);
    $after = (int)Run::find()->status(null)->count();

    if (!$result->getSucceeded()) {
        return 'preview failed: ' . $result->error;
    }

    return $after === $before && $result->headings === ['ID', 'Title'] && count($result->rows) <= 2
        ? true
        : "before={$before} after={$after} rows=" . count($result->rows);
});

check('a preview stops at its row cap', function() use ($plugin, $makeReport) {
    if ((int)Entry::find()->count() < 3) {
        return 'not enough entries on this site to test the cap';
    }

    $result = $plugin->runner->preview($makeReport(['handle' => 'check-preview-cap', 'title' => 'Check cap']), [], 2);

    return count($result->rows) === 2 && $result->truncated ? true : count($result->rows) . ' rows, truncated=' . var_export($result->truncated, true);
});

check('a --format override for one run is not written back to the report', function() use ($plugin, $makeReport, $track) {
    // `afterRun()` used to save the report element to bump its counter, which persisted whatever
    // else was on it in memory — so one console run with `--format=csv` permanently changed what
    // the report produced for everybody else.
    $report = $makeReport(['handle' => 'check-format-override', 'title' => 'Check override', 'format' => 'json']);
    $report->format = 'csv';
    $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    /** @var Report $fresh */
    $fresh = Report::find()->id($report->id)->status(null)->one();

    return $fresh->format === 'json' ? true : 'the report is now ' . $fresh->format;
});

check('a run bumps the report’s counter and last-run stamp', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-counter', 'title' => 'Check counter']);
    $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    /** @var Report $fresh */
    $fresh = Report::find()->id($report->id)->status(null)->one();

    return $fresh->runCount === 1 && $fresh->lastRunAt !== null ? true : "runCount={$fresh->runCount}";
});

section('Query reports');

check('a query report resolves attributes and writes them', function() use ($plugin, $makeReport, $track) {
    if ((int)Entry::find()->count() === 0) {
        return 'no entries on this site to test with';
    }

    $report = $makeReport(['handle' => 'check-query', 'title' => 'Check query', 'type' => Report::TYPE_QUERY, 'template' => null]);
    $report->setQuerySpec(QuerySpec::fromArray([
        'elementType' => Entry::class,
        'source' => '*',
        'limit' => 3,
        'columns' => [
            ['key' => 'attr:id', 'heading' => 'ID'],
            ['key' => 'attr:title', 'heading' => 'Title'],
            ['key' => 'attr:dateCreated', 'heading' => 'Created', 'format' => 'date'],
        ],
    ]));
    Craft::$app->getElements()->saveElement($report);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    if (!$run->getIsFinished()) {
        return 'run failed: ' . $run->statusMessage;
    }

    $csv = file_get_contents((string)$run->filePath());

    return str_contains($csv, 'ID,Title,Created') && $run->totalRows === min(3, (int)Entry::find()->count())
        ? true
        : var_export(substr($csv, 0, 200), true);
});

check('a twig column is evaluated as an object template', function() use ($plugin) {
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries on this site to test with';
    }

    $column = Column::fromArray(['key' => 'twig:{{ object.title|upper }}', 'heading' => 'Shouted']);
    $value = $plugin->queries->resolve($entry, $column);

    return $value === mb_strtoupper((string)$entry->title) ? true : var_export($value, true);
});

check('a column key with no prefix is read as an attribute', function() {
    return Column::fromArray(['key' => 'title'])->parse() === ['attr', 'title'];
});

check('a heading is derived from the key when one is not given', function() {
    return Column::fromArray(['key' => 'attr:dateCreated'])->heading === 'Date Created'
        ? true
        : Column::fromArray(['key' => 'attr:dateCreated'])->heading;
});

check('a column pointing at a field the element does not have is blank, not fatal', function() use ($plugin) {
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries on this site to test with';
    }

    $column = Column::fromArray(['key' => 'field:noSuchFieldAnywhere']);

    return $plugin->queries->resolve($entry, $column) === null;
});

check('a source that no longer exists fails loudly rather than reporting everything', function() use ($plugin) {
    $spec = QuerySpec::fromArray([
        'elementType' => Entry::class,
        'source' => 'section:00000000-0000-0000-0000-000000000000',
        'columns' => [['key' => 'attr:id']],
    ]);

    try {
        $plugin->queries->build($spec);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'no longer exists');
    }

    return 'no exception was thrown';
});

check('a section source builds with nobody signed in, as on a schedule', function() use ($plugin, $makeReport, $track) {
    if (Craft::$app->getUser()->getIdentity() !== null) {
        return 'this check needs to run with no signed-in user';
    }

    // The section with the most entries, so the comparison means something.
    $section = null;
    $expected = 0;

    foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
        $count = (int)Entry::find()->sectionId($candidate->id)->status(null)->count();

        if ($count > $expected) {
            [$section, $expected] = [$candidate, $count];
        }
    }

    if ($section === null) {
        return 'no entries on this site to test with';
    }

    $report = $makeReport(['handle' => 'check-unattended', 'title' => 'Check unattended', 'type' => Report::TYPE_QUERY, 'template' => null]);
    $report->setQuerySpec(QuerySpec::fromArray([
        'elementType' => Entry::class,
        'source' => 'section:' . $section->uid,
        'status' => '',
        'columns' => [['key' => 'attr:id', 'heading' => 'ID']],
    ]));
    Craft::$app->getElements()->saveElement($report);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_SCHEDULE));

    if (!$run->getIsFinished()) {
        return 'run failed: ' . $run->statusMessage;
    }

    return $run->totalRows > 0 ? true : "0 rows, expected up to {$expected}";
});

check('a duplicated report gets its own handle and starts disabled, unscheduled and unimported', function() use ($makeReport, $track) {
    $report = $makeReport(['handle' => 'check-duplicate', 'title' => 'Check duplicate', 'legacyId' => 987654]);
    $copy = $track(Craft::$app->getElements()->duplicateElement($report));

    $problems = array_filter([
        $copy->handle === $report->handle ? 'same handle' : null,
        $copy->enabled ? 'enabled' : null,
        $copy->legacyId !== null ? 'kept legacyId' : null,
        $copy->runCount !== 0 ? 'kept runCount' : null,
    ]);

    return $problems === [] ? true : implode(', ', $problems);
});

check('a required parameter nobody answered fails the run with the reason', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport([
        'handle' => 'check-required',
        'title' => 'Check required',
        'params' => [['name' => 'since', 'label' => 'Since', 'type' => 'date', 'required' => true]],
    ]);

    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_SCHEDULE));

    return !$run->getIsFinished() && $run->statusMessage !== null && $run->statusMessage !== ''
        ? true
        : 'status=' . $run->runStatus . ' message=' . var_export($run->statusMessage, true);
});

section('Storage');

check('a run knows which filesystem it was written to', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-storage', 'title' => 'Check storage']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    return $run->path !== null && $run->fileSize > 0 ? true : 'path=' . var_export($run->path, true);
});

check('a stream can be opened for a stored run', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-stream', 'title' => 'Check stream']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    $stream = $plugin->storage->stream($run);

    if (!is_resource($stream)) {
        return 'no stream';
    }

    $head = fread($stream, 16);
    fclose($stream);

    return $head !== '' && $head !== false;
});

check('hard-deleting a run removes its file', function() use ($plugin, $makeReport) {
    $report = $makeReport(['handle' => 'check-delete-file', 'title' => 'Check delete file']);
    $run = $plugin->runner->run($report, [], Run::INITIATOR_CONSOLE);
    $path = $run->filePath();

    Craft::$app->getElements()->deleteElement($run, true);

    return $path !== null && !is_file($path);
});

check('the storage folder is protected against being served', function() use ($plugin) {
    $folder = $plugin->storage->localFolder();

    return is_file($folder . '/.htaccess') && is_file($folder . '/index.html');
});

section('Retention');

check('keeping the last N runs deletes the rest', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-retention', 'title' => 'Check retention', 'retentionRuns' => 2]);

    for ($index = 0; $index < 3; $index++) {
        $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));
    }

    // The retention sweep runs after each build, so by now only the last two should be left.
    $remaining = (int)Run::find()->reportId($report->id)->status(null)->count();

    return $remaining === 2 ? true : "{$remaining} runs remain";
});

check('deleting a report keeps its runs, with the title they were for', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-orphan', 'title' => 'Check orphan']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    Craft::$app->getElements()->deleteElement($report, true);

    /** @var Run|null $fresh */
    $fresh = Run::find()->id($run->id)->status(null)->one();

    if ($fresh === null) {
        return 'the run was deleted with the report';
    }

    return $fresh->reportId === null && $fresh->reportTitle === 'Check orphan'
        ? true
        : 'reportId=' . var_export($fresh->reportId, true) . ' title=' . var_export($fresh->reportTitle, true);
});

check('a run whose report is gone still renders its index row', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-orphan-html', 'title' => 'Check orphan html']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));
    Craft::$app->getElements()->deleteElement($report, true);

    /** @var Run $fresh */
    $fresh = Run::find()->id($run->id)->status(null)->one();

    // Lab Reports' getUiLabel() dereferenced the configured report without a null check, so this
    // was a fatal on the index the moment anybody tidied up a configuration.
    return is_string($fresh->getUiLabel()) && $fresh->getUiLabel() !== '';
});

section('Scheduling');

check('a due report is found by the scheduler', function() use ($plugin, $makeReport) {
    $report = $makeReport(['handle' => 'check-due', 'title' => 'Check due']);
    $report->setSchedule(Schedule::fromArray(['frequency' => 'daily', 'time' => '00:00']));
    Craft::$app->getElements()->saveElement($report);

    Craft::$app->getDb()->createCommand()
        ->update('{{%reportr_reports}}', ['nextRunAt' => \craft\helpers\Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $report->id])
        ->execute();

    $due = $plugin->schedules->due();

    foreach ($due as $candidate) {
        if ($candidate->id === $report->id) {
            return true;
        }
    }

    return count($due) . ' due, none of them this one';
});

check('a claimed schedule cannot be claimed twice', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-claim', 'title' => 'Check claim']);
    $report->setSchedule(Schedule::fromArray(['frequency' => 'daily', 'time' => '00:00']));
    Craft::$app->getElements()->saveElement($report);

    Craft::$app->getDb()->createCommand()
        ->update('{{%reportr_reports}}', ['nextRunAt' => \craft\helpers\Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $report->id])
        ->execute();

    $first = $plugin->schedules->runDue(null, true);

    foreach ($first as $run) {
        $track($run);
    }

    $second = $plugin->schedules->runDue(null, true);

    foreach ($second as $run) {
        $track($run);
    }

    $ranTwice = false;

    foreach ($second as $run) {
        if ($run->reportId === $report->id) {
            $ranTwice = true;
        }
    }

    return !$ranTwice ? true : 'the same report fired twice';
});

check('a stalled run is swept up rather than spinning for ever', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-stalled', 'title' => 'Check stalled']);

    $run = new Run();
    $run->setReport($report);
    $run->runStatus = Run::STATUS_RUNNING;
    Craft::$app->getElements()->saveElement($run, false, false, false);
    $track($run);

    Craft::$app->getDb()->createCommand()
        ->update('{{%elements}}', ['dateCreated' => \craft\helpers\Db::prepareDateForDb(new DateTime('-30 days'))], ['id' => $run->id])
        ->execute();
    Craft::$app->getDb()->createCommand()
        ->update('{{%reportr_runs}}', ['dateCreated' => \craft\helpers\Db::prepareDateForDb(new DateTime('-30 days'))], ['id' => $run->id])
        ->execute();

    $plugin->runner->failStalledRuns();

    /** @var Run $fresh */
    $fresh = Run::find()->id($run->id)->status(null)->one();

    return $fresh->runStatus === Run::STATUS_ERROR ? true : $fresh->runStatus;
});

section('Element indexes');

check('every source, sort option and table column of both element types survives being asked for', function() {
    // The whole class of bug this catches: an element-query param typed more narrowly than the
    // source's criteria value takes the entire index down, for the one source nobody clicked.
    foreach ([Report::class, Run::class] as $elementType) {
        /** @var class-string<craft\base\ElementInterface> $elementType */
        foreach (Craft::$app->getElementSources()->getSources($elementType, 'index') as $source) {
            if (!isset($source['key'])) {
                continue;
            }

            $query = $elementType::find();

            if (!empty($source['criteria'])) {
                Craft::configure($query, $source['criteria']);
            }

            try {
                $query->limit(2)->all();
            } catch (Throwable $e) {
                return "{$elementType} source {$source['key']}: " . $e->getMessage();
            }
        }

        // `sortOptions()` is a mixed bag: `attribute => label` pairs *and* list entries that are
        // hashes with a closure for `orderBy`. Only the first kind can be sorted by here, and for
        // those the attribute is the key — the value is the label, and ordering by a label is a
        // SQL error about an ambiguous column that has nothing to do with the plugin.
        foreach ($elementType::sortOptions() as $key => $option) {
            $sort = is_string($key) ? $key : null;

            if ($sort === null) {
                continue;
            }

            try {
                $elementType::find()->status(null)->orderBy([$sort => SORT_ASC])->limit(2)->all();
            } catch (Throwable $e) {
                return "{$elementType} sort {$sort}: " . $e->getMessage();
            }
        }
    }

    return true;
});

check('every table attribute renders for a real run', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-attrs', 'title' => 'Check attributes']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    // `getAttributeHtml()`, not the protected `attributeHtml()`: the public one fires the event
    // other plugins use to add their own columns to every element type, and calling the
    // protected one directly reports their columns as this plugin's failures.
    foreach ([[$run, Run::class], [$report, Report::class]] as [$element, $class]) {
        foreach (array_keys($class::tableAttributes()) as $attribute) {
            try {
                $element->getAttributeHtml($attribute);
            } catch (Throwable $e) {
                return "{$class} column {$attribute}: " . $e->getMessage();
            }
        }
    }

    return true;
});

check('a run’s status filters on its own column, not the element’s enabled flag', function() use ($plugin, $makeReport, $track) {
    $report = $makeReport(['handle' => 'check-status-filter', 'title' => 'Check status filter']);
    $run = $track($plugin->runner->run($report, [], Run::INITIATOR_CONSOLE));

    $found = Run::find()->status(Run::STATUS_FINISHED)->id($run->id)->exists();
    $notFound = Run::find()->status(Run::STATUS_ERROR)->id($run->id)->exists();

    return $found && !$notFound ? true : "finished={$found} error={$notFound}";
});

section('Twig');

check('the filters are registered under prefixed names', function() {
    $twig = Craft::$app->getView()->getTwig();

    return $twig->getFilter('reportCell') !== null
        && $twig->getFilter('reportColumn') !== null
        && $twig->getFilter('reportValues') !== null;
});

check('reportCell flattens a list without exploding an element', function() {
    $extension = new \justinholtweb\reportr\twig\Extension();
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries on this site to test with';
    }

    return $extension->cell([$entry, 'x']) === $entry->title . ', x'
        ? true
        : var_export($extension->cell([$entry, 'x']), true);
});

check('craft.reportr is registered with the old method names intact', function() {
    $variable = new \justinholtweb\reportr\twig\ReportrVariable();

    foreach (['reports', 'runs', 'configuredReports', 'generatedReports', 'report', 'run', 'formatFunctionNames', 'formatFunctionOptions', 'formats', 'queue'] as $method) {
        if (!method_exists($variable, $method)) {
            return "craft.reportr has no {$method}()";
        }
    }

    return $variable->configuredReports() instanceof \justinholtweb\reportr\elements\db\ReportQuery;
});

check('reportColumn pulls one key out of a list of rows', function() {
    $extension = new \justinholtweb\reportr\twig\Extension();

    return $extension->column([['amount' => 3], ['amount' => 5]], 'amount') === [3, 5];
});

section('Permissions and routes');

check('the permissions are registered', function() {
    $permissions = Craft::$app->getUserPermissions()->getAllPermissions();
    $flat = [];

    array_walk_recursive($permissions, static function($value, $key) use (&$flat) {
        $flat[] = (string)$key;
    });

    $registered = Json::encode($permissions);

    foreach ([Plugin::PERMISSION_VIEW_REPORTS, Plugin::PERMISSION_MANAGE_REPORTS, Plugin::PERMISSION_VIEW_RUNS, Plugin::PERMISSION_DOWNLOAD_RUNS] as $permission) {
        if (!str_contains($registered, $permission)) {
            return "missing {$permission}";
        }
    }

    return true;
});

check('every control-panel template compiles', function() {
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

    try {
        foreach ([
            'reportr/reports/_index',
            'reportr/reports/_edit',
            'reportr/reports/_run',
            'reportr/runs/_index',
            'reportr/runs/_detail',
            'reportr/runs/_status',
            'reportr/settings/_index',
            'reportr/import/_index',
            'reportr/_email/run',
        ] as $template) {
            $resolved = $view->resolveTemplate($template);

            if ($resolved === false) {
                return "missing template {$template}";
            }

            // Compiling catches a syntax error; rendering would need every variable each screen
            // expects, which is the controller's job and not this check's.
            $view->getTwig()->load($template . '.twig');
        }
    } catch (Throwable $e) {
        return $e->getMessage();
    } finally {
        $view->setTemplateMode($mode);
    }

    return true;
});

section('Importing from Lab Reports');

check('the importer reports nothing to do when the tables are absent', function() use ($plugin) {
    if (Craft::$app->getDb()->tableExists('{{%labreports_configured_reports}}')) {
        return 'this site already has Lab Reports tables, so the empty case cannot be tested here';
    }

    return $plugin->importer->isAvailable() === false;
});

check('a Lab Reports database imports its configurations and history', function() use ($plugin, $track, &$createdLegacyTables, &$createdLegacyElementIds) {
    $db = Craft::$app->getDb();

    if ($db->tableExists('{{%labreports_configured_reports}}')) {
        return 'this site already has Lab Reports tables; not overwriting them';
    }

    // Built here rather than mocked: the import is a set of joins against Craft's own elements
    // table, and a fake that skipped that would not be testing the thing that breaks.
    $db->createCommand()->createTable('{{%labreports_configured_reports}}', [
        'id' => 'integer NOT NULL',
        'reportType' => 'varchar(25)',
        'reportTitle' => 'varchar(255)',
        'reportDescription' => 'varchar(255)',
        'template' => 'varchar(255)',
        'formatFunction' => 'varchar(100)',
        'dateCreated' => 'datetime NOT NULL',
        'dateUpdated' => 'datetime NOT NULL',
        'uid' => 'char(36)',
    ])->execute();

    $db->createCommand()->createTable('{{%labreports_reports}}', [
        'id' => 'integer NOT NULL',
        'configuredReportId' => 'integer',
        'reportStatus' => 'varchar(25)',
        'statusMessage' => 'text',
        'filename' => 'varchar(255)',
        'totalRows' => 'integer',
        'userId' => 'integer',
        'dateGenerated' => 'datetime',
        'dateCreated' => 'datetime NOT NULL',
        'dateUpdated' => 'datetime NOT NULL',
        'uid' => 'char(36)',
    ])->execute();

    $createdLegacyTables = true;

    $now = \craft\helpers\Db::prepareDateForDb(new DateTime());
    $elements = Craft::$app->getElements();

    $makeElement = static function(string $type) use ($db, $now, &$createdLegacyElementIds): int {
        $db->createCommand()->insert('{{%elements}}', [
            'type' => $type,
            'enabled' => true,
            'archived' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        $id = (int)$db->getLastInsertID('{{%elements}}');
        $createdLegacyElementIds[] = $id;

        return $id;
    };

    $legacyReportId = $makeElement('Masuga\\LabReports\\elements\\ConfiguredReport');
    $db->createCommand()->insert('{{%labreports_configured_reports}}', [
        'id' => $legacyReportId,
        'reportType' => 'advanced',
        'reportTitle' => 'Legacy Books Export',
        'reportDescription' => 'Imported by the checks',
        'template' => '_reports/books',
        'formatFunction' => 'bookDump',
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();

    $legacyRunId = $makeElement('Masuga\\LabReports\\elements\\Report');
    $db->createCommand()->insert('{{%labreports_reports}}', [
        'id' => $legacyRunId,
        'configuredReportId' => $legacyReportId,
        'reportStatus' => 'in_progress',
        'filename' => 'legacy-books-export-20240101120000.csv',
        'totalRows' => 1234,
        'dateGenerated' => \craft\helpers\Db::prepareDateForDb(new DateTime('2024-01-01 12:00:00')),
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();

    $dry = $plugin->importer->import(true, true, false);

    if ($dry->reportsImported !== 1 || $dry->runsImported !== 1) {
        return 'the dry run counted ' . $dry->reportsImported . ' reports and ' . $dry->runsImported . ' runs';
    }

    if (Report::find()->status(null)->handle('legacy-books-export')->exists()) {
        return 'the dry run created something';
    }

    $summary = $plugin->importer->import(false, true, false);

    /** @var Report|null $imported */
    $imported = Report::find()->status(null)->legacyId($legacyReportId)->one();

    if ($imported === null) {
        return 'the report was not imported: ' . Json::encode($summary->warnings);
    }

    $track($imported);

    /** @var Run|null $importedRun */
    $importedRun = Run::find()->status(null)->legacyId($legacyRunId)->one();

    if ($importedRun === null) {
        return 'the run was not imported';
    }

    $track($importedRun);

    if ($imported->type !== Report::TYPE_ADVANCED || $imported->formatFunction !== 'bookDump') {
        return 'the report imported as ' . $imported->type . '/' . $imported->formatFunction;
    }

    if ($importedRun->totalRows !== 1234) {
        return 'the run imported with ' . $importedRun->totalRows . ' rows';
    }

    // An `in_progress` legacy run is one that died. Importing it as "still running" would leave
    // a spinner on the index for ever.
    if ($importedRun->runStatus !== Run::STATUS_ERROR) {
        return 'an in-progress legacy run imported as ' . $importedRun->runStatus;
    }

    if ($importedRun->dateFinished === null || $importedRun->dateFinished->format('Y') !== '2024') {
        return 'the run lost its original date';
    }

    // Running it again must be a no-op, because somebody will.
    $again = $plugin->importer->import(false, true, false);

    return $again->reportsImported === 0 && $again->reportsSkipped === 1
        ? true
        : 'a second import created ' . $again->reportsImported . ' more reports';
});

// ---------------------------------------------------------------------------

echo "\n";
echo $failed === 0
    ? "\033[32mAll {$passed} checks passed.\033[0m\n"
    : "\033[31m{$passed} passed, {$failed} failed.\033[0m\n";

exit($failed === 0 ? 0 : 1);
