<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use craft\base\FsInterface;
use craft\helpers\FileHelper;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;
use RuntimeException;
use Throwable;

/**
 * Where report files live, and how they get there.
 *
 * ## The bug this class exists to fix
 *
 * Lab Reports issue #8 is somebody on Heroku watching every generated report turn into
 * "Unavailable (File Missing)". Nothing was broken: the reports were written to a folder inside
 * the application, and Heroku's filesystem is ephemeral, so each dyno restart took the lot. The
 * plugin had no way to put a report anywhere else, because a report knew only its filename and
 * the folder was a global setting.
 *
 * So a run here records **which filesystem** it was written to as well as the path within it. Set
 * a Craft filesystem — S3, DigitalOcean Spaces, anything with a Flysystem adapter — and reports
 * leave the container before the container leaves. Runs written before the setting changed still
 * know where they went, so changing the default does not orphan the archive.
 *
 * ## Always local first
 *
 * A build always writes to a local temporary file and moves the finished file into place
 * afterwards, never streaming row by row to a remote filesystem. Two reasons: an object store
 * charges per request and has no notion of appending, and a build that fails halfway must not
 * leave a truncated file sitting where a downstream system is watching for it.
 */
class Storage extends Component
{
    /** Where builds happen, before the finished file is moved into place. */
    public function tempPath(string $filename): string
    {
        $folder = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'reportr';

        FileHelper::createDirectory($folder);

        return $folder . DIRECTORY_SEPARATOR . $filename;
    }

    /** The local folder used when no filesystem is configured. */
    public function localFolder(): string
    {
        $folder = Plugin::getInstance()->getSettings()->getStoragePath();

        if (!is_dir($folder)) {
            try {
                FileHelper::createDirectory($folder);
            } catch (Throwable $e) {
                throw new RuntimeException("Could not create the report folder at “{$folder}”: " . $e->getMessage(), 0, $e);
            }
        }

        // Anything under `storage/` is already outside the web root on a standard Craft install,
        // but a custom folder may not be — and a directory of exported customer data with a
        // guessable filename is a data breach waiting for somebody with a wordlist.
        $this->protectFolder($folder);

        return $folder;
    }

    /** The filesystem a report should write to, or null for local disk. */
    public function filesystemFor(?Report $report): ?FsInterface
    {
        $handle = $report?->fsHandle ?: Plugin::getInstance()->getSettings()->fsHandle;

        if (!$handle) {
            return null;
        }

        $fs = Craft::$app->getFs()->getFilesystemByHandle($handle);

        if ($fs === null) {
            Craft::warning("Reportr is configured to use the “{$handle}” filesystem, which does not exist. Falling back to local storage.", 'reportr');
        }

        return $fs;
    }

    public function subpathFor(?Report $report): string
    {
        $subpath = $report->fsSubpath ?? Plugin::getInstance()->getSettings()->fsSubpath;

        // `..` segments dropped, so a report can't write outside its filesystem's folder — the
        // local fallback is the storage folder, and the web root is two levels up.
        $segments = array_filter(
            explode('/', str_replace('\\', '/', (string)$subpath)),
            static fn(string $segment) => $segment !== '' && $segment !== '.' && $segment !== '..',
        );

        return implode('/', $segments);
    }

    /**
     * Put a finished local file where the run says it belongs, and stamp the run with the result.
     *
     * The run is not saved here — the runner does that once, with the status and the timings, so
     * a build is not three writes to the same row.
     */
    public function store(Run $run, string $localPath, ?Report $report = null): void
    {
        if (!is_file($localPath)) {
            throw new RuntimeException("The report file at “{$localPath}” was never written.");
        }

        $report ??= $run->getReport();
        $filename = $run->filename ?: basename($localPath);
        $subpath = $this->subpathFor($report);
        $path = $subpath !== '' ? $subpath . '/' . $filename : $filename;
        $fs = $this->filesystemFor($report);

        if ($fs !== null) {
            $stream = fopen($localPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Could not read the report file at “{$localPath}”.");
            }

            try {
                $fs->writeFileFromStream($path, $stream, []);
            } finally {
                // Flysystem closes the stream on most adapters and leaves it open on some, and a
                // double `fclose()` is a warning rather than an error. Checking the resource is
                // still a resource is the only portable way to be right either way.
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $run->fsHandle = $fs->handle;
            $run->path = $path;
            $run->fileSize = filesize($localPath) ?: null;

            FileHelper::unlink($localPath);

            return;
        }

        $destination = $this->localFolder() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        FileHelper::createDirectory(dirname($destination));

        // `rename()` across a device boundary fails silently on some systems — the temp folder and
        // the storage folder are frequently on different mounts in a container — so a failed
        // rename falls back to a copy rather than losing the report.
        if (!@rename($localPath, $destination)) {
            if (!@copy($localPath, $destination)) {
                throw new RuntimeException("Could not move the report file to “{$destination}”.");
            }

            FileHelper::unlink($localPath);
        }

        $run->fsHandle = null;
        $run->path = $path;
        $run->fileSize = filesize($destination) ?: null;
    }

    public function exists(Run $run): bool
    {
        if (!$run->path && !$run->filename) {
            return false;
        }

        if ($run->fsHandle) {
            $fs = Craft::$app->getFs()->getFilesystemByHandle($run->fsHandle);

            try {
                return $fs !== null && $this->pathOf($run) !== '' && $fs->fileExists($this->pathOf($run));
            } catch (Throwable) {
                return false;
            }
        }

        $path = $this->localPath($run);

        return $path !== null && is_file($path);
    }

    /** The full local path, or null when the file lives on a remote filesystem. */
    public function localPath(Run $run): ?string
    {
        if ($run->fsHandle) {
            return null;
        }

        $path = $this->pathOf($run);

        if ($path === '') {
            return null;
        }

        return Plugin::getInstance()->getSettings()->getStoragePath()
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * A read stream for the file, wherever it is.
     *
     * The one method downloads should go through, so that a remote file is streamed to the
     * browser rather than read into memory first — a 400 MB export served with `readfile()` needs
     * 400 MB of PHP memory it does not have.
     *
     * @return resource|null
     */
    public function stream(Run $run)
    {
        if ($run->fsHandle) {
            $fs = Craft::$app->getFs()->getFilesystemByHandle($run->fsHandle);

            if ($fs === null || $this->pathOf($run) === '') {
                return null;
            }

            try {
                return $fs->getFileStream($this->pathOf($run));
            } catch (Throwable $e) {
                Craft::warning('Could not open the report file: ' . $e->getMessage(), 'reportr');

                return null;
            }
        }

        $path = $this->localPath($run);

        if ($path === null || !is_file($path)) {
            return null;
        }

        $stream = fopen($path, 'rb');

        return $stream !== false ? $stream : null;
    }

    public function delete(Run $run): bool
    {
        if ($run->fsHandle) {
            $fs = Craft::$app->getFs()->getFilesystemByHandle($run->fsHandle);

            if ($fs === null || $this->pathOf($run) === '') {
                return false;
            }

            try {
                $fs->deleteFile($this->pathOf($run));

                return true;
            } catch (Throwable $e) {
                Craft::warning('Could not delete the report file: ' . $e->getMessage(), 'reportr');

                return false;
            }
        }

        $path = $this->localPath($run);

        if ($path === null || !is_file($path)) {
            return false;
        }

        return FileHelper::unlink($path);
    }

    /**
     * Copy a remote run's file to a local temporary path so it can be attached to an email.
     *
     * Returns null when there is nothing to copy. The caller owns the file and must delete it.
     */
    public function copyToTemp(Run $run): ?string
    {
        $local = $this->localPath($run);

        if ($local !== null) {
            return is_file($local) ? $local : null;
        }

        $stream = $this->stream($run);

        if ($stream === null) {
            return null;
        }

        $path = $this->tempPath('attach-' . $run->id . '-' . ($run->filename ?: 'report'));
        $out = fopen($path, 'wb');

        if ($out === false) {
            fclose($stream);

            return null;
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    /**
     * The stored path, falling back to the bare filename for runs imported from Lab Reports.
     *
     * Empty for a path that steps out with `..` or carries a null byte. Paths are only ever written
     * by {@see store()}, but an imported row came from another plugin's table, and garbage
     * collection deletes whatever this returns.
     */
    private function pathOf(Run $run): string
    {
        $path = trim(str_replace('\\', '/', (string)($run->path ?: $run->filename)), '/');

        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return '';
        }

        return $path;
    }

    /**
     * Drop an Apache/`.htaccess` deny and an empty index into the local folder.
     *
     * Cheap insurance, written once. It does nothing on nginx, which is why the folder should be
     * outside the web root — but the folder is configurable, and somebody will point it at
     * `web/reports/` because that seemed convenient.
     */
    private function protectFolder(string $folder): void
    {
        $htaccess = $folder . DIRECTORY_SEPARATOR . '.htaccess';

        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }

        $index = $folder . DIRECTORY_SEPARATOR . 'index.html';

        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }
}
