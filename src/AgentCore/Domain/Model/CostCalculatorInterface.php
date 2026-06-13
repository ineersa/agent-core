<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

/**
 * Computes cost from token usage and model pricing.
 *
 * Implemented in CodingAgent using HatfieldModelCatalog / AiCost.
 * The interface lives in AgentCore so the LlmPlatformAdapter can
 * depend on it without crossing the architecture boundary.
 */
interface CostCalculatorInterface
{
    /**
     * Calculate cost from token usage for a given model.
     *
     * @param string               $modelRef "provider/model" string
     * @param array<string, mixed> $usage    Token usage array from extractUsage()
     *
     * @return float Cost in USD, or 0.0 if the model has no pricing configured
     */
    public function calculateCost(string $modelRef, array $usage): float;
}
