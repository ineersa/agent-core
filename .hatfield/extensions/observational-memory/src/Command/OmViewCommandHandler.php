<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Command;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\MarkdownCommandContextInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class OmViewCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(
        private readonly OmQueryService $query,
        private readonly OmSessionContext $sessionContext,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        $runId = null;
        try {
            $runId = $this->sessionContext->requireSessionId();
            $text = $this->query->formatView($runId);
            if ($context instanceof MarkdownCommandContextInterface) {
                $context->notifyMarkdown($text);

                return;
            }

            // Older hosts without MarkdownCommandContextInterface keep plain notify().
            $context->notify($text, 'info');
        } catch (\Throwable) {
            // Never surface raw exception text (paths/content). Structured log only.
            // Capture run_id before try so a throwing lazy getSessionId() cannot rethrow in catch.
            $this->logger->error('om.command.view_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.command.view_failed',
                'run_id' => $runId,
            ]);
            $context->notify('OM view unavailable.', 'error');
        }
    }
}
