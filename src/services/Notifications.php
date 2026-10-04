<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\models\Delivery;
use justinholtweb\reportr\Plugin;
use Throwable;

/**
 * Emails a finished run to whoever asked for it.
 *
 * A scheduled report nobody is told about is a file quietly accumulating on a disk, and the run
 * most worth an email is the one that *failed* — a plugin that only writes on success is a
 * plugin whose users discover their Monday report has been broken for five weeks when somebody
 * finally asks for the numbers.
 *
 * The message is composed here rather than registered as a Craft system message. System messages
 * are the right home for anything an editor should be able to reword per site; this is
 * operational mail with a table in it, and putting it in project config would mean a schema
 * change every time a line is added.
 */
class Notifications extends Component
{
    public function sendForRun(Run $run, ?Report $report = null): bool
    {
        $report ??= $run->getReport();

        if ($report === null) {
            return false;
        }

        $delivery = $report->getDelivery();
        $succeeded = $run->getIsFinished();

        if (!$delivery->shouldSendFor($succeeded)) {
            return false;
        }

        $attachment = $this->attachmentFor($run, $delivery);
        $sent = false;

        try {
            $message = Craft::$app->getMailer()->compose();
            $message->setSubject($this->subjectFor($report, $run, $delivery));
            $message->setHtmlBody($this->render($report, $run, $attachment !== null));
            $message->setTextBody($this->renderText($report, $run));

            if ($attachment !== null) {
                $message->attach($attachment, ['fileName' => (string)$run->filename]);
            }

            // One message per recipient, not one message with everyone in the To field. An
            // export of customer data should not also disclose who else receives it.
            foreach ($delivery->getResolvedRecipients() as $recipient) {
                $sent = $message->setTo($recipient)->send() || $sent;
            }
        } catch (Throwable $e) {
            Craft::error('Reportr could not send a run notification: ' . $e->getMessage(), 'reportr');
        } finally {
            // Only a *copy* is deleted. A run stored on local disk hands back its real path, and
            // deleting that would destroy the report to send an email about it.
            if ($attachment !== null && $attachment !== Plugin::getInstance()->storage->localPath($run)) {
                FileHelper::unlink($attachment);
            }
        }

        return $sent;
    }

    private function attachmentFor(Run $run, Delivery $delivery): ?string
    {
        if (!$delivery->attach || !$run->getIsDownloadable()) {
            return null;
        }

        $cap = Plugin::getInstance()->getSettings()->getMaxAttachmentBytes();

        // Over the cap the email still goes, with a link instead of a file. Silently dropping the
        // attachment would be worse; refusing to send at all would be worse still.
        if ($cap > 0 && ($run->fileSize ?? 0) > $cap) {
            return null;
        }

        return Plugin::getInstance()->storage->copyToTemp($run);
    }

    private function subjectFor(Report $report, Run $run, Delivery $delivery): string
    {
        if ($delivery->subject) {
            return str_replace(
                ['{report}', '{status}', '{rows}'],
                [(string)$report->title, $run->getStatusLabel(), (string)$run->totalRows],
                $delivery->subject,
            );
        }

        return $run->getIsFinished()
            ? Craft::t('reportr', '{report} — {rows} rows', ['report' => $report->title, 'rows' => number_format($run->totalRows)])
            : Craft::t('reportr', '{report} — report failed', ['report' => $report->title]);
    }

    private function render(Report $report, Run $run, bool $attached): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            return $view->renderTemplate('reportr/_email/run', [
                'report' => $report,
                'run' => $run,
                'attached' => $attached,
                'url' => UrlHelper::cpUrl('reportr/runs/' . $run->id),
                'params' => $run->describeParams(),
            ], View::TEMPLATE_MODE_CP);
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    private function renderText(Report $report, Run $run): string
    {
        $lines = [
            (string)$report->title,
            '',
            Craft::t('reportr', 'Status: {status}', ['status' => $run->getStatusLabel()]),
        ];

        if ($run->getIsFinished()) {
            $lines[] = Craft::t('reportr', 'Rows: {rows}', ['rows' => number_format($run->totalRows)]);
            $lines[] = Craft::t('reportr', 'File: {filename}', ['filename' => (string)$run->filename]);
        } elseif ($run->statusMessage) {
            $lines[] = '';
            $lines[] = $run->statusMessage;
        }

        $lines[] = '';
        $lines[] = UrlHelper::cpUrl('reportr/runs/' . $run->id);

        return implode("\n", $lines);
    }
}
