<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Symfony\Component\Yaml\Yaml;

/**
 * Central model/reasoning selection with four-tier priority and persistence.
 *
 * Model resolution priority:
 *  1. explicit request (CLI --model, StartRunRequest.model)
 *  2. session metadata (model key in metadata.yaml)
 *  3. Hatfield ai.default_model
 *  4. first available configured model
 *
 * Reasoning mirrors model selection, falling back to medium.
 *
 * On change: persists both home default and session metadata.
 *
 * This service depends only on CodingAgent config services.
 * It does not import AgentCore, Tui, HttpFoundation, or FrameworkBundle.
 */
final class ModelSelectionService
{
    /** Valid reasoning levels. */
    public const LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    public function __construct(
        private readonly AppConfigResolver $configResolver,
        private readonly HomeSettingsWriter $homeWriter,
        private readonly SettingsPathResolver $pathResolver,
    ) {
    }

    // ──────────────────────────────────────────────
    //  Model resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve the initial model for a session.
     *
     * @param string|null $explicitModel Explicit request (e.g. "deepseek/deepseek-v4-pro")
     * @param string      $sessionId     Session ID for metadata lookup (empty for new sessions)
     * @param string      $projectCwd    Project working directory
     *
     * @return AiModelReference|null Null only if no models are configured at all
     */
    public function resolveInitialModel(
        ?string $explicitModel = null,
        string $sessionId = '',
        string $projectCwd = '',
    ): ?AiModelReference {
        $catalog = $this->createCatalog($projectCwd);

        // 1. Explicit request
        if (null !== $explicitModel) {
            $ref = AiModelReference::tryParse($explicitModel);
            if (null !== $ref && $catalog->isAvailable($ref)) {
                return $ref;
            }
        }

        // 2. Session metadata
        if ('' !== $sessionId) {
            $meta = $this->readSessionMetadata($sessionId, $projectCwd);
            $sessionModel = \is_string($meta['model'] ?? null) ? $meta['model'] : null;
            if (null !== $sessionModel) {
                $ref = AiModelReference::tryParse($sessionModel);
                if (null !== $ref && $catalog->isAvailable($ref)) {
                    return $ref;
                }
            }
        }

        // 3. Hatfield ai.default_model
        $defaultRef = $catalog->defaultModelReference();
        if (null !== $defaultRef && $catalog->isAvailable($defaultRef)) {
            return $defaultRef;
        }

        // 4. First available
        return $catalog->firstAvailableModel();
    }

    /**
     * Get all available model references for the current project.
     *
     * @return list<AiModelReference>
     */
    public function getAvailableModels(string $projectCwd = ''): array
    {
        return $this->createCatalog($projectCwd)->allModels();
    }

    // ──────────────────────────────────────────────
    //  Reasoning resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve the initial reasoning level for a session.
     *
     * @param string|null $explicitReasoning Explicit request (e.g. "high")
     * @param string      $sessionId         Session ID for metadata lookup
     * @param string      $projectCwd        Project working directory
     *
     * @return string A reasoning level from {@see LEVELS}
     */
    public function resolveInitialReasoning(
        ?string $explicitReasoning = null,
        string $sessionId = '',
        string $projectCwd = '',
    ): string {
        // 1. Explicit request
        if (null !== $explicitReasoning) {
            return $explicitReasoning;
        }

        // 2. Session metadata
        if ('' !== $sessionId) {
            $meta = $this->readSessionMetadata($sessionId, $projectCwd);
            $sessionReasoning = \is_string($meta['reasoning'] ?? null) ? $meta['reasoning'] : null;
            if (null !== $sessionReasoning) {
                return $sessionReasoning;
            }
        }

        // 3. Hatfield ai.default_reasoning
        $config = $this->configResolver->resolve($projectCwd);
        $defaultReasoning = $config->ai?->defaultReasoning;
        if (null !== $defaultReasoning && '' !== $defaultReasoning) {
            return $defaultReasoning;
        }

        // 4. Fallback
        return 'medium';
    }

    // ──────────────────────────────────────────────
    //  Persistence (model)
    // ──────────────────────────────────────────────

    /**
     * Change the model for the current session.
     *
     * Persists the new default to home settings and current state to
     * session metadata, so the next session picks up the same model
     * and a resumed session restores it from metadata.
     *
     * @throws \RuntimeException If the model is not available
     */
    public function changeModel(
        AiModelReference $model,
        string $sessionId,
        string $projectCwd = '',
    ): void {
        $catalog = $this->createCatalog($projectCwd);

        if (!$catalog->isAvailable($model)) {
            throw new \RuntimeException(\sprintf('Model "%s" is not available.', $model->toString()));
        }

        // Persist default to home settings
        $homePath = $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
        $this->homeWriter->writeDefaultModel($homePath, $model->toString());

        // Persist current state to session metadata
        $this->writeSessionMetadata($sessionId, $projectCwd, [
            'model' => $model->toString(),
            'model_provider' => $model->providerId,
            'model_name' => $model->modelName,
        ]);
    }

    // ──────────────────────────────────────────────
    //  Persistence (reasoning)
    // ──────────────────────────────────────────────

    /**
     * Change the reasoning level for the current session.
     *
     * @throws \InvalidArgumentException If the level is not a valid reasoning level
     */
    public function changeReasoning(
        string $level,
        string $sessionId,
        string $projectCwd = '',
    ): void {
        if (!\in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid reasoning level "%s". Valid levels: %s.', $level, implode(', ', self::LEVELS)));
        }

        // Ensure home settings exist (triggers bootstrap from defaults on first launch)
        $this->configResolver->resolve($projectCwd);

        // Persist default to home settings
        $homePath = $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml';
        $this->homeWriter->writeDefaultReasoning($homePath, $level);

        // Persist current state to session metadata
        $this->writeSessionMetadata($sessionId, $projectCwd, [
            'reasoning' => $level,
        ]);
    }

    // ──────────────────────────────────────────────
    //  Private: catalog
    // ──────────────────────────────────────────────

    /**
     * Build a HatfieldModelCatalog from the resolved AI config.
     */
    private function createCatalog(string $projectCwd): HatfieldModelCatalog
    {
        $config = $this->configResolver->resolve($projectCwd);
        $aiConfig = $config->ai ?? AiConfig::fromArray([]);

        return new HatfieldModelCatalog($aiConfig);
    }

    // ──────────────────────────────────────────────
    //  Private: session metadata
    // ──────────────────────────────────────────────

    /**
     * Read session metadata as an associative array.
     *
     * @return array<string, mixed> Empty array if the session/metadata don't exist
     */
    private function readSessionMetadata(string $sessionId, string $projectCwd): array
    {
        $path = $this->sessionMetadataPath($sessionId, $projectCwd);

        if (!is_readable($path)) {
            return [];
        }

        $data = Yaml::parseFile($path);

        return \is_array($data) ? $data : [];
    }

    /**
     * Write session metadata, merging $fields into the existing file.
     *
     * Preserves all existing metadata keys; only overwrites those
     * present in $fields. Updates the updated_at timestamp.
     *
     * @param array<string, string> $fields Key-value pairs to set
     */
    private function writeSessionMetadata(string $sessionId, string $projectCwd, array $fields): void
    {
        $existing = $this->readSessionMetadata($sessionId, $projectCwd);
        $merged = array_merge($existing, $fields);
        $merged['updated_at'] = date('c');

        $dir = \dirname($this->sessionMetadataPath($sessionId, $projectCwd));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $this->sessionMetadataPath($sessionId, $projectCwd),
            Yaml::dump($merged, 4, 2),
        );
    }

    /**
     * Full path to a session's metadata.yaml file.
     */
    private function sessionMetadataPath(string $sessionId, string $projectCwd): string
    {
        return $this->sessionBasePath($projectCwd).'/'.$sessionId.'/metadata.yaml';
    }

    /**
     * Resolve the sessions base directory from Hatfield config.
     *
     * Uses sessions.path from config or defaults to $cwd/.hatfield/sessions.
     */
    private function sessionBasePath(string $projectCwd): string
    {
        $current = getcwd();
        $cwd = '' !== $projectCwd ? $projectCwd : (false !== $current ? $current : '/');
        $config = $this->configResolver->resolve($cwd);
        $path = (string) ($config->sessions['path'] ?? '');

        if ('' === $path) {
            $path = rtrim($cwd, '/').'/.hatfield/sessions';
        }

        return $path;
    }
}
