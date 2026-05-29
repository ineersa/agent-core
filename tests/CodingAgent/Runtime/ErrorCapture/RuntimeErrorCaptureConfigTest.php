<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ErrorCapture;

use Ineersa\CodingAgent\Runtime\ErrorCapture\RuntimeErrorCaptureConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RuntimeErrorCaptureConfigTest extends TestCase
{
    #[Test]
    public function defaultsToEnabledWhenNoEnvVarSet(): void
    {
        // Simulate no env var by constructing with null.
        $config = new RuntimeErrorCaptureConfig(envValue: null);
        $this->assertTrue($config->captureErrors, 'captureErrors should be true by default');
    }

    #[Test]
    public function envVarOneEnablesCapture(): void
    {
        $config = new RuntimeErrorCaptureConfig(envValue: '1');
        $this->assertTrue($config->captureErrors);
    }

    #[Test]
    public function envVarZeroDisablesCapture(): void
    {
        $config = new RuntimeErrorCaptureConfig(envValue: '0');
        $this->assertFalse($config->captureErrors);
    }

    #[Test]
    public function envVarEmptyStringDefaultsToEnabled(): void
    {
        // An empty string does not equal '1', so it should be disabled
        // (preserving the original behavior: only explicit '1' enables).
        $config = new RuntimeErrorCaptureConfig(envValue: '');
        $this->assertFalse($config->captureErrors);
    }

    #[Test]
    public function envVarOtherValueDefaultsToDisabled(): void
    {
        // Anything other than '1' disables capture (crash mode).
        $config = new RuntimeErrorCaptureConfig(envValue: 'true');
        $this->assertFalse($config->captureErrors);
    }
}
