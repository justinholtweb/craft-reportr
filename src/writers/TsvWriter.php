<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

use justinholtweb\reportr\models\FormatOptions;

/** A tab-separated file. Same writer, one forced option, its own extension. */
class TsvWriter extends DelimitedWriter
{
    public function __construct(string $path, FormatOptions $options)
    {
        $options = clone $options;
        $options->delimiter = "\t";

        parent::__construct($path, $options);
    }

    public static function extension(): string
    {
        return 'tsv';
    }

    public static function mimeType(): string
    {
        return 'text/tab-separated-values';
    }
}
