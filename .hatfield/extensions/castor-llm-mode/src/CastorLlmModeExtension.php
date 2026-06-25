<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\CastorLlmMode;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;

final readonly class CastorLlmModeExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $settings = $api->getSettings('castor_llm_mode');
        $enabled = (bool) ($settings['enabled'] ?? true);
        if (!$enabled) {
            return;
        }

        $api->registerToolCallRewriteHook('bash', new CastorLlmModeToolCallHook(new CastorCommandRewriter()));
    }
}
