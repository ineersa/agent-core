<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;

final readonly class SemanticIndexStartupHook implements AfterSessionStartHookInterface
{
    public function __construct(private ExtensionApiInterface $api, private OmSettings $settings)
    {
    }

    public function onAfterSessionStart(AfterSessionStartHookContextDTO $context): void
    {
        SemanticIndexJobHandler::schedule($this->api, $this->settings, $context->runId);
    }
}
