<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

use Craft;
use craft\helpers\Html;

/**
 * A self-contained HTML table.
 *
 * The format for a report whose audience is a person rather than a program — it opens in a
 * browser on a phone, it survives being forwarded, and it needs no application installed. The CSS
 * is inline in a `<style>` block rather than linked, because the file is meant to be emailed and
 * a stylesheet URL would not survive the trip.
 */
class HtmlWriter extends BaseWriter
{
    private bool $wroteHead = false;

    public static function extension(): string
    {
        return 'html';
    }

    public static function mimeType(): string
    {
        return 'text/html';
    }

    public function open(array $headings = []): void
    {
        parent::open($headings);

        $title = Html::encode($this->options->sheetName);

        $this->write(<<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>$title</title>
        <style>
        :root { color-scheme: light dark; }
        body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 2rem; }
        table { border-collapse: collapse; width: 100%; }
        caption { text-align: left; font-weight: 600; padding-bottom: .75rem; }
        th, td { border: 1px solid rgba(128,128,128,.35); padding: .4rem .6rem; text-align: left; vertical-align: top; }
        th { background: rgba(128,128,128,.12); font-weight: 600; position: sticky; top: 0; }
        tbody tr:nth-child(even) { background: rgba(128,128,128,.06); }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        </style></head><body>
        <table><caption>$title</caption>
        HTML);

        if ($this->options->headers && $this->headings !== []) {
            $this->write("\n<thead><tr>");

            foreach ($this->headings as $heading) {
                $this->write('<th scope="col">' . Html::encode($heading) . '</th>');
            }

            $this->write("</tr></thead>\n");
        }

        $this->write('<tbody>');
        $this->wroteHead = true;
    }

    public function writeRow(array $row): bool
    {
        $values = $this->stringifyRow($row);

        $html = "\n<tr>";

        foreach ($values as $value) {
            $class = is_numeric($value) ? ' class="num"' : '';
            $html .= '<td' . $class . '>' . nl2br(Html::encode($value)) . '</td>';
        }

        $written = $this->write($html . '</tr>');

        if ($written) {
            $this->rowsWritten++;
        }

        return $written;
    }

    public function close(): int
    {
        if ($this->handle !== null && $this->wroteHead) {
            $generated = Craft::$app->getFormatter()->asDatetime(new \DateTime(), 'medium');
            $this->write("\n</tbody></table>\n<p><small>" . Html::encode($generated) . "</small></p>\n</body></html>\n");
        }

        return parent::close();
    }
}
