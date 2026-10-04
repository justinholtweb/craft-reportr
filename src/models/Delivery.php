<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\StringHelper;

/**
 * Who hears about a run, and whether they get the file.
 *
 * A scheduled report nobody is told about is a file accumulating on a disk. The interesting
 * half of this model is `when`: the run that matters most is the one that *failed*, and a
 * plugin that only emails on success is a plugin whose users find out their Monday report has
 * been broken for five weeks when somebody asks for the numbers.
 */
class Delivery extends Model
{
    public const WHEN_NEVER = 'never';
    public const WHEN_ALWAYS = 'always';
    public const WHEN_SUCCESS = 'success';
    public const WHEN_FAILURE = 'failure';

    public string $when = self::WHEN_NEVER;

    /** @var string[] */
    public array $recipients = [];

    /** Attach the file, subject to the size cap in the plugin settings. */
    public bool $attach = true;

    public ?string $subject = null;

    public static function options(): array
    {
        return [
            self::WHEN_NEVER => Craft::t('reportr', 'Never'),
            self::WHEN_ALWAYS => Craft::t('reportr', 'Every run'),
            self::WHEN_SUCCESS => Craft::t('reportr', 'Successful runs only'),
            self::WHEN_FAILURE => Craft::t('reportr', 'Failed runs only'),
        ];
    }

    public static function fromArray(?array $config): self
    {
        $delivery = new self();

        if ($config === null) {
            return $delivery;
        }

        $delivery->when = (string)($config['when'] ?? self::WHEN_NEVER);

        if (!isset(self::options()[$delivery->when])) {
            $delivery->when = self::WHEN_NEVER;
        }

        $delivery->attach = !isset($config['attach']) || (bool)$config['attach'];
        $delivery->subject = ($config['subject'] ?? null) ?: null;
        $delivery->recipients = self::normalizeRecipients($config['recipients'] ?? []);

        return $delivery;
    }

    /**
     * Accepts a textarea, a comma-separated string or a list.
     *
     * An entry is kept as written: an address, or an environment variable or alias such as
     * `$REPORT_RECIPIENTS`. The variable is resolved when the message is sent (see
     * {@see getResolvedRecipients()}), never here — resolving it on save would write the secret
     * into the database, and show any env var that looks like an address to whoever edits the
     * report.
     *
     * @return string[]
     */
    public static function normalizeRecipients(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $item) {
            $entry = trim((string)$item);

            // Keyed case-insensitively so one address written two ways is one recipient, but the
            // first spelling is what is kept — the local part of an address is technically
            // case-sensitive and lower-casing it is not ours to do.
            $key = StringHelper::toLowerCase($entry);

            if ($entry === '' || isset($entries[$key])) {
                continue;
            }

            if (self::isReference($entry) || filter_var($entry, FILTER_VALIDATE_EMAIL)) {
                $entries[$key] = $entry;
            }
        }

        return array_values($entries);
    }

    /**
     * The addresses to send to, with environment variables and aliases resolved.
     *
     * @return string[]
     */
    public function getResolvedRecipients(): array
    {
        $addresses = [];

        foreach ($this->recipients as $entry) {
            $parsed = self::isReference($entry) ? (string)App::parseEnv($entry) : $entry;

            foreach (preg_split('/[\s,;]+/', $parsed) ?: [] as $address) {
                $address = trim($address);
                $key = StringHelper::toLowerCase($address);

                if ($address !== '' && !isset($addresses[$key]) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $addresses[$key] = $address;
                }
            }
        }

        return array_values($addresses);
    }

    /** `$VARIABLE` or `@alias`: resolved at send time. */
    private static function isReference(string $entry): bool
    {
        return (bool)preg_match('/^[$@][A-Za-z_][A-Za-z0-9_]*$/', $entry);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'when' => $this->when,
            'recipients' => $this->recipients,
            'attach' => $this->attach,
            'subject' => $this->subject,
        ];
    }

    public function getIsEnabled(): bool
    {
        return $this->when !== self::WHEN_NEVER && $this->recipients !== [];
    }

    public function shouldSendFor(bool $succeeded): bool
    {
        if (!$this->getIsEnabled()) {
            return false;
        }

        return match ($this->when) {
            self::WHEN_ALWAYS => true,
            self::WHEN_SUCCESS => $succeeded,
            self::WHEN_FAILURE => !$succeeded,
            default => false,
        };
    }
}
