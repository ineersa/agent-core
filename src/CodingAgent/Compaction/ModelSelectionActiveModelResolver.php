<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\ChildRunDefinitionModelLookupInterface;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Production implementation of {@see ActiveModelResolverInterface}.
 *
 * Resolution order for a run id:
 *  1. Canonical child run UUID → durable child model only:
 *     deferred_subagent_child.definition_model, else run_started metadata.model
 *     for agent_child runs. Never cast a UUID into hatfield_session lookup.
 *  2. Canonical numeric normal session id / empty → ModelSelectionService
 *     session/default path (preserves /model changes for normal sessions).
 *  3. Any other id domain fails closed — never coerce labels into session lookup
 *     or global default.
 *
 * Missing child rows/metadata or empty/invalid child models fail closed with
 * structured diagnostics — never fall through to the global default for child UUIDs.
 */
final readonly class ModelSelectionActiveModelResolver implements ActiveModelResolverInterface
{
    public function __construct(
        private ModelSelectionService $modelSelectionService,
        private ChildRunDefinitionModelLookupInterface $childRunDefinitionModelLookup,
        private SubagentRunMetadataReader $childRunMetadataReader,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function resolveActiveModel(string $runId): ?string
    {
        if ($this->isChildRunUuid($runId)) {
            return $this->resolveChildRunModel($runId);
        }

        if ('' === $runId || ctype_digit($runId)) {
            return $this->modelSelectionService->resolveInitialModel(
                explicitModel: null,
                sessionId: $runId,
            )?->toString();
        }

        $this->logger->error('model.resolve.unknown_run_id_domain', [
            'event_type' => 'model.resolve.unknown_run_id_domain',
            'run_id' => $runId,
            'component' => 'model_selection',
        ]);

        throw new \RuntimeException(\sprintf('Cannot resolve model for run_id=%s: expected a numeric hatfield_session id or a deferred-subagent child UUID.', $runId));
    }

    public function getActiveModel(string $runId): ?string
    {
        return $this->resolveActiveModel($runId);
    }

    /**
     * Child deferred-subagent runs use UUID run ids. Normal sessions use pure
     * numeric hatfield_session ids. Arbitrary non-numeric labels are not a
     * supported execution identity domain.
     */
    private function isChildRunUuid(string $runId): bool
    {
        if ('' === $runId || ctype_digit($runId)) {
            return false;
        }

        return 1 === preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $runId,
        );
    }

    private function resolveChildRunModel(string $childRunId): string
    {
        $definitionModel = $this->childRunDefinitionModelLookup->findDefinitionModelByChildRunId($childRunId);
        if (null === $definitionModel || '' === trim($definitionModel)) {
            $definitionModel = $this->readAgentChildMetadataModel($childRunId);
        }

        if (null === $definitionModel) {
            $this->logger->error('model.resolve.child_run_unknown_or_model_missing', [
                'event_type' => 'model.resolve.child_run_unknown_or_model_missing',
                'run_id' => $childRunId,
                'component' => 'model_selection',
            ]);

            throw new \RuntimeException(\sprintf('Cannot resolve model for child run_id=%s: no durable child definition model or agent_child run_started model exists.', $childRunId));
        }

        $definitionModel = trim($definitionModel);
        if ('' === $definitionModel) {
            $this->logger->error('model.resolve.child_model_missing', [
                'event_type' => 'model.resolve.child_model_missing',
                'run_id' => $childRunId,
                'component' => 'model_selection',
            ]);

            throw new \RuntimeException(\sprintf('Cannot resolve model for child run_id=%s: durable child model is empty.', $childRunId));
        }

        $ref = AiModelReference::tryParse($definitionModel);
        if (null === $ref) {
            $this->logger->error('model.resolve.child_model_invalid', [
                'event_type' => 'model.resolve.child_model_invalid',
                'run_id' => $childRunId,
                'component' => 'model_selection',
            ]);

            throw new \RuntimeException(\sprintf('Cannot resolve model for child run_id=%s: definition_model is not a valid provider/model reference.', $childRunId));
        }

        // Validate availability against the configured catalog without falling
        // through to session/default tiers (explicit-only resolution).
        $resolved = $this->modelSelectionService->resolveInitialModel(
            explicitModel: $definitionModel,
            sessionId: '',
        );
        if (null === $resolved || $resolved->toString() !== $definitionModel) {
            $this->logger->error('model.resolve.child_model_unavailable', [
                'event_type' => 'model.resolve.child_model_unavailable',
                'run_id' => $childRunId,
                'requested_model' => $definitionModel,
                'component' => 'model_selection',
            ]);

            throw new \RuntimeException(\sprintf('Cannot resolve model for child run_id=%s: definition_model "%s" is not available in the model catalog.', $childRunId, $definitionModel));
        }

        return $definitionModel;
    }

    private function readAgentChildMetadataModel(string $childRunId): ?string
    {
        if (!$this->childRunMetadataReader->isAgentChild($childRunId)) {
            return null;
        }

        $metadata = $this->childRunMetadataReader->readRunStartedMetadata($childRunId);
        if (null === $metadata) {
            return null;
        }

        $model = $metadata['model'] ?? null;
        if (!\is_string($model)) {
            return null;
        }

        $model = trim($model);

        return '' !== $model ? $model : null;
    }
}
