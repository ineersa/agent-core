<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * Initialises TuiSessionState fields needed by the footer.
 *
 * Seeds model/reasoning from session metadata, request, or AppConfig
 * fallback. Detects cwd (short: last 2 path segments) and git branch.
 * Looks up context window from the Hatfield catalog. Sets session
 * start time on first call.
 */
final readonly class FooterStateInitializer
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private AppConfig $appConfig,
    ) {
    }

    public function initialize(TuiSessionState $state): void
    {
        // Seed model/reasoning from session metadata
        $fullModel = '';
        $reasoning = '';
        $session = $this->sessionStore->findSession($state->sessionId);
        if (null !== $session) {
            $fullModel = $session->model ?? '';
            $reasoning = $session->reasoning ?? '';
        }

        // Fallback: StartRunRequest (first run before session persisted)
        if ('' === $fullModel && null !== $state->request) {
            $fullModel = $state->request->model ?? '';
            $reasoning = $state->request->reasoning ?? '';
        }

        // Fallback: AppConfig default model
        if ('' === $fullModel && null !== $this->appConfig->ai) {
            $defaultModel = $this->appConfig->ai->defaultModel;
            if (null !== $defaultModel && '' !== $defaultModel) {
                $fullModel = $defaultModel;
            }

            if ('' === $reasoning && null !== $this->appConfig->ai->defaultReasoning && '' !== $this->appConfig->ai->defaultReasoning) {
                $reasoning = $this->appConfig->ai->defaultReasoning;
            }
        }

        $state->footerModel = self::shortModelName($fullModel);
        $state->footerReasoning = self::clampReasoningForModel($this->appConfig, $fullModel, $reasoning);
        $state->contextWindow = self::resolveContextWindow($this->appConfig, $fullModel);

        if (0.0 === $state->sessionStartTime) {
            $state->sessionStartTime = microtime(true);
        }

        $cwd = getcwd();
        $state->cwd = false !== $cwd ? self::shortCwd($cwd) : '';
        $state->branch = self::detectGitBranch();
    }

    // ── Helpers ──

    public static function detectGitBranch(): string
    {
        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];

        $process = @proc_open(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            $descriptors,
            $pipes,
        );

        if (!\is_resource($process)) {
            return '';
        }

        try {
            $stdout = stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        }

        if (0 !== $exitCode) {
            return '';
        }

        $branch = trim((string) $stdout);

        return '' !== $branch ? $branch : '';
    }

    public static function shortModelName(string $model): string
    {
        $slash = strpos($model, '/');
        if (false !== $slash) {
            return substr($model, $slash + 1);
        }

        return $model;
    }

    public static function shortCwd(string $path): string
    {
        $parts = explode('/', $path);
        $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));

        if (\count($parts) >= 2) {
            return $parts[\count($parts) - 2].'/'.$parts[\count($parts) - 1];
        }

        return $parts[0] ?? '';
    }

    /**
     * Resolve context window for an already-parsed model reference.
     *
     * Public so that callers doing model selection / footer update
     * (e.g. ModelControlListener, ModelCommandHandler,
     * ModelPickerController) can share the same catalog lookup
     * without duplicating the logic.
     */
    public static function resolveContextWindowForRef(AppConfig $appConfig, AiModelReference $ref): int
    {
        $catalog = $appConfig->catalog;
        if (null === $catalog) {
            return 0;
        }

        $definition = $catalog->getModel($ref);

        return null !== $definition ? ($definition->contextWindow ?? 0) : 0;
    }

    /**
     * Clamp the reasoning level for the resolved model.
     *
     * When the active model does not support thinking levels (e.g. llama_cpp
     * without reasoning:true or a provider with supports_thinking_levels: false),
     * the footer diamond/model colour must be reset to 'off' regardless of what
     * session metadata / defaults / request carry.  Otherwise a stale high/xhigh
     * level from a previous thinking-capable session leaks into a non-thinking
     * session and confuses the user with an incorrect colour indicator.
     */
    private static function clampReasoningForModel(
        AppConfig $appConfig,
        string $fullModel,
        string $reasoning,
    ): string {
        if ('' === $fullModel || '' === $reasoning) {
            return $reasoning;
        }

        $catalog = $appConfig->catalog;
        if (null === $catalog) {
            return $reasoning;
        }

        $ref = AiModelReference::tryParse($fullModel);
        if (null === $ref) {
            return $reasoning;
        }

        // Non-thinking model: force reasoning to 'off' so the footer
        // colour indicator stays neutral.
        if (!$catalog->supportsThinkingLevels($ref)) {
            return 'off';
        }

        return $reasoning;
    }

    private static function resolveContextWindow(AppConfig $appConfig, string $fullModel): int
    {
        if ('' === $fullModel) {
            return 0;
        }

        $ref = AiModelReference::tryParse($fullModel);

        return null !== $ref ? self::resolveContextWindowForRef($appConfig, $ref) : 0;
    }
}
