<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ErrorCapture;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RuntimeErrorCaptureConfigTest extends TestCase
{
    #[Test]
    public function defaultConstructorEnablesCapture(): void
    {
        $config = new RuntimeErrorCaptureConfig();
        $this->assertTrue($config->captureErrors, 'captureErrors should be true by default');
    }

    #[Test]
    public function trueEnablesCapture(): void
    {
        $config = new RuntimeErrorCaptureConfig(captureErrors: true);
        $this->assertTrue($config->captureErrors);
    }

    #[Test]
    public function falseDisablesCapture(): void
    {
        $config = new RuntimeErrorCaptureConfig(captureErrors: false);
        $this->assertFalse($config->captureErrors);
    }

    #[Test]
    public function isMutablePerInstance(): void
    {
        $enabled = new RuntimeErrorCaptureConfig(captureErrors: true);
        $disabled = new RuntimeErrorCaptureConfig(captureErrors: false);
        $this->assertTrue($enabled->captureErrors);
        $this->assertFalse($disabled->captureErrors);
    }
}
