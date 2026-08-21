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
        $this->assertSame('custom', $screen->phase());
        $this->assertStringContainsString('Add your own server', $this->plain($terminal));

        $tui->handleInput("\x04");

        $this->assertTrue($screen->finished());
        $this->assertSame('summary', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('AI Provider Setup', $text);
        $this->assertStringNotContainsString('Add your own server', $text);
    }

    #[Test]
    public function listEnterSurvivesDetachAndReattachRoundtrip(): void
    {
        // list → custom settings (list detached) → Esc back → list re-added;
        // Enter must still fire onSelect after re-wire.
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $screen->selectValue('custom');
        $this->assertSame('custom', $screen->phase());

        $tui->handleInput("\x1b"); // Esc back to picker (list re-attached)
        $this->assertSame('picker', $screen->phase());

        $tui->handleInput("\r"); // first row = zai → api_where choice
        $this->assertSame('choice', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('environment variable', $text);
        $this->assertStringContainsString('Esc back · Ctrl+D quit', $text);
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
    public function customFormShowsAllRowsWithHelpDescriptions(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $text = $this->plain($terminal);
        $this->assertSame('custom', $screen->phase());
        $this->assertStringContainsString('Add your own server', $text);
        $this->assertStringContainsString('All options on one screen', $text);
        $this->assertStringContainsString('Provider id', $text);
        $this->assertStringContainsString('Server URL', $text);
        $this->assertStringContainsString('Completions path', $text);
        $this->assertStringContainsString('API key', $text);
        $this->assertStringContainsString('Saved models', $text);
        $this->assertStringContainsString('Model id', $text);
        $this->assertStringContainsString('Display name', $text);
        $this->assertStringContainsString('Context window', $text);
        $this->assertStringContainsString('Max output tokens', $text);
        $this->assertStringContainsString('Modalities', $text);
        $this->assertStringContainsString('Reasoning', $text);
        $this->assertStringContainsString('Developer role', $text);
        $this->assertStringContainsString('Reasoning format', $text);
        $this->assertStringContainsString('Add model to list', $text);
        $this->assertSame(1, substr_count($text, 'Save and enable')); // orphan-guard: exactly one save row
        // Selected row description (first = Provider id)
        $this->assertStringContainsString('A short name to identify this provider', $text);
        $this->assertStringContainsString('Example: runpod', $text);
        $this->assertStringNotContainsString('Other server', $text);
        $this->assertStringNotContainsString('Done', $text);
        $this->assertStringContainsString('┌', $text);
    }

    #[Test]
    public function customFormCyclesYesNoAndSavesFullDefinition(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->changeSetting('id', 'local-llm');
        $screen->changeSetting('baseUrl', 'http://127.0.0.1:8080');
        $screen->changeSetting('completionsPath', '/v1/chat/completions');
        $screen->changeSetting('apiKey', 'LOCAL_API_KEY');
        $screen->changeSetting('modelId', 'llama-3');
        $screen->changeSetting('modelName', 'Llama 3');
        $screen->changeSetting('contextWindow', '128000');
        $screen->changeSetting('maxTokens', '8192');
        $screen->changeSetting('modalities', 'text');
        $screen->changeSetting('reasoning', 'no');
        $screen->changeSetting('supportsDeveloperRole', 'no');
        $screen->changeSetting('thinkingFormat', '');
        $screen->changeSetting('save', '↵');

        $this->assertSame('confirm', $screen->phase());
        $this->assertCount(1, $flow->savedCustoms);
        $saved = $flow->savedCustoms[0];
        $this->assertSame('local-llm', $saved['id']);
        $this->assertSame('http://127.0.0.1:8080', $saved['baseUrl']);
        $this->assertSame('/v1/chat/completions', $saved['completionsPath']);
        $this->assertSame('env:LOCAL_API_KEY', $saved['apiKey']);
        $this->assertArrayHasKey('llama-3', $saved['models']);
        $this->assertSame('Llama 3', $saved['models']['llama-3']['name']);
        $this->assertSame(['text'], $saved['models']['llama-3']['input']);
        $this->assertFalse($saved['models']['llama-3']['reasoning']);
        $this->assertFalse($saved['supportsDeveloperRole']);
        $this->assertSame('', $saved['thinkingFormat']);
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Continue', $text);
        $this->assertStringContainsString('Exit', $text);
    }

    #[Test]
    public function customFormAddModelThenSaveKeepsBothModels(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->changeSetting('id', 'multi');
        $screen->changeSetting('baseUrl', 'http://127.0.0.1:8080');
        $screen->changeSetting('modelId', 'a');
        $screen->changeSetting('modelName', 'A');
        $screen->changeSetting('add_model', '↵');
        $text = $this->plain($terminal);
        $this->assertStringContainsString('a', $text); // saved models row
        $screen->changeSetting('modelId', 'b');
        $screen->changeSetting('modelName', 'B');
        $screen->changeSetting('modalities', 'text+image');
        $screen->changeSetting('reasoning', 'yes');
        $screen->changeSetting('save', '↵');

        $this->assertCount(1, $flow->savedCustoms);
        $models = $flow->savedCustoms[0]['models'];
        $this->assertArrayHasKey('a', $models);
        $this->assertArrayHasKey('b', $models);
        $this->assertSame(['text', 'image'], $models['b']['input']);
        $this->assertTrue($models['b']['reasoning']);
        $this->assertNotSame([], $models['b']['thinking_level_map']);
    }

    #[Test]
    public function customCatalogCollisionShowsInlineErrorAndStaysOnForm(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->changeSetting('id', 'zai');

        $this->assertSame('custom', $screen->phase());
        $this->assertStringContainsString(
            '"zai" is built into Hatfield — choose it from the list above instead.',
            $screen->errorText(),
        );
        $text = $this->plain($terminal);
        $this->assertStringContainsString('⚠', $text);
        $this->assertSame([], $flow->savedCustoms);
    }

    #[Test]
    public function customSaveWithZeroModelsShowsError(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->changeSetting('id', 'empty');
        $screen->changeSetting('baseUrl', 'http://127.0.0.1:8080');
        $screen->changeSetting('modelId', ''); // clear draft
        $screen->changeSetting('save', '↵');

        $this->assertSame('custom', $screen->phase());
        $this->assertStringContainsString('Add at least one model.', $screen->errorText());
        $this->assertSame([], $flow->savedCustoms);
    }

    #[Test]
    public function customSaveRequiresServerUrl(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen] = $this->mount($flow);

        $screen->selectValue('custom');
        $screen->changeSetting('id', 'nourl');
        $screen->changeSetting('baseUrl', '');
        $screen->changeSetting('modelId', 'm');
        $screen->changeSetting('save', '↵');

        $this->assertSame('custom', $screen->phase());
        $this->assertStringContainsString('Server URL is required.', $screen->errorText());
        $this->assertSame([], $flow->savedCustoms);
    }

    #[Test]
    public function customSubmenuTextPathTypesAndSavesViaRealEvents(): void
    {
        // Drives activateCurrentItem → submenu factory → SelectEvent → SettingChangeEvent.
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $screen->selectValue('custom');
        $this->assertSame('custom', $screen->phase());

        // Row 0 = Provider id (default 'local'). Clear, type, backspace, submit.
        $tui->handleInput("\r");
        $tui->processRender();
        for ($i = 0; $i < 5; ++$i) { // erase 'local'
            $tui->handleInput("\x7f");
        }
        foreach (str_split('runpodx') as $ch) {
            $tui->handleInput($ch);
        }
        $tui->handleInput("\x7f"); // backspace trailing x
        $tui->handleInput("\r"); // submit submenu
        $tui->processRender();

        $text = $this->plain($terminal);
        $this->assertStringContainsString('runpod', $text);
        $this->assertStringNotContainsString('runpodx', $text);
        $this->assertStringNotContainsString('local', $text);

        // Fill remaining required fields via drivers, then Save via changeSetting.
        $screen->changeSetting('baseUrl', 'https://abc.proxy.runpod.net');
        $screen->changeSetting('modelId', 'llama');
        $screen->changeSetting('modelName', 'Llama');
        $screen->changeSetting('save', '↵');

        $this->assertCount(1, $flow->savedCustoms);
        $this->assertSame('runpod', $flow->savedCustoms[0]['id']);
        $this->assertSame('https://abc.proxy.runpod.net', $flow->savedCustoms[0]['baseUrl']);
    }

    #[Test]
    public function customSubmenuEscCancelsWithoutChangingValue(): void
    {
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $screen->selectValue('custom');
        $before = $this->plain($terminal);
        $this->assertStringContainsString('local', $before); // default id

        $tui->handleInput("\r"); // open id submenu
        $tui->processRender();
        foreach (str_split('changed') as $ch) {
            $tui->handleInput($ch);
        }
        $tui->handleInput("\x1b"); // Esc cancel
        $tui->processRender();

        $this->assertSame('custom', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('local', $text);
        $this->assertStringNotContainsString('changed', $text);
        $this->assertSame([], $flow->savedCustoms);
    }

    #[Test]
    public function ctrlDInsideOpenCustomSubmenuQuits(): void
    {
        // SettingsListWidget.onInput(quitOnCtrlD) runs BEFORE submenu forward.
        $flow = new FakeProvidersSetupFlow();
        [$screen, $terminal, $tui] = $this->mount($flow);

        $screen->selectValue('custom');
        $tui->handleInput("\r"); // open id submenu
        $tui->processRender();
        $tui->handleInput("\x04"); // Ctrl+D

        $this->assertTrue($screen->finished());
        $this->assertSame('summary', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('AI Provider Setup', $text);
        $this->assertStringNotContainsString('Add your own server', $text);
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

        $this->assertSame('custom', $screen->phase());
        $text = $this->plain($terminal);
        $this->assertStringContainsString('Edit your server', $text);
        $this->assertStringContainsString('https://abc.proxy.runpod.net', $text);
        $this->assertStringContainsString('/v1/chat/completions', $text);
        $this->assertStringContainsString('env:RUNPOD_API_KEY', $text);
        $this->assertStringContainsString('llama', $text);
        $this->assertStringContainsString('Locked while editing', $text);
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
