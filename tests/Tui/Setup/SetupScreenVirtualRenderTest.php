<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\Tui\Setup\ProvidersSetupFlowInterface;
use Ineersa\Tui\Setup\SetupScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Thesis: SetupScreen renders the picker dashboard with live status and
 * drives enable/disable/custom collision flows against a headless flow fake.
 */
#[CoversClass(SetupScreen::class)]
final class SetupScreenVirtualRenderTest extends TestCase
{
    #[Test]
    public function freshPickerRendersCatalogProvidersOtherServerAndStatuses(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $text = $this->plain($terminal);
        $this->assertStringContainsString('AI Provider Setup', $text);
        $this->assertStringContainsString('Hatfield needs at least one AI provider to run.', $text);
        $this->assertStringContainsString('Z.ai (GLM)', $text);
        $this->assertStringContainsString('DeepSeek', $text);
        $this->assertStringContainsString('OpenAI Codex', $text);
        $this->assertStringContainsString('Grok / xAI', $text);
        $this->assertStringContainsString('Other server', $text);
        $this->assertStringContainsString('Done', $text);
        $this->assertStringContainsString('needs an API key', $text);
        $this->assertStringContainsString('log in with your ChatGPT account', $text);
        $this->assertStringContainsString('log in with your xAI account', $text);
        $this->assertStringContainsString('✗ disabled', $text);
        $this->assertStringNotContainsString('(enabled)', $text);
        $this->assertStringNotContainsString('not set up', $text);
        $this->assertSame('picker', $screen->phase());
        $this->assertStringContainsString('↑/↓ select · Enter confirm · Esc exit · Ctrl+D quit', $screen->footerText());
        $this->assertStringContainsString('↑/↓ select · Enter confirm · Esc exit · Ctrl+D quit', $text);
    }

    #[Test]
    public function footerCopyMatchesPhaseForActionInputAndSummary(): void
    {
        $flow = new FakeProvidersSetupFlow(enabled: ['zai' => true]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('zai');
        $this->assertSame('action', $screen->phase());
        $this->assertStringContainsString('Esc back · Ctrl+D quit', $screen->footerText());

        $screen->selectValue('configure');
        $this->assertSame('choice', $screen->phase()); // api where list
        $this->assertStringContainsString('Esc back · Ctrl+D quit', $screen->footerText());

        $screen->selectValue('env');
        $this->assertSame('input', $screen->phase());
        $this->assertStringContainsString('Enter submit · Esc back · Ctrl+D quit', $screen->footerText());
        $this->assertStringContainsString('Enter submit · Esc back · Ctrl+D quit', $this->plain($terminal));

        $screen = $this->mount(new FakeProvidersSetupFlow())[0];
        $screen->selectValue('done');
        $this->assertSame('summary', $screen->phase());
        $this->assertStringContainsString('Enter close · Esc exit · Ctrl+D quit', $screen->footerText());
    }

    #[Test]
    public function ctrlDFromPickerQuitsCleanly(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen] = $this->mount($flow);

        $screen->pressCtrlD();

        $this->assertTrue($screen->finished());
        $this->assertSame('summary', $screen->phase());
    }

    #[Test]
    public function ctrlDFromInputQuitsCleanly(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen] = $this->mount($flow);

        $screen->selectValue('custom');
        $this->assertSame('input', $screen->phase());
        $screen->pressCtrlD();

        $this->assertTrue($screen->finished());
        $this->assertSame('summary', $screen->phase());
    }

    #[Test]
    public function enablingOauthShowsEnabledBadgeOnNextPicker(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli'); // enable oauth immediately
        // After enable → Add another? → No → summary/exit. Choose Yes to return.
        $screen->selectValue('yes');

        $text = $this->plain($terminal);
        $this->assertTrue($flow->isEnabled('grok-cli'));
        $this->assertStringContainsString('Grok / xAI', $text);
        $this->assertStringNotContainsString('Grok / xAI (enabled)', $text);
        $this->assertStringContainsString('✓ enabled', $text);
        $this->assertSame(['auth:grok'], $flow->pendingAuthCommands());
    }

    #[Test]
    public function disableConfirmRendersClearSettingsCopy(): void
    {
        $flow = new FakeProvidersSetupFlow(enabled: ['zai' => true]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('zai');
        $this->assertSame('action', $screen->phase());
        $screen->selectValue('disable');

        $text = $this->plain($terminal);
        $this->assertStringContainsString('Disable Z.ai (GLM)? This clears its settings entry.', $text);
        $this->assertSame('confirm', $screen->phase());
    }

    #[Test]
    public function customFormStartsWithProviderIdPrompt(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Add your own server', $text);
        $this->assertStringContainsString('Step 1 of 13 — Provider id', $text);
        $this->assertStringContainsString('Provider id (slug)', $text);
        $this->assertStringNotContainsString('Other server', $text);
        $this->assertStringNotContainsString('Done', $text);
        $this->assertSame('input', $screen->phase());
    }

    #[Test]
    public function inputPhaseHidesProviderList(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('deepseek');
        $this->assertSame('choice', $screen->phase()); // api where
        $screen->selectValue('env');
        $this->assertSame('input', $screen->phase());

        $text = $this->plain($terminal);
        $this->assertStringContainsString('Variable name', $text);
        $this->assertStringNotContainsString('Other server', $text);
        $this->assertStringNotContainsString('Z.ai (GLM)', $text);
        $this->assertStringNotContainsString('Done', $text);
    }

    #[Test]
    public function customCatalogCollisionShowsInlineErrorAndStaysOnInput(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->submitInput('zai');

        $this->assertSame('input', $screen->phase());
        $this->assertStringContainsString(
            '"zai" is built into Hatfield — choose it from the list above instead.',
            $screen->errorText(),
        );
        $text = $this->plain($terminal);
        $this->assertStringContainsString('⚠', $text);
        $this->assertSame([], $flow->savedCustoms);
    }

    #[Test]
    public function oauthEnableSummaryContainsAuthHintAndSavedTo(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli');
        $screen->selectValue('no'); // Add another? → default-model confirm
        $this->assertSame('confirm', $screen->phase());
        $screen->selectValue('no'); // skip default model → summary

        $this->assertSame('summary', $screen->phase());
        $this->assertTrue($screen->finished());
        $collapsed = str_replace(["\n", ' '], '', $this->plain($terminal));
        $this->assertStringContainsString(str_replace(' ', '', 'To finish: run `hatfield auth:grok`'), $collapsed);
        $this->assertStringContainsString(str_replace(' ', '', 'Saved to'), $collapsed);
    }

    #[Test]
    public function enableThenDisableSameRunSummaryHasSavedToButNoAuthHint(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli');
        $screen->selectValue('yes'); // Add another?
        $screen->selectValue('grok-cli');
        $screen->selectValue('disable');
        $screen->selectValue('yes'); // confirm disable
        $screen->selectValue('no'); // Add another? → summary

        $this->assertSame('summary', $screen->phase());
        $collapsed = str_replace(["\n", ' '], '', $this->plain($terminal));
        $this->assertStringContainsString(str_replace(' ', '', 'Saved to'), $collapsed);
        $this->assertStringNotContainsString(str_replace(' ', '', 'To finish'), $collapsed);
        $this->assertSame([], $flow->pendingAuthCommands());
    }

    #[Test]
    public function nothingChangedSummaryWhenDoneImmediately(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('done');

        $this->assertSame('summary', $screen->phase());
        $this->assertTrue($screen->finished());
        $collapsed = str_replace(["\n", ' '], '', $this->plain($terminal));
        $this->assertStringContainsString(str_replace(' ', '', 'Nothing changed.'), $collapsed);
        $this->assertStringNotContainsString(str_replace(' ', '', 'Saved to'), $collapsed);
    }

    /**
     * @return array{0: SetupScreen, 1: VirtualTerminal}
     */
    private function mount(FakeProvidersSetupFlow $flow): array
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 40);
        $tui = new Tui(terminal: $terminal);
        $screen = new SetupScreen($flow);
        $screen->mount($tui);
        $tui->start();
        $tui->requestRender(force: true);
        $tui->processRender();

        return [$screen, $terminal];
    }

    private function plain(VirtualTerminal $terminal): string
    {
        $buffer = new ScreenBuffer(
            width: $terminal->getColumns(),
            height: $terminal->getRows(),
        );
        $buffer->write($terminal->getOutput());

        return $buffer->getScreen();
    }
}

/**
 * Minimal in-memory flow for SetupScreen virtual renders.
 */
final class FakeProvidersSetupFlow implements ProvidersSetupFlowInterface
{
    /** @var list<array<string, mixed>> */
    public array $savedCustoms = [];
    /** @var array<string, bool> */
    private array $enabled;

    /** @var list<array{id: string, models: list<string>, authCommand: ?string}> */
    private array $configured = [];

    private bool $wrote = false;

    /**
     * @param array<string, bool> $enabled
     */
    public function __construct(array $enabled = [])
    {
        $this->enabled = $enabled;
    }

    public function providerRows(): array
    {
        $catalog = [
            ['id' => 'zai', 'label' => 'Z.ai (GLM)', 'need' => 'needs an API key', 'kind' => 'apikey', 'authCommand' => null, 'models' => ['glm-5.3']],
            ['id' => 'deepseek', 'label' => 'DeepSeek', 'need' => 'needs an API key', 'kind' => 'apikey', 'authCommand' => null, 'models' => ['deepseek-v4-pro']],
            ['id' => 'openai-codex', 'label' => 'OpenAI Codex', 'need' => 'log in with your ChatGPT account', 'kind' => 'oauth', 'authCommand' => 'auth:codex', 'models' => ['gpt-5.6-luna']],
            ['id' => 'grok-cli', 'label' => 'Grok / xAI', 'need' => 'log in with your xAI account', 'kind' => 'oauth', 'authCommand' => 'auth:grok', 'models' => ['grok-composer-2.5-fast']],
        ];
        $rows = [];
        foreach ($catalog as $row) {
            $rows[] = [
                ...$row,
                'status' => $this->isEnabled($row['id']) ? '✓ enabled' : 'not set up',
            ];
        }

        return $rows;
    }

    public function isEnabled(string $id): bool
    {
        return $this->enabled[$id] ?? false;
    }

    public function enableOauth(string $id): void
    {
        $this->enabled[$id] = true;
        $this->wrote = true;
        $auth = match ($id) {
            'grok-cli' => 'auth:grok',
            'openai-codex' => 'auth:codex',
            default => null,
        };
        $models = match ($id) {
            'grok-cli' => ['grok-composer-2.5-fast'],
            'openai-codex' => ['gpt-5.6-luna'],
            default => [],
        };
        $this->configured[] = ['id' => $id, 'models' => $models, 'authCommand' => $auth];
    }

    public function enableApiKey(string $id, string $apiKey): void
    {
        $this->enabled[$id] = true;
        $this->wrote = true;
        $this->configured[] = ['id' => $id, 'models' => ['glm-5.3'], 'authCommand' => null];
    }

    public function disable(string $id): void
    {
        $this->enabled[$id] = false;
        $this->wrote = true;
        $this->configured = array_values(array_filter(
            $this->configured,
            static fn (array $row): bool => $row['id'] !== $id,
        ));
    }

    public function validateCustomId(string $id): void
    {
        $id = strtolower(trim($id));
        foreach ($this->providerRows() as $row) {
            if ($row['id'] === $id) {
                throw new \InvalidArgumentException(\sprintf('"%s" is built into Hatfield — choose it from the list above instead.', $id));
            }
        }
    }

    public function saveCustom(
        string $id,
        string $baseUrl,
        string $completionsPath,
        ?string $apiKey,
        array $models,
        bool $supportsDeveloperRole,
        string $thinkingFormat,
    ): void {
        $this->validateCustomId($id);
        $this->savedCustoms[] = compact('id', 'baseUrl', 'completionsPath', 'apiKey', 'models', 'supportsDeveloperRole', 'thinkingFormat');
        $this->enabled[$id] = true;
        $this->wrote = true;
        $this->configured[] = ['id' => $id, 'models' => array_keys($models), 'authCommand' => null];
    }

    public function setDefaultModel(string $ref): void
    {
        $this->wrote = true;
    }

    public function pendingAuthCommands(): array
    {
        $pending = [];
        foreach ($this->configured as $row) {
            if (null !== $row['authCommand']) {
                $pending[] = $row['authCommand'];
            }
        }

        return array_values(array_unique($pending));
    }

    public function configuredModelRefs(): array
    {
        $refs = [];
        foreach ($this->configured as $row) {
            foreach ($row['models'] as $modelId) {
                $refs[] = $row['id'].'/'.$modelId;
            }
        }

        return $refs;
    }

    public function settingsPath(): string
    {
        return '/tmp/fake/.hatfield/settings.yaml';
    }

    public function wroteSomething(): bool
    {
        return $this->wrote;
    }

    public function defaultModelWarningFor(string $providerId): ?string
    {
        return null;
    }

    public function formatEnvApiKey(string $envName): string
    {
        return 'env:'.$envName;
    }

    public function defaultThinkingLevelMap(): array
    {
        return ['off' => 'none', 'low' => 'low'];
    }
}
