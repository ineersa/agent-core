<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCliErrorClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextCliErrorClassifierTest extends TestCase
{
    #[Test]
    public function classifiesKnownAuthenticationRequiredStderr(): void
    {
        $stderr = "Authentication required\nfrom ai.grazie.indexing.code.cli.command.util.IsLoggedInGuard";

        $this->assertSame(
            JbcontextCliErrorClassifier::AUTH_REQUIRED,
            JbcontextCliErrorClassifier::classifyEmptyStdout($stderr),
        );
        $this->assertSame(
            JbcontextCliErrorClassifier::AUTH_USER_GUIDANCE,
            JbcontextCliErrorClassifier::userGuidance(JbcontextCliErrorClassifier::AUTH_REQUIRED),
        );
        $this->assertStringContainsString('jbcontext login', JbcontextCliErrorClassifier::AUTH_USER_GUIDANCE);
        $this->assertStringNotContainsString($stderr, (string) JbcontextCliErrorClassifier::userGuidance(JbcontextCliErrorClassifier::AUTH_REQUIRED));
    }

    #[Test]
    public function leavesUnknownEmptyStdoutUnclassified(): void
    {
        $this->assertSame(
            JbcontextCliErrorClassifier::EMPTY_STDOUT,
            JbcontextCliErrorClassifier::classifyEmptyStdout('boom'),
        );
        $this->assertNull(JbcontextCliErrorClassifier::userGuidance(JbcontextCliErrorClassifier::EMPTY_STDOUT));
    }

    #[Test]
    public function doesNotTreatTokenBearingStderrAsAuthWithoutKnownPhrase(): void
    {
        $secret = 'token=super-secret-value';
        $this->assertSame(
            JbcontextCliErrorClassifier::EMPTY_STDOUT,
            JbcontextCliErrorClassifier::classifyEmptyStdout($secret),
        );
        $this->assertNull(JbcontextCliErrorClassifier::userGuidance(JbcontextCliErrorClassifier::EMPTY_STDOUT));
    }
}
