<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Agent;

/**
 * Public request for one isolated Hatfield agent invocation.
 *
 * Model must be an exact configured provider/model reference such as
 * `openai/gpt-5` or `llama_cpp_test/test`. Alias forms (for example `@compaction`)
 * are not accepted by this API.
 */
final readonly class AgentCallRequestDTO
{
    /**
     * @param string             $model         exact configured `provider/model` reference
     * @param string             $sessionId     session/run correlation id (often session_id === run_id)
     * @param string             $instructions  system instructions for this invocation
     * @param string             $input         user/input content for this invocation
     * @param list<AgentToolDTO> $tools         isolated tools available only for this call
     * @param string|null        $correlationId optional extra correlation token for diagnostics
     */
    public function __construct(
        public string $model,
        public string $sessionId,
        public string $instructions,
        public string $input,
        public array $tools = [],
        public ?string $correlationId = null,
    ) {
        if ('' === trim($this->model)) {
            throw new \InvalidArgumentException('Agent model must be a non-empty exact provider/model reference.');
        }

        if (str_starts_with(trim($this->model), '@')) {
            throw new \InvalidArgumentException('Agent model aliases are not supported; use an exact provider/model reference.');
        }

        if (!str_contains($this->model, '/')) {
            throw new \InvalidArgumentException('Agent model must be an exact provider/model reference (provider/model).');
        }

        if ('' === trim($this->sessionId)) {
            throw new \InvalidArgumentException('Agent sessionId must be a non-empty string.');
        }

        foreach ($this->tools as $tool) {
            if (!$tool instanceof AgentToolDTO) {
                throw new \InvalidArgumentException('Agent tools must be a list of AgentToolDTO.');
            }
        }
    }
}
