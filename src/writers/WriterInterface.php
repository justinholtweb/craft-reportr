<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

use justinholtweb\reportr\models\FormatOptions;

/**
 * A report file being written, one row at a time.
 *
 * Every format is streamed. Nothing accumulates rows in memory and nothing re-reads the file it
 * has written — including XLSX, which is a zip of XML and is therefore usually implemented by
 * building the whole sheet as a string first. Lab Reports issues #6 and #7 are both a report that
 * outgrew the memory or the clock it was given, and a writer that holds the export in RAM puts a
 * hard ceiling on how large a report can ever be.
 */
interface WriterInterface
{
    public function __construct(string $path, FormatOptions $options);

    /** The file extension, without the dot. */
    public static function extension(): string;

    public static function mimeType(): string;

    /** Called once, before any row. May be a no-op for formats with no header concept. */
    public function open(array $headings = []): void;

    public function writeRow(array $row): bool;

    /** Finish the file. Returns the number of bytes written. */
    public function close(): int;
}
