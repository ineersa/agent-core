<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Job;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Controller-side eligibility starter for interactive sessions.
 *
 * Runs in the process that owns the async extension_agent transport.
 * Every controller session-start (including resume of the same conversation)
 * claims a fresh check generation so stale Disabled/Eligible state cannot
 * permanently skip rechecks after the operator fixes CLI/index access.
 */
final readonly class JbcontextSessionStartHook implements AfterSessionStartHookInterface
{
    public function __construct(
        private ExtensionApiInterface $api,
        private JbcontextPaths $paths,
        private LoggerInterface $logger,
    ) {
    }

    public function onAfterSessionStart(AfterSessionStartHookContextDTO $context): void
    {
        $sessionId = trim($context->runId);
        if ('' === $sessionId) {
            return;
        }

        $store = JbcontextStatusStore::forSession($this->paths, $sessionId);
        $claimedGeneration = 0;
        $store->update(static function (JbcontextSessionState $current) use (&$claimedGeneration): JbcontextSessionState {
            $claimedGeneration = $current->checkGeneration + 1;

            return $current->with(
                mode: JbcontextSessionModeEnum::Pending,
                clearReason: true,
                attempt: 1,
                startedAt: microtime(true),
                reindexPending: false,
                reindexRunning: false,
                eligibilityStarted: true,
                checkGeneration: $claimedGeneration,
                updatedAt: microtime(true),
            );
        });

        try {
            $this->api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: JbcontextEligibilityJobHandler::HANDLER_ID,
                payload: [
                    'session_id' => $sessionId,
                    'attempt' => 1,
                    'check_generation' => $claimedGeneration,
                ],
                jobId: 'jbcontext.eligibility.'.$sessionId.'.g'.$claimedGeneration.'.attempt.1',
                correlationId: $sessionId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('jbcontext.eligibility.startup_dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.startup_dispatch_failed',
                'session_id' => $sessionId,
                'check_generation' => $claimedGeneration,
                'exception_class' => $e::class,
            ]);
            $store->update(static function (JbcontextSessionState $current) use ($claimedGeneration): JbcontextSessionState {
                if ($current->checkGeneration !== $claimedGeneration) {
                    return $current;
                }

                return $current->with(
                    mode: JbcontextSessionModeEnum::Disabled,
                    reason: 'jbcontext disabled: could not start background eligibility check. Check Hatfield logs and restart Hatfield.',
                    attempt: max(1, $current->attempt),
                    eligibilityStarted: true,
                );
            });
        }
    }
}
