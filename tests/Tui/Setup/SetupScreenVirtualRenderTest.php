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
        $this->assertStringContainsString('↑/↓ select · Enter confirm · Esc exit · Ctrl+D quit', $text);
        $this->assertStringContainsString('┌', $text); // bordered panel
        $this->assertStringContainsString('│', $text);
    }

    #[Test]
    public function footerCopyMatchesPhaseForActionInputAndSummary(): void
    {
        $flow = new FakeProvidersSetupFlow(enabled: ['zai' => true]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('zai');
        $this->assertSame('action', $screen->phase());
        $this->assertStringContainsString('Esc back · Ctrl+D quit', $this->plain($terminal));

        $screen->selectValue('configure');
        $this->assertSame('choice', $screen->phase()); // api where list
        $this->assertStringContainsString('Esc back · Ctrl+D quit', $this->plain($terminal));

        $screen->selectValue('env');
        $this->assertSame('input', $screen->phase());
        $this->assertStringContainsString('Enter submit · Esc back · Ctrl+D quit', $this->plain($terminal));

        [$screen, $terminal] = $this->mount(new FakeProvidersSetupFlow());
        $screen->selectValue('done');
        $this->assertSame('summary', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Esc exit · Ctrl+D quit', $text);
        $this->assertStringNotContainsString('Enter close', $text);
    }

    #[Test]
    public function ctrlDFromPickerQuitsCleanly(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $tui->handleInput("\x04");

        $this->assertTrue($screen->finished());
        $this->assertSame('summary', $screen->phase());
        $this->assertStringContainsString('AI Provider Setup', $this->plain($terminal));
    }

    #[Test]
    public function ctrlDFromCustomInputResetsChromeOnSummary(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $screen->selectValue('custom');
        $this->assertSame('input', $screen->phase());
        $this->assertStringContainsString('Add your own server', $this->plain($terminal));

        $tui->handleInput("\x04");

        $this->assertTrue($screen->finished());
        $this->assertSame('summary', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('AI Provider Setup', $text);
        $this->assertStringNotContainsString('Add your own server', $text);
    }

    #[Test]
    public function realEnterAdvancesCustomUrlAfterPhaseRemount(): void
    {
        // Regression: AbstractWidget::detach() wipes onSubmit listeners when
        // applyPhaseLayout removes+re-adds input between custom steps. Drivers
        // bypass widgets; this uses the real Tui::handleInput path.
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow, columns: 120);

        $screen->selectValue('custom');
        $screen->submitInput('runpod'); // now at custom_url (second input attach)
        $this->assertSame('input', $screen->phase());
        $this->assertStringContainsString('Server URL', $this->plain($terminal));

        // Clear the default URL first (ctrl+u = delete_to_line_start), then paste.
        // Bracketed paste inserts at the cursor; without clearing it would append.
        $tui->handleInput("\x15");
        $tui->handleInput("\x1b[200~https://example.com/v1\x1b[201~");
        $tui->processRender(); // handleInput only requestRender()s; virtual needs flush
        $this->assertStringContainsString('https://example.com/v1', $this->plain($terminal));

        $tui->handleInput("\r");

        $this->assertSame('input', $screen->phase());
        $this->assertStringContainsString('Completions path', $this->plain($terminal));
        $this->assertStringContainsString('Step 3 of 13', $this->plain($terminal));
    }

    #[Test]
    public function listEnterSurvivesDetachAndReattachRoundtrip(): void
    {
        // list → input (list detached/listeners wiped) → Esc back → list re-added;
        // Enter must still fire onSelect after re-wire.
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $screen->selectValue('custom');
        $this->assertSame('input', $screen->phase());

        $tui->handleInput("\x1b"); // Esc back to picker (list re-attached)
        $this->assertSame('picker', $screen->phase());

        $tui->handleInput("\r"); // first row = zai → api_where choice
        $this->assertSame('choice', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('environment variable', $text);
        $this->assertStringContainsString('Esc back · Ctrl+D quit', $text);
    }

    #[Test]
    public function ctrlDQuitWorksAfterInputPhaseTransition(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->submitInput('runpod'); // remounts input (listeners wiped then re-wired)
        $this->assertSame('input', $screen->phase());

        $tui->handleInput("\x04");

        $this->assertTrue($screen->finished());
        $this->assertSame('summary', $screen->phase());
        $this->assertStringContainsString('AI Provider Setup', $this->plain($terminal));
    }

    #[Test]
    public function enablingOauthShowsEnabledBadgeOnNextPicker(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli'); // confirm Enable?
        $this->assertSame('confirm', $screen->phase());
        $screen->selectValue('yes'); // confirm → enable
        // After enable → Continue? → Continue to return.
        $screen->selectValue('yes');

        $text = $this->plain($terminal);
        $this->assertTrue($flow->isEnabled('grok-cli'));
        $this->assertStringContainsString('Grok / xAI', $text);
        $this->assertStringNotContainsString('Grok / xAI (enabled)', $text);
        $this->assertStringContainsString('✓ enabled', $text);
        $this->assertSame(['auth:grok'], $flow->pendingAuthCommands());
    }

    #[Test]
    public function oauthEnableConfirmNoWritesNothing(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli');
        $this->assertSame('confirm', $screen->phase());
        $this->assertStringContainsString('Enable Grok / xAI?', $this->plain($terminal));
        $screen->selectValue('no');

        $this->assertSame('picker', $screen->phase());
        $this->assertFalse($flow->isEnabled('grok-cli'));
        $this->assertFalse($flow->wroteSomething());
        $this->assertSame([], $flow->pendingAuthCommands());
    }

    #[Test]
    public function oauthActionMenuOmitsReconfigure(): void
    {
        $flow = new FakeProvidersSetupFlow(enabled: ['grok-cli' => true]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli');
        $this->assertSame('action', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringNotContainsString('Reconfigure', $text);
        $this->assertStringContainsString('Disable', $text);
        $this->assertStringContainsString('Cancel', $text);
    }

    #[Test]
    public function apiKeyActionMenuKeepsReconfigure(): void
    {
        $flow = new FakeProvidersSetupFlow(enabled: ['zai' => true]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('zai');
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Reconfigure', $text);
        $this->assertStringContainsString('Disable', $text);
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
        $this->assertStringContainsString('A short name to identify this provider in menus and settings.', $text);
        $this->assertStringContainsString('Example: runpod', $text);
        $this->assertStringNotContainsString('Other server', $text);
        $this->assertStringNotContainsString('Done', $text);
        $this->assertSame('input', $screen->phase());
    }

    #[Test]
    public function customUrlStepShowsHelpAndExampleInsidePanel(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->submitInput('runpod');

        $text = $this->plain($terminal);
        $this->assertSame('input', $screen->phase());
        $this->assertStringContainsString('Step 2 of 13 — Server URL', $text);
        $this->assertStringContainsString('The address of the API server Hatfield will talk to.', $text);
        $this->assertStringContainsString('Example: https://abc-123.proxy.runpod.net', $text);
        $this->assertStringContainsString('┌', $text);
        $this->assertStringContainsString('│', $text);
    }

    #[Test]
    public function customWizardReachesStepThirteenReasoningFormat(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->submitInput('local-llm');
        $screen->submitInput('http://127.0.0.1:8080');
        $screen->submitInput('/v1/chat/completions');
        $screen->selectValue('yes'); // Set an API key?
        $this->assertStringContainsString('Step 4 of 13', $this->plain($terminal));
        $screen->selectValue('env');
        $screen->submitInput('LOCAL_API_KEY');
        $screen->submitInput('llama-3');
        $screen->submitInput('Llama 3');
        $screen->submitInput('128000');
        $screen->submitInput('8192');
        $screen->selectValue('text');
        $screen->selectValue('no'); // reasoning
        $screen->selectValue('no'); // another model
        $screen->selectValue('no'); // developer role

        $this->assertSame('input', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Step 13 of 13 — Reasoning format', $text);
        $this->assertStringContainsString('Label the server uses to return thinking output, if any.', $text);
        $this->assertStringContainsString('Example: (blank for none)', $text);
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
        $screen->selectValue('yes'); // Enable?
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Continue?', $text);
        $this->assertStringContainsString('Exit', $text);
        $this->assertStringNotContainsString('Add another?', $text);
        $screen->selectValue('no'); // Exit → summary directly (no default-model ask)

        $this->assertSame('summary', $screen->phase());
        $this->assertTrue($screen->finished());
        $collapsed = str_replace(["\n", ' '], '', $this->plain($terminal));
        $this->assertStringContainsString(str_replace(' ', '', 'To finish: run `hatfield auth:grok`'), $collapsed);
        $this->assertStringContainsString(str_replace(' ', '', 'Saved to'), $collapsed);
        $this->assertStringNotContainsString(str_replace(' ', '', 'Set as your default model?'), $collapsed);
    }

    #[Test]
    public function enableThenDisableSameRunSummaryHasSavedToButNoAuthHint(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli');
        $screen->selectValue('yes'); // Enable?
        $screen->selectValue('yes'); // Continue?
        $screen->selectValue('grok-cli');
        $screen->selectValue('disable');
        $screen->selectValue('yes'); // confirm disable
        $screen->selectValue('no'); // Exit → summary

        $this->assertSame('summary', $screen->phase());
        $collapsed = str_replace(["\n", ' '], '', $this->plain($terminal));
        $this->assertStringContainsString(str_replace(' ', '', 'Saved to'), $collapsed);
        $this->assertStringNotContainsString(str_replace(' ', '', 'To finish'), $collapsed);
        $this->assertSame([], $flow->pendingAuthCommands());
    }

    #[Test]
    public function mainPickerWithCustomsShowsOtherServerNotCustomIds(): void
    {
        $flow = new FakeProvidersSetupFlow(customs: [
            'runpod' => [
                'baseUrl' => 'https://abc.proxy.runpod.net',
                'models' => ['m' => ['name' => 'm']],
            ],
        ]);
        [$screen, $terminal] = $this->mount($flow);

        $text = $this->plain($terminal);
        $this->assertSame('picker', $screen->phase());
        $this->assertStringContainsString('Other server', $text);
        $this->assertStringContainsString('Z.ai (GLM)', $text);
        // Customs live under the submenu, not the main picker.
        $this->assertStringNotContainsString('runpod', $text);
        $this->assertStringNotContainsString('https://abc.proxy.runpod.net', $text);
    }

    #[Test]
    public function serversSubmenuShowsUrlAndStatusGlyphs(): void
    {
        $flow = new FakeProvidersSetupFlow(customs: [
            'runpod' => [
                'baseUrl' => 'https://abc.proxy.runpod.net',
                'enabled' => true,
                'models' => ['m' => ['name' => 'm']],
            ],
            'llama-local' => [
                'baseUrl' => 'http://127.0.0.1:8080',
                'enabled' => false,
                'models' => ['m' => ['name' => 'm']],
            ],
        ]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $this->assertSame('servers', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Your servers', $text);
        $this->assertStringContainsString('runpod', $text);
        $this->assertStringContainsString('https://abc.proxy.runpod.net', $text);
        $this->assertStringContainsString('llama-local', $text);
        $this->assertStringContainsString('http://127.0.0.1:8080', $text);
        $this->assertStringContainsString('✓ enabled', $text);
        $this->assertStringContainsString('✗ disabled', $text);
        $this->assertStringContainsString('Add a new server', $text);
        $this->assertStringContainsString('Back', $text);
    }

    #[Test]
    public function customServerActionMenuHasEditDisableRemove(): void
    {
        $flow = new FakeProvidersSetupFlow(customs: [
            'runpod' => [
                'baseUrl' => 'https://abc.proxy.runpod.net',
                'enabled' => true,
                'models' => ['m' => ['name' => 'm']],
            ],
        ]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->selectValue('runpod');
        $this->assertSame('action', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Edit', $text);
        $this->assertStringContainsString('Disable', $text);
        $this->assertStringContainsString('Remove', $text);
        $this->assertStringNotContainsString('Reconfigure', $text);
    }

    #[Test]
    public function removeCustomConfirmNoWritesNothingYesDeletes(): void
    {
        $flow = new FakeProvidersSetupFlow(customs: [
            'runpod' => [
                'baseUrl' => 'https://abc.proxy.runpod.net',
                'enabled' => true,
                'models' => ['m' => ['name' => 'm']],
            ],
        ]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->selectValue('runpod');
        $screen->selectValue('remove');
        $this->assertSame('confirm', $screen->phase());
        $this->assertStringContainsString('Remove runpod? This deletes its settings entry.', $this->plain($terminal));

        $screen->selectValue('no');
        $this->assertSame('servers', $screen->phase());
        $this->assertSame([], $flow->removedCustoms);
        $this->assertNotEmpty($flow->customProviderRows());

        $screen->selectValue('runpod');
        $screen->selectValue('remove');
        $screen->selectValue('yes');
        $this->assertSame(['runpod'], $flow->removedCustoms);
        $this->assertSame([], $flow->customProviderRows());
    }

    #[Test]
    public function editCustomPrefillsSavedUrlAndPath(): void
    {
        $flow = new FakeProvidersSetupFlow(customs: [
            'runpod' => [
                'baseUrl' => 'https://abc.proxy.runpod.net',
                'completionsPath' => '/v1/chat/completions',
                'apiKey' => 'env:RUNPOD_API_KEY',
                'enabled' => true,
                'models' => [
                    'llama' => [
                        'name' => 'Llama',
                        'context_window' => 128000,
                        'max_tokens' => 8192,
                        'input' => ['text'],
                        'tool_calling' => true,
                        'reasoning' => false,
                        'thinking_level_map' => [],
                        'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                    ],
                ],
                'supportsDeveloperRole' => false,
                'thinkingFormat' => '',
            ],
        ]);
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->selectValue('runpod');
        $screen->selectValue('edit');

        $this->assertSame('input', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Edit your server', $text);
        $this->assertStringContainsString('Step 2 of 13 — Server URL', $text);
        $this->assertStringContainsString('https://abc.proxy.runpod.net', $text);

        $screen->submitInput('https://abc.proxy.runpod.net');
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Step 3 of 13 — Completions path', $text);
        $this->assertStringContainsString('/v1/chat/completions', $text);
    }

    #[Test]
    public function anotherModelChoiceUsesAddAndFinishLabels(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->submitInput('local-llm');
        $screen->submitInput('http://127.0.0.1:8080');
        $screen->submitInput('/v1/chat/completions');
        $screen->selectValue('no'); // no API key
        $screen->submitInput('llama-3');
        $screen->submitInput('Llama 3');
        $screen->submitInput('128000');
        $screen->submitInput('8192');
        $screen->selectValue('text');
        $screen->selectValue('no'); // reasoning

        $this->assertSame('choice', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Add another model', $text);
        $this->assertStringContainsString('Finish', $text);
        $this->assertStringNotContainsString('→ Yes', $text);
        $this->assertStringNotContainsString('→ No', $text);
    }

    #[Test]
    public function setDefaultModelRowAbsentWhenNoConfiguredModels(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $text = $this->plain($terminal);
        $this->assertSame('picker', $screen->phase());
        $this->assertStringNotContainsString('Set default model', $text);
        $this->assertSame([], $flow->configuredModelRefs());
    }

    #[Test]
    public function setDefaultModelRowShowsCurrentAndReturnsToPicker(): void
    {
        $flow = new FakeProvidersSetupFlow(defaultModel: 'zai/glm-5.3');
        [$screen, $terminal] = $this->mount($flow);

        // Enable an API-key provider so configuredModelRefs becomes non-empty.
        $screen->selectValue('zai');
        $screen->selectValue('env');
        $screen->submitInput('ZAI_API_KEY');
        $screen->selectValue('yes'); // Continue → picker

        $text = $this->plain($terminal);
        $this->assertSame('picker', $screen->phase());
        $this->assertStringContainsString('Set default model', $text);
        $this->assertStringContainsString('current: zai/glm-5.3', $text);
        $this->assertStringContainsString('Done', $text);

        $screen->selectValue('default_model');
        $this->assertSame('choice', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Choose the model new chats start with.', $text);
        $this->assertStringContainsString('zai/glm-5.3', $text);
        $this->assertStringContainsString('(current)', $text);

        $screen->selectValue('zai/glm-5.3');
        $this->assertSame('picker', $screen->phase());
        $this->assertFalse($screen->finished());
        $this->assertSame('zai/glm-5.3', $flow->currentDefaultModel());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Set default model', $text);
        $this->assertStringContainsString('current: zai/glm-5.3', $text);
    }

    #[Test]
    public function continueNoExitsStraightToSummaryWithoutDefaultModelAsk(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('grok-cli');
        $screen->selectValue('yes'); // Enable?
        $screen->selectValue('no'); // Exit

        $this->assertSame('summary', $screen->phase());
        $this->assertTrue($screen->finished());
        $text = $this->plain($terminal);
        $this->assertStringNotContainsString('Set as your default model?', $text);
        $this->assertStringNotContainsString('Add another?', $text);
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
     * @return array{0: SetupScreen, 1: VirtualTerminal, 2: Tui}
     */
    private function mount(FakeProvidersSetupFlow $flow, int $columns = 100): array
    {
        $terminal = new VirtualTerminal(columns: $columns, rows: 40);
        $tui = new Tui(terminal: $terminal);
        $screen = new SetupScreen($flow);
        $screen->mount($tui);
        $tui->start();
        $tui->requestRender(force: true);
        $tui->processRender();

        return [$screen, $terminal, $tui];
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
    /** @var list<string> */
    public array $removedCustoms = [];
    /** @var array<string, bool> */
    private array $enabled;

    /**
     * @var array<string, array{
     *     id: string,
     *     baseUrl: string,
     *     completionsPath: string,
     *     apiKey: ?string,
     *     models: array<string, array<string, mixed>>,
     *     supportsDeveloperRole: bool,
     *     thinkingFormat: string
     * }>
     */
    private array $customs;

    /** @var list<array{id: string, models: list<string>, authCommand: ?string}> */
    private array $configured = [];

    private bool $wrote = false;

    private ?string $defaultModel = null;

    /**
     * @param array<string, bool>                                                                                                                                                                                                  $enabled
     * @param array<string, array{id?: string, baseUrl?: string, completionsPath?: string, apiKey?: ?string, models?: array<string, array<string, mixed>>, supportsDeveloperRole?: bool, thinkingFormat?: string, enabled?: bool}> $customs
     */
    public function __construct(array $enabled = [], array $customs = [], ?string $defaultModel = null)
    {
        $this->enabled = $enabled;
        $this->defaultModel = $defaultModel;
        $this->customs = [];
        foreach ($customs as $id => $def) {
            $this->customs[$id] = [
                'id' => $def['id'] ?? $id,
                'baseUrl' => $def['baseUrl'] ?? '',
                'completionsPath' => $def['completionsPath'] ?? '/v1/chat/completions',
                'apiKey' => $def['apiKey'] ?? null,
                'models' => $def['models'] ?? [],
                'supportsDeveloperRole' => $def['supportsDeveloperRole'] ?? false,
                'thinkingFormat' => $def['thinkingFormat'] ?? '',
            ];
            if (\array_key_exists('enabled', $def)) {
                $this->enabled[$id] = (bool) $def['enabled'];
            } elseif (!\array_key_exists($id, $this->enabled)) {
                $this->enabled[$id] = true;
            }
        }
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

    public function customProviderRows(): array
    {
        $rows = [];
        foreach ($this->customs as $id => $def) {
            $rows[] = [
                'id' => $id,
                'url' => $def['baseUrl'],
                'enabled' => $this->isEnabled($id),
            ];
        }

        return $rows;
    }

    public function customDefinition(string $id): ?array
    {
        return $this->customs[$id] ?? null;
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

    public function enableCustom(string $id): void
    {
        if (!isset($this->customs[$id])) {
            throw new \InvalidArgumentException(\sprintf('Unknown custom provider "%s".', $id));
        }
        $this->enabled[$id] = true;
        $this->wrote = true;
        $this->configured[] = ['id' => $id, 'models' => array_keys($this->customs[$id]['models']), 'authCommand' => null];
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

    public function removeCustom(string $id): void
    {
        unset($this->customs[$id], $this->enabled[$id]);
        $this->removedCustoms[] = $id;
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
        $this->customs[$id] = [
            'id' => $id,
            'baseUrl' => $baseUrl,
            'completionsPath' => $completionsPath,
            'apiKey' => $apiKey,
            'models' => $models,
            'supportsDeveloperRole' => $supportsDeveloperRole,
            'thinkingFormat' => $thinkingFormat,
        ];
        $this->enabled[$id] = true;
        $this->wrote = true;
        $this->configured[] = ['id' => $id, 'models' => array_keys($models), 'authCommand' => null];
    }

    public function setDefaultModel(string $ref): void
    {
        $this->defaultModel = $ref;
        $this->wrote = true;
    }

    public function currentDefaultModel(): ?string
    {
        return null !== $this->defaultModel && '' !== $this->defaultModel ? $this->defaultModel : null;
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
