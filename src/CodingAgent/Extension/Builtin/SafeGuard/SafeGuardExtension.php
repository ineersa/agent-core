<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;

/**
 * SafeGuard bundled extension for Hatfield.
 *
 * Registers a tool-call hook that intercepts bash, write, edit, and read
 * tool invocations, classifies them against policy rules, and returns a
 * decision to block, allow, or request approval for risky operations.
 *
 * For policy-relaxable categories (destructive commands, dangerous git ops,
 * sensitive info access, writes outside CWD, protected reads), the hook
 * returns RequireApproval instead of Block, triggering the HITL approval
 * flow. The human can answer "✅ Allow" or "❌ Deny".
 *
 * Static allowlists (commands/paths/tools) come from settings/config only —
 * there is no interactive "Always allow" persistence.
 *
 * Enabled by default in hatfield.defaults.yaml under extensions.enabled.
 * Configured via extensions.settings.safe_guard in YAML config.
 *
 * @see SafeGuardConfig
 * @see SafeGuardToolCallHook
 * @see SafeGuardClassifier
 */
final readonly class SafeGuardExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $settings = $api->getSettings('safe_guard');
        $config = SafeGuardConfig::fromArray($settings);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $cwd = $api->getCwd();

        $api->registerToolCallHook(new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            cwd: $cwd,
            autoDenyInNoninteractive: $config->autoDenyInNoninteractive,
            settingsToolName: $config->settingsToolName,
        ));
    }
}
