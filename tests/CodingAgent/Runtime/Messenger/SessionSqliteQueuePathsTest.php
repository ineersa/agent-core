<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths
 */
final class SessionSqliteQueuePathsTest extends TestCase
{
    public function testForSessionBuildsDurableDatabaseAndPrivatePerLaunchEndpoint(): void
    {
        $left = SessionSqliteQueuePaths::forSession(
            '/tmp/hatfield-sessions',
            '/tmp/project-a',
            '42',
        );
        $right = SessionSqliteQueuePaths::forSession(
            '/tmp/hatfield-sessions',
            '/tmp/project-a',
            '42',
        );

        $this->assertSame('/tmp/hatfield-sessions/42/messenger-queue.sqlite', $left->databasePath);
        $this->assertSame($left->databasePath, $right->databasePath);
        $this->assertNotSame($left->endpointPath, $right->endpointPath, 'each launch must allocate a fresh private endpoint');
        $this->assertStringStartsWith('/tmp/hq-', $left->endpointDirectory());
        $this->assertSame($left->endpointDirectory().'/q.sock', $left->endpointPath);
        $this->assertLessThanOrEqual(SessionSqliteQueuePaths::MAX_ENDPOINT_BYTES, \strlen($left->endpointPath));

        $dsns = $left->transportDsns();
        $this->assertStringStartsWith('sqlite-queue://run_control?endpoint=', $dsns['HATFIELD_RUN_CONTROL_TRANSPORT_DSN']);
        $this->assertStringContainsString(rawurlencode($left->endpointPath), $dsns['HATFIELD_LLM_TRANSPORT_DSN']);
        $this->assertStringNotContainsString('run_control_42', $dsns['HATFIELD_RUN_CONTROL_TRANSPORT_DSN']);
    }

    public function testConstructorRejectsTraversalAndUnknownSessionIdentities(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported session identity');
        new SessionSqliteQueuePaths('../escape', '/tmp/db.sqlite', '/tmp/hq-aaaaaaaaaaaaaaaa/q.sock');
    }

    public function testForSessionRejectsTraversalIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported session identity');
        SessionSqliteQueuePaths::forSession('/tmp/sessions', '/tmp/root', '../escape');
    }
}
