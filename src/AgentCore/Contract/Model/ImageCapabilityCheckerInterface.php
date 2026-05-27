<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Model;

/**
 * Check whether a given model supports image inputs.
 *
 * Implementations use the model catalog, provider metadata, or
 * other available sources to determine image capability for a
 * specific model name.
 *
 * This contract lives in AgentCore so that AgentMessageConverter
 * (also in AgentCore) can depend on it without importing
 * CodingAgent classes.
 */
interface ImageCapabilityCheckerInterface
{
    /**
     * Returns true when the named model is known to accept
     * image inputs (Capability::INPUT_IMAGE equivalent).
     *
     * Returns false when the model is unknown or does not
     * support images. This is a safe default: without
     * confirmation, do not attach images.
     *
     * @param non-empty-string $modelName Full model identifier
     *                                    (e.g. "llama_cpp/flash")
     */
    public function supportsImages(string $modelName): bool;
}
