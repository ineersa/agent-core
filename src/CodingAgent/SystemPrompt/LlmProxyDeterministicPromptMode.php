<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\SystemPrompt;

/**
 * Detects whether LLM requests should use stable prompt/context prefixes
 * for llama-proxy HTTP cache replay (live smoke / recording).
 *
 * Enabled when either:
 *   - HATFIELD_LLM_PROXY_DETERMINISTIC=1
 *   - LLAMA_CPP_SMOKE_TEST is set (Castor test:llm-real / test:controller)
 *
 * Does not affect DI-level fixture replay (HATFIELD_LLM_REPLAY_FIXTURE_PATH).
 *
 * fixedCwd() is for stable path attributes in AGENTS/skills user-context XML
 * only — not for the system prompt {cwd} placeholder (tools need the real cwd).
 * The system prompt uses an empty {date} in this mode (not fixedDate()).
 */
final readonly class LlmProxyDeterministicPromptMode
{
    public const FIXED_DATE = '2000-01-01';
    public const FIXED_CWD = '/hatfield/test-project';

    public function enabled(): bool
    {
        if ($this->envTruthy('HATFIELD_LLM_PROXY_DETERMINISTIC')) {
            return true;
        }

        return $this->envTruthy('LLAMA_CPP_SMOKE_TEST');
    }

    public function fixedDate(): string
    {
        $override = getenv('HATFIELD_LLM_PROXY_FIXED_DATE');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return self::FIXED_DATE;
    }

    public function fixedCwd(): string
    {
        $override = getenv('HATFIELD_LLM_PROXY_FIXED_CWD');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return self::FIXED_CWD;
    }

    private function envTruthy(string $name): bool
    {
        $value = getenv($name);
        if (false === $value || '' === $value) {
            return false;
        }

        return !\in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
    }
}
