<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Standalone providers:setup TUI — not coupled to ChatScreen / InteractiveMode.
 *
 * Booted by {@see \Ineersa\CodingAgent\CLI\Providers\ProvidersSetupCommand}
 * against a {@see ProvidersSetupFlowInterface} implementation.
 */
final class SetupScreen
{
    private const PHASE_PICKER = 'picker';
    private const PHASE_SERVERS = 'servers';
    private const PHASE_ACTION = 'action';
    private const PHASE_CONFIRM = 'confirm';
    private const PHASE_CHOICE = 'choice';
    private const PHASE_INPUT = 'input';
    private const PHASE_CUSTOM = 'custom';
    private const PHASE_SUMMARY = 'summary';

    private Tui $tui;
    private TextWidget $titleWidget;
    private ContainerWidget $panelWidget;
    private TextWidget $stepWidget;
    private TextWidget $hintWidget;
    private TextWidget $errorWidget;
    private TextWidget $footerWidget;
    private SelectListWidget $listWidget;
    private InputWidget $inputWidget;
    private SettingsListWidget $settingsWidget;
    private bool $mounted = false;

    private string $phase = self::PHASE_PICKER;
    private ?string $activeProviderId = null;
    private string $formKind = '';
    /** @var array<string, mixed> */
    private array $formState = [];
    private ?string $pendingConfirm = null;
    /** Where action-menu Cancel returns: picker or servers submenu. */
    private string $actionReturn = self::PHASE_PICKER;
    private ?string $error = null;
    private int $exitCode = 0;

    public function __construct(
        private readonly ProvidersSetupFlowInterface $flow,
    ) {
        $this->titleWidget = new TextWidget('AI Provider Setup');
        $this->titleWidget->setStyle(new Style(bold: true, color: Color::named('cyan')));

        $this->panelWidget = new ContainerWidget();
        $this->panelWidget->setStyle(new Style(
            border: Border::all(1),
            padding: Padding::xy(2, 1),
            gap: 1,
        ));

        $this->stepWidget = new TextWidget('');
        $this->hintWidget = new TextWidget('Hatfield needs at least one AI provider to run.');
        $this->errorWidget = new TextWidget('');
        $this->footerWidget = new TextWidget('');
        $this->listWidget = new SelectListWidget([], maxVisible: 12);
        $this->inputWidget = new InputWidget();
        $this->settingsWidget = new SettingsListWidget([], maxVisible: 16);
        $this->applyQuitSetupBindings($this->listWidget);
        $this->applyQuitSetupBindings($this->inputWidget);
        $this->applyQuitSetupBindings($this->settingsWidget);

        // Static panel chrome — list/input/settings are mounted by applyPhaseLayout().
        $this->panelWidget->add($this->stepWidget);
        $this->panelWidget->add($this->hintWidget);
        $this->panelWidget->add($this->errorWidget);
    }

    public function mount(Tui $tui): void
    {
        if ($this->mounted) {
            return;
        }
        $this->mounted = true;
        $this->tui = $tui;

        $tui->add($this->titleWidget);
        $tui->add($this->panelWidget);
        $tui->add($this->footerWidget);

        $this->showPicker();
    }

    public function run(?TerminalInterface $terminal = null): int
    {
        $this->tui = new Tui(terminal: $terminal ?? new Terminal());
        $this->mount($this->tui);
        $this->tui->run();

        return $this->exitCode;
    }

    private function wireListListeners(): void
    {
        $this->listWidget->onSelect(function (SelectEvent $event): void {
            $value = $event->getValue();
            if ('' === $value) {
                return;
            }
            $this->onListSelect($value);
        });
        $this->listWidget->onCancel(function (CancelEvent $_): void {
            if (\in_array($this->phase, [self::PHASE_PICKER, self::PHASE_SUMMARY], true)) {
                $this->finishSuccess();
            } elseif (self::PHASE_SERVERS === $this->phase) {
                $this->showPicker();
            } elseif (\in_array($this->phase, [self::PHASE_ACTION, self::PHASE_CONFIRM], true)
                && self::PHASE_SERVERS === $this->actionReturn) {
                $this->showServersSubmenu();
            } else {
                $this->showPicker();
            }
        });
        $this->listWidget->onInput($this->quitOnCtrlD(...));
    }

    private function wireInputListeners(): void
    {
        $this->inputWidget->onSubmit(function (SubmitEvent $_): void {
            $this->onInputSubmit(trim($this->inputWidget->getValue()));
        });
        $this->inputWidget->onCancel(function (CancelEvent $_): void {
            $this->showPicker();
        });
        $this->inputWidget->onInput($this->quitOnCtrlD(...));
    }

    private function wireSettingsListeners(): void
    {
        $this->settingsWidget->onChange(function (SettingChangeEvent $event): void {
            $this->onCustomSettingChange($event->getId(), $event->getValue());
        });
        $this->settingsWidget->onCancel(function (CancelEvent $_): void {
            // Edit path always sets actionReturn=SERVERS; add path keeps PICKER.
            if (self::PHASE_SERVERS === $this->actionReturn) {
                $this->showServersSubmenu();

                return;
            }
            $this->showPicker();
        });
        $this->settingsWidget->onInput($this->quitOnCtrlD(...));
    }

    private function quitOnCtrlD(string $data): bool
    {
        if ($this->activeQuitWidget()->getKeybindings()->matches($data, 'quit_setup')) {
            $this->finishSuccess();

            return true;
        }

        return false;
    }

    private function applyQuitSetupBindings(SelectListWidget|InputWidget|SettingsListWidget $widget): void
    {
        $widget->setKeybindings(new Keybindings([
            'quit_setup' => [Key::ctrl('d')],
        ]));
    }

    private function activeQuitWidget(): SelectListWidget|InputWidget|SettingsListWidget
    {
        return match ($this->phase) {
            self::PHASE_INPUT => $this->inputWidget,
            self::PHASE_CUSTOM => $this->settingsWidget,
            default => $this->listWidget,
        };
    }

    private function onListSelect(string $value): void
    {
        $this->error = null;
        match ($this->phase) {
            self::PHASE_PICKER => $this->handlePickerSelect($value),
            self::PHASE_SERVERS => $this->handleServersSelect($value),
            self::PHASE_ACTION => $this->handleActionSelect($value),
            self::PHASE_CONFIRM => $this->handleConfirmSelect($value),
            self::PHASE_CHOICE => $this->handleChoiceSelect($value),
            default => null,
        };
        $this->refreshError();
    }

    private function onInputSubmit(string $value): void
    {
        $this->error = null;
        try {
            match ($this->formKind) {
                'api_env_name' => $this->finishApiEnv($value),
                'api_raw_key' => $this->finishApiRaw($value),
                'api_raw_confirm' => $this->finishApiRawConfirm($value),
                default => $this->showPicker(),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
            $this->refreshError();
            $this->focusInput();
        }
    }

    private function handlePickerSelect(string $value): void
    {
        if ('done' === $value) {
            $this->finishSuccess();

            return;
        }
        if ('default_model' === $value) {
            $this->showDefaultModelPicker();

            return;
        }
        if ('custom' === $value) {
            if ([] !== $this->flow->customProviderRows()) {
                $this->showServersSubmenu();
            } else {
                $this->startCustom();
            }

            return;
        }

        $this->activeProviderId = $value;
        if ($this->flow->isEnabled($value)) {
            $this->showActionMenu(self::PHASE_PICKER);

            return;
        }

        if ('oauth' === $this->catalogKind($value)) {
            $this->pendingConfirm = 'oauth_enable';
            $this->phase = self::PHASE_CONFIRM;
            $this->hintWidget->setText(\sprintf('Enable %s?', $this->labelFor($value)));
            $this->showList($this->confirmItems());

            return;
        }

        $this->startEnable($value);
    }

    private function handleServersSelect(string $value): void
    {
        if ('back' === $value) {
            $this->showPicker();

            return;
        }
        if ('add' === $value) {
            $this->startCustom();

            return;
        }

        $this->activeProviderId = $value;
        $this->showActionMenu(self::PHASE_SERVERS);
    }

    private function handleActionSelect(string $value): void
    {
        if ('cancel' === $value || null === $this->activeProviderId) {
            $this->returnFromAction();

            return;
        }
        if ('disable' === $value) {
            $this->pendingConfirm = 'disable';
            $label = $this->labelFor($this->activeProviderId);
            $this->phase = self::PHASE_CONFIRM;
            $this->hintWidget->setText(\sprintf('Disable %s? This clears its settings entry.', $label));
            $this->showList($this->confirmItems());

            return;
        }
        if ('enable' === $value) {
            $id = $this->activeProviderId;
            $this->flow->enableCustom($id);
            $this->askContinue(\sprintf('%s enabled.', $this->labelFor($id)));

            return;
        }
        if ('remove' === $value) {
            $this->pendingConfirm = 'remove_custom';
            $id = $this->activeProviderId;
            $this->phase = self::PHASE_CONFIRM;
            $this->hintWidget->setText(\sprintf('Remove %s? This deletes its settings entry.', $id));
            $this->showList($this->confirmItems());

            return;
        }
        if ('edit' === $value) {
            $this->startCustomEdit($this->activeProviderId);

            return;
        }
        $this->startEnable($this->activeProviderId);
    }

    private function handleConfirmSelect(string $value): void
    {
        if ('oauth_enable' === $this->pendingConfirm) {
            $id = $this->activeProviderId;
            $this->pendingConfirm = null;
            if ('no' === $value || null === $id) {
                $this->showPicker();

                return;
            }
            $this->startEnable($id);

            return;
        }
        if ('disable' === $this->pendingConfirm) {
            if ('no' === $value || null === $this->activeProviderId) {
                $this->returnFromAction();

                return;
            }
            $id = $this->activeProviderId;
            $this->flow->disable($id);
            $warning = $this->flow->defaultModelWarningFor($id);
            $this->pendingConfirm = null;
            $this->activeProviderId = null;
            if (null !== $warning) {
                $this->error = $warning;
            }
            $this->askContinue(\sprintf('%s disabled.', $this->labelFor($id)));

            return;
        }
        if ('remove_custom' === $this->pendingConfirm) {
            if ('no' === $value || null === $this->activeProviderId) {
                $this->returnFromAction();

                return;
            }
            $id = $this->activeProviderId;
            $this->flow->removeCustom($id);
            $this->pendingConfirm = null;
            $this->activeProviderId = null;
            $this->askContinue(\sprintf('%s removed.', $id));

            return;
        }
        if ('add_another' === $this->pendingConfirm) {
            $this->pendingConfirm = null;
            if ('yes' === $value) {
                $this->showPicker();
            } else {
                $this->finishSuccess();
            }

            return;
        }
        $this->showPicker();
    }

    private function handleChoiceSelect(string $value): void
    {
        match ($this->formKind) {
            'api_where' => $this->handleApiWhere($value),
            'default_model' => $this->handleDefaultModel($value),
            default => $this->showPicker(),
        };
    }

    private function startEnable(string $id): void
    {
        $this->activeProviderId = $id;
        $kind = $this->catalogKind($id);
        if ('oauth' === $kind) {
            $this->flow->enableOauth($id);
            $this->askContinue(\sprintf('%s enabled.', $this->labelFor($id)));

            return;
        }

        $this->formKind = 'api_where';
        $this->formState = ['id' => $id];
        $this->phase = self::PHASE_CHOICE;
        $this->hintWidget->setText('API key: read from an environment variable, or paste it now?');
        $this->showList([
            ['value' => 'env', 'label' => 'environment variable'],
            ['value' => 'raw', 'label' => 'paste'],
        ]);
    }

    private function handleApiWhere(string $value): void
    {
        $id = (string) ($this->formState['id'] ?? '');
        if ('raw' === $value) {
            $this->beginInput('api_raw_key', 'Paste your API key (shown; confirm next)');

            return;
        }
        $this->beginInput('api_env_name', 'Variable name', $this->suggestedEnvVar($id));
    }

    private function finishApiEnv(string $value): void
    {
        $id = (string) ($this->formState['id'] ?? '');
        $apiKey = $this->flow->formatEnvApiKey('' !== $value ? $value : $this->suggestedEnvVar($id));
        $this->flow->enableApiKey($id, $apiKey);
        $this->askContinue(\sprintf('%s enabled.', $this->labelFor($id))."\n(Everything else is preconfigured.)");
    }

    private function finishApiRaw(string $value): void
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('API key cannot be empty.');
        }
        $this->formState['raw'] = $value;
        $this->beginInput('api_raw_confirm', 'Re-type to confirm');
    }

    private function finishApiRawConfirm(string $value): void
    {
        $raw = (string) ($this->formState['raw'] ?? '');
        if ($value !== $raw) {
            throw new \InvalidArgumentException('Keys did not match — try again.');
        }
        $id = (string) ($this->formState['id'] ?? '');
        $this->flow->enableApiKey($id, $raw);
        $this->askContinue(\sprintf('%s enabled.', $this->labelFor($id))."\n(Everything else is preconfigured.)");
    }

    private function startCustom(): void
    {
        $this->actionReturn = self::PHASE_PICKER;
        $this->formState = $this->blankCustomState();
        $this->showCustomForm();
    }

    private function startCustomEdit(string $id): void
    {
        $definition = $this->flow->customDefinition($id);
        if (null === $definition) {
            $this->error = \sprintf('Unknown server "%s".', $id);
            $this->showServersSubmenu();

            return;
        }

        $this->actionReturn = self::PHASE_SERVERS;
        $this->formState = [
            'id' => $definition['id'],
            'baseUrl' => $definition['baseUrl'],
            'completionsPath' => $definition['completionsPath'],
            'apiKey' => $definition['apiKey'],
            // ponytail: edit keeps existing models; no per-model delete UI — remove+re-add to drop one.
            'models' => $definition['models'],
            'supportsDeveloperRole' => $definition['supportsDeveloperRole'],
            'thinkingFormat' => $definition['thinkingFormat'],
            'editing' => true,
            'modelId' => '',
            'modelName' => '',
            'contextWindow' => '128000',
            'maxTokens' => '8192',
            'modalities' => 'text',
            'reasoning' => 'no',
        ];
        $this->showCustomForm();
    }

    /**
     * @return array<string, mixed>
     */
    private function blankCustomState(): array
    {
        return [
            'id' => 'local',
            'baseUrl' => 'http://127.0.0.1:8080',
            'completionsPath' => '/v1/chat/completions',
            'apiKey' => null,
            'models' => [],
            'supportsDeveloperRole' => false,
            'thinkingFormat' => '',
            'editing' => false,
            'modelId' => 'default',
            'modelName' => 'default',
            'contextWindow' => '128000',
            'maxTokens' => '8192',
            'modalities' => 'text',
            'reasoning' => 'no',
        ];
    }

    private function showCustomForm(): void
    {
        $this->phase = self::PHASE_CUSTOM;
        $this->formKind = '';
        $this->error = null;
        $this->applyChrome();
        $this->hintWidget->setText('Edit any row. Enter opens editor or toggles. Save when ready.');
        // Remove before reassign — ContainerWidget::remove() no-ops on a fresh
        // unattached instance, which would orphan the previously mounted form.
        $this->panelWidget->remove($this->settingsWidget);
        $this->settingsWidget = new SettingsListWidget($this->customSettingItems(), maxVisible: 16);
        $this->applyQuitSetupBindings($this->settingsWidget);
        $this->refreshError();
        $this->refreshFooter();
        $this->applyPhaseLayout();
        $this->tui->setFocus($this->settingsWidget);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    /**
     * @return list<SettingItem>
     */
    private function customSettingItems(): array
    {
        $editing = true === ($this->formState['editing'] ?? false);
        $id = (string) ($this->formState['id'] ?? 'local');
        $apiKeyDisplay = $this->apiKeyDisplay();
        $models = \is_array($this->formState['models'] ?? null) ? $this->formState['models'] : [];
        $modelIds = array_keys($models);
        $modelsDisplay = [] === $modelIds ? '(none yet)' : implode(', ', $modelIds);

        $items = [];
        if ($editing) {
            $items[] = new SettingItem(
                'id',
                'Provider id',
                $id,
                'Locked while editing. Remove and re-add to rename.',
            );
        } else {
            $items[] = new SettingItem(
                'id',
                'Provider id',
                $id,
                'A short name to identify this provider in menus and settings. Example: runpod',
                submenu: $this->textSubmenu(...),
            );
        }

        $items[] = new SettingItem(
            'baseUrl',
            'Server URL',
            (string) ($this->formState['baseUrl'] ?? ''),
            'The address of the API server Hatfield will talk to. Example: https://abc-123.proxy.runpod.net',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'completionsPath',
            'Completions path',
            (string) ($this->formState['completionsPath'] ?? '/v1/chat/completions'),
            'Where the server accepts chat requests. Nearly all OpenAI-compatible servers use /v1/chat/completions.',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'apiKey',
            'API key',
            $apiKeyDisplay,
            'Blank = none. Env var name (RUNPOD_API_KEY) or env:NAME, or paste a raw key (stored in settings).',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'modelsSaved',
            'Saved models',
            $modelsDisplay,
            'Models already on this provider. Add another below, then Save.',
        );
        $items[] = new SettingItem(
            'modelId',
            'Model id',
            (string) ($this->formState['modelId'] ?? ''),
            'The model\'s id exactly as the server expects it in requests. Example: llama-3.3-70b',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'modelName',
            'Display name',
            (string) ($this->formState['modelName'] ?? ''),
            'A friendly name shown in the model picker. Defaults to the id.',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'contextWindow',
            'Context window',
            (string) ($this->formState['contextWindow'] ?? '128000'),
            'How much text (tokens) the model can read at once. 128000 is a safe default.',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'maxTokens',
            'Max output tokens',
            (string) ($this->formState['maxTokens'] ?? '8192'),
            'The most text the model can produce in one reply.',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'modalities',
            'Modalities',
            (string) ($this->formState['modalities'] ?? 'text'),
            'What kinds of input the model accepts: text only, or text and images.',
            values: ['text', 'text+image'],
        );
        $items[] = new SettingItem(
            'reasoning',
            'Reasoning',
            (string) ($this->formState['reasoning'] ?? 'no'),
            'Whether this model shows its thinking before answering.',
            values: ['yes', 'no'],
        );
        $items[] = new SettingItem(
            'supportsDeveloperRole',
            'Developer role',
            true === ($this->formState['supportsDeveloperRole'] ?? false) ? 'yes' : 'no',
            'Whether the server accepts \'developer\' role messages. Some clones only accept \'system\' — pick No then.',
            values: ['yes', 'no'],
        );
        $items[] = new SettingItem(
            'thinkingFormat',
            'Reasoning format',
            (string) ($this->formState['thinkingFormat'] ?? ''),
            'Label the server uses to return thinking output, if any. Leave blank if it doesn\'t.',
            submenu: $this->textSubmenu(...),
        );
        $items[] = new SettingItem(
            'add_model',
            'Add model to list',
            '↵',
            'Commit the model fields above into Saved models, then clear them for another.',
            values: ['↵'],
        );
        $items[] = new SettingItem(
            'save',
            'Save and enable',
            '↵',
            'Validate and write the full provider definition to your settings.',
            values: ['↵'],
        );

        return $items;
    }

    private function textSubmenu(string $current): SettingsTextInputWidget
    {
        // Vendor submenu factory passes ($current, $onDone); PHP ignores the extra arg.
        return new SettingsTextInputWidget($current);
    }

    private function onCustomSettingChange(string $id, string $value): void
    {
        $this->error = null;
        try {
            match ($id) {
                'id' => $this->setCustomId($value),
                'baseUrl' => $this->formState['baseUrl'] = rtrim(trim($value), '/'),
                'completionsPath' => $this->formState['completionsPath'] = $this->normalizePath($value),
                'apiKey' => $this->formState['apiKey'] = $this->normalizeApiKeyInput($value),
                'modelId' => $this->setModelId($value),
                'modelName' => $this->formState['modelName'] = trim($value),
                'contextWindow' => $this->formState['contextWindow'] = $this->normalizePositiveInt($value, 'Context window', '128000'),
                'maxTokens' => $this->formState['maxTokens'] = $this->normalizePositiveInt($value, 'Max tokens', '8192'),
                'modalities' => $this->formState['modalities'] = $value,
                'reasoning' => $this->formState['reasoning'] = $value,
                'supportsDeveloperRole' => $this->formState['supportsDeveloperRole'] = 'yes' === $value,
                'thinkingFormat' => $this->formState['thinkingFormat'] = trim($value),
                'add_model' => $this->commitDraftModel(),
                'save' => $this->saveCustomForm(),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
        if (self::PHASE_CUSTOM !== $this->phase) {
            $this->refreshError();

            return;
        }
        if (null !== $this->error) {
            // Vendor submenu writes the rejected value onto the item before onChange;
            // restore from formState so the row stays coherent without a rebuild.
            if (!\in_array($id, ['save', 'add_model', 'modalities', 'reasoning'], true)) {
                $this->settingsWidget->updateValue($id, $this->displayForSetting($id, $value));
            }
            $this->refreshError();

            return;
        }
        if ('add_model' === $id) {
            $this->refreshCustomFormValuesAfterModelCommit();
            $this->refreshError();

            return;
        }

        $this->settingsWidget->updateValue($id, $this->displayForSetting($id, $value));
        if ('modelId' === $id && '' !== (string) ($this->formState['modelName'] ?? '')) {
            $this->settingsWidget->updateValue('modelName', (string) $this->formState['modelName']);
        }
        $this->refreshError();
    }

    private function displayForSetting(string $id, string $value): string
    {
        // Prefer formState so normalized values (rtrim URL, int coerce) and
        // error-path restores beat the raw vendor event value.
        return match ($id) {
            'apiKey' => $this->apiKeyDisplay(),
            'supportsDeveloperRole' => true === ($this->formState['supportsDeveloperRole'] ?? false) ? 'yes' : 'no',
            default => (string) ($this->formState[$id] ?? $value),
        };
    }

    private function apiKeyDisplay(): string
    {
        $apiKey = $this->formState['apiKey'] ?? null;

        return \is_string($apiKey) && '' !== $apiKey ? $apiKey : '(none)';
    }

    private function refreshCustomFormValuesAfterModelCommit(): void
    {
        $models = \is_array($this->formState['models'] ?? null) ? $this->formState['models'] : [];
        $modelIds = array_keys($models);
        $modelsDisplay = [] === $modelIds ? '(none yet)' : implode(', ', $modelIds);
        $this->settingsWidget->updateValue('modelsSaved', $modelsDisplay);
        // commitDraftModel always resets draft keys before this runs.
        $this->settingsWidget->updateValue('modelId', (string) $this->formState['modelId']);
        $this->settingsWidget->updateValue('modelName', (string) $this->formState['modelName']);
        $this->settingsWidget->updateValue('contextWindow', (string) $this->formState['contextWindow']);
        $this->settingsWidget->updateValue('maxTokens', (string) $this->formState['maxTokens']);
        $this->settingsWidget->updateValue('modalities', (string) $this->formState['modalities']);
        $this->settingsWidget->updateValue('reasoning', (string) $this->formState['reasoning']);
    }

    private function setCustomId(string $value): void
    {
        if (true === ($this->formState['editing'] ?? false)) {
            return;
        }
        $id = strtolower(trim('' !== $value ? $value : 'local'));
        $this->flow->validateCustomId($id);
        $this->formState['id'] = $id;
    }

    private function setModelId(string $value): void
    {
        $modelId = trim($value);
        $this->formState['modelId'] = $modelId;
        if ('' !== $modelId && '' === trim((string) ($this->formState['modelName'] ?? ''))) {
            $this->formState['modelName'] = $modelId;
        }
    }

    private function normalizePath(string $value): string
    {
        $path = trim($value);
        if ('' === $path) {
            $path = '/v1/chat/completions';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $path;
    }

    private function normalizeApiKeyInput(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value || '(none)' === $value) {
            return null;
        }
        if (str_starts_with($value, 'env:')) {
            return $this->flow->formatEnvApiKey(substr($value, 4));
        }
        if (1 === preg_match('/^[A-Z][A-Z0-9_]*$/', $value)) {
            return $this->flow->formatEnvApiKey($value);
        }

        return $value;
    }

    private function normalizePositiveInt(string $value, string $label, string $default): string
    {
        $raw = '' !== trim($value) ? trim($value) : $default;
        $n = (int) $raw;
        if ($n < 1) {
            throw new \InvalidArgumentException($label.' must be >= 1.');
        }

        return (string) $n;
    }

    private function commitDraftModel(): void
    {
        $modelId = trim((string) ($this->formState['modelId'] ?? ''));
        if ('' === $modelId) {
            throw new \InvalidArgumentException('Model id is required to add a model.');
        }
        $models = \is_array($this->formState['models'] ?? null) ? $this->formState['models'] : [];
        $models[$modelId] = $this->draftModelPayload($modelId);
        $this->formState['models'] = $models;
        $this->formState['modelId'] = '';
        $this->formState['modelName'] = '';
        $this->formState['contextWindow'] = '128000';
        $this->formState['maxTokens'] = '8192';
        $this->formState['modalities'] = 'text';
        $this->formState['reasoning'] = 'no';
    }

    /**
     * @return array<string, mixed>
     */
    private function draftModelPayload(string $modelId): array
    {
        $name = trim((string) ($this->formState['modelName'] ?? ''));
        if ('' === $name) {
            $name = $modelId;
        }
        $reasoning = 'yes' === ($this->formState['reasoning'] ?? 'no');

        return [
            'name' => $name,
            'context_window' => (int) ($this->formState['contextWindow'] ?? 128000),
            'max_tokens' => (int) ($this->formState['maxTokens'] ?? 8192),
            'input' => 'text+image' === ($this->formState['modalities'] ?? 'text') ? ['text', 'image'] : ['text'],
            'tool_calling' => true,
            'reasoning' => $reasoning,
            'thinking_level_map' => $reasoning ? $this->flow->defaultThinkingLevelMap() : [],
            'cost' => [
                'input' => 0,
                'output' => 0,
                'cache_read' => 0,
                'cache_write' => 0,
            ],
        ];
    }

    private function saveCustomForm(): void
    {
        $models = \is_array($this->formState['models'] ?? null) ? $this->formState['models'] : [];
        $draftId = trim((string) ($this->formState['modelId'] ?? ''));
        if ('' !== $draftId) {
            $models[$draftId] = $this->draftModelPayload($draftId);
        }
        if ([] === $models) {
            throw new \InvalidArgumentException('Add at least one model.');
        }

        $id = (string) ($this->formState['id'] ?? '');
        $baseUrl = (string) ($this->formState['baseUrl'] ?? '');
        if ('' === trim($baseUrl)) {
            throw new \InvalidArgumentException('Server URL is required.');
        }

        $apiKey = $this->formState['apiKey'] ?? null;
        $this->flow->saveCustom(
            $id,
            $baseUrl,
            (string) ($this->formState['completionsPath'] ?? '/v1/chat/completions'),
            \is_string($apiKey) && '' !== $apiKey ? $apiKey : null,
            $models,
            (bool) ($this->formState['supportsDeveloperRole'] ?? false),
            (string) ($this->formState['thinkingFormat'] ?? ''),
        );
        $verb = true === ($this->formState['editing'] ?? false) ? 'updated' : 'added';
        $this->askContinue(\sprintf('%s %s.', $id, $verb));
    }

    private function askContinue(string $message): void
    {
        $this->pendingConfirm = 'add_another';
        $this->phase = self::PHASE_CONFIRM;
        $this->formKind = '';
        $this->formState = [];
        $this->hintWidget->setText($message."\n\nContinue?");
        $this->showList([
            ['value' => 'yes', 'label' => 'Continue'],
            ['value' => 'no', 'label' => 'Exit'],
        ]);
    }

    private function showDefaultModelPicker(): void
    {
        $this->formKind = 'default_model';
        $this->phase = self::PHASE_CHOICE;
        $current = $this->flow->currentDefaultModel();
        $this->hintWidget->setText('Choose the model new chats start with.');
        $items = [];
        foreach ($this->flow->configuredModelRefs() as $ref) {
            $item = ['value' => $ref, 'label' => $ref];
            if ($ref === $current) {
                $item['description'] = '(current)';
            }
            $items[] = $item;
        }
        $this->showList($items);
    }

    private function handleDefaultModel(string $value): void
    {
        $this->flow->setDefaultModel($value);
        $this->showPicker();
    }

    private function finishSuccess(): void
    {
        $lines = [];
        if ($this->flow->wroteSomething()) {
            $lines[] = 'Saved to '.$this->flow->settingsPath();
        }
        foreach ($this->flow->pendingAuthCommands() as $authCommand) {
            $lines[] = \sprintf('To finish: run `hatfield %s` and log in.', $authCommand);
        }
        if ([] === $lines) {
            $lines[] = 'Nothing changed.';
        }

        $this->phase = self::PHASE_SUMMARY;
        $this->formKind = '';
        $this->formState = [];
        $this->hintWidget->setText(implode("\n", $lines));
        $this->showList([
            ['value' => 'ok', 'label' => 'Done'],
        ]);
        $this->exitCode = 0;
        if ($this->tui->isRunning()) {
            $this->tui->stop();
        }
    }

    private function showPicker(): void
    {
        $this->phase = self::PHASE_PICKER;
        $this->formKind = '';
        $this->formState = [];
        $this->activeProviderId = null;
        $this->pendingConfirm = null;
        $this->actionReturn = self::PHASE_PICKER;
        $this->hintWidget->setText('Hatfield needs at least one AI provider to run.');
        $this->showList($this->pickerItems());
    }

    private function showServersSubmenu(): void
    {
        $this->phase = self::PHASE_SERVERS;
        $this->formKind = '';
        $this->formState = [];
        $this->activeProviderId = null;
        $this->pendingConfirm = null;
        $this->actionReturn = self::PHASE_SERVERS;
        $this->hintWidget->setText('Your servers — pick one to edit, or add a new one.');
        $this->showList($this->serverItems());
    }

    private function showActionMenu(string $returnPhase = self::PHASE_PICKER): void
    {
        $id = (string) $this->activeProviderId;
        $this->actionReturn = $returnPhase;
        $this->phase = self::PHASE_ACTION;
        $enabled = $this->flow->isEnabled($id);
        $this->hintWidget->setText(\sprintf(
            '%s is %s. What do you want to do?',
            $this->labelFor($id),
            $enabled ? 'already enabled' : 'disabled',
        ));
        $this->showList($this->actionItems($id));
    }

    private function returnFromAction(): void
    {
        if (self::PHASE_SERVERS === $this->actionReturn) {
            $this->showServersSubmenu();

            return;
        }
        $this->showPicker();
    }

    /**
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function pickerItems(): array
    {
        $items = [];
        foreach ($this->flow->providerRows() as $row) {
            $enabled = '✓ enabled' === $row['status'];
            $statusLabel = $enabled ? '✓ enabled' : '✗ disabled';
            $items[] = [
                'value' => $row['id'],
                'label' => $row['label'],
                'description' => $row['need'].'  '.$this->colorStatus($statusLabel, $enabled),
            ];
        }
        $items[] = [
            'value' => 'custom',
            'label' => 'Other server',
            'description' => 'any OpenAI-compatible endpoint',
        ];
        $refs = $this->flow->configuredModelRefs();
        if ([] !== $refs) {
            $current = $this->flow->currentDefaultModel();
            $items[] = [
                'value' => 'default_model',
                'label' => 'Set default model',
                'description' => null !== $current
                    ? 'current: '.$current
                    : 'choose the model new chats start with',
            ];
        }
        $items[] = [
            'value' => 'done',
            'label' => 'Done',
        ];

        return $items;
    }

    /**
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function serverItems(): array
    {
        $items = [];
        foreach ($this->flow->customProviderRows() as $row) {
            $enabled = $row['enabled'];
            $statusLabel = $enabled ? '✓ enabled' : '✗ disabled';
            $url = '' !== $row['url'] ? $row['url'] : '(no URL)';
            $items[] = [
                'value' => $row['id'],
                'label' => $row['id'],
                'description' => $url.'  '.$this->colorStatus($statusLabel, $enabled),
            ];
        }
        $items[] = ['value' => 'add', 'label' => 'Add a new server'];
        $items[] = ['value' => 'back', 'label' => 'Back'];

        return $items;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function actionItems(string $id): array
    {
        $kind = $this->catalogKind($id);
        $items = [];

        if ('custom' === $kind) {
            $items[] = ['value' => 'edit', 'label' => 'Edit'];
            $items[] = $this->flow->isEnabled($id)
                ? ['value' => 'disable', 'label' => 'Disable']
                : ['value' => 'enable', 'label' => 'Enable'];
            $items[] = ['value' => 'remove', 'label' => 'Remove'];
            $items[] = ['value' => 'cancel', 'label' => 'Cancel'];

            return $items;
        }

        if ('oauth' !== $kind) {
            $items[] = ['value' => 'configure', 'label' => 'Reconfigure'];
        }
        $items[] = ['value' => 'disable', 'label' => 'Disable'];
        $items[] = ['value' => 'cancel', 'label' => 'Cancel'];

        return $items;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function confirmItems(): array
    {
        return [
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ];
    }

    /**
     * @param list<array{value: string, label: string, description?: string}> $items
     */
    private function showList(array $items): void
    {
        $this->applyChrome();
        $this->listWidget->setItems($items);
        $this->listWidget->setSelectedIndex(0);
        $this->refreshError();
        $this->refreshFooter();
        $this->applyPhaseLayout();
        $this->tui->setFocus($this->listWidget);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function beginInput(string $kind, string $prompt, string $default = ''): void
    {
        $this->formKind = $kind;
        $this->phase = self::PHASE_INPUT;
        $this->applyChrome();
        $this->hintWidget->setText($prompt);
        $this->inputWidget->setPrompt('> ');
        $this->inputWidget->setValue($default);
        $this->refreshError();
        $this->refreshFooter();
        $this->applyPhaseLayout();
        $this->focusInput();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function focusInput(): void
    {
        $this->tui->setFocus($this->inputWidget);
    }

    private function refreshError(): void
    {
        $this->errorWidget->setText(null !== $this->error && '' !== $this->error ? '⚠ '.$this->error : '');
    }

    private function applyPhaseLayout(): void
    {
        // ContainerWidget::remove → WidgetTree::detach → AbstractWidget::detach()
        // clears $listeners. Always remove all focusables first, then wire
        // unconditionally after the add that mounts the active widget.
        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->inputWidget);
        $this->panelWidget->remove($this->settingsWidget);
        if (self::PHASE_INPUT === $this->phase) {
            $this->panelWidget->add($this->inputWidget);
            $this->wireInputListeners();
        } elseif (self::PHASE_CUSTOM === $this->phase) {
            $this->inputWidget->setPrompt('');
            $this->inputWidget->setValue('');
            $this->panelWidget->add($this->settingsWidget);
            $this->wireSettingsListeners();
        } else {
            $this->inputWidget->setPrompt('');
            $this->inputWidget->setValue('');
            $this->panelWidget->add($this->listWidget);
            $this->wireListListeners();
        }
    }

    private function refreshFooter(): void
    {
        $plain = match ($this->phase) {
            self::PHASE_PICKER => '↑/↓ select · Enter confirm · Esc exit · Ctrl+D quit',
            self::PHASE_INPUT => 'Enter submit · Esc back · Ctrl+D quit',
            self::PHASE_CUSTOM => '↑/↓ select · Enter edit/toggle · Esc back · Ctrl+D quit',
            self::PHASE_SUMMARY => 'Esc exit · Ctrl+D quit',
            default => '↑/↓ select · Enter confirm · Esc back · Ctrl+D quit',
        };
        $this->footerWidget->setText((new Style(dim: true))->apply($plain));
    }

    private function applyChrome(): void
    {
        if (self::PHASE_CUSTOM === $this->phase) {
            $this->titleWidget->setText(
                true === ($this->formState['editing'] ?? false) ? 'Edit your server' : 'Add your own server'
            );
            $this->stepWidget->setText(
                (new Style(dim: true))->apply('All options on one screen — edit any row, then save')
            );

            return;
        }
        $this->titleWidget->setText('AI Provider Setup');
        $this->stepWidget->setText('');
    }

    private function colorStatus(string $label, bool $enabled): string
    {
        $style = new Style(color: Color::named($enabled ? 'green' : 'red'));

        return $style->apply($label);
    }

    private function labelFor(string $id): string
    {
        foreach ($this->flow->providerRows() as $row) {
            if ($row['id'] === $id) {
                return $row['label'];
            }
        }

        return $id;
    }

    private function catalogKind(string $id): string
    {
        foreach ($this->flow->providerRows() as $row) {
            if ($row['id'] === $id) {
                return $row['kind'];
            }
        }

        foreach ($this->flow->customProviderRows() as $row) {
            if ($row['id'] === $id) {
                return 'custom';
            }
        }

        return 'apikey';
    }

    private function suggestedEnvVar(string $providerId): string
    {
        return strtoupper(str_replace('-', '_', $providerId)).'_API_KEY';
    }
}
