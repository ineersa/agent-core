<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Border;
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
    private const PHASE_SCOPE = 'scope';
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
        private readonly TuiTheme $theme,
    ) {
        $this->titleWidget = new TextWidget($this->theme->color(ThemeColorEnum::Header, 'Hatfield Extensions Setup'));

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
            } elseif (self::PHASE_SCOPE === $this->phase) {
                $this->showPicker();
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
            self::PHASE_SCOPE => $this->handleScopeSelect($value),
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
        if ('scope' === $value) {
            $this->showScopeMenu();

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
            'back' => $this->showPicker(),
            default => null,
        };
    }

    private function handleScopeSelect(string $value): void
    {
        if ('back' === $value) {
            $this->showPicker();

            return;
        }
        if ('project' === $value) {
            $this->flow->setProjectScope(true);
            $this->showPicker();

            return;
        }
        if ('user' === $value) {
            $this->flow->setProjectScope(false);
            $this->showPicker();
        }
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

    private function showPicker(): void
    {
        $this->phase = self::PHASE_PICKER;
        $this->activeId = null;
        $this->formState = [];
        $this->pendingConfirm = null;
        $this->error = null;
        $this->applyChrome();
        $this->stepWidget->setText('Select an extension');
        $this->hintWidget->setText('Scope: '.$this->flow->scopeLabel().'. Project is preselected; choose Change settings scope for ~/.hatfield. Changes apply on the next Hatfield session.');

        $options = [];
        foreach ($this->flow->extensionRows() as $row) {
            $options[] = [
                'value' => $row['id'],
                'label' => \sprintf('%s — %s', $row['name'], $row['status']),
            ];
        }
        $options[] = [
            'value' => 'scope',
            'label' => 'Change settings scope (current: '.($this->flow->isProjectScope() ? 'project' : 'user').')',
        ];
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

    private function showScopeMenu(): void
    {
        $this->phase = self::PHASE_SCOPE;
        $this->applyChrome();
        $this->stepWidget->setText('Settings scope');
        $this->hintWidget->setText('Project writes <cwd>/.hatfield/settings.yaml. User writes ~/.hatfield/settings.yaml and excludes project-only overrides from prefills.');

        $options = [
            [
                'value' => 'project',
                'label' => $this->flow->isProjectScope()
                    ? 'Project (.hatfield/settings.yaml) — selected'
                    : 'Project (.hatfield/settings.yaml)',
            ],
            [
                'value' => 'user',
                'label' => !$this->flow->isProjectScope()
                    ? 'User (~/.hatfield/settings.yaml) — selected'
                    : 'User (~/.hatfield/settings.yaml)',
            ],
            ['value' => 'back', 'label' => 'Back'],
        ];
        $this->panelWidget->remove($this->listWidget);
        $this->panelWidget->remove($this->settingsWidget);
        $this->listWidget = new SelectListWidget($options, maxVisible: 8);
        if (!$this->flow->isProjectScope()) {
            $this->listWidget->setSelectedIndex(1);
        }
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
        $this->hintWidget->setText((string) ($row['status'] ?? '').' Enable/disable and child membership are edited in Configure / save settings.');

        $options = [
            ['value' => 'configure', 'label' => 'Configure / save settings'],
            ['value' => 'back', 'label' => 'Back'],
        ];

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
        $this->hintWidget->setText('Edit guided fields with the settings list. Parent enablement and forks/agents membership are toggles here. Save writes this scope only. Esc cancels without writing.');

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

        foreach ($this->guidedSettingItems() as $item) {
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
     * @return list<SettingItem>
     */
    private function guidedSettingItems(): array
    {
        $settings = \is_array($this->formState['settings'] ?? null) ? $this->formState['settings'] : [];
        $fields = \is_array($this->formState['guidedFields'] ?? null) ? $this->formState['guidedFields'] : [];
        $items = [];
        foreach ($fields as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $id = (string) ($field['id'] ?? '');
            $type = (string) ($field['type'] ?? '');
            $label = (string) ($field['label'] ?? $id);
            $description = (string) ($field['description'] ?? '');
            $path = (string) ($field['path'] ?? '');
            if ('' === $id || '' === $path) {
                continue;
            }
            $raw = $this->valueAtPath($settings, $path);
            if (null === $raw) {
                $raw = $field['default'] ?? null;
            }
            if ('bool' === $type) {
                $items[] = new SettingItem(
                    $id,
                    $label,
                    $this->yesNo((bool) $raw),
                    $description,
                    values: ['yes', 'no'],
                );
                continue;
            }

            $display = match ($type) {
                'csv_list', 'absolute_path_list' => $this->csv(\is_array($raw) ? $raw : []),
                default => null === $raw ? '' : (string) $raw,
            };
            $items[] = new SettingItem(
                $id,
                $label,
                $display,
                $description,
                submenu: $this->textSubmenu(...),
            );
        }

        return $items;
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

        // Keep the existing SettingsListWidget so cursor/submenu focus survive edits.
        // The vendor widget already updated the changed item's display value.
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
        $fields = \is_array($this->formState['guidedFields'] ?? null) ? $this->formState['guidedFields'] : [];
        foreach ($fields as $field) {
            if (!\is_array($field) || ($field['id'] ?? null) !== $id) {
                continue;
            }
            $path = (string) ($field['path'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ('' === $path) {
                return;
            }
            $stored = match ($type) {
                'bool' => 'yes' === $value,
                'csv_list', 'absolute_path_list' => $value,
                'positive_int', 'open_unit_ratio', 'path_string', 'model_id' => $value,
                default => $value,
            };
            $this->setAtPath($settings, $path, $stored);
            $this->formState['settings'] = $settings;

            return;
        }
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
        $this->titleWidget->setText($this->theme->color(ThemeColorEnum::Header, 'Hatfield Extensions Setup'));
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
        if (null === $this->error) {
            $this->errorWidget->setText('');
            $this->errorWidget->setStyle(new Style());

            return;
        }

        $this->errorWidget->setText($this->theme->error($this->error));
        $this->errorWidget->setStyle(new Style());
    }

    private function refreshFooter(): void
    {
        $plain = match ($this->phase) {
            self::PHASE_PICKER => '↑/↓ select · Enter confirm · Esc exit · Ctrl+D quit',
            self::PHASE_SETTINGS => '↑/↓ select · Enter edit/toggle · Esc cancel · Ctrl+D quit',
            self::PHASE_SCOPE => '↑/↓ select · Enter confirm · Esc back · Ctrl+D quit',
            self::PHASE_SUMMARY => 'Esc exit · Ctrl+D quit',
            default => '↑/↓ select · Enter confirm · Esc back · Ctrl+D quit',
        };
        $this->footerWidget->setText($this->theme->muted($plain));
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

        return ['id' => $id, 'name' => $id, 'status' => '', 'parentEnabled' => false, 'package' => null, 'version' => null];
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
     * @param array<string, mixed> $source
     */
    private function valueAtPath(array $source, string $dotted): mixed
    {
        $cursor = $source;
        foreach (explode('.', $dotted) as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function setAtPath(array &$target, string $dotted, mixed $value): void
    {
        $segments = explode('.', $dotted);
        $cursor = &$target;
        foreach ($segments as $index => $segment) {
            if ($index === \count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }
            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }
}
