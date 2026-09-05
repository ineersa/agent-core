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
 * Controller-side one-shot eligibility starter for interactive sessions.
 *
 * Runs in the process that owns the async extension_agent transport.
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
        $shouldDispatch = false;
        $store->update(static function (JbcontextSessionState $current) use (&$shouldDispatch): JbcontextSessionState {
            if ($current->eligibilityStarted
                || JbcontextSessionModeEnum::Eligible === $current->mode
                || JbcontextSessionModeEnum::Disabled === $current->mode
            ) {
                $shouldDispatch = false;

                return $current;
            }

            $shouldDispatch = true;

            return $current->with(
                statusText: 'jbcontext: checking index…',
                attempt: max(1, $current->attempt),
                eligibilityStarted: true,
            );
        });

        if (!$shouldDispatch) {
            return;
        }

        try {
            $this->api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: JbcontextEligibilityJobHandler::HANDLER_ID,
                payload: [
                    'session_id' => $sessionId,
                    'attempt' => 1,
                ],
                jobId: 'jbcontext.eligibility.'.$sessionId.'.attempt.1',
                correlationId: $sessionId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('jbcontext.eligibility.startup_dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.startup_dispatch_failed',
                'session_id' => $sessionId,
                'exception_class' => $e::class,
            ]);
            $store->update(static fn (JbcontextSessionState $current): JbcontextSessionState => $current->with(
                mode: JbcontextSessionModeEnum::Disabled,
                reason: 'jbcontext disabled: could not start background eligibility check.',
                statusText: 'jbcontext disabled: could not start background eligibility check.',
                attempt: max(1, $current->attempt),
                eligibilityStarted: true,
            ));
        }
    }
}
