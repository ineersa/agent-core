<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Bounded presentation fields for one retained tool_completion message. */
final readonly class RetainedToolCompletionPresentation
{
    public function __construct(
        public string $severity,
        public string $terminalOutcome,
        public string $subject,
        public string $boundedSummary,
    ) {
        foreach ([
            'severity' => $this->severity,
            'terminalOutcome' => $this->terminalOutcome,
            'subject' => $this->subject,
            'boundedSummary' => $this->boundedSummary,
        ] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('Retained presentation %s must be nonblank.', $field));
            }
        }
        RetainedToolCompletionUtf8::requireValid($this->subject, 'subject');
        RetainedToolCompletionUtf8::requireValid($this->boundedSummary, 'bounded_summary');
        if (RetainedToolCompletionUtf8::byteLength($this->subject) > RetainedToolCompletionBounds::SUBJECT_MAX_BYTES) {
            throw new \InvalidArgumentException('Retained envelope subject exceeds byte budget.');
        }
        if (RetainedToolCompletionUtf8::byteLength($this->boundedSummary) > RetainedToolCompletionBounds::SUMMARY_MAX_BYTES) {
            throw new \InvalidArgumentException('Retained envelope summary exceeds byte budget.');
        }
    }
}
