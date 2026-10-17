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
    public function testSealInspectConflictSymlinkForeignAncestorAndSessionDeletion(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $root = $sessions->createSession('retained-body');
        /** @var SessionRetainedToolCompletionBodyStore $store */
        $store = self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class);

        $json = json_encode(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => str_repeat('x', 9000)]]], \JSON_THROW_ON_ERROR);
        $stream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($stream);
        fwrite($stream, $json);
        rewind($stream);
        $ref = $store->sealJsonStream($root, 'completion-1', $stream, 'application/json');
        fclose($stream);
        $this->assertTrue($store->exists($ref, $root));
        $this->assertTrue($store->isHeld($root, 'completion-1'));
        $read = $store->inspectJson($ref, $root);
        $this->assertFalse($read['truncated']);
        $this->assertSame(9000, \strlen($read['json']['content'][0]['text']));

        $dupStream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($dupStream);
        fwrite($dupStream, $json);
        rewind($dupStream);
        $duplicate = $store->sealJsonStream($root, 'completion-1', $dupStream, 'application/json');
        fclose($dupStream);
        $this->assertSame($ref->sha256, $duplicate->sha256);

        $conflict = fopen('php://temp', 'r+b');
        $this->assertNotFalse($conflict);
        fwrite($conflict, '{"changed":true}');
        rewind($conflict);
        try {
            $store->sealJsonStream($root, 'completion-1', $conflict, 'application/json');
            $this->fail('Expected conflict.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::Conflict, $failure->code());
        } finally {
            fclose($conflict);
        }

        try {
            $store->inspectJson($ref, 'not-'.$root);
            $this->fail('Expected foreign scope.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }

        $path = $sessions->resolveSessionsBasePath().'/'.$root.'/runtime/retained-tool-completions/completion-1.json';
        $this->assertFileExists($path);
        unlink($path);
        symlink('/tmp/foreign-retained-body', $path);
        try {
            $store->inspectJson($ref, $root);
            $this->fail('Expected missing/corrupt for symlink.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertContains($failure->code(), [
                RetainedToolCompletionFailureCodeEnum::BodyMissing,
                RetainedToolCompletionFailureCodeEnum::BodyCorrupt,
                RetainedToolCompletionFailureCodeEnum::ForeignScope,
            ]);
        } finally {
            @unlink($path);
        }

        // Ancestor symlink redirection must also fail closed.
        $runtime = $sessions->resolveSessionsBasePath().'/'.$root.'/runtime';
        $bodyDir = $runtime.'/retained-tool-completions';
        $this->assertTrue(is_dir($bodyDir));
        rename($bodyDir, $bodyDir.'.real');
        symlink($bodyDir.'.real', $bodyDir);
        $ancestorStream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($ancestorStream);
        fwrite($ancestorStream, '{"tool_name":"bash","content":[{"type":"text","text":"x"}]}');
        rewind($ancestorStream);
        try {
            $store->sealJsonStream($root, 'completion-ancestor', $ancestorStream, 'application/json');
            $this->fail('Expected ancestor symlink refusal.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertContains($failure->code(), [
                RetainedToolCompletionFailureCodeEnum::ForeignScope,
                RetainedToolCompletionFailureCodeEnum::BodyCorrupt,
            ]);
        } finally {
            fclose($ancestorStream);
            @unlink($bodyDir);
            @rename($bodyDir.'.real', $bodyDir);
        }

        $restream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($restream);
        fwrite($restream, $json);
        rewind($restream);
        $store->sealJsonStream($root, 'completion-1', $restream, 'application/json');
        fclose($restream);
        $this->assertTrue($store->isHeld($root, 'completion-1'));
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

        $stream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($stream);
        fwrite($stream, '{"a":1}');
        rewind($stream);
        try {
            $store->sealJsonStream($root, '../escape', $stream, 'application/json');
            $this->fail('Expected foreign/path rejection.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        } finally {
            fclose($stream);
        }
    }
}
