<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing;

use Ineersa\AgentCore\Contract\Model\ImageCapabilityCheckerInterface;
use Ineersa\CodingAgent\Config\ModelSelectionService;

/**
 * Resolves whether the model associated with a run supports image/vision input.
 *
 * Bridges ModelSelectionService (session-model resolution) with
 * ImageCapabilityCheckerInterface (model-capability lookup). Exists as a
 * dedicated service so ViewImageTool can be tested without mocking the
 * final ModelSelectionService.
 */
readonly class RunVisionCheckService
{
    public function __construct(
        private ModelSelectionService $modelSelection,
        private ImageCapabilityCheckerInterface $imageCapabilityChecker,
    ) {
    }

    /**
     * Return true when the session associated with $runId uses a model
     * capable of image input, false otherwise.
     */
    public function isModelVisionCapable(string $runId): bool
    {
        $modelRef = $this->modelSelection->resolveInitialModel(
            explicitModel: null,
            sessionId: $runId,
        );

        if (null === $modelRef) {
            return false;
        }

        return $this->imageCapabilityChecker->supportsImages($modelRef->toString());
    }
}
