<?php
/**
 * Reportr's default configuration.
 *
 * Copy this file to `config/reportr.php` and edit it there. Anything left out falls back to the
 * value here, and everything except `functions` and `writers` can also be set on the settings
 * screen — the file wins where both are set, which is Craft's usual arrangement.
 */

return [
    /**
     * Extra logging, written to `storage/logs/reportr.log`, and full stack traces on failed runs.
     *
     * Also leaves Craft's database query logging switched on during a build, which is otherwise
     * turned off for the duration because it is the largest memory allocation in a long export.
     */
    'debug' => false,

    /**
     * PHP formatting functions for advanced reports.
     *
     * Each function takes one element and returns one row as a flat array, in the same order as
     * the column headings the template declares. This is the same shape Lab Reports used, and by
     * default Reportr reads that plugin's `config/labreports.php` as well — so an existing site's
     * functions keep working without being moved. Names defined here win on a collision.
     */
    'functions' => [
        // 'bookDump' => function($entry) {
        //     $author = $entry->getFieldValue('bookAuthor')->one();
        //
        //     return [
        //         (int)$entry->id,
        //         $entry->title,
        //         $entry->postDate->format('Y-m-d'),
        //         $author?->title,
        //     ];
        // },
    ],

    /**
     * Additional export formats.
     *
     * A map of format key to a class implementing `justinholtweb\reportr\writers\WriterInterface`.
     * The one extension point a reporting tool genuinely needs, because the awkward format is
     * always the one somebody else's finance system insists on.
     */
    'writers' => [
        // 'fixed' => \modules\reports\FixedWidthWriter::class,
    ],
];
