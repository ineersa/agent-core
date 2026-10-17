<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRetainedToolCompletionBodyStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\RetainedOutputCapBodyPromoter;
use Symfony\Component\Filesystem\Filesystem;

final class RetainedOutputCapBodyPromoterTest extends IsolatedKernelTestCase
{
    public function testPromotesOwnedFullBodyAndIgnoresForeignOrSymlinkPaths(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $root = $sessions->createSession('retained-output-cap');
        /** @var SessionRetainedToolCompletionBodyStore $store */
        $store = self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class);

        $tmp = TestDirectoryIsolation::createOsTempDir('hatfield-retained-output-cap');
        try {
            $config = new OutputCapConfig(storageDir: $tmp);
            $promoter = new RetainedOutputCapBodyPromoter($config, $store);
            $sourceRunId = 'child-run-'.bin2hex(random_bytes(4));
            $scope = $tmp.'/run-'.hash('sha256', $sourceRunId);
            (new Filesystem())->mkdir($scope, 0700);
            $ownedPath = $scope.'/full-output.txt';
            file_put_contents($ownedPath, str_repeat('owned-output-', 200));

            $promoted = $promoter->promoteIfOwned(
                owningRootSessionId: $root,
                messageId: 'completion-output-cap',
                normalizedToolResult: [
                    'tool_name' => 'bash',
                    'content' => [['type' => 'text', 'text' => 'capped']],
                    'is_error' => false,
                    'details' => [
                        'output_cap' => [
                            'capped' => true,
                            'saved_path' => $ownedPath,
                        ],
                    ],
                ],
                sourceRunId: $sourceRunId,
            );
            $this->assertNotNull($promoted);
            $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $promoted->kind);
            $this->assertSame('text/plain', $promoted->mediaType);
            $this->assertTrue($store->isHeld($root, 'completion-output-cap'));
            $inspect = $store->inspectJson($promoted, $root);
            $this->assertFalse($inspect['truncated']);
            $this->assertStringContainsString('owned-output-', $inspect['text'] ?? '');

            unlink($ownedPath);
            $this->assertTrue($store->exists($promoted, $root));

            $foreignDir = TestDirectoryIsolation::createOsTempDir('hatfield-retained-output-cap-foreign');
            $foreignPath = $foreignDir.'/foreign.txt';
            file_put_contents($foreignPath, 'foreign');
            $this->assertNull($promoter->promoteIfOwned(
                owningRootSessionId: $root,
                messageId: 'completion-foreign',
                normalizedToolResult: [
                    'tool_name' => 'bash',
                    'content' => [['type' => 'text', 'text' => 'capped']],
                    'is_error' => false,
                    'details' => [
                        'output_cap' => [
                            'capped' => true,
                            'saved_path' => $foreignPath,
                        ],
                    ],
                ],
                sourceRunId: $sourceRunId,
            ));

            $linkPath = $scope.'/linked-output.txt';
            symlink($foreignPath, $linkPath);
            $this->assertNull($promoter->promoteIfOwned(
                owningRootSessionId: $root,
                messageId: 'completion-symlink',
                normalizedToolResult: [
                    'tool_name' => 'bash',
                    'content' => [['type' => 'text', 'text' => 'capped']],
                    'is_error' => false,
                    'details' => [
                        'output_cap' => [
                            'capped' => true,
                            'saved_path' => $linkPath,
                        ],
                    ],
                ],
                sourceRunId: $sourceRunId,
            ));
            TestDirectoryIsolation::removeDirectory($foreignDir);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }
}
