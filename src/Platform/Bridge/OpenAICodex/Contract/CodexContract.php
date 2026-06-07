<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract;

use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexAssistantMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexMessageBagNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexToolCallMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexUserMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\Content\CodexTextNormalizer;
use Symfony\AI\Platform\Contract;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Codex-specific Contract that produces Responses API request shape.
 *
 * Registers Codex-specific normalizers FIRST so that
 * ModelContractNormalizer instances (which check for CodexModel in context)
 * take priority over the unconditional default normalizers appended by
 * {@see Contract::create()}.
 *
 * Transforms:
 *  - MessageBag → {input: [...], instructions: "..."}
 *  - AssistantMessage → {role, type: 'message', content} or function_call items
 *  - ToolCallMessage → {type: 'function_call_output', call_id, output}
 *  - Tool → flat {type: 'function', name, description, parameters}
 *  - ToolCall (in input) → flat {call_id, name, arguments, type: 'function_call'}
 *
 * Following the OpenResponses bridge pattern.
 */
final class CodexContract extends Contract
{
    /**
     * @param NormalizerInterface[] $normalizers
     */
    public static function create(array $normalizers = []): Contract
    {
        // Pass Codex-specific normalizers first so they take priority over
        // the default normalizers that match unconditionally.
        $codexNormalizers = [
            new CodexMessageBagNormalizer(),
            new CodexAssistantMessageNormalizer(),
            new CodexToolCallMessageNormalizer(),
            new CodexUserMessageNormalizer(),
            new CodexTextNormalizer(),
            new CodexToolNormalizer(),
            new CodexToolCallNormalizer(),
            ...$normalizers,
        ];

        return parent::create($codexNormalizers);
    }
}
