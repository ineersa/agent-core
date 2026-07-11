<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\ImagePaste\PastedImagePendingDTO;
use Ineersa\Tui\ImagePaste\PastedImageSubmissionService;
use Ineersa\Tui\ImagePaste\PastedImageValidationService;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\Test;

final class PastedImageSubmissionServiceTest extends IsolatedKernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('paste-submit');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function promotesReferencedPlaceholderIntoSessionAttachment(): void
    {
        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);

        $sessionId = $store->createSession('seed');
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        $staged = $this->projectDir.'/staged-paste-1.png';
        file_put_contents($staged, $png);

        $state = new TuiSessionState($sessionId);
        $state->pastedImagePendingByIndex[1] = new PastedImagePendingDTO(1, '[Image #1]', $staged);

        /** @var AppConfig $appConfig */
        $appConfig = self::getContainer()->get(AppConfig::class);

        $service = new PastedImageSubmissionService(
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            $store,
            $appConfig,
            new TranscriptBlockFactory(),
            new TestLogger(),
        );

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            $sessionId,
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );

        $resolved = $service->resolveSubmittedText('see [Image #1] please', $state, $screen);
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('view_image', $resolved);
        $this->assertStringContainsString('attachments/pasted-image-1.png', $resolved);
        $this->assertStringNotContainsString('[Image #1]', $resolved);

        $attachment = $store->resolveSessionsBasePath().'/'.$sessionId.'/attachments/pasted-image-1.png';
        $this->assertFileExists($attachment);
        $this->assertSame('0600', substr(\sprintf('%o', fileperms($attachment)), -4));
        $attachmentsDir = \dirname($attachment);
        $this->assertSame('0700', substr(\sprintf('%o', fileperms($attachmentsDir)), -4));
    }

    #[Test]
    public function secondPlaceholderFailureLeavesFirstRetryableWithoutOrphanAttachment(): void
    {
        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);

        $sessionId = $store->createSession('seed-multi-fail');
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);

        $staged1 = $this->projectDir.'/staged-paste-multi-1.png';
        file_put_contents($staged1, $png);

        $state = new TuiSessionState($sessionId);
        $state->pastedImagePendingByIndex[1] = new PastedImagePendingDTO(1, '[Image #1]', $staged1);
        // No pending entry for [Image #2] — preflight must fail before promoting #1.

        /** @var AppConfig $appConfig */
        $appConfig = self::getContainer()->get(AppConfig::class);

        $service = new PastedImageSubmissionService(
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            $store,
            $appConfig,
            new TranscriptBlockFactory(),
            new TestLogger(),
        );

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            $sessionId,
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );

        $attachmentsDir = $store->resolveSessionsBasePath().'/'.$sessionId.'/attachments';
        @unlink($attachmentsDir.'/pasted-image-1.png');

        $resolved = $service->resolveSubmittedText('one [Image #1] two [Image #2]', $state, $screen);
        $this->assertNull($resolved);
        $this->assertArrayHasKey(1, $state->pastedImagePendingByIndex);
        $this->assertFileExists($staged1);

        $this->assertFileDoesNotExist($attachmentsDir.'/pasted-image-1.png');

        $resolvedRetry = $service->resolveSubmittedText('retry [Image #1]', $state, $screen);
        $this->assertNotNull($resolvedRetry);
        $this->assertStringContainsString('attachments/pasted-image-1.png', $resolvedRetry);
    }
}
