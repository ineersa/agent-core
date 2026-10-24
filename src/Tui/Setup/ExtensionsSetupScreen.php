<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
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
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Standalone extensions:setup TUI — not coupled to ChatScreen / InteractiveMode.
 *
 * Booted by {@see \Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupCommand}
 * against a {@see ExtensionsSetupFlowInterface} implementation.
 */
final class ExtensionsSetupScreen
{
    private const PHASE_PICKER = 'picker';
    private const PHASE_ACTION = 'action';
    private const PHASE_CONFIRM = 'confirm';
    private const PHASE_SETTINGS = 'settings';
    private const PHASE_SUMMARY = 'summary';

    private Tui $tui;
    private TextWidget $titleWidget;
    private ContainerWidget $panelWidget;
    private TextWidget $stepWidget;
    private TextWidget $hintWidget;
    private TextWidget $errorWidget;
    private TextWidget $footerWidget;
    private SelectListWidget $listWidget;
    private SettingsListWidget $settingsWidget;
    private bool $mounted = false;

    private string $phase = self::PHASE_PICKER;
    private ?string $activeId = null;
    /** @var array<string, mixed> */
    private array $formState = [];
    private ?string $pendingConfirm = null;
    private ?string $error = null;
    private int $exitCode = 0;

    public function __construct(
        private readonly ExtensionsSetupFlowInterface $flow,
    ) {
        $this->titleWidget = new TextWidget('Hatfield Extensions Setup');
        $this->titleWidget->setStyle(new Style(bold: true, color: Color::named('cyan')));

        $this->panelWidget = new ContainerWidget();
        $this->panelWidget->setStyle(new Style(
            border: Border::all(1),
            padding: Padding::xy(2, 1),
            gap: 1,
        ));

        $this->stepWidget = new TextWidget('');
        $this->hintWidget = new TextWidget('');
        $this->errorWidget = new TextWidget('');
        $this->footerWidget = new TextWidget('');
        $this->listWidget = new SelectListWidget([], maxVisible: 12);
        $this->settingsWidget = new SettingsListWidget([], maxVisible: 16);
        $this->applyQuitSetupBindings($this->listWidget);
        $this->applyQuitSetupBindings($this->settingsWidget);

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
        $this->listWidget->off(SelectEvent::class);
        $this->listWidget->off(CancelEvent::class);
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
            } elseif (self::PHASE_CONFIRM === $this->phase) {
                $this->showActionMenu();
            } else {
                $this->showPicker();
            }
        });
        $this->listWidget->onInput($this->quitOnCtrlD(...));
    }

    private function wireSettingsListeners(): void
    {
        $this->settingsWidget->off(SettingChangeEvent::class);
        $this->settingsWidget->off(CancelEvent::class);
        $this->settingsWidget->onChange(function (SettingChangeEvent $event): void {
            $this->onSettingChange($event->getId(), $event->getValue());
        });
        $this->settingsWidget->onCancel(function (CancelEvent $_): void {
            $this->showActionMenu();
        });
        $this->settingsWidget->onInput($this->quitOnCtrlD(...));
    }

    private function quitOnCtrlD(string $data): bool
    {
        $bindings = self::PHASE_SETTINGS === $this->phase
            ? $this->settingsWidget->getKeybindings()
            : $this->listWidget->getKeybindings();
        if ($bindings->matches($data, 'quit_setup')) {
            $this->finishSuccess();

            return true;
        }

        return false;
    }

    private function applyQuitSetupBindings(SelectListWidget|SettingsListWidget $widget): void
    {
        $widget->setKeybindings(new Keybindings([
            'quit_setup' => [Key::ctrl('d')],
        ]));
    }

    private function onListSelect(string $value): void
    {
        $this->error = null;
        match ($this->phase) {
            self::PHASE_PICKER => $this->handlePickerSelect($value),
            self::PHASE_ACTION => $this->handleActionSelect($value),
            self::PHASE_CONFIRM => $this->handleConfirmSelect($value),
            default => null,
        };
        $this->refreshError();
    }

    private function handlePickerSelect(string $value): void
    {
        if ('done' === $value) {
            $this->finishSuccess();

            return;
        }

        $this->activeId = $value;
        $this->formState = $this->flow->formStateFor($value);
        $this->showActionMenu();
    }

    private function handleActionSelect(string $value): void
    {
        match ($value) {
            'configure' => $this->beginConfigure(),
            'enable' => $this->runEnable(),
            'disable' => $this->runDisable(),
            'back' => $this->showPicker(),
            default => null,
        };
    }

    private function handleConfirmSelect(string $value): void
    {
        if ('no' === $value) {
            $this->pendingConfirm = null;
            $this->showActionMenu();

            return;
        }
        if ('yes' !== $value) {
            return;
        }

        try {
            if ('install' === $this->pendingConfirm) {
                $this->flow->install((string) $this->activeId);
                $this->formState = $this->flow->formStateFor((string) $this->activeId);
                $this->pendingConfirm = null;
                $this->showSettings();

                return;
            }
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->pendingConfirm = null;
            $this->showActionMenu();
            $this->refreshError();

            return;
        }

        $this->pendingConfirm = null;
        $this->showActionMenu();
    }

    private function beginConfigure(): void
    {
        if (null === $this->activeId) {
            $this->showPicker();

            return;
        }

        $this->formState = $this->flow->formStateFor($this->activeId);
        if ($this->flow->needsInstallConfirmation($this->activeId)) {
            $this->pendingConfirm = 'install';
            $row = $this->rowFor($this->activeId);
            $package = (string) ($row['package'] ?? '');
            $version = (string) ($row['version'] ?? '');
            $this->showConfirm(
                \sprintf('Install %s:%s into this scope with Composer?', $package, $version),
                'Composer writes under the selected .hatfield/extensions root. Cancel leaves settings unchanged; already-started package files are not rolled back.',
            );

            return;
        }

        $this->showSettings();
    }

    private function runEnable(): void
    {
        try {
            $this->flow->enable((string) $this->activeId);
            $this->showPicker();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->refreshError();
        }
    }

    private function runDisable(): void
    {
        try {
            $this->flow->disable((string) $this->activeId);
            $this->showPicker();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->refreshError();
        }
    }

    private function showPicker(): void
    {
        $this->phase = self::PHASE_PICKER;
        $this->activeId = null;
        $this->formState = [];
        $this->pendingConfirm = null;
        $this->error = null;
        $this->applyChrome();
        $this->stepWidget->setText('Select an extension');
        $this->hintWidget->setText('Scope: '.$this->flow->scopeLabel().'. Project is default; run with --user for home settings. Changes apply on the next Hatfield session.');

        $options = [];
        foreach ($this->flow->extensionRows() as $row) {
            $options[] = [
                'value' => $row['id'],
                'label' => \sprintf('%s — %s', $row['name'], $row['status']),
            ];
        }
        $options[] = ['value' => 'done', 'label' => 'Done'];

        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->settingsWidget);
        $this->listWidget = new SelectListWidget($options, maxVisible: 12);
        $this->applyQuitSetupBindings($this->listWidget);
        $this->wireListListeners();
        $this->refreshError();
        $this->refreshFooter();
        $this->applyPhaseLayout();
        $this->tui->setFocus($this->listWidget);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function showActionMenu(): void
    {
        if (null === $this->activeId) {
            $this->showPicker();

            return;
        }

        $this->phase = self::PHASE_ACTION;
        $this->pendingConfirm = null;
        $row = $this->rowFor($this->activeId);
        $this->applyChrome();
        $this->stepWidget->setText((string) ($row['name'] ?? $this->activeId));
        $this->hintWidget->setText((string) ($row['status'] ?? ''));

        $options = [
            ['value' => 'configure', 'label' => 'Configure / save settings'],
        ];
        if (true === ($row['parentEnabled'] ?? false)) {
            $options[] = ['value' => 'disable', 'label' => 'Disable for parent'];
        } else {
            $options[] = ['value' => 'enable', 'label' => 'Enable for parent'];
        }
        $options[] = ['value' => 'back', 'label' => 'Back'];

        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->settingsWidget);
        $this->listWidget = new SelectListWidget($options, maxVisible: 12);
        $this->applyQuitSetupBindings($this->listWidget);
        $this->wireListListeners();
        $this->refreshError();
        $this->refreshFooter();
        $this->applyPhaseLayout();
        $this->tui->setFocus($this->listWidget);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function showConfirm(string $question, string $hint): void
    {
        $this->phase = self::PHASE_CONFIRM;
        $this->applyChrome();
        $this->stepWidget->setText($question);
        $this->hintWidget->setText($hint);

        $options = [
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ];
        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->settingsWidget);
        $this->listWidget = new SelectListWidget($options, maxVisible: 8);
        $this->applyQuitSetupBindings($this->listWidget);
        $this->wireListListeners();
        $this->refreshError();
        $this->refreshFooter();
        $this->applyPhaseLayout();
        $this->tui->setFocus($this->listWidget);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function showSettings(): void
    {
        $this->phase = self::PHASE_SETTINGS;
        $this->error = null;
        $this->applyChrome();
        $name = (string) ($this->formState['name'] ?? 'Extension');
        $this->stepWidget->setText($name.' settings');
        $this->hintWidget->setText('Edit guided fields with the settings list. Save writes enablement + settings for this scope only. Esc cancels without writing.');

        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->settingsWidget);
        $this->settingsWidget = new SettingsListWidget($this->settingItems(), maxVisible: 16);
        $this->applyQuitSetupBindings($this->settingsWidget);
        $this->wireSettingsListeners();
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
    private function settingItems(): array
    {
        $id = (string) ($this->formState['id'] ?? '');
        $settings = \is_array($this->formState['settings'] ?? null) ? $this->formState['settings'] : [];
        $items = [];

        $items[] = new SettingItem(
            'parentEnabled',
            'Enable for parent',
            $this->yesNo((bool) ($this->formState['parentEnabled'] ?? false)),
            'Adds or removes the extension class from extensions.enabled for this settings scope.',
            values: ['yes', 'no'],
        );
        $items[] = new SettingItem(
            'forksAlwaysOn',
            'Forks always on',
            $this->yesNo((bool) ($this->formState['forksAlwaysOn'] ?? false)),
            'forks.extensions.always_on. Requires parent enablement.',
            values: ['yes', 'no'],
        );
        $items[] = new SettingItem(
            'forksEnabled',
            'Forks optional',
            $this->yesNo((bool) ($this->formState['forksEnabled'] ?? false)),
            'forks.extensions.enabled. Requires parent enablement.',
            values: ['yes', 'no'],
        );
        $items[] = new SettingItem(
            'agentsAlwaysOn',
            'Agents always on',
            $this->yesNo((bool) ($this->formState['agentsAlwaysOn'] ?? false)),
            'agents.extensions.always_on. Optional per-agent frontmatter extensions stay separate. Requires parent enablement.',
            values: ['yes', 'no'],
        );

        foreach ($this->guidedSettingItems($id, $settings) as $item) {
            $items[] = $item;
        }

        $items[] = new SettingItem(
            'save',
            'Save',
            '↵',
            'Validate and write enablement + guided settings for '.$this->flow->scopeLabel().'.',
            values: ['↵'],
        );

        return $items;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return list<SettingItem>
     */
    private function guidedSettingItems(string $id, array $settings): array
    {
        return match ($id) {
            'safe-guard' => [
                new SettingItem(
                    'sg.auto_deny_in_noninteractive',
                    'Auto-deny noninteractive',
                    $this->yesNo((bool) ($settings['auto_deny_in_noninteractive'] ?? true)),
                    'Block instead of RequireApproval when no approval channel is present.',
                    values: ['yes', 'no'],
                ),
                new SettingItem(
                    'sg.allow_write_outside_cwd',
                    'Allow write outside CWD',
                    $this->csv((array) ($settings['allow_write_outside_cwd'] ?? [])),
                    'Comma-separated absolute paths where outside-CWD writes are allowed.',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'sg.allow_command_patterns',
                    'Allow command patterns',
                    $this->csv((array) ($settings['allow_command_patterns'] ?? [])),
                    'Comma-separated command substrings that bypass destructive checks.',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'sg.protected_read_patterns',
                    'Extra protected reads',
                    $this->csv((array) ($settings['protected_read_patterns'] ?? [])),
                    'Additive on top of built-in secret-path defaults.',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'sg.dangerous_command_patterns',
                    'Extra dangerous commands',
                    $this->csv((array) ($settings['dangerous_command_patterns'] ?? [])),
                    'Comma-separated extra dangerous command substrings.',
                    submenu: $this->textSubmenu(...),
                ),
            ],
            'task-workflow' => [
                new SettingItem(
                    'tw.task_root',
                    'Task board root',
                    (string) ($settings['task_root'] ?? ''),
                    'Absolute or project-relative path to the external task board. Leave blank to use sibling discovery / env.',
                    submenu: $this->textSubmenu(...),
                ),
            ],
            'file-rewind' => [
                new SettingItem(
                    'fr.enabled',
                    'File Rewind active',
                    $this->yesNo((bool) ($settings['enabled'] ?? true)),
                    'extensions.settings.file_rewind.enabled (class must still be in extensions.enabled).',
                    values: ['yes', 'no'],
                ),
                new SettingItem(
                    'fr.max_retained_turns',
                    'Max retained turns',
                    (string) ($settings['max_retained_turns'] ?? 100),
                    'Newest-N checkpoint rows retained in the project ledger.',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'fr.git_timeout_seconds',
                    'Git timeout seconds',
                    (string) ($settings['git_timeout_seconds'] ?? 30),
                    'Timeout for hidden-git snapshot operations.',
                    submenu: $this->textSubmenu(...),
                ),
            ],
            'observational-memory' => [
                new SettingItem(
                    'om.model',
                    'Shared model',
                    (string) ($settings['model'] ?? ''),
                    'Exact provider/model id shared by Observer/Reflector/Dropper (example: llama_cpp/flash).',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'om.database',
                    'Database path',
                    (string) (\is_array($settings['storage'] ?? null) ? ($settings['storage']['database'] ?? '') : ''),
                    'SQLite path for observational memory storage.',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'om.context_window_ratio',
                    'Observer context ratio',
                    (string) (\is_array($settings['observer'] ?? null) ? ($settings['observer']['context_window_ratio'] ?? 0.65) : 0.65),
                    'Fraction of the model context window used by Observer.',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'om.reflect_after_observation_tokens',
                    'Reflect after tokens',
                    (string) (\is_array($settings['reflector'] ?? null) ? ($settings['reflector']['reflect_after_observation_tokens'] ?? 40000) : 40000),
                    'Observation-token threshold before Reflector runs.',
                    submenu: $this->textSubmenu(...),
                ),
                new SettingItem(
                    'om.observations_max_tokens',
                    'Observations pool max tokens',
                    (string) (\is_array($settings['pools'] ?? null) ? ($settings['pools']['observations_max_tokens'] ?? 30000) : 30000),
                    'Max tokens retained in the observations pool.',
                    submenu: $this->textSubmenu(...),
                ),
            ],
            default => [],
        };
    }

    private function textSubmenu(string $current): SettingsTextInputWidget
    {
        return new SettingsTextInputWidget($current);
    }

    private function onSettingChange(string $id, string $value): void
    {
        $this->error = null;
        try {
            if ('save' === $id) {
                $this->flow->save($this->formState);
                $this->showPicker();

                return;
            }

            match ($id) {
                'parentEnabled' => $this->formState['parentEnabled'] = 'yes' === $value,
                'forksAlwaysOn' => $this->formState['forksAlwaysOn'] = 'yes' === $value,
                'forksEnabled' => $this->formState['forksEnabled'] = 'yes' === $value,
                'agentsAlwaysOn' => $this->formState['agentsAlwaysOn'] = 'yes' === $value,
                default => $this->applyGuidedChange($id, $value),
            };
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        if (self::PHASE_SETTINGS === $this->phase) {
            $this->settingsWidget = new SettingsListWidget($this->settingItems(), maxVisible: 16);
            $this->applyQuitSetupBindings($this->settingsWidget);
            $this->wireSettingsListeners();
            $this->applyPhaseLayout();
            $this->tui->setFocus($this->settingsWidget);
        }
        $this->refreshError();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function applyGuidedChange(string $id, string $value): void
    {
        if (!\is_array($this->formState['settings'] ?? null)) {
            $this->formState['settings'] = [];
        }
        /** @var array<string, mixed> $settings */
        $settings = $this->formState['settings'];

        match ($id) {
            'sg.auto_deny_in_noninteractive' => $settings['auto_deny_in_noninteractive'] = 'yes' === $value,
            'sg.allow_write_outside_cwd' => $settings['allow_write_outside_cwd'] = $value,
            'sg.allow_command_patterns' => $settings['allow_command_patterns'] = $value,
            'sg.protected_read_patterns' => $settings['protected_read_patterns'] = $value,
            'sg.dangerous_command_patterns' => $settings['dangerous_command_patterns'] = $value,
            'tw.task_root' => $settings['task_root'] = $value,
            'fr.enabled' => $settings['enabled'] = 'yes' === $value,
            'fr.max_retained_turns' => $settings['max_retained_turns'] = $value,
            'fr.git_timeout_seconds' => $settings['git_timeout_seconds'] = $value,
            'om.model' => $settings['model'] = $value,
            'om.database' => $settings['storage'] = $this->assocMerge(
                \is_array($settings['storage'] ?? null) ? $settings['storage'] : [],
                ['database' => $value],
            ),
            'om.context_window_ratio' => $settings['observer'] = $this->assocMerge(
                \is_array($settings['observer'] ?? null) ? $settings['observer'] : [],
                ['context_window_ratio' => $value],
            ),
            'om.reflect_after_observation_tokens' => $settings['reflector'] = $this->assocMerge(
                \is_array($settings['reflector'] ?? null) ? $settings['reflector'] : [],
                ['reflect_after_observation_tokens' => $value],
            ),
            'om.observations_max_tokens' => $settings['pools'] = $this->assocMerge(
                \is_array($settings['pools'] ?? null) ? $settings['pools'] : [],
                ['observations_max_tokens' => $value],
            ),
            default => null,
        };

        $this->formState['settings'] = $settings;
    }

    private function finishSuccess(): void
    {
        $this->phase = self::PHASE_SUMMARY;
        $this->applyChrome();
        $this->stepWidget->setText('Extensions setup');
        $lines = $this->flow->summaryLines();
        if ([] === $lines) {
            $this->hintWidget->setText($this->flow->wroteSomething()
                ? 'Saved. Restart Hatfield to load extension changes.'
                : 'No settings changes were written.');
        } else {
            $this->hintWidget->setText(implode("\n", $lines));
        }

        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->settingsWidget);
        $this->listWidget = new SelectListWidget([
            ['value' => 'exit', 'label' => 'Exit'],
        ], maxVisible: 4);
        $this->applyQuitSetupBindings($this->listWidget);
        $this->listWidget->off(SelectEvent::class);
        $this->listWidget->off(CancelEvent::class);
        $this->listWidget->onSelect(function (): void {
            $this->exitCode = 0;
            $this->tui->stop();
        });
        $this->listWidget->onCancel(function (): void {
            $this->exitCode = 0;
            $this->tui->stop();
        });
        $this->listWidget->onInput($this->quitOnCtrlD(...));
        $this->refreshError();
        $this->refreshFooter();
        $this->applyPhaseLayout();
        $this->tui->setFocus($this->listWidget);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    private function applyChrome(): void
    {
        $this->titleWidget->setText('Hatfield Extensions Setup');
    }

    private function applyPhaseLayout(): void
    {
        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->settingsWidget);
        if (self::PHASE_SETTINGS === $this->phase) {
            $this->panelWidget->add($this->settingsWidget);
        } else {
            $this->panelWidget->add($this->listWidget);
        }
    }

    private function refreshError(): void
    {
        $this->errorWidget->setText(null === $this->error ? '' : $this->error);
        $style = new Style(color: Color::named(null === $this->error ? 'default' : 'red'));
        $this->errorWidget->setStyle($style);
    }

    private function refreshFooter(): void
    {
        $text = match ($this->phase) {
            self::PHASE_PICKER => '↑/↓ select · Enter confirm · Esc exit · Ctrl+D quit',
            self::PHASE_SETTINGS => '↑/↓ select · Enter edit/toggle · Esc cancel · Ctrl+D quit',
            self::PHASE_SUMMARY => 'Esc exit · Ctrl+D quit',
            default => '↑/↓ select · Enter confirm · Esc back · Ctrl+D quit',
        };
        $this->footerWidget->setText($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $id): array
    {
        foreach ($this->flow->extensionRows() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return ['id' => $id, 'name' => $id, 'status' => '', 'parentEnabled' => false];
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * @param list<mixed>|array<mixed> $values
     */
    private function csv(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $parts[] = trim($value);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function assocMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = $value;
        }

        return $base;
    }
}
