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
 * Runs before OutputCap so large returns can still be capped after diagnostics
 * are extracted from the raw envelope.
 */
final readonly class CodeModeDiagnosticsToolResultProcessor implements ToolResultProcessorInterface
{
    private const int DIAGNOSTIC_TEXT_CHARS = 4000;

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
            $hasDiagnostics = $rawResult->hasDiagnostics();
            $stdout = trim((string) ($rawResult->diagnostics['stdout'] ?? ''));
            $stderr = trim((string) ($rawResult->diagnostics['stderr'] ?? ''));
        } else {
            $value = $rawResult;
            $hasDiagnostics = false;
            $stdout = '';
            $stderr = '';
        }

        // Only rewrite visible text for an explicit successful null return.
        if (!$hasDiagnostics) {
            if (null !== $value) {
                return $result;
            }

            $details['raw_result'] = null;

            return new ToolResult(
                toolCallId: $result->toolCallId,
                toolName: $result->toolName,
                content: [[
                    'type' => 'text',
                    'text' => 'null',
                ]],
                details: $details,
                isError: false,
            );
        }

        $sections = [];
        if ('' !== $stdout) {
            $sections[] = "stdout:\n".$stdout;
        }
        if ('' !== $stderr) {
            $sections[] = "stderr:\n".$stderr;
        }
        $diagnosticBlock = $this->truncate("code_mode diagnostics\n".implode("\n\n", $sections));
        $visibleReturn = $this->normalizeVisibleResult($value);
        $visibleText = '' === $visibleReturn
            ? $diagnosticBlock
            : $visibleReturn."\n\n".$diagnosticBlock;

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
        $details['code_mode_diagnostics'] = array_filter([
            'stdout' => $stdout,
            'stderr' => $stderr,
        ], static fn (string $chunk): bool => '' !== $chunk);

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

    private function truncate(string $text): string
    {
        if (\strlen($text) <= self::DIAGNOSTIC_TEXT_CHARS) {
            return $text;
        }

        // Keep a valid UTF-8 suffix; never split a multibyte character.
        $suffix = substr($text, -self::DIAGNOSTIC_TEXT_CHARS);
        if (!mb_check_encoding($suffix, 'UTF-8')) {
            $suffix = mb_substr($text, -self::DIAGNOSTIC_TEXT_CHARS, null, 'UTF-8');
        }

        return $suffix;
    }

    private function normalizeVisibleResult(mixed $result): string
    {
        if (null === $result) {
            return 'null';
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
            throw new \RuntimeException(
                'code_mode diagnostics processor cannot encode the script return value for display: '.$exception->getMessage(),
                0,
                $exception,
            );
        }
    }
}
