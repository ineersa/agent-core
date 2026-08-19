<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
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
    private const PHASE_ACTION = 'action';
    private const PHASE_CONFIRM = 'confirm';
    private const PHASE_CHOICE = 'choice';
    private const PHASE_INPUT = 'input';
    private const PHASE_SUMMARY = 'summary';

    private Tui $tui;
    private TextWidget $titleWidget;
    private TextWidget $hintWidget;
    private TextWidget $errorWidget;
    private SelectListWidget $listWidget;
    private InputWidget $inputWidget;
    private bool $mounted = false;

    private string $phase = self::PHASE_PICKER;
    private ?string $activeProviderId = null;
    private string $formKind = '';
    /** @var array<string, mixed> */
    private array $formState = [];
    private ?string $pendingConfirm = null;
    private ?string $error = null;
    private bool $finished = false;
    private int $exitCode = 0;

    public function __construct(
        private readonly ProvidersSetupFlowInterface $flow,
    ) {
        $this->titleWidget = new TextWidget('AI Provider Setup');
        $this->hintWidget = new TextWidget('Hatfield needs at least one AI provider to run.');
        $this->errorWidget = new TextWidget('');
        $this->listWidget = new SelectListWidget([], maxVisible: 12);
        $this->inputWidget = new InputWidget();
    }

    public function mount(Tui $tui): void
    {
        if ($this->mounted) {
            return;
        }
        $this->mounted = true;
        $this->tui = $tui;

        $tui->add($this->titleWidget);
        $tui->add($this->hintWidget);
        $tui->add($this->errorWidget);
        $tui->add($this->listWidget);
        $tui->add($this->inputWidget);

        $this->listWidget->onSelect(function (SelectEvent $event): void {
            $value = $event->getValue();
            if ('' === $value) {
                return;
            }
            $this->onListSelect($value);
        });
        $this->listWidget->onCancel(function (CancelEvent $_): void {
            if (self::PHASE_PICKER === $this->phase) {
                $this->finishSuccess();
            } else {
                $this->showPicker();
            }
        });
        $this->inputWidget->onSubmit(function (SubmitEvent $_): void {
            $this->onInputSubmit(trim($this->inputWidget->getValue()));
        });
        $this->inputWidget->onCancel(function (CancelEvent $_): void {
            $this->showPicker();
        });

        $this->showPicker();
    }

    public function run(?TerminalInterface $terminal = null): int
    {
        $this->tui = new Tui(terminal: $terminal);
        $this->mount($this->tui);
        $this->tui->run();

        return $this->exitCode;
    }

    public function flow(): ProvidersSetupFlowInterface
    {
        return $this->flow;
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

    public function tui(): Tui
    {
        return $this->tui;
    }

    public function listWidget(): SelectListWidget
    {
        return $this->listWidget;
    }

    public function hintText(): string
    {
        return $this->hintWidget->getText();
    }

    public function errorText(): string
    {
        return $this->errorWidget->getText();
    }

    public function titleText(): string
    {
        return $this->titleWidget->getText();
    }

    /**
     * @return list<array{value: string, label: string, description?: string}>
     */
    public function visibleItems(): array
    {
        // SelectListWidget has no public getter for items — rebuild from phase.
        return match ($this->phase) {
            self::PHASE_PICKER => $this->pickerItems(),
            self::PHASE_ACTION => $this->actionItems(),
            self::PHASE_CONFIRM => $this->confirmItems(),
            self::PHASE_CHOICE => $this->choiceItems(),
            self::PHASE_SUMMARY => $this->summaryItems(),
            default => [],
        };
    }

    private function onListSelect(string $value): void
    {
        $this->error = null;
        match ($this->phase) {
            self::PHASE_PICKER => $this->handlePickerSelect($value),
            self::PHASE_ACTION => $this->handleActionSelect($value),
            self::PHASE_CONFIRM => $this->handleConfirmSelect($value),
            self::PHASE_CHOICE => $this->handleChoiceSelect($value),
            self::PHASE_SUMMARY => $this->handleSummarySelect($value),
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
            $this->showSummaryOrExit();

            return;
        }
        if ('custom' === $value) {
            $this->startCustom();

            return;
        }

        $this->activeProviderId = $value;
        if ($this->flow->isEnabled($value)) {
            $this->showActionMenu();

            return;
        }

        $this->startEnable($value);
    }

    private function handleActionSelect(string $value): void
    {
        if ('cancel' === $value || null === $this->activeProviderId) {
            $this->showPicker();

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
        // reconfigure
        $this->startEnable($this->activeProviderId);
    }

    private function handleConfirmSelect(string $value): void
    {
        if ('no' === $value || null === $this->activeProviderId) {
            $this->showPicker();

            return;
        }
        if ('disable' === $this->pendingConfirm) {
            $id = $this->activeProviderId;
            $this->flow->disable($id);
            $warning = $this->flow->defaultModelWarningFor($id);
            $this->pendingConfirm = null;
            $this->activeProviderId = null;
            if (null !== $warning) {
                $this->error = $warning;
            }
            $this->askAddAnother(\sprintf('%s disabled.', $this->labelFor($id)));

            return;
        }
        if ('add_another' === $this->pendingConfirm) {
            $this->pendingConfirm = null;
            if ('yes' === $value) {
                $this->showPicker();
            } else {
                $this->showSummaryOrExit();
            }

            return;
        }
        if ('set_default' === $this->pendingConfirm) {
            $this->pendingConfirm = null;
            if ('yes' === $value) {
                $this->showDefaultModelPicker();
            } else {
                $this->finishSuccess();
            }
        }
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

    private function handleSummarySelect(string $value): void
    {
        $this->finishSuccess();
    }

    private function startEnable(string $id): void
    {
        $this->activeProviderId = $id;
        $kind = $this->catalogKind($id);
        if ('oauth' === $kind) {
            $this->flow->enableOauth($id);
            $this->askAddAnother(\sprintf('%s enabled.', $this->labelFor($id)));

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
        $suggested = strtoupper(str_replace('-', '_', $id)).'_API_KEY';
        $this->beginInput('api_env_name', 'Variable name', $suggested);
    }

    private function finishApiEnv(string $value): void
    {
        $id = (string) ($this->formState['id'] ?? '');
        $apiKey = $this->flow->formatEnvApiKey('' !== $value ? $value : strtoupper(str_replace('-', '_', $id)).'_API_KEY');
        if (($this->formState['afterKey'] ?? null) === 'custom_model') {
            $this->resumeAfterCustomKey($apiKey);

            return;
        }
        $this->flow->enableApiKey($id, $apiKey);
        $this->askAddAnother(\sprintf('%s enabled.', $this->labelFor($id))."\n(Everything else is preconfigured.)");
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
        $this->askAddAnother(\sprintf('%s enabled.', $this->labelFor($id))."\n(Everything else is preconfigured.)");
    }

    private function startCustom(): void
    {
        $this->formKind = 'custom_id';
        $this->formState = [
            'models' => [],
        ];
        $this->beginInput('custom_id', 'Provider id (slug)', 'local');
    }

    private function advanceCustomId(string $value): void
    {
        $id = strtolower('' !== $value ? $value : 'local');
        $this->flow->validateCustomId($id);
        $this->formState['id'] = $id;
        $this->beginInput('custom_url', 'Server URL (e.g. http://localhost:8080)', 'http://127.0.0.1:8080');
    }

    private function advanceCustomUrl(string $value): void
    {
        $url = '' !== $value ? $value : 'http://127.0.0.1:8080';
        if ('' === trim($url)) {
            throw new \InvalidArgumentException('Server URL is required.');
        }
        $this->formState['baseUrl'] = rtrim(trim($url), '/');
        $this->beginInput('custom_path', 'Completions path', '/v1/chat/completions');
    }

    private function advanceCustomPath(string $value): void
    {
        $path = '' !== $value ? $value : '/v1/chat/completions';
        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        $this->formState['completionsPath'] = $path;
        $this->formKind = 'custom_want_key';
        $this->phase = self::PHASE_CHOICE;
        $this->hintWidget->setText('Set an API key?');
        $this->showList([
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ]);
    }

    private function handleCustomWantKey(string $value): void
    {
        if ('yes' === $value) {
            $this->formState['wantKey'] = true;
            $this->formKind = 'api_where';
            $this->formState['id'] = (string) ($this->formState['id'] ?? 'local');
            $this->phase = self::PHASE_CHOICE;
            $this->hintWidget->setText('API key: read from an environment variable, or paste it now?');
            $this->showList([
                ['value' => 'env', 'label' => 'environment variable'],
                ['value' => 'raw', 'label' => 'paste'],
            ]);
            // Mark that after key we resume custom model form.
            $this->formState['afterKey'] = 'custom_model';

            return;
        }
        $this->formState['apiKey'] = null;
        $this->beginCustomModel();
    }

    private function resumeAfterCustomKey(string $apiKey): void
    {
        $this->formState['apiKey'] = $apiKey;
        unset($this->formState['afterKey'], $this->formState['raw']);
        $this->beginCustomModel();
    }

    private function beginCustomModel(): void
    {
        $this->hintWidget->setText('Add at least one model (Enter keeps the default).');
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
        $this->hintWidget->setText('Input modalities');
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
        $this->hintWidget->setText('Supports reasoning/thinking?');
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
        $this->hintWidget->setText('Add another model?');
        $this->showList([
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
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
        $this->hintWidget->setText('Allow developer-role messages?');
        $this->showList([
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ]);
    }

    private function handleCustomDeveloperRole(string $value): void
    {
        $this->formState['supportsDeveloperRole'] = 'yes' === $value;
        $this->beginInput('custom_thinking_format', 'Reasoning format label (blank = none)', '');
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
        $this->askAddAnother(\sprintf('%s added.', $id));
    }

    private function askAddAnother(string $message): void
    {
        $this->pendingConfirm = 'add_another';
        $this->phase = self::PHASE_CONFIRM;
        $this->hintWidget->setText($message."\n\nAdd another?");
        $this->showList($this->confirmItems());
    }

    private function showSummaryOrExit(): void
    {
        if (!$this->flow->wroteSomething() && [] === $this->flow->configuredModelRefs() && [] === $this->flow->pendingAuthCommands()) {
            $this->hintWidget->setText('Nothing changed.');
            $this->finishSuccess();

            return;
        }

        $refs = $this->flow->configuredModelRefs();
        if ([] !== $refs) {
            $this->pendingConfirm = 'set_default';
            $this->phase = self::PHASE_CONFIRM;
            $this->hintWidget->setText('Set as your default model?');
            $this->showList($this->confirmItems());

            return;
        }

        $this->finishSuccess();
    }

    private function showDefaultModelPicker(): void
    {
        $refs = $this->flow->configuredModelRefs();
        if ([] === $refs) {
            $this->finishSuccess();

            return;
        }
        $this->formKind = 'default_model';
        $this->phase = self::PHASE_CHOICE;
        $this->hintWidget->setText('Default model');
        $items = [];
        foreach ($refs as $ref) {
            $items[] = ['value' => $ref, 'label' => $ref];
        }
        $this->showList($items);
    }

    private function handleDefaultModel(string $value): void
    {
        $this->flow->setDefaultModel($value);
        $this->finishSuccess();
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
        if ([] === $lines && !$this->flow->wroteSomething()) {
            $lines[] = 'Nothing changed.';
        }

        $this->phase = self::PHASE_SUMMARY;
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
        $this->titleWidget->setText('AI Provider Setup');
        $this->hintWidget->setText('Hatfield needs at least one AI provider to run.');
        $this->showList($this->pickerItems());
    }

    private function showActionMenu(): void
    {
        $id = (string) $this->activeProviderId;
        $this->phase = self::PHASE_ACTION;
        $this->hintWidget->setText(\sprintf('%s is already enabled. What do you want to do?', $this->labelFor($id)));
        $this->showList($this->actionItems());
    }

    /**
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function pickerItems(): array
    {
        $items = [];
        foreach ($this->flow->providerRows() as $row) {
            $items[] = [
                'value' => $row['id'],
                'label' => $row['label'].('✓ enabled' === $row['status'] ? ' (enabled)' : ''),
                'description' => $row['need'].' · '.$row['status'],
            ];
        }
        $items[] = [
            'value' => 'custom',
            'label' => 'Other server',
            'description' => 'any OpenAI-compatible endpoint',
        ];
        $items[] = [
            'value' => 'done',
            'label' => 'Done',
        ];

        return $items;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function actionItems(): array
    {
        return [
            ['value' => 'configure', 'label' => 'Reconfigure'],
            ['value' => 'disable', 'label' => 'Disable'],
            ['value' => 'cancel', 'label' => 'Cancel'],
        ];
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
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function choiceItems(): array
    {
        // Rebuilt on each showList call; used only by visibleItems() helper.
        return [];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function summaryItems(): array
    {
        return [['value' => 'ok', 'label' => 'Done']];
    }

    /**
     * @param list<array{value: string, label: string, description?: string}> $items
     */
    private function showList(array $items): void
    {
        $this->inputWidget->setPrompt('');
        $this->inputWidget->setValue('');
        $this->listWidget->setItems($items);
        $this->listWidget->setSelectedIndex(0);
        $this->refreshError();
        $this->tui->setFocus($this->listWidget);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function beginInput(string $kind, string $prompt, string $default = ''): void
    {
        $this->formKind = $kind;
        $this->phase = self::PHASE_INPUT;
        $this->hintWidget->setText($prompt);
        $this->inputWidget->setPrompt('> ');
        $this->inputWidget->setValue($default);
        $this->refreshError();
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

        return 'apikey';
    }
}
