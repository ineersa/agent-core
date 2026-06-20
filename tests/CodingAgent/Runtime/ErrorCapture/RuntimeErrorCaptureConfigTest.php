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
        self::assertTrue($config->captureErrors, 'captureErrors should be true by default');
    }

    #[Test]
    public function trueEnablesCapture(): void
    {
        $config = new RuntimeErrorCaptureConfig(captureErrors: true);
        self::assertTrue($config->captureErrors);
    }

    #[Test]
    public function falseDisablesCapture(): void
    {
        $config = new RuntimeErrorCaptureConfig(captureErrors: false);
        self::assertFalse($config->captureErrors);
    }

    #[Test]
    public function isMutablePerInstance(): void
    {
        $enabled = new RuntimeErrorCaptureConfig(captureErrors: true);
        $disabled = new RuntimeErrorCaptureConfig(captureErrors: false);
        self::assertTrue($enabled->captureErrors);
        self::assertFalse($disabled->captureErrors);
    }
}
