<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Promote bounded code_mode stdout/stderr into the model-facing tool text.
 *
 * Keeps the script return value first, then appends a bounded diagnostics
 * block. delivery=context notifications alone are not model-facing for tool
 * results; only delivery=tool_result_replace replaces content, so diagnostics
 * must live in the visible content text.
 *
 * Also rewrites successful null/bool returns to explicit `null`/`true`/`false`
 * because ToolExecutor's generic scalar stringification turns false into "".
 *
 * Runs before OutputCap so large returns can still be capped after diagnostics
 * are extracted from the raw envelope. The diagnostics block itself is already
 * hard-bounded so a tiny return plus chatty output stays under the default cap.
 */
final readonly class CodeModeDiagnosticsToolResultProcessor implements ToolResultProcessorInterface
{
    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function process(ToolResult $result, ToolCall $toolCall): ToolResult
    {
        if (CodeModeTool::NAME !== $toolCall->toolName) {
            return $result;
        }

        // Failures and already-capped/sanitized results must stay untouched.
        // OutputCap strips raw_result; absent raw_result must never become "null".
        if ($result->isError) {
            return $result;
        }

        $details = \is_array($result->details) ? $result->details : [];
        if (!\array_key_exists('raw_result', $details)) {
            return $result;
        }

        $rawResult = $details['raw_result'];
        if ($rawResult instanceof CodeModeExecutionResult) {
            $value = $rawResult->result;
            // Host already prepared diagnostics; do not re-run prepare here.
            $diagnostics = $rawResult->diagnostics;
            $hasDiagnostics = $rawResult->hasDiagnostics();
        } else {
            $value = $rawResult;
            $diagnostics = [];
            $hasDiagnostics = false;
        }

        // Rewrite visible text for diagnostics and for null/bool returns.
        // Without this, ToolExecutor leaves false as "" and true as "1".
        if (!$hasDiagnostics && null !== $value && !\is_bool($value)) {
            return $result;
        }

        $visibleReturn = $this->normalizeVisibleResult($value);
        if (!$hasDiagnostics) {
            $details['raw_result'] = $value;

            return new ToolResult(
                toolCallId: $result->toolCallId,
                toolName: $result->toolName,
                content: [[
                    'type' => 'text',
                    'text' => $visibleReturn,
                ]],
                details: $details,
                isError: false,
            );
        }

        $diagnosticBlock = CodeModeDiagnostics::renderBlock($diagnostics);
        $visibleText = '' === $visibleReturn
            ? $diagnosticBlock
            : $visibleReturn."\n\n".$diagnosticBlock;

        $stdout = (string) ($diagnostics['stdout'] ?? '');
        $stderr = (string) ($diagnostics['stderr'] ?? '');
        $notificationId = hash('sha256', implode('|', [
            $toolCall->toolCallId,
            'code_mode',
            'diagnostics',
            $diagnosticBlock,
        ]));

        $notification = new ModelNotificationDTO(
            id: $notificationId,
            source: 'code_mode',
            kind: 'script_diagnostics',
            severity: 'info',
            delivery: 'context',
            text: $diagnosticBlock,
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            orderIndex: $toolCall->orderIndex,
            metadata: [
                'stdout_chars' => \strlen($stdout),
                'stderr_chars' => \strlen($stderr),
                'diagnostics_chars' => \strlen($diagnosticBlock),
                'truncated' => str_contains($diagnosticBlock, CodeModeDiagnostics::TRUNCATION_MARKER),
            ],
        );

        $existingNotifications = \is_array($details['model_notifications'] ?? null)
            ? $details['model_notifications']
            : [];
        /** @var array<string, mixed> $notificationArray */
        $notificationArray = $this->normalizer->normalize($notification, null, [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);
        $existingNotifications[] = $notificationArray;
        $details['model_notifications'] = $existingNotifications;
        $details['raw_result'] = $value;
        $details['code_mode_diagnostics'] = $diagnostics;

        return new ToolResult(
            toolCallId: $result->toolCallId,
            toolName: $result->toolName,
            content: [[
                'type' => 'text',
                'text' => $visibleText,
            ]],
            details: $details,
            isError: false,
        );
    }

    private function normalizeVisibleResult(mixed $result): string
    {
        if (null === $result) {
            return 'null';
        }

        if (\is_bool($result)) {
            return $result ? 'true' : 'false';
        }

        if (\is_string($result)) {
            return $result;
        }

        if (\is_scalar($result)) {
            return (string) $result;
        }

        try {
            return json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('code_mode diagnostics processor cannot encode the script return value for display: '.$exception->getMessage(), 0, $exception);
        }
    }
}
