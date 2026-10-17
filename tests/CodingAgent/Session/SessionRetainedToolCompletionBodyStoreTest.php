<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRetainedToolCompletionBodyStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

final class SessionRetainedToolCompletionBodyStoreTest extends IsolatedKernelTestCase
{
    public function testSealReadConflictSymlinkForeignAndSessionDeletion(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $root = $sessions->createSession('retained-body');
        /** @var SessionRetainedToolCompletionBodyStore $store */
        $store = self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class);

        $json = json_encode(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => str_repeat('x', 9000)]]], \JSON_THROW_ON_ERROR);
        $ref = $store->sealJson($root, 'completion-1', $json, 'application/json');
        $this->assertTrue($store->exists($ref, $root));
        $read = $store->readJson($ref, $root);
        $this->assertSame(9000, \strlen($read['json']['content'][0]['text']));

        $duplicate = $store->sealJson($root, 'completion-1', $json, 'application/json');
        $this->assertSame($ref->sha256, $duplicate->sha256);

        try {
            $store->sealJson($root, 'completion-1', '{"changed":true}', 'application/json');
            $this->fail('Expected conflict.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::Conflict, $failure->code());
        }

        try {
            $store->readJson($ref, 'not-'.$root);
            $this->fail('Expected foreign scope.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }

        $path = $sessions->resolveSessionsBasePath().'/'.$root.'/runtime/retained-tool-completions/completion-1.json';
        $this->assertFileExists($path);
        unlink($path);
        symlink('/tmp/foreign-retained-body', $path);
        try {
            $store->readJson($ref, $root);
            $this->fail('Expected missing/corrupt for symlink.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertContains($failure->code(), [
                RetainedToolCompletionFailureCodeEnum::BodyMissing,
                RetainedToolCompletionFailureCodeEnum::BodyCorrupt,
            ]);
        } finally {
            @unlink($path);
        }

        // Re-seal and prove authorized root deletion removes owned evidence.
        $store->sealJson($root, 'completion-1', $json, 'application/json');
        $this->assertTrue($store->exists($ref, $root));
        $sessions->deleteSession($root);
        $this->assertFalse(is_dir($sessions->resolveSessionsBasePath().'/'.$root));
    }

    public function testOrdinaryStoreConstructionRejectsPathTraversalMessageIds(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $root = $sessions->createSession('retained-body-path');
        $store = new SessionRetainedToolCompletionBodyStore(
            $sessions,
            self::getContainer()->get(LockFactory::class),
            new Filesystem(),
        );

        try {
            $store->sealJson($root, '../escape', '{"a":1}', 'application/json');
            $this->fail('Expected foreign/path rejection.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }
    }
}
