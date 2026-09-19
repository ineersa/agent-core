<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\ImagePaste\ClipboardImageReadResultDTO;
use Ineersa\Tui\ImagePaste\PastedImagePendingDTO;
use Ineersa\Tui\ImagePaste\PastedImageSubmissionService;
use Ineersa\Tui\ImagePaste\PastedImageValidationService;
use Ineersa\Tui\Listener\ImagePasteInputListener;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Tui\Event\TickEvent;

final class PastedImageSubmissionServiceTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;

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
    public function resumedPasteUsesHighestAttachmentNumberAndPreservesQuotedText(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $initializer = self::getContainer()->get(SessionInitializer::class);
        $sessionId = $store->createSession('seed');
        $this->assertSame(1, $initializer->initialize($sessionId)->nextPastedImageIndex);
        $attachments = $store->ensureSessionAttachmentsDirectory($sessionId);
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        file_put_contents($attachments.'/pasted-image-1.png', $png);
        file_put_contents($attachments.'/pasted-image-7.webp', 'existing image bytes');
        file_put_contents($attachments.'/unrelated-100.png', 'not a pasted image');
        $staged = $this->projectDir.'/new-image.png';
        file_put_contents($staged, $png);

        $state = $initializer->initialize($sessionId);
        $state->handle = new RunHandle($sessionId);
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->with($sessionId, $this->callback(
            static fn (UserCommand $command): bool => 'follow_up' === $command->type
                && str_contains($command->text, 'Error: [Image #1]. [Image #8: ')
                && str_contains($command->text, '/attachments/pasted-image-8.png'),
        ));

        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())
            ->withState($state)->withClient($client)->withSessionStore($store)->build();
        self::getContainer()->get(SubmitListener::class)->register($context);
        (new ImagePasteInputListener(
            new FakeClipboardImageReader(ClipboardImageReadResultDTO::image($staged)),
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            new TranscriptBlockFactory(),
            new TestLogger(),
        ))->register($context);

        try {
            $harness->startInputLoop();
            $harness->sendInput('Error: [Image #1]. ');
            $harness->sendInput("\x16");
            $context->ticks->dispatch(new TickEvent());
            $this->assertSame('Error: [Image #1]. [Image #8]', $harness->screen()->editorText());
            $harness->sendInput("\r");

            $this->assertSame($png, file_get_contents($attachments.'/pasted-image-8.png'));
            $this->assertSame($png, file_get_contents($attachments.'/pasted-image-1.png'));
            $this->assertSame('existing image bytes', file_get_contents($attachments.'/pasted-image-7.webp'));
            $this->assertSame([], $state->pastedImagePendingByIndex);
            $this->assertSame(9, $initializer->initialize($sessionId)->nextPastedImageIndex);
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
    public function failedPromotionStillAllowsTypedAndPastedErrorTextToBeSubmitted(): void
    {
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $sessionId = $store->createSession('seed');
        $state = new TuiSessionState($sessionId);
        $state->handle = new RunHandle($sessionId);
        $staged = $this->projectDir.'/invalid-image.png';
        file_put_contents($staged, 'not an image');
        $state->pastedImagePendingByIndex[2] = new PastedImagePendingDTO($staged);
        $text = 'Image paste: Session attachment already exists for [Image #1].';
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(2))->method('send')->with($sessionId, $this->callback(
            static fn (UserCommand $command): bool => 'follow_up' === $command->type && $text === $command->text,
        ));
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())
            ->withState($state)->withClient($client)->withSessionStore($store)->build();
        self::getContainer()->get(SubmitListener::class)->register($context);

        try {
            $harness->startInputLoop();
            $harness->sendInput('[Image #2]');
            $harness->sendInput("\r");
            $this->assertStringContainsString('Image paste:', $harness->plainScreenText());
            $this->assertSame(RunActivityStateEnum::Idle, $state->activity);
            $this->assertArrayHasKey(2, $state->pastedImagePendingByIndex);

            foreach ([$text, "\x1b[200~".$text."\x1b[201~"] as $input) {
                $harness->sendInput($input);
                $this->assertSame($text, $harness->screen()->editorText());
                $harness->sendInput("\r");
                $this->assertSame(RunActivityStateEnum::Starting, $state->activity);
                $state->activity = RunActivityStateEnum::Idle;
            }
            $this->assertSame([], $state->pastedImagePendingByIndex);
            $this->assertFileDoesNotExist($staged);
        } finally {
            $harness->stopInputLoop();
        }
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
        $state->pastedImagePendingByIndex[1] = new PastedImagePendingDTO($staged);

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
    public function existingDestinationPathBlocksPromotionBeforeStaging(): void
    {
        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);

        $sessionId = $store->createSession('seed-existing-dest');
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);

        $staged1 = $this->projectDir.'/staged-existing-1.png';
        file_put_contents($staged1, $png);

        $state = new TuiSessionState($sessionId);
        $state->pastedImagePendingByIndex[1] = new PastedImagePendingDTO($staged1);

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

        // createSession only writes state/events; attachments are lazy. Ensure
        // the dir exists so a pre-existing destination path can block promotion
        // without relying on leftover FS from a prior method under shared CWD.
        $attachmentsDir = $store->ensureSessionAttachmentsDirectory($sessionId);
        touch($attachmentsDir.'/pasted-image-1.png');

        $resolved = $service->resolveSubmittedText('see [Image #1]', $state, $screen);
        $this->assertNull($resolved);
        $this->assertArrayHasKey(1, $state->pastedImagePendingByIndex);
        $this->assertFileExists($staged1);
    }

    #[Test]
    public function emptySessionIdReturnsNullWithoutPromotingPlaceholder(): void
    {
        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);

        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        $staged = $this->projectDir.'/staged-no-session.png';
        file_put_contents($staged, $png);

        $state = new TuiSessionState('');
        $state->pastedImagePendingByIndex[1] = new PastedImagePendingDTO($staged);

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
            '',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );

        $resolved = $service->resolveSubmittedText('see [Image #1]', $state, $screen);
        $this->assertNull($resolved);
        $this->assertArrayHasKey(1, $state->pastedImagePendingByIndex);
        $this->assertStringContainsString('[Image #1]', 'see [Image #1]');
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
        $state->pastedImagePendingByIndex[1] = new PastedImagePendingDTO($staged1);
        // A genuine staged image that disappeared must fail before promoting #1.
        $state->pastedImagePendingByIndex[2] = new PastedImagePendingDTO($this->projectDir.'/missing-image.png');

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
