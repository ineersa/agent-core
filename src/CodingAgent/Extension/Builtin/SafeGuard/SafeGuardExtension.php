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
 * block decision for dangerous/risky operations.
 *
 * When autoDenyInNoninteractive is false, policy-relaxable categories
 * (destructive, dangerous_git, etc.) return RequireApproval instead of
 * Block, enabling the interactive HITL approval flow.
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

        $eventReader = new SessionEventReader($cwd);
        $tracker = new ApprovalSessionTracker($eventReader);
        $policyWriter = new SafeGuardPolicyWriter($cwd.'/.hatfield/settings.yaml');

        $api->registerToolCallHook(new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            tracker: $tracker,
            policyWriter: $policyWriter,
            autoDenyInNoninteractive: $config->autoDenyInNoninteractive,
            cwd: $cwd,
        ));
    }
}
