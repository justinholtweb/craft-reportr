<?php

declare(strict_types=1);

namespace justinholtweb\reportr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

/**
 * Builds one report in the background.
 *
 * ## The TTR is the whole point of this class
 *
 * Craft's default time-to-reserve is 300 seconds, which is generous for resizing a thumbnail and
 * nowhere near enough for a year of orders. Lab Reports issue #7 is a person whose nightly export
 * grew until it hit that ceiling — "exceeded the timeout of 300 seconds" — with no way to raise
 * it, because the number is the queue's and not the report's. The runner passes the plugin's
 * own setting as `ttr` when it pushes the job, and `getTtr()` returns the same — yii-queue only
 * consults these two methods on a job implementing `RetryableJobInterface`, which `BaseJob` does
 * not, so the interface is declared here rather than left implied.
 *
 * The job does not retry. The runner records failures *on the run*, with the reason, so a retry
 * would produce a second file for the same request and hide the first failure rather than
 * reporting it. A report that failed should be looked at, not attempted again at 3am.
 */
class BuildReport extends BaseJob implements RetryableJobInterface
{
    public ?int $runId = null;
    public string $reportTitle = '';

    public function execute($queue): void
    {
        if ($this->runId === null) {
            return;
        }

        /** @var Run|null $run */
        $run = Run::find()->id($this->runId)->status(null)->one();

        if ($run === null) {
            // The run was deleted between being queued and being picked up. Nothing to do, and
            // nothing worth failing the job over.
            Craft::info("Reportr run {$this->runId} no longer exists; skipping.", 'reportr');

            return;
        }

        Plugin::getInstance()->runner->execute(
            $run,
            function(float $progress, string $label) use ($queue): void {
                $this->setProgress($queue, $progress, $label);
            },
        );
    }

    public function getTtr(): int
    {
        return Plugin::getInstance()->getSettings()->jobTtr;
    }

    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('reportr', 'Building the “{report}” report', [
            'report' => $this->reportTitle !== '' ? $this->reportTitle : Craft::t('reportr', 'report'),
        ]);
    }
}
