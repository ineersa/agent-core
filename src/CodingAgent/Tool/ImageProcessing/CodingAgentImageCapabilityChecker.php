<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing;

use Ineersa\AgentCore\Contract\Model\ImageCapabilityCheckerInterface;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;

/**
 * Checks image capability using the Hatfield model catalog.
 *
 * The catalog's {@see AiModelDefinition::$input} array lists accepted
 * input modalities. When 'image' is present, the model supports
 * image inputs.
 */
final readonly class CodingAgentImageCapabilityChecker implements ImageCapabilityCheckerInterface
{
    public function __construct(
        private ?HatfieldModelCatalog $catalog,
    ) {
    }

    /**
     * DI factory — extract the model catalog from AppConfig.
     *
     * Used by the Symfony container via services.yaml factory definition
     * so that autowired consumers receive the same catalog that lives
     * inside AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return new self($appConfig->catalog);
    }

    public function supportsImages(string $modelName): bool
    {
        if (null === $this->catalog || '' === $modelName) {
            return false;
        }

        $model = $this->catalog->getModel($modelName);
        if (null === $model) {
            return false;
        }

        return \in_array('image', $model->input, true);
    }
}
