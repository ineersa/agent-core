<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * Thin Symfony model catalog projected from Hatfield metadata.
 *
 * Each configured model is projected to {@see CompletionsModel} with
 * capabilities derived from Hatfield's rich model definitions.
 * Unknown models are not supported — only explicitly listed models
 * are registered.
 *
 * This catalog is used for Symfony AI Platform routing and capability
 * checks. It does not carry cost, context window, thinking-level maps,
 * compatibility quirks, or any other Hatfield metadata.
 */
final class ProjectedSymfonyModelCatalog extends AbstractModelCatalog
{
    /**
     * @param array<string, AiModelDefinition> $hatfieldModels Model name → definition
     */
    public function __construct(array $hatfieldModels)
    {
        $this->models = [];

        foreach ($hatfieldModels as $modelName => $modelDef) {
            $capabilities = $this->capabilitiesFor($modelDef);

            $this->models[$modelName] = [
                'class' => CompletionsModel::class,
                'capabilities' => $capabilities,
            ];
        }
    }

    /**
     * Support provider-qualified model names (e.g. "llama_cpp/flash").
     *
     * Strip a provider prefix before delegating to parent logic,
     * so that "llama_cpp/flash:23b" becomes "flash:23b" and the
     * parent's size-variant handling (the ":" separator) still works.
     *
     * Only strip when the catalog does NOT contain the full
     * qualified name as a direct key.
     */
    protected function parseModelName(string $modelName): array
    {
        $stripped = $modelName;
        if (!isset($this->models[$stripped]) && str_contains($stripped, '/')) {
            $bare = explode('/', $stripped, 2)[1] ?? '';
            if ('' !== $bare) {
                // Accept "flash" directly, or "flash:23b" if the bare
                // part after ":" maps to a known model.
                if (isset($this->models[$bare])) {
                    $stripped = $bare;
                } elseif (str_contains($bare, ':') && isset($this->models[explode(':', $bare, 2)[0]])) {
                    $stripped = $bare;
                }
            }
        }

        return parent::parseModelName($stripped);
    }

    /**
     * Derive Symfony AI capabilities from a Hatfield model definition.
     *
     * @return list<Capability>
     */
    private function capabilitiesFor(AiModelDefinition $modelDef): array
    {
        $capabilities = [
            Capability::INPUT_MESSAGES,
            Capability::OUTPUT_TEXT,
            Capability::OUTPUT_STREAMING,
        ];

        if ($modelDef->toolCalling) {
            $capabilities[] = Capability::TOOL_CALLING;
        }

        if ($modelDef->reasoning) {
            $capabilities[] = Capability::THINKING;
        }

        if (\in_array('image', $modelDef->input, true)) {
            $capabilities[] = Capability::INPUT_IMAGE;
        }

        return $capabilities;
    }
}
