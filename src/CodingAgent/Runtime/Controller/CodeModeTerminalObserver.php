<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeCallJournal;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use Ineersa\SqliteQueue\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CodeModeTerminalObserver
{
    public function __construct(
        private CodeModeProcessOwner $processes,
        private DeferredToolCompletionRepositoryInterface $deferred,
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private CodeModeCallJournal $journal,
        #[Autowire('%env(HATFIELD_SQLITE_QUEUE_ENDPOINT)%')] private string $endpoint,
    ) {
    }

    public function observe(string $sessionId, string $runId): void
    {
        foreach ($this->processes->controllerInvocationIds($runId) as $id) {
            try {
                $this->settle($runId, $id);
            } catch (\Throwable $error) {
                $this->logger->warning('code_mode.terminal_observation_failed', [
                    'run_id' => $runId, 'session_id' => $sessionId, 'component' => 'tool.code_mode',
                    'event_type' => 'code_mode.terminal_observation_failed', 'error_type' => $error::class,
                ]);
            }
        }
    }

    private function settle(string $runId, string $id): void
    {
        $invocation = $this->processes->invocation($runId, $id);
        $parent = $invocation['parent'] ?? null;
        if (null !== $parent) {
            $root = $this->deferred->findMetadataByDeferredId($parent['rootId']);
            $destination = $this->processes->invocation($runId, $parent['invocationId']);
            $stop = null === $root || 'pending' !== $root->status ? 'owner settled' : $root->interruptionReason;
            $reply = $this->processes->observeTerminal($runId, $id, $parent['limit'], $stop);
            if (null !== $reply && null !== $destination && $this->journal->completeChild($destination['workspace'], $parent['callId'], $id, $reply)) {
                $client = Client::connect($this->endpoint);
                try {
                    $client->send('code_mode_reply.'.$parent['invocationId'], json_encode(['id' => $parent['callId'], ...$reply], \JSON_THROW_ON_ERROR));
                } finally {
                    $client->close();
                }
            }

            return;
        }
        $metadata = $this->deferred->findMetadataByDeferredId($id);
        if (null === $metadata || 'pending' !== $metadata->status || $runId !== $metadata->reference->runId || 'code_mode' !== $metadata->reference->toolName) {
            return;
        }
        $call = $this->deferred->findByDeferredId($id);
        if (null === $call) {
            return;
        }
        $explicit = $call->arguments['timeout_seconds'] ?? null;
        $explicit = \is_int($explicit) ? $explicit : null;
        $limit = $explicit;
        if (null === $metadata->backgroundedAt) {
            $limit ??= CodeModeArgumentsDTO::DEFAULT_TIMEOUT_SECONDS;
            if (null !== $call->timeoutSeconds && $call->timeoutSeconds > 0) {
                $limit = min($limit, $call->timeoutSeconds);
            }
        }
        $reply = $this->processes->observeTerminal($runId, $id, $limit, $metadata->interruptionReason);
        if (null === $reply) {
            return;
        }
        $text = $reply['ok'] ? json_encode($reply['result'] ?? null, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : ($reply['error'] ?? 'Code-mode failed.');
        // The usual deferred transport persists the canonical result and the
        // run-control admission path settles duplicate sends by correlation.
        $this->commandBus->dispatch(ToolCallResultFactory::fromDeferredCorrelationAndCompletion($call, [['type' => 'text', 'text' => $text]], null, !$reply['ok'], null));
    }
}
