<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextPathFilter;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextSearchResultNormalizer;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

final readonly class CodeSearchToolHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(
        private JbcontextStatusStore $store,
        private JbcontextCli $cli,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $state = $this->store->read();
        if (JbcontextSessionModeEnum::Pending === $state->mode) {
            return JbcontextToolResult::unavailable(
                'jbcontext code_search is still checking index eligibility for this session.',
                ['mode' => $state->mode->value],
            );
        }
        if (JbcontextSessionModeEnum::Disabled === $state->mode) {
            $message = $state->reason
                ?? 'jbcontext code_search is disabled for this session. Run jbcontext index manually when a prior index exists.';

            return JbcontextToolResult::unavailable($message, [
                'mode' => $state->mode->value,
            ]);
        }

        $text = trim((string) ($arguments['text'] ?? ''));
        if ('' === $text) {
            return JbcontextToolResult::unavailable('text is required and must be a non-empty string.');
        }

        try {
            $pathFilter = JbcontextPathFilter::validate(
                isset($arguments['path_filter']) ? (string) $arguments['path_filter'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return JbcontextToolResult::unavailable($e->getMessage());
        }

        $timeout = null;
        if (null !== $context->timeoutSeconds) {
            $timeout = (float) $context->timeoutSeconds;
        }

        $result = $this->cli->search(
            text: $text,
            pathFilter: $pathFilter,
            cancellationToken: $context->cancellationToken,
            timeoutSeconds: $timeout,
        );

        if ($result['cancelled']) {
            return JbcontextToolResult::unavailable('code_search was cancelled.');
        }
        if ($result['timed_out']) {
            return JbcontextToolResult::unavailable('code_search timed out.');
        }
        if (!$result['ok'] || null === $result['payload']) {
            $this->logger->warning('jbcontext.search.failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.search.failed',
                'run_id' => $context->runId,
                'error' => $result['error'],
                'exit_code' => $result['exit_code'],
            ]);

            return JbcontextToolResult::unavailable(
                'jbcontext search failed. Check CLI auth/status and try again.',
                ['error' => $result['error']],
            );
        }

        $hits = JbcontextSearchResultNormalizer::normalize($result['payload']);
        $revision = isset($result['payload']['revision']) ? (string) $result['payload']['revision'] : null;

        return JbcontextToolResult::structured([
            'available' => true,
            'query' => $text,
            'path_filter' => $pathFilter,
            'revision' => $revision,
            'results' => $hits,
        ]);
    }
}
