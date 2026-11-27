<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\CLI\Extensions\Exception\ExtensionSetupRefusalException;
use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Yaml\Yaml;

final class ExtensionsSetupScreen
{
    private Tui $tui;
    private ContainerWidget $panel;
    private TextWidget $notice;

    private ?\Closure $back = null;

    public function __construct(private readonly ExtensionsSetupFlow $flow, private readonly TuiTheme $theme, private readonly LoggerInterface $logger)
    {
    }

    public function mount(Tui $tui, ContainerWidget $container, \Closure $back): void
    {
        $this->tui = $tui;
        $this->back = $back;
        $this->panel = new ContainerWidget();
        $this->notice = new TextWidget($this->theme->muted('Choose an extension to configure. Installing a package does not enable it.'));
        $container->add((new TextWidget($this->theme->color(ThemeColorEnum::Accent, 'Manage extensions')))->setStyle(new Style(bold: true)));
        $container->add($this->notice)->add($this->panel);
        $this->menu();
    }

    private function leave(): void
    {
        if (null !== $this->back) {
            ($this->back)();
        } else {
            $this->tui->stop();
        }
    }

    private function show(SelectListWidget|SettingsListWidget $widget): void
    {
        $this->panel->clear()->add($widget);
        $this->tui->setFocus($widget);
    }

    /** @param list<array{value: string, label: string}> $items */
    private function choose(array $items, \Closure $selected, \Closure $cancel): void
    {
        $list = new SelectListWidget($items, maxVisible: 12);
        $list->onSelect(function (SelectEvent $event) use ($selected): void {
            $this->attempt(static fn () => $selected($event->getValue()));
        });
        $list->onCancel($cancel);
        $this->show($list);
    }

    private function menu(): void
    {
        $items = [];
        foreach ($this->flow->packages() as $id => $row) {
            $status = $row['installed'] ? $this->theme->success($this->flow->isBuiltIn($id) ? 'Installed · Configure' : 'Installed · Configure / Uninstall') : $this->theme->muted('Available · Install');
            $package = str_starts_with($id, 'ineersa/hatfield-ext-');
            $items[] = [
                'value' => $id,
                'label' => $package ? ucwords(str_replace('-', ' ', substr($id, \strlen('ineersa/hatfield-ext-')))) : $row['label'],
                'description' => $package ? $status.' — '.$row['label'] : $status,
            ];
        }
        $this->choose($items, function (string $id): void {
            if (!$this->flow->packages()[$id]['installed']) {
                $this->choose([
                    ['value' => 'cancel', 'label' => 'Cancel installation'],
                    ['value' => 'install', 'label' => 'Install '.$id.' using Composer in '.$this->flow->layer->name.' scope'],
                ], function (string $answer) use ($id): void {
                    if ('install' === $answer) {
                        $this->flow->install($id);
                        $this->notice->setText('Installed only. Configure + Save below to apply configuration and enablement.');
                        $this->form($id);
                    } else {
                        $this->menu();
                    }
                }, $this->menu(...));
            } elseif ($this->flow->isBuiltIn($id)) {
                $this->form($id);
            } else {
                $this->choose([
                    ['value' => 'configure', 'label' => 'Configure'],
                    ['value' => 'uninstall', 'label' => 'Uninstall'],
                ], function (string $action) use ($id): void {
                    if ('configure' === $action) {
                        $this->form($id);
                    } else {
                        $this->confirmUninstall($id);
                    }
                }, $this->menu(...));
            }
        }, $this->leave(...));
    }

    private function form(string $package): void
    {
        $scope = $this->flow->layer->value;
        try {
            $this->flow->select($package);
            $this->showForm($package);
        } catch (\Throwable $error) {
            $this->actionRefused('Configure', $scope, $error, 'Unable to open the settings form. Check package compatibility and settings.yaml.');
        }
    }

    private function confirmUninstall(string $package): void
    {
        $layer = $this->flow->layer;
        $this->choose([
            ['value' => 'cancel', 'label' => 'Cancel uninstall'],
            ['value' => 'uninstall', 'label' => 'Uninstall '.$package.' from '.$layer->value.' scope and delete its settings'],
        ], function (string $answer) use ($package, $layer): void {
            if ('uninstall' === $answer && $layer === $this->flow->layer) {
                try {
                    $this->flow->uninstall($package);
                    $this->notice->setText('Uninstalled from '.$layer->value.' scope. Restart Hatfield to reload extensions.');
                } catch (\Throwable $error) {
                    $this->actionRefused('Uninstall', $layer->value, $error, 'Unable to prepare removal. Check package metadata and settings.yaml.');
                }
            }
            $this->menu();
        }, $this->menu(...));
    }

    private function actionRefused(string $action, string $scope, \Throwable $error, string $fallback): void
    {
        $this->logger->notice('extension.setup.refused', ['component' => 'extensions_setup', 'event_type' => 'extension.setup.refused', 'exception_class' => $error::class, 'cause_class' => null !== $error->getPrevious() ? $error->getPrevious()::class : null]);
        $reason = $error instanceof ExtensionSetupRefusalException ? $error->getMessage() : $fallback;
        $this->notice->setText($this->theme->error($action.' failed in '.$scope.' scope. '.$reason));
    }

    private function showForm(string $package): void
    {
        $items = [
            new SettingItem('parent', $this->flow->parentLabel, $this->flow->members['parent'], values: ['true', 'false']),
            new SettingItem('forks', 'Fork selection', $this->flow->members['forks'], values: ['off', 'enabled', 'always_on']),
            new SettingItem('agents', 'Always on for agents', $this->flow->members['agents'], values: ['true', 'false']),
        ];
        if (isset($this->flow->members['addition'])) {
            array_splice($items, 1, 0, [new SettingItem('addition', 'Add to project (keeps base enablement)', $this->flow->members['addition'], values: ['true', 'false'])]);
        }
        $initial = [];
        $hidden = [];
        $clearActions = [];
        $required = [];
        $pending = [];
        foreach ($this->flow->nodes as $path => $node) {
            if ($node instanceof ArrayNode && !$node instanceof PrototypedArrayNode && null === $this->flow->value($this->flow->settings, $path)) {
                foreach ($node->getChildren() as $name => $child) {
                    if ($child->isRequired() && !$child->hasDefaultValue()) {
                        $required[$path][] = $path.'.'.$name;
                    }
                }
            }
        }
        foreach ($this->flow->nodes as $path => $node) {
            $value = $this->flow->value($this->flow->settings, $path);
            if (null === $value && $node->hasDefaultValue()) {
                $value = $node->getDefaultValue();
            }
            $display = \is_string($value) ? $value : (null === $value ? '' : trim(Yaml::dump($value, 0)));
            $initial[$path] = $display;
            $choices = $node instanceof BooleanNode ? ['true', 'false'] : ($node instanceof EnumNode ? array_map(strval(...), $node->getValues()) : []);
            $picker = $node instanceof BaseNode && true === $node->getAttribute('hatfield.model_picker', false);
            $submenu = $picker ? function (string $current): SelectListWidget {
                $models = array_unique([$current, ...$this->flow->models()]);

                return new SelectListWidget(array_map(static fn (string $model): array => ['value' => $model, 'label' => $model], array_values(array_filter($models, static fn (string $id): bool => '' !== $id))), maxVisible: 12);
            } : static fn (string $current, callable $done): SettingsTextInputWidget => new SettingsTextInputWidget($done, $current);
            // Untyped values, including nested YAML, may contain secrets. Never prefill
            // a malformed on-disk string even when the schema declares a number.
            $numeric = ($node instanceof IntegerNode || $node instanceof FloatNode)
                && (null === $value || \is_int($value) || \is_float($value));
            if ([] === $choices && !$picker && !$numeric) {
                $hidden[$path] = true;
                $submenu = static fn (string $_, callable $done): SettingsTextInputWidget => new SettingsTextInputWidget($done, secret: true);
                $display = '' === $display ? '(not configured)' : '(configured)';
                $clearActions['clear:'.$path] = $path;
            }
            $label = ucfirst(str_replace('_', ' ', str_replace('.', ' / ', $path)));
            $help = $node instanceof BaseNode ? $node->getInfo() : null;
            if ($node instanceof ArrayNode && !$node instanceof PrototypedArrayNode) {
                $help = trim(($help ?? '').' Enter one YAML mapping, or edit the child fields below.');
            }
            $items[] = new SettingItem($path, $label, $display, $help, values: $choices, submenu: [] === $choices ? $submenu : null);
            if (isset($hidden[$path])) {
                $items[] = new SettingItem('clear:'.$path, 'Clear '.$label, '', values: ['clear']);
            }
        }
        $settings = new SettingsListWidget($items, maxVisible: 16);
        $settings->onChange(function (SettingChangeEvent $event) use ($settings, &$initial, $hidden, $clearActions, $package, &$required, &$pending): void {
            $id = $event->getId();
            $value = $event->getValue();
            $members = $this->flow->members;
            $path = $clearActions[$id] ?? $id;
            $clear = isset($clearActions[$id]);
            $value = $clear ? '' : $value;
            $group = substr($path, 0, (int) strrpos($path, '.'));
            $before = $members[$path] ?? $pending[$group][$path] ?? $initial[$path];
            $display = static fn (string $text): string => isset($hidden[$path]) ? ('' === $text ? '(not configured)' : '(configured)') : $text;
            try {
                if ($clear && ($this->flow->nodes[$path] ?? null) instanceof ArrayNode) {
                    $value = $before;
                    $saved = $this->flow->clearMapping($path);
                    $this->notice->setText($saved ? 'Removed override from this scope.' : 'No override in this scope.');
                    if ($saved) {
                        $this->showForm($package);
                    }

                    return;
                }
                if ($before === $value) {
                    return;
                }
                $edits = [];
                if (isset($members[$path])) {
                    $this->flow->members[$path] = $value;
                    if (\in_array($path, ['parent', 'addition'], true) && 'false' === $this->flow->members['parent'] && 'true' !== ($this->flow->members['addition'] ?? null)) {
                        // Child-selected extensions must also be available in the parent.
                        $this->flow->members['forks'] = 'off';
                        $this->flow->members['agents'] = 'false';
                    }
                } else {
                    $edits[$path] = $value;
                }
                if (isset($required[$group])) {
                    $edits = array_replace($pending[$group] ?? [], $edits);
                    if ($clear) {
                        unset($edits[$path]);
                    }
                    if ([] !== array_diff($required[$group], array_keys($edits))) {
                        $pending[$group] = $edits;
                        $this->notice->setText('Not saved yet. Complete the required fields to create this object, or Esc to leave it unchanged.');

                        return;
                    }
                }
                $this->flow->save($edits);
                if (isset($required[$group])) {
                    unset($required[$group], $pending[$group]);
                }
                if (($this->flow->nodes[$path] ?? null) instanceof ArrayNode) {
                    // Refresh the child rows after writing the compound value atomically.
                    $this->form($package);

                    return;
                }
                if ([] !== $edits) {
                    $initial = array_replace($initial, $edits);
                }
                foreach ($this->flow->members as $key => $member) {
                    $settings->updateValue($key, $member);
                }
                $this->notice->setText('Saved to '.$this->flow->layer->value.' scope. Changes load next session.');
            } catch (\Throwable $error) {
                $this->flow->members = $members;
                $value = $before;
                $this->logger->notice('extension.setup.refused', ['component' => 'extensions_setup', 'event_type' => 'extension.setup.refused', 'exception_class' => $error::class]);
                $this->notice->setText($this->theme->error('Not saved. Check the setting or target YAML.'));
            } finally {
                $settings->updateValue($path, $display($value));
                if ($clear) {
                    $settings->updateValue($id, '');
                }
            }
        });
        $settings->onCancel(function (): void {
            $this->notice->setText('');
            $this->menu();
        });
        $this->show($settings);
    }

    private function attempt(\Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $error) {
            $this->logger->notice('extension.setup.refused', ['component' => 'extensions_setup', 'event_type' => 'extension.setup.refused', 'exception_class' => $error::class]);
            $this->notice->setText($this->theme->error('Action failed. Check the configuration or package availability.'));
        }
    }
}
