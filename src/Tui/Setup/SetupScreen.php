<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
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
    private const PHASE_SUMMARY = 'summary';

    /** Custom-wizard step count (id→thinking format, with API-key branch collapsed). */
    private const CUSTOM_STEP_TOTAL = 13;

    private Tui $tui;
    private TextWidget $titleWidget;
    private ContainerWidget $panelWidget;
    private TextWidget $stepWidget;
    private TextWidget $hintWidget;
    private TextWidget $errorWidget;
    private TextWidget $footerWidget;
    private SelectListWidget $listWidget;
    private InputWidget $inputWidget;
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
    private bool $finished = false;
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

        // Static panel chrome — list/input are mounted by applyPhaseLayout().
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
        // Footer stays root chrome below the panel.
        $tui->add($this->footerWidget);

        // Listeners are wired in applyPhaseLayout() after each add — remove()
        // → detach() wipes them, so mount never pre-wires.
        $this->showPicker();
    }

    public function run(?TerminalInterface $terminal = null): int
    {
        $this->tui = new Tui(terminal: $terminal ?? new Terminal());
        $this->mount($this->tui);
        $this->tui->run();

        return $this->exitCode;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    /**
     * Drive one selection without a live TTY (virtual tests).
     */
    public function selectValue(string $value): void
    {
        $this->onListSelect($value);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    /**
     * Drive one input submit without a live TTY (virtual tests).
     */
    public function submitInput(string $value): void
    {
        $this->onInputSubmit(trim($value));
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    public function errorText(): string
    {
        return $this->errorWidget->getText();
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
            // Esc: exit from picker/summary; servers → picker; action/confirm → return phase.
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

    private function quitOnCtrlD(string $data): bool
    {
        // Ctrl+D is delete_char_forward on InputWidget by default — steal it before widget keybindings.
        if ("\x04" === $data) {
            $this->finishSuccess();

            return true;
        }

        return false;
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
            // SUMMARY exits via tui->stop() before input; no select handler.
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
                'custom_id' => $this->advanceCustomId($value),
                'custom_url' => $this->advanceCustomUrl($value),
                'custom_path' => $this->advanceCustomPath($value),
                'custom_model_id' => $this->advanceCustomModelId($value),
                'custom_model_name' => $this->advanceCustomModelName($value),
                'custom_context' => $this->advanceCustomContext($value),
                'custom_max_tokens' => $this->advanceCustomMaxTokens($value),
                'custom_thinking_format' => $this->finishCustomThinkingFormat($value),
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
        // Reconfigure (API-key catalog providers only — oauth omits this option).
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
            'custom_want_key' => $this->handleCustomWantKey($value),
            'custom_modalities' => $this->handleCustomModalities($value),
            'custom_reasoning' => $this->handleCustomReasoning($value),
            'custom_another_model' => $this->handleCustomAnotherModel($value),
            'custom_developer_role' => $this->handleCustomDeveloperRole($value),
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
            // InputWidget has no mask — show-then-confirm.
            $this->beginInput('api_raw_key', 'Paste your API key (shown; confirm next)');

            return;
        }
        $this->beginInput('api_env_name', 'Variable name', $this->suggestedEnvVar($id));
    }

    private function finishApiEnv(string $value): void
    {
        $id = (string) ($this->formState['id'] ?? '');
        $apiKey = $this->flow->formatEnvApiKey('' !== $value ? $value : $this->suggestedEnvVar($id));
        if (($this->formState['afterKey'] ?? null) === 'custom_model') {
            $this->resumeAfterCustomKey($apiKey);

            return;
        }
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
        if (($this->formState['afterKey'] ?? null) === 'custom_model') {
            $this->resumeAfterCustomKey($raw);

            return;
        }
        $this->flow->enableApiKey($id, $raw);
        $this->askContinue(\sprintf('%s enabled.', $this->labelFor($id))."\n(Everything else is preconfigured.)");
    }

    private function startCustom(): void
    {
        $this->formKind = 'custom_id';
        $this->formState = [
            'models' => [],
        ];
        $this->beginInput('custom_id', 'Provider id (slug)', 'local');
    }

    /**
     * Re-run the custom wizard prefilled from a saved definition.
     * Skips the id step; keeps existing models (can only add more — remove+re-add to drop one).
     */
    private function startCustomEdit(string $id): void
    {
        $definition = $this->flow->customDefinition($id);
        if (null === $definition) {
            $this->error = \sprintf('Unknown server "%s".', $id);
            $this->showServersSubmenu();

            return;
        }

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
        ];
        $this->beginInput(
            'custom_url',
            'Server URL (e.g. http://localhost:8080)',
            '' !== $definition['baseUrl'] ? $definition['baseUrl'] : 'http://127.0.0.1:8080',
        );
    }

    private function advanceCustomId(string $value): void
    {
        $id = strtolower(trim('' !== $value ? $value : 'local'));
        $this->flow->validateCustomId($id);
        $this->formState['id'] = $id;
        $this->beginInput('custom_url', 'Server URL (e.g. http://localhost:8080)', 'http://127.0.0.1:8080');
    }

    private function advanceCustomUrl(string $value): void
    {
        $url = '' !== $value ? $value : (string) ($this->formState['baseUrl'] ?? 'http://127.0.0.1:8080');
        if ('' === trim($url)) {
            throw new \InvalidArgumentException('Server URL is required.');
        }
        $this->formState['baseUrl'] = rtrim(trim($url), '/');
        $existingPath = $this->formState['completionsPath'] ?? null;
        $pathDefault = \is_string($existingPath) && '' !== $existingPath
            ? $existingPath
            : '/v1/chat/completions';
        $this->beginInput('custom_path', 'Completions path', $pathDefault);
    }

    private function advanceCustomPath(string $value): void
    {
        $existingPath = $this->formState['completionsPath'] ?? null;
        $path = '' !== $value
            ? $value
            : (\is_string($existingPath) && '' !== $existingPath ? $existingPath : '/v1/chat/completions');
        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        $this->formState['completionsPath'] = $path;

        // Edit with a saved key: keep it and continue (user can still change via Remove+re-add).
        $apiKey = $this->formState['apiKey'] ?? null;
        if (true === ($this->formState['editing'] ?? false)
            && \is_string($apiKey)
            && '' !== $apiKey) {
            $this->continueAfterCustomKeyOrModels();

            return;
        }

        $this->formKind = 'custom_want_key';
        $this->phase = self::PHASE_CHOICE;
        $this->showList([
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ]);
    }

    /** After key decision (or kept key on edit): either add first model or ask to add another. */
    private function continueAfterCustomKeyOrModels(): void
    {
        $models = $this->formState['models'] ?? [];
        if (true === ($this->formState['editing'] ?? false) && \is_array($models) && [] !== $models) {
            $this->formKind = 'custom_another_model';
            $this->phase = self::PHASE_CHOICE;
            $this->showList([
                ['value' => 'yes', 'label' => 'Add another model'],
                ['value' => 'no', 'label' => 'Finish'],
            ]);

            return;
        }
        $this->beginCustomModel();
    }

    private function handleCustomWantKey(string $value): void
    {
        if ('yes' === $value) {
            $this->formState['wantKey'] = true;
            $this->formKind = 'api_where';
            $this->formState['id'] = (string) ($this->formState['id'] ?? 'local');
            $this->phase = self::PHASE_CHOICE;
            // Mark before showList so Step-4 chrome is intentional, not accidental.
            $this->formState['afterKey'] = 'custom_model';
            $this->showList([
                ['value' => 'env', 'label' => 'environment variable'],
                ['value' => 'raw', 'label' => 'paste'],
            ]);

            return;
        }
        $this->formState['apiKey'] = null;
        $this->continueAfterCustomKeyOrModels();
    }

    private function resumeAfterCustomKey(string $apiKey): void
    {
        $this->formState['apiKey'] = $apiKey;
        unset($this->formState['afterKey'], $this->formState['raw']);
        $this->continueAfterCustomKeyOrModels();
    }

    private function beginCustomModel(): void
    {
        $this->beginInput('custom_model_id', 'Model id (e.g. llama-3.3-70b)', 'default');
    }

    private function advanceCustomModelId(string $value): void
    {
        $modelId = '' !== $value ? $value : 'default';
        $this->formState['modelId'] = $modelId;
        $this->beginInput('custom_model_name', 'Display name', $modelId);
    }

    private function advanceCustomModelName(string $value): void
    {
        $modelId = (string) ($this->formState['modelId'] ?? 'default');
        $this->formState['modelName'] = '' !== $value ? $value : $modelId;
        $this->beginInput('custom_context', 'Context window', '128000');
    }

    private function advanceCustomContext(string $value): void
    {
        $n = (int) ('' !== $value ? $value : '128000');
        if ($n < 1) {
            throw new \InvalidArgumentException('Context window must be >= 1.');
        }
        $this->formState['contextWindow'] = $n;
        $this->beginInput('custom_max_tokens', 'Max output tokens', '8192');
    }

    private function advanceCustomMaxTokens(string $value): void
    {
        $n = (int) ('' !== $value ? $value : '8192');
        if ($n < 1) {
            throw new \InvalidArgumentException('Max tokens must be >= 1.');
        }
        $this->formState['maxTokens'] = $n;
        $this->formKind = 'custom_modalities';
        $this->phase = self::PHASE_CHOICE;
        $this->showList([
            ['value' => 'text', 'label' => 'text'],
            ['value' => 'text+image', 'label' => 'text+image'],
        ]);
    }

    private function handleCustomModalities(string $value): void
    {
        $this->formState['input'] = 'text+image' === $value ? ['text', 'image'] : ['text'];
        $this->formKind = 'custom_reasoning';
        $this->phase = self::PHASE_CHOICE;
        $this->showList([
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ]);
    }

    private function handleCustomReasoning(string $value): void
    {
        $reasoning = 'yes' === $value;
        $modelId = (string) ($this->formState['modelId'] ?? 'default');
        $models = $this->formState['models'] ?? [];
        if (!\is_array($models)) {
            $models = [];
        }
        $models[$modelId] = [
            'name' => (string) ($this->formState['modelName'] ?? $modelId),
            'context_window' => (int) ($this->formState['contextWindow'] ?? 128000),
            'max_tokens' => (int) ($this->formState['maxTokens'] ?? 8192),
            'input' => $this->formState['input'] ?? ['text'],
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
        $this->formState['models'] = $models;
        $this->formKind = 'custom_another_model';
        $this->phase = self::PHASE_CHOICE;
        $this->showList([
            ['value' => 'yes', 'label' => 'Add another model'],
            ['value' => 'no', 'label' => 'Finish'],
        ]);
    }

    private function handleCustomAnotherModel(string $value): void
    {
        if ('yes' === $value) {
            $this->beginCustomModel();

            return;
        }
        $this->formKind = 'custom_developer_role';
        $this->phase = self::PHASE_CHOICE;
        $this->showList([
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ]);
    }

    private function handleCustomDeveloperRole(string $value): void
    {
        $this->formState['supportsDeveloperRole'] = 'yes' === $value;
        $thinkingFormat = $this->formState['thinkingFormat'] ?? null;
        $default = \is_string($thinkingFormat) ? $thinkingFormat : '';
        $this->beginInput('custom_thinking_format', 'Reasoning format label (blank = none)', $default);
    }

    private function finishCustomThinkingFormat(string $value): void
    {
        /** @var array<string, array<string, mixed>> $models */
        $models = \is_array($this->formState['models'] ?? null) ? $this->formState['models'] : [];
        $id = (string) ($this->formState['id'] ?? '');
        $this->flow->saveCustom(
            $id,
            (string) ($this->formState['baseUrl'] ?? ''),
            (string) ($this->formState['completionsPath'] ?? '/v1/chat/completions'),
            isset($this->formState['apiKey']) && \is_string($this->formState['apiKey']) ? $this->formState['apiKey'] : null,
            $models,
            (bool) ($this->formState['supportsDeveloperRole'] ?? false),
            $value,
        );
        $verb = true === ($this->formState['editing'] ?? false) ? 'updated' : 'added';
        $this->askContinue(\sprintf('%s %s.', $id, $verb));
    }

    private function askContinue(string $message): void
    {
        $this->pendingConfirm = 'add_another';
        $this->phase = self::PHASE_CONFIRM;
        $this->formKind = '';
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
        $this->hintWidget->setText(implode("\n", $lines));
        $this->showList([
            ['value' => 'ok', 'label' => 'Done'],
        ]);
        $this->finished = true;
        $this->exitCode = 0;
        // Stop the live event loop if running; virtual harnesses never call run().
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

        // OAuth: no Reconfigure (nothing to reconfigure beyond login).
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
        // Custom wizard help/example come from applyChrome(); other inputs keep $prompt.
        if (!$this->isCustomWizardKind($kind)) {
            $this->hintWidget->setText($prompt);
        }
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
        // clears $listeners. Always remove both first, then wire unconditionally
        // after the add that mounts the active widget — at add() time the widget
        // was either just detached (listeners wiped) or never attached (never
        // wired, since wiring only happens here).
        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->inputWidget);
        if (self::PHASE_INPUT === $this->phase) {
            $this->panelWidget->add($this->inputWidget);
            $this->wireInputListeners();
        } else {
            $this->inputWidget->setPrompt('');
            $this->inputWidget->setValue('');
            $this->panelWidget->add($this->listWidget);
            $this->wireListListeners();
        }
        // Footer stays root chrome below the panel (already mounted in mount()).
    }

    private function refreshFooter(): void
    {
        $plain = match ($this->phase) {
            self::PHASE_PICKER => '↑/↓ select · Enter confirm · Esc exit · Ctrl+D quit',
            self::PHASE_INPUT => 'Enter submit · Esc back · Ctrl+D quit',
            self::PHASE_SUMMARY => 'Esc exit · Ctrl+D quit',
            default => '↑/↓ select · Enter confirm · Esc back · Ctrl+D quit',
        };
        $this->footerWidget->setText((new Style(dim: true))->apply($plain));
    }

    private function applyChrome(): void
    {
        if ($this->isCustomWizardKind($this->formKind)) {
            $this->titleWidget->setText(
                true === ($this->formState['editing'] ?? false) ? 'Edit your server' : 'Add your own server'
            );
            $this->stepWidget->setText(
                (new Style(dim: true))->apply($this->customStepHeader($this->formKind))
            );
            $this->hintWidget->setText($this->formatStepHelp($this->formKind));

            return;
        }
        $this->titleWidget->setText('AI Provider Setup');
        $this->stepWidget->setText('');
    }

    private function formatStepHelp(string $kind): string
    {
        [$help, $example] = $this->customStepHelp($kind);
        $lines = [$help];
        if ('' !== $example) {
            $lines[] = (new Style(dim: true))->apply('Example: '.$example);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{0: string, 1: string} help text + optional example (empty = none)
     */
    private function customStepHelp(string $kind): array
    {
        return match ($kind) {
            'custom_id' => [
                'A short name to identify this provider in menus and settings.',
                'runpod',
            ],
            'custom_url' => [
                'The address of the API server Hatfield will talk to.',
                'https://abc-123.proxy.runpod.net',
            ],
            'custom_path' => [
                'Where the server accepts chat requests. Nearly all OpenAI-compatible servers use /v1/chat/completions — keep the default unless yours differs.',
                '/v1/chat/completions',
            ],
            'custom_want_key' => [
                'Whether the server requires an API key to authenticate.',
                '',
            ],
            'api_where' => [
                'How Hatfield should get the key: read it from an environment variable (recommended — stays out of files), or you type it in now.',
                'OPENAI_API_KEY',
            ],
            'api_env_name' => [
                'Name of the environment variable holding the API key.',
                'RUNPOD_API_KEY',
            ],
            'api_raw_key' => [
                'The API key for this server. It will be stored in your settings file.',
                '',
            ],
            'api_raw_confirm' => [
                'Type the same API key again to confirm.',
                '',
            ],
            'custom_model_id' => [
                'The model\'s id exactly as the server expects it in requests.',
                'llama-3.3-70b',
            ],
            'custom_model_name' => [
                'A friendly name shown in the model picker. Defaults to the id.',
                'Llama 3.3 70B',
            ],
            'custom_context' => [
                'How much text (tokens) the model can read at once. Bigger = longer conversations it can see. Check your model\'s docs; 128000 is a safe default.',
                '128000',
            ],
            'custom_max_tokens' => [
                'The most text the model can produce in one reply.',
                '8192',
            ],
            'custom_modalities' => [
                'What kinds of input the model accepts: text only, or text and images.',
                '',
            ],
            'custom_reasoning' => [
                'Whether this model shows its thinking before answering.',
                '',
            ],
            'custom_another_model' => [
                'Add another model now, or finish this provider.',
                '',
            ],
            'custom_developer_role' => [
                'Whether the server accepts \'developer\' role messages. Some clones only accept \'system\' — pick No then.',
                '',
            ],
            'custom_thinking_format' => [
                'Label the server uses to return thinking output, if any. Leave blank if it doesn\'t.',
                '(blank for none)',
            ],
            default => ['', ''],
        };
    }

    private function isCustomWizardKind(string $kind): bool
    {
        return '' !== $kind && (
            str_starts_with($kind, 'custom_')
            || (
                isset($this->formState['afterKey'])
                && \in_array($kind, ['api_where', 'api_env_name', 'api_raw_key', 'api_raw_confirm'], true)
            )
        );
    }

    private function customStepHeader(string $kind): string
    {
        [$step, $field] = $this->customStepMeta($kind);

        return \sprintf('Step %d of %d — %s', $step, self::CUSTOM_STEP_TOTAL, $field);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function customStepMeta(string $kind): array
    {
        return match ($kind) {
            'custom_id' => [1, 'Provider id'],
            'custom_url' => [2, 'Server URL'],
            'custom_path' => [3, 'Completions path'],
            'custom_want_key', 'api_where', 'api_env_name', 'api_raw_key', 'api_raw_confirm' => [4, 'API key'],
            'custom_model_id' => [5, 'Model id'],
            'custom_model_name' => [6, 'Display name'],
            'custom_context' => [7, 'Context window'],
            'custom_max_tokens' => [8, 'Max output tokens'],
            'custom_modalities' => [9, 'Modalities'],
            'custom_reasoning' => [10, 'Reasoning'],
            'custom_another_model' => [11, 'Another model'],
            'custom_developer_role' => [12, 'Developer role'],
            'custom_thinking_format' => [13, 'Reasoning format'],
            default => [1, 'Setup'],
        };
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
