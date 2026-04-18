<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Run\RunId;
use PHPUnit\Framework\TestCase;

final class RunIdTest extends TestCase
{
    public function testGenerateReturnsUuidV4LikeIdentifier(): void
    {
        $runId = (string) RunId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $runId);
    }
}
