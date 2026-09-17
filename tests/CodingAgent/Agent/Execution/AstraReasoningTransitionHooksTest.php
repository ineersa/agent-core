<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningContentFeatureShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
use Ineersa\CodingAgent\Agent\Execution\AstraReasoningTransitionRequestHook;
use Ineersa\CodingAgent\Agent\Execution\AstraReasoningTransitionTransformHook;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapLlmTransformHook;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class AstraReasoningTransitionHooksTest extends IsolatedKernelTestCase
{
    private string $tempDir;
    private string $homeDir;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('astra-transition-hooks', 0o750);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        TestDirectoryIsolation::createHatfieldTree($this->homeDir);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testContainerTaggedHooksExecuteThroughPreparerAndPreserveMarkersAcrossCompatConversion(): void
    {
        $container = static::getContainer();
        /** @var HatfieldSessionStore $store */
        $store = $container->get(HatfieldSessionStore::class);
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';

        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $decision = $store->claimReasoningBaseline($sessionId, $modelRef, 'high');
        $this->assertSame('high', $decision['update'] ?? null);

        $history = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'first']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'second']]),
        ];
        $messageKey = AstraReasoningTransitionTransformHook::messageKeyInHistory($history, 1);
        $this->assertNotNull($messageKey);
        $store->rememberReasoningTransition($sessionId, $modelRef, $messageKey, 'high');

        /** @var AstraReasoningTransitionTransformHook $transformHook */
        $transformHook = $container->get(AstraReasoningTransitionTransformHook::class);
        $marked = $transformHook->transformContext($history, null, $sessionId);
        $this->assertSame('high', $marked[1]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);
        $this->assertSame($messageKey, $marked[1]->metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY] ?? null);

        $bag = (new AgentMessageConverter())->toMessageBagForTarget($marked, $modelRef);
        $this->assertSame(
            'high',
            $bag->withoutSystemMessage()->getMessages()[1]->getMetadata()->get(CodexReasoningTransitionMetadata::KEY),
        );
        $this->assertSame(
            $messageKey,
            $bag->withoutSystemMessage()->getMessages()[1]->getMetadata()->get(CodexReasoningTransitionMetadata::MESSAGE_KEY),
        );

        $resolved = new ResolvedModel(
            model: $modelRef,
            providerId: 'openai-codex',
            reasoning: 'high',
            providerOptions: [],
            compatFeatures: [
                ReasoningOptionsFeatureShaper::FEATURE,
                ReasoningContentFeatureShaper::FEATURE,
            ],
            reasoningOptions: [
                'reasoning' => ['effort' => 'medium', 'summary' => 'auto'],
                CodexRequestBodyFactory::REASONING_UPDATE => 'low',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        /** @var ProviderRequestPreparer $preparer */
        $preparer = $container->get(ProviderRequestPreparer::class);
        $prepared = $preparer->prepare(
            $resolved,
            $bag,
            array_replace($resolved->providerOptions, $resolved->reasoningOptions),
            new ModelInvocationInput(runId: $sessionId, messages: $marked),
            null,
        );

        $this->assertInstanceOf(MessageBag::class, $prepared['input']);
        $preparedMessages = $prepared['input']->withoutSystemMessage()->getMessages();
        $this->assertSame('low', $preparedMessages[1]->getMetadata()->get(CodexReasoningTransitionMetadata::KEY));
        $this->assertSame('low', $prepared['options'][CodexRequestBodyFactory::REASONING_UPDATE] ?? null);
        $this->assertSame($sessionId, $prepared['options']['hatfield_run_id'] ?? null);

        $transitions = $store->listReasoningTransitions($sessionId, $modelRef);
        $this->assertNotSame([], $transitions);
        $this->assertSame('low', $transitions[array_key_last($transitions)]['effort']);

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            $prepared['input'],
            [],
        );
        $this->assertSame('configuration_update', $payload['input'][1]['type']);
        $this->assertSame('low', $payload['input'][1]['reasoning']['effort']);
    }

    public function testUnchangedEffortDoesNotStampWhileChangeAndReturnReplayStablePrefix(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';

        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $unchanged = $store->claimReasoningBaseline($sessionId, $modelRef, 'medium');
        $this->assertSame([
            'baseline' => 'medium',
            'update' => null,
            'last_emitted' => 'medium',
        ], $unchanged);

        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $transformHook = $this->createTransformHook($store);

        $firstUser = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'first']]);
        $secondUser = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'second']]);
        $history = [$firstUser, $secondUser];

        $toHigh = $store->claimReasoningBaseline($sessionId, $modelRef, 'high');
        $this->assertSame('high', $toHigh['update'] ?? null);

        $markedHistory = $transformHook->transformContext($history, null, $sessionId);
        $bag = (new AgentMessageConverter())->toMessageBag($markedHistory);
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $marked = $transformHook->transformContext($history, null, $sessionId);
        $this->assertSame('high', $marked[1]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);

        $payload = $this->normalize($marked);
        $this->assertSame('configuration_update', $payload['input'][1]['type']);
        $this->assertSame('high', $payload['input'][1]['reasoning']['effort']);

        $session = $store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('high', $session->reasoningBaseline['last_emitted'] ?? null);
        $stillHigh = $store->claimReasoningBaseline($sessionId, $modelRef, 'high');
        $this->assertIsArray($stillHigh);
        $this->assertNull($stillHigh['update']);

        $thirdUser = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'third']]);
        $extendedHistory = [...$history, $thirdUser];
        $replayed = $transformHook->transformContext($extendedHistory, null, $sessionId);
        $extendedPayload = $this->normalize($replayed);
        $this->assertSame(
            \array_slice($extendedPayload['input'], 0, \count($payload['input'])),
            $payload['input'],
        );

        $toMedium = $store->claimReasoningBaseline($sessionId, $modelRef, 'medium');
        $this->assertSame('medium', $toMedium['update'] ?? null);
        $historyBack = [
            ...$extendedHistory,
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'fourth']]),
        ];
        $markedBack = $transformHook->transformContext($historyBack, null, $sessionId);
        $bagBack = (new AgentMessageConverter())->toMessageBag($markedBack);
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bagBack],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'medium',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $withReturn = $transformHook->transformContext($historyBack, null, $sessionId);
        $returnPayload = $this->normalize($withReturn);
        $this->assertSame('configuration_update', $returnPayload['input'][1]['type']);
        $this->assertSame('high', $returnPayload['input'][1]['reasoning']['effort']);
        $this->assertSame('configuration_update', $returnPayload['input'][4]['type']);
        $this->assertSame('medium', $returnPayload['input'][4]['reasoning']['effort']);

        $store->resetReasoningBaseline($sessionId);
        $this->assertSame([], $store->listReasoningTransitions($sessionId, $modelRef));
        $stripped = $transformHook->transformContext($withReturn, null, $sessionId);
        foreach ($stripped as $message) {
            $this->assertArrayNotHasKey(CodexReasoningTransitionMetadata::KEY, $message->metadata);
        }
    }

    public function testMultipartToolDetailsAndCustomRoleShareCanonicalIdentityAcrossConversion(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $this->assertSame('high', $store->claimReasoningBaseline($sessionId, $modelRef, 'high')['update'] ?? null);

        $history = [
            new AgentMessage(role: 'user', content: [
                ['type' => 'text', 'text' => 'line-a'],
                ['type' => 'text', 'text' => 'line-b'],
            ]),
            new AgentMessage(
                role: 'tool',
                content: [],
                toolCallId: 'call-1',
                toolName: 'bash',
                details: ['stdout' => 'tool-details', 'exit_code' => 0],
            ),
            new AgentMessage(role: 'developer', content: [['type' => 'text', 'text' => 'custom']]),
        ];

        $transformHook = $this->createTransformHook($store);
        $marked = $transformHook->transformContext($history, null, $sessionId);
        $expectedKey = AstraReasoningTransitionTransformHook::messageKeyInHistory($history, 2);
        $this->assertNotNull($expectedKey);
        $this->assertSame($expectedKey, $marked[2]->metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY] ?? null);
        $this->assertSame(
            '[developer] custom',
            AstraReasoningTransitionTransformHook::canonicalText($history[2]),
        );
        $this->assertSame(
            "line-a\nline-b",
            AstraReasoningTransitionTransformHook::canonicalText($history[0]),
        );
        $this->assertSame(
            json_encode(['stdout' => 'tool-details', 'exit_code' => 0], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            AstraReasoningTransitionTransformHook::canonicalText($history[1]),
        );

        $bag = (new AgentMessageConverter())->toMessageBag($marked);
        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $transitions = $store->listReasoningTransitions($sessionId, $modelRef);
        $this->assertSame([['message_key' => $expectedKey, 'effort' => 'high']], $transitions);

        $replayed = $transformHook->transformContext($history, null, $sessionId);
        $this->assertSame('high', $replayed[2]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);
        $payload = $this->normalize($replayed);
        $this->assertSame('configuration_update', $payload['input'][2]['type']);
        $this->assertSame('high', $payload['input'][2]['reasoning']['effort']);
    }

    public function testOutputCapRewriteKeepsMessageKeyIdentityForRequestHook(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $this->assertSame('high', $store->claimReasoningBaseline($sessionId, $modelRef, 'high')['update'] ?? null);

        $oversized = str_repeat('x', 5000);
        $history = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'ask']]),
            new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => $oversized]],
                toolCallId: 'call-cap',
                toolName: 'bash',
                details: ['arguments' => []],
            ),
        ];

        $capDir = $this->tempDir.'/output-cap';
        mkdir($capDir, 0777, true);
        $outputCap = new OutputCap(
            new OutputCapConfig(storageDir: $capDir, defaultCap: 100),
            new LockFactory(new FlockStore($capDir)),
            new NullLogger(),
        );
        $capHook = new OutputCapLlmTransformHook(
            $outputCap,
            \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $cappedHistory = $capHook->transformContext($history, null, $sessionId);
        $this->assertNotSame($oversized, $cappedHistory[1]->content[0]['text'] ?? null);

        $transformHook = $this->createTransformHook($store);
        $marked = $transformHook->transformContext($cappedHistory, null, $sessionId);
        $expectedKey = AstraReasoningTransitionTransformHook::messageKeyInHistory($cappedHistory, 1);
        $this->assertNotNull($expectedKey);
        $this->assertSame($expectedKey, $marked[1]->metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY] ?? null);

        $bag = (new AgentMessageConverter())->toMessageBag($marked);
        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $this->assertSame(
            [['message_key' => $expectedKey, 'effort' => 'high']],
            $store->listReasoningTransitions($sessionId, $modelRef),
        );

        $retryMarked = $transformHook->transformContext($cappedHistory, null, $sessionId);
        $this->assertSame('high', $retryMarked[1]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);
        $retryDecision = $store->claimReasoningBaseline($sessionId, $modelRef, 'high');
        $this->assertIsArray($retryDecision);
        $this->assertNull($retryDecision['update']);
    }

    public function testImageOnlySyntheticUserIsSkippedWhenSelectingAnchor(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $this->assertSame('high', $store->claimReasoningBaseline($sessionId, $modelRef, 'high')['update'] ?? null);

        $imagePath = $this->tempDir.'/tiny.png';
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $history = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'anchor']]),
            new AgentMessage(
                role: 'tool',
                content: [
                    ['type' => 'text', 'text' => 'tool-output'],
                    ['type' => 'image_ref', 'path' => $imagePath, 'media_type' => 'image/png', 'width' => 1, 'height' => 1, 'bytes' => 68],
                ],
                toolCallId: 'call-img',
                toolName: 'view_image',
                details: ['arguments' => []],
            ),
        ];

        $transformHook = $this->createTransformHook($store);
        $marked = $transformHook->transformContext($history, null, $sessionId);
        $bag = (new AgentMessageConverter())->toMessageBag($marked);
        $messages = $bag->withoutSystemMessage()->getMessages();
        $this->assertGreaterThan(2, \count($messages));
        $last = $messages[\count($messages) - 1];
        $this->assertInstanceOf(\Symfony\AI\Platform\Message\UserMessage::class, $last);
        $this->assertTrue($last->hasImageContent());

        $expectedKey = $marked[1]->metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY] ?? null;
        $this->assertNotNull($expectedKey);
        $this->assertSame(
            $expectedKey,
            $messages[1]->getMetadata()->get(CodexReasoningTransitionMetadata::MESSAGE_KEY),
        );
        $this->assertNull($last->getMetadata()->get(CodexReasoningTransitionMetadata::MESSAGE_KEY));
        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $this->assertSame(
            [['message_key' => $expectedKey, 'effort' => 'high']],
            $store->listReasoningTransitions($sessionId, $modelRef),
        );
    }

    public function testCompactingStatusAndNonAstraModelStripTransitions(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $key = AstraReasoningTransitionTransformHook::messageKeyFor('user', null, 'hello', 0);
        $this->assertNotNull($key);
        $store->rememberReasoningTransition($sessionId, $modelRef, $key, 'high');

        $history = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'hello']],
                metadata: [
                    CodexReasoningTransitionMetadata::KEY => 'high',
                    CodexReasoningTransitionMetadata::MESSAGE_KEY => $key,
                ],
            ),
        ];

        $compactingReader = new class implements RunOperationalStatusReaderInterface {
            public function findOperationalStatus(string $runId): ?RunOperationalStatusDTO
            {
                return new RunOperationalStatusDTO(RunStatus::Compacting);
            }
        };
        $compactingHook = $this->createTransformHook($store, $compactingReader);
        $stripped = $compactingHook->transformContext($history, null, $sessionId);
        $this->assertArrayNotHasKey(CodexReasoningTransitionMetadata::KEY, $stripped[0]->metadata);
        $this->assertArrayNotHasKey(CodexReasoningTransitionMetadata::MESSAGE_KEY, $stripped[0]->metadata);

        $store->updateMetadata($sessionId, ['model' => 'openai-codex/gpt-test']);
        $nonAstraHook = $this->createTransformHook($store);
        $nonAstra = $nonAstraHook->transformContext($history, null, $sessionId);
        $this->assertArrayNotHasKey(CodexReasoningTransitionMetadata::KEY, $nonAstra[0]->metadata);
    }

    public function testMissingImagePlaceholderIsSkippedSoPrecedingToolAnchorsAndReplays(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $this->assertSame('high', $store->claimReasoningBaseline($sessionId, $modelRef, 'high')['update'] ?? null);

        $missingPath = $this->tempDir.'/missing-view-image.png';
        $history = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'ask']]),
            new AgentMessage(
                role: 'tool',
                content: [
                    ['type' => 'text', 'text' => 'tool-output'],
                    ['type' => 'image_ref', 'path' => $missingPath, 'media_type' => 'image/png', 'width' => 1, 'height' => 1, 'bytes' => 0],
                ],
                toolCallId: 'call-missing-img',
                toolName: 'view_image',
                details: ['arguments' => []],
            ),
        ];

        $transformHook = $this->createTransformHook($store);
        $marked = $transformHook->transformContext($history, null, $sessionId);
        $expectedKey = $marked[1]->metadata[CodexReasoningTransitionMetadata::MESSAGE_KEY] ?? null;
        $this->assertNotNull($expectedKey);

        $bag = (new AgentMessageConverter())->toMessageBag($marked);
        $messages = $bag->withoutSystemMessage()->getMessages();
        $this->assertGreaterThan(2, \count($messages));
        $last = $messages[\count($messages) - 1];
        $this->assertInstanceOf(\Symfony\AI\Platform\Message\UserMessage::class, $last);
        $this->assertFalse($last->hasImageContent());
        $this->assertNull($last->getMetadata()->get(CodexReasoningTransitionMetadata::MESSAGE_KEY));
        $this->assertStringContainsString('[Tool result image for view_image:', $last->asText() ?? '');

        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $this->assertSame(
            [['message_key' => $expectedKey, 'effort' => 'high']],
            $store->listReasoningTransitions($sessionId, $modelRef),
        );

        $replayed = $transformHook->transformContext($history, null, $sessionId);
        $this->assertSame('high', $replayed[1]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);
        $payload = $this->normalize($replayed);
        $this->assertSame('configuration_update', $payload['input'][1]['type']);
        $this->assertSame('high', $payload['input'][1]['reasoning']['effort']);

        $retry = $store->claimReasoningBaseline($sessionId, $modelRef, 'high');
        $this->assertIsArray($retry);
        $this->assertNull($retry['update']);
    }

    public function testMissingAnchorDegradesWithoutAdvancingLastEmitted(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $this->assertSame('high', $store->claimReasoningBaseline($sessionId, $modelRef, 'high')['update'] ?? null);

        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $requestHook = new AstraReasoningTransitionRequestHook($store, $logger);
        $bag = new MessageBag(Message::ofAssistant('assistant-only'));
        $result = $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_UPDATE, $result->options ?? []);
        $this->assertSame([], $store->listReasoningTransitions($sessionId, $modelRef));
        $this->assertSame('medium', $store->findSession($sessionId)?->reasoningBaseline['last_emitted'] ?? null);
        $this->assertSame('high', $store->claimReasoningBaseline($sessionId, $modelRef, 'high')['update'] ?? null);
        $this->assertNotSame([], $logger->records);
        $this->assertSame('astra.reasoning_transition.anchor_missing', $logger->records[0]['context']['event_type'] ?? null);
    }

    public function testDuplicateIdenticalUserMessagesGetDistinctTransitionKeys(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));

        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $transformHook = $this->createTransformHook($store);

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'same']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'same']]),
        ];
        $marked = $transformHook->transformContext($messages, null, $sessionId);
        $bag = (new AgentMessageConverter())->toMessageBag($marked);
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $replayed = $transformHook->transformContext($messages, null, $sessionId);
        $this->assertArrayNotHasKey(CodexReasoningTransitionMetadata::KEY, $replayed[0]->metadata);
        $this->assertSame('high', $replayed[1]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);

        $key0 = AstraReasoningTransitionTransformHook::messageKeyInHistory($messages, 0);
        $key1 = AstraReasoningTransitionTransformHook::messageKeyInHistory($messages, 1);
        $this->assertNotSame($key0, $key1);
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        file_put_contents($classCwd.'/.hatfield/settings.yaml', <<<'YAML'
ai:
    default_model: openai-codex/gpt-6-astra
    default_reasoning: medium
    providers:
        openai-codex:
            type: codex
            enabled: true
            models:
                gpt-6-astra:
                    id: gpt-6-astra
                    name: Astra
                    context_window: 200000
                    max_tokens: 65536
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    reasoning_levels: [low, medium, high]
                    thinking_level_map:
                        low: low
                        medium: medium
                        high: high
                    compatibility:
                        supports_reasoning_configuration_updates: true
                gpt-test:
                    id: gpt-test
                    name: Test
                    context_window: 128000
                    max_tokens: 4096
                    input: [text]
                    tool_calling: true
YAML);
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return array{input: list<array<string, mixed>>}
     */
    private function normalize(array $messages): array
    {
        $bag = (new AgentMessageConverter())->toMessageBag($messages);

        /** @var array{input: list<array<string, mixed>>} $payload */
        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            $bag,
            [],
        );

        return $payload;
    }

    private function createStore(): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->entityManager,
            dispatcher: new EventDispatcher(),
        );
    }

    private function createTransformHook(
        HatfieldSessionStore $store,
        ?RunOperationalStatusReaderInterface $statusReader = null,
    ): AstraReasoningTransitionTransformHook {
        $aiData = [
            'default_model' => 'openai-codex/gpt-6-astra',
            'default_reasoning' => 'medium',
            'providers' => [
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'compatibility' => ['thinking_format' => 'codex'],
                    'models' => [
                        'gpt-6-astra' => [
                            'name' => 'Astra',
                            'reasoning' => true,
                            'thinking_level_map' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high'],
                            'compatibility' => ['supports_reasoning_configuration_updates' => true],
                        ],
                        'gpt-test' => [
                            'name' => 'Test',
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];
        $ai = AiConfig::optionalFromArray(['ai' => $aiData]);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: ['ai' => $aiData],
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $selection = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $store, new NullLogger()),
            $homeWriter,
            $store,
        );

        return new AstraReasoningTransitionTransformHook(
            $selection,
            $appConfig->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: [])),
            $store,
            $statusReader,
        );
    }

    private function createSession(HatfieldSessionStore $store, string $model, string $reasoning): string
    {
        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $entity->model = $model;
        $entity->reasoning = $reasoning;
        $entity->providerCacheKey = UuidV7::v7()->toRfc4122();
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $this->assertTrue(Uuid::isValid((string) $entity->providerCacheKey));

        return (string) $entity->id;
    }
}
