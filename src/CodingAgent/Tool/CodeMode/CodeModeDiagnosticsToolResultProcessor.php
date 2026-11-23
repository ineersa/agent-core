<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Tool\CodeModeTool;

/**
 * Promote code_mode stdout/stderr into the model-facing tool text.
 *
 * Keeps the script return value first, then appends labeled stdout/stderr
 * sections in the ordinary tool result. Ordinary OutputCap then applies the
 * document-report cap and saved-output recovery to that combined text.
 *
 * Also rewrites successful null/bool returns to explicit `null`/`true`/`false`
 * because ToolExecutor's generic scalar stringification turns false into "".
 *
 * Runs before OutputCap so large returns can still be capped after diagnostics
 * are extracted from the raw envelope.
 */
final readonly class CodeModeDiagnosticsToolResultProcessor implements ToolResultProcessorInterface
{
    public function process(ToolResult $result, ToolCall $toolCall): ToolResult
    {
        if (CodeModeTool::NAME !== $toolCall->toolName) {
            return $result;
        }

        // Failures and already-capped/sanitized results must stay untouched.
        // Render only once; absent raw_result must never become "null".
        if ($result->isError) {
            return $result;
        }

        $details = \is_array($result->details) ? $result->details : [];
        if (!\array_key_exists('raw_result', $details)) {
            return $result;
        }

        $rawResult = $details['raw_result'];
        if (\is_array($rawResult) && \array_key_exists('code_mode_value', $rawResult)
            && \is_array($rawResult['code_mode_diagnostics'] ?? null)) {
            $value = $rawResult['code_mode_value'];
            // Host already prepared diagnostics; do not re-run prepare here.
            $diagnostics = $rawResult['code_mode_diagnostics'];
            $hasDiagnostics = [] !== $diagnostics;
        } else {
            $value = $rawResult;
            $diagnostics = [];
            $hasDiagnostics = false;
        }

        // A script return is data, not canonical control metadata. Remove it
        // before output capping, which can preserve trusted image references.
        unset($details['raw_result']);
        $visibleText = $this->normalizeVisibleResult($value);
        if ($hasDiagnostics) {
            $diagnosticBlock = CodeModeDiagnostics::renderBlock($diagnostics);
            $visibleText = '' === $visibleText ? $diagnosticBlock : $visibleText."\n\n".$diagnosticBlock;
        }

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
