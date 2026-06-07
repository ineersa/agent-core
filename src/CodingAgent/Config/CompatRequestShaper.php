<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ProviderRequestOptionKeys;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Applies provider/model compatibility quirks to invocation options and
 * flags unsupported roles before the Symfony AI Platform invokes a provider.
 *
 * Registered as a {@see BeforeProviderRequestHookInterface} so it runs
 * inside {@see BeforeProviderRequestSubscriber} without that class needing
 * to know about CodingAgent internals.
 *
 * The shaper reads the reasoning level from the internal
 * {@see self::REASONING_KEY} option. When present and the model supports
 * reasoning, it delegates to {@see ReasoningOptionsResolver} and merges the
 * resulting options (enable_thinking, reasoning_effort, etc.).
 *
 * Providers that disable the developer role get a
 * {@see self::SUPPRESS_DEVELOPER_ROLE_KEY} flag emitted into the options.
 * Message converters downstream should check that flag before emitting
 * developer/system differentiation.
 */
final class CompatRequestShaper implements BeforeProviderRequestHookInterface
{
    /**
     * Internal option key carrying the user-facing reasoning level
     * (off|minimal|low|medium|high|xhigh). Stripped before options reach
     * the provider.
     */
    public const string REASONING_KEY = ProviderRequestOptionKeys::REASONING;

    /**
     * Internal option key signaling that the provider does not support the
     * OpenAI developer role. Message converters should suppress developer
     * messages when this flag is present.
     */
    public const string SUPPRESS_DEVELOPER_ROLE_KEY = ProviderRequestOptionKeys::SUPPRESS_DEVELOPER_ROLE;

    public function __construct(
        private readonly HatfieldModelCatalog $catalog,
    ) {
    }

    public function beforeProviderRequest(
        string $model,
        array $input,
        array $options,
        ?CancellationTokenInterface $cancelToken = null,
    ): ?ProviderRequest {
        // Always extract and strip the reasoning key before any early returns,
        // so it never leaks into provider request bodies when model lookup fails.
        $reasoningLevel = \is_string($options[self::REASONING_KEY] ?? null) ? $options[self::REASONING_KEY] : null;
        $newOptions = $options;
        unset($newOptions[self::REASONING_KEY]);

        $ref = $this->findModelRef($model);

        if (null === $ref) {
            return $newOptions !== $options ? new ProviderRequest(options: $newOptions) : null;
        }

        $modelDef = $this->catalog->getModel($ref);

        if (null === $modelDef) {
            return $newOptions !== $options ? new ProviderRequest(options: $newOptions) : null;
        }

        // ── Reasoning options ──
        if (null !== $reasoningLevel) {
            $resolver = new ReasoningOptionsResolver($this->catalog);
            $reasoningOptions = $resolver->resolve($ref, $reasoningLevel);
            if ([] !== $reasoningOptions) {
                $newOptions = array_merge($newOptions, $reasoningOptions);
            }
        }

        // ── Developer-role suppression flag ──
        $compat = $modelDef->compatibility
            ?? $this->catalog->getProvider($ref->providerId)?->compatibility;

        if (null !== $compat && !$compat->supportsDeveloperRole) {
            $newOptions[self::SUPPRESS_DEVELOPER_ROLE_KEY] = true;
        }

        // Return null when nothing changed — the subscriber no-ops.
        if ($newOptions === $options) {
            return null;
        }

        return new ProviderRequest(options: $newOptions);
    }

    /**
     * Walk all configured models to find the AiModelReference whose
     * modelName matches the raw name being sent to the platform.
     */
    private function findModelRef(string $modelName): ?AiModelReference
    {
        foreach ($this->catalog->allModels() as $ref) {
            if ($ref->modelName === $modelName) {
                return $ref;
            }
        }

        return null;
    }
}
