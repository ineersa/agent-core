<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Agent;

/**
 * Public request for one isolated Hatfield agent invocation.
 *
 * Model must be an exact configured provider/model reference such as
 * `openai/gpt-5` or `llama_cpp_test/test`. Alias forms (for example `@compaction`)
 * are not accepted by this API.
 *
 * Optional maxToolCalls bounds the native AgentProcessor tool loop. When null,
 * Hatfield uses the framework default (50). Callers that need exactly-one tool
 * recording (Observer) should set a small positive limit (for example 3) so a
 * model can correct once or twice without unbounded tool loops.
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
     * @param int|null           $maxToolCalls  optional AgentProcessor tool-loop ceiling (>= 1); null = framework default
     */
    public function __construct(
        public string $model,
        public string $sessionId,
        public string $instructions,
        public string $input,
        public array $tools = [],
        public ?string $correlationId = null,
        public ?int $maxToolCalls = null,
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

        if (null !== $this->maxToolCalls && $this->maxToolCalls < 1) {
            throw new \InvalidArgumentException('Agent maxToolCalls must be null or a positive integer.');
        }

        foreach ($this->tools as $tool) {
            if (!$tool instanceof AgentToolDTO) {
                throw new \InvalidArgumentException('Agent tools must be a list of AgentToolDTO.');
            }
        }
    }
}
