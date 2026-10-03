<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\GeneratedContext;

use function Symfony\Component\String\u;

/** Counts logical messages while retaining only bounded, sanitized presentation text. */
final class PresentationMessageAccumulator
{
    public int $messageCount = 0;
    public int $eligibleCount = 0;
    public bool $hasAssistant = false;
    public ?string $excerpt = null;
    public string $excerptRawPrefix = '';
    /** @var list<string> */
    public array $lines = [];
    private int $generatedCount = 0;
    private bool $firstIsSystem = false;
    private int $leadingSurvivingSystems = 0;
    private bool $survivingPrefixEnded = false;

    public function __construct(private readonly int $limit, private readonly int $summaryChars)
    {
    }

    public function add(AgentMessage $message): void
    {
        $generated = GeneratedContext::isGeneratedUserContext($message);
        if (0 === $this->messageCount) {
            $this->firstIsSystem = 'system' === $message->role;
        }
        ++$this->messageCount;
        if ($generated) {
            ++$this->generatedCount;
        } elseif (!$this->survivingPrefixEnded) {
            if ('system' === $message->role) {
                ++$this->leadingSurvivingSystems;
            } else {
                $this->survivingPrefixEnded = true;
            }
        }
        if ('assistant' === $message->role) {
            $this->hasAssistant = true;
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    // Handoff inclusion checks the raw prefix before trimming.
                    $this->excerptRawPrefix = mb_substr((string) $block['text'], 0, 32);
                    $this->excerpt = u(trim((string) $block['text']))->truncate(800, '...')->toString();
                    break;
                }
            }
        }
        if (\in_array($message->role, ['system', 'user-context', 'tool'], true)) {
            return;
        }
        ++$this->eligibleCount;
        if (0 === $this->limit) {
            return;
        }
        $parts = [];
        foreach ($message->content as $part) {
            if ('text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }
        $text = trim(implode(' ', $parts));
        $normalized = preg_replace('/\s+/', ' ', '' === $text ? '(non-text content omitted)' : $text) ?? $text;
        if (mb_strlen($normalized) > $this->summaryChars) {
            $normalized = mb_substr($normalized, 0, $this->summaryChars - 1).'…';
        }
        $tool = null !== $message->toolName && '' !== $message->toolName ? ' tool='.$message->toolName : '';
        $tool .= null !== $message->toolCallId && '' !== $message->toolCallId ? ' tool_call_id='.$message->toolCallId : '';
        $this->lines[] = \sprintf('- role=%s%s%s — %s', $message->role, $tool, $message->isError ? ' error=yes' : '', $normalized);
        if (\count($this->lines) > $this->limit) {
            array_shift($this->lines);
        }
    }

    /** GeneratedContext::replace prepends new instructions and removes old generated instructions. */
    public function refresh(self $context): void
    {
        $remaining = $this->messageCount - $this->generatedCount - (int) $this->firstIsSystem;
        $leadingSystems = $this->leadingSurvivingSystems - (int) $this->firstIsSystem;
        $this->firstIsSystem = $context->messageCount > 0 ? $context->firstIsSystem : $leadingSystems > 0;
        $this->messageCount = $context->messageCount + $remaining;
        $this->generatedCount = $context->generatedCount;
        $this->leadingSurvivingSystems = $context->leadingSurvivingSystems + ($context->survivingPrefixEnded ? 0 : $leadingSystems);
        $this->survivingPrefixEnded = $context->survivingPrefixEnded || $this->survivingPrefixEnded;
        $this->eligibleCount += $context->eligibleCount;
        $this->lines = array_values(\array_slice([...$context->lines, ...$this->lines], -$this->limit));
        if (null === $this->excerpt) {
            $this->excerpt = $context->excerpt;
            $this->excerptRawPrefix = $context->excerptRawPrefix;
        }
        $this->hasAssistant = $this->hasAssistant || $context->hasAssistant;
    }

    public function addToolResults(int $count): void
    {
        $this->messageCount += $count;
        if ($count > 0) {
            $this->survivingPrefixEnded = true;
        }
    }
}
