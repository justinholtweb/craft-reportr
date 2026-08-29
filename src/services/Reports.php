<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\db\ReportQuery;
use justinholtweb\reportr\models\Parameter;
use justinholtweb\reportr\Plugin;

/**
 * Finding reports, and the PHP formatting functions advanced reports use.
 *
 * The formatting-function registry is the one piece of Lab Reports kept intact on purpose. Those
 * functions live in a site's `config/labreports.php` and there may be dozens of them; asking
 * somebody to move and rename them all before a single report works would be reason enough not
 * to migrate. So this reads Reportr's own config file *and*, unless the setting is turned off,
 * the old plugin's — with Reportr's winning on a name collision.
 */
class Reports extends Component
{
    /** @var array<string, callable>|null */
    private ?array $functions = null;

    public function getReportById(int $id): ?Report
    {
        /** @var Report|null */
        return Report::find()->id($id)->status(null)->one();
    }

    public function getReportByHandle(string $handle): ?Report
    {
        /** @var Report|null */
        return Report::find()->handle($handle)->status(null)->one();
    }

    /** Accepts a handle, a numeric ID, or a numeric-looking string — as a console user would. */
    public function getReport(string|int $identifier): ?Report
    {
        if (is_int($identifier) || ctype_digit((string)$identifier)) {
            $report = $this->getReportById((int)$identifier);

            if ($report !== null) {
                return $report;
            }
        }

        return $this->getReportByHandle((string)$identifier);
    }

    /** @return Report[] */
    public function getAllReports(): array
    {
        /** @var Report[] */
        return Report::find()->status(null)->orderBy(['title' => SORT_ASC])->all();
    }

    public function query(array $criteria = []): ReportQuery
    {
        /** @var ReportQuery $query */
        $query = Report::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    // Formatting functions
    // -------------------------------------------------------------------------

    /** @return array<string, callable> */
    public function formatFunctions(): array
    {
        if ($this->functions !== null) {
            return $this->functions;
        }

        $functions = [];
        $configService = Craft::$app->getConfig();

        if (Plugin::getInstance()->getSettings()->readLabReportsConfig) {
            foreach ($configService->getConfigFromFile('labreports')['functions'] ?? [] as $name => $function) {
                if (is_callable($function)) {
                    $functions[(string)$name] = $function;
                }
            }
        }

        foreach (Plugin::getInstance()->getConfigItem('functions') ?? [] as $name => $function) {
            if (is_callable($function)) {
                $functions[(string)$name] = $function;
            }
        }

        ksort($functions);

        return $this->functions = $functions;
    }

    public function formatFunction(string $name): ?callable
    {
        return $this->formatFunctions()[$name] ?? null;
    }

    /** @return string[] */
    public function formatFunctionNames(): array
    {
        return array_keys($this->formatFunctions());
    }

    /** @return array<string, string> An empty option first, as a CP select wants. */
    public function formatFunctionOptions(): array
    {
        $options = ['' => Craft::t('reportr', 'Select a function…')];

        foreach ($this->formatFunctionNames() as $name) {
            $options[$name] = $name;
        }

        return $options;
    }

    // Filenames
    // -------------------------------------------------------------------------

    /**
     * The filename a run should get.
     *
     * Tokens rather than a fixed pattern, because the filename is frequently the *interface*: a
     * finance system watching a dropbox for `orders-2026-08.csv` cares about the name in a way
     * that "whatever the plugin generates" cannot satisfy. Unknown tokens are left alone rather
     * than blanked, so a typo is visible in the filename instead of silently producing
     * `report--.csv`.
     *
     * @param array<string, mixed> $params Normalised parameter answers.
     */
    public function buildFilename(Report $report, string $extension, array $params = [], ?\DateTime $when = null): string
    {
        $when ??= new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone()));
        $pattern = trim((string)$report->filenameFormat) ?: '{handle}-{datetime}';

        $replaced = preg_replace_callback(
            '/\{([a-zA-Z0-9_:]+)\}/',
            function(array $matches) use ($report, $params, $when): string {
                $token = $matches[1];

                if (str_starts_with($token, 'param:')) {
                    $value = $params[substr($token, 6)] ?? '';

                    if ($value instanceof \DateTimeInterface) {
                        $value = $value->format('Y-m-d');
                    }

                    if (is_array($value)) {
                        $value = implode('-', array_map('strval', $value));
                    }

                    return StringHelper::slugify((string)$value);
                }

                return match ($token) {
                    'handle' => $report->handle,
                    'title' => StringHelper::slugify((string)$report->title),
                    'id' => (string)$report->id,
                    'date' => $when->format('Y-m-d'),
                    'time' => $when->format('His'),
                    'datetime' => $when->format('Ymd-His'),
                    'timestamp' => (string)$when->getTimestamp(),
                    'Y' => $when->format('Y'),
                    'm' => $when->format('m'),
                    'd' => $when->format('d'),
                    'H' => $when->format('H'),
                    'i' => $when->format('i'),
                    's' => $when->format('s'),
                    default => $matches[0],
                };
            },
            $pattern,
        ) ?? $pattern;

        // Everything that could escape the storage folder, or confuse a browser's download
        // handler, comes out here — including the `..` that a `{param:…}` token could carry in
        // from a URL if slugify ever changed its mind about dots.
        $replaced = str_replace(['/', '\\', "\0"], '-', $replaced);
        $replaced = trim(preg_replace('/\.{2,}/', '.', $replaced) ?? $replaced, '. ');
        $replaced = $replaced !== '' ? $replaced : $report->handle;

        return mb_substr($replaced, 0, 200) . '.' . $extension;
    }

    // Parameters
    // -------------------------------------------------------------------------

    /**
     * Normalise whatever was submitted into the values a template sees.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function normalizeParams(Report $report, array $raw): array
    {
        $values = [];

        foreach ($report->getParams() as $param) {
            $values[$param->name] = $param->normalize($raw[$param->name] ?? null);
        }

        return $values;
    }

    /**
     * The same answers, reduced to what a database column can hold.
     *
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public function serializeParams(Report $report, array $normalized): array
    {
        $serialized = [];

        foreach ($report->getParams() as $param) {
            $serialized[$param->name] = $param->serialize($normalized[$param->name] ?? null);
        }

        return $serialized;
    }

    /**
     * Anything required that was not answered.
     *
     * @param array<string, mixed> $normalized
     * @return array<string, string> Parameter name => message.
     */
    public function validateParams(Report $report, array $normalized): array
    {
        $errors = [];

        foreach ($report->getParams() as $param) {
            if (!$param->required) {
                continue;
            }

            $value = $normalized[$param->name] ?? null;

            // `false` is a real answer to a yes/no question, so emptiness is tested by hand
            // rather than with `empty()` — which would demand that every required checkbox be
            // ticked before the report could run.
            $missing = $value === null
                || $value === ''
                || (is_array($value) && $value === [])
                || ($param->type !== Parameter::TYPE_BOOLEAN && $value === false);

            if ($missing) {
                $errors[$param->name] = Craft::t('reportr', '{label} is required.', ['label' => $param->label]);
            }
        }

        return $errors;
    }
}
