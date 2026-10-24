<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
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

    public function __construct(private readonly ExtensionsSetupFlow $flow, private readonly TuiTheme $theme, private readonly LoggerInterface $logger)
    {
    }

    public function run(): int
    {
        $this->tui = new Tui();
        $this->panel = new ContainerWidget();
        $this->notice = new TextWidget('Install packages first; Configure + Save applies configuration and enablement.');
        $this->tui->add(new TextWidget('Hatfield extensions:setup'));
        $this->tui->add($this->notice);
        $this->tui->add($this->panel);
        $this->tui->add(new TextWidget('Enter: select/edit  Esc: cancel/back  Ctrl+D: exit without saving pending edits'));
        $this->menu();
        $this->tui->run();

        return 0;
    }

    private function show(SelectListWidget|SettingsListWidget $widget): void
    {
        $widget->setKeybindings(new Keybindings(['quit_setup' => [Key::ctrl('d')]]));
        $widget->onInput(function (string $key) use ($widget): bool {
            if (!$widget->getKeybindings()->matches($key, 'quit_setup')) {
                return false;
            }
            $this->tui->stop();

            return true;
        });
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
        $items = [['value' => 'scope', 'label' => 'Scope: '.$this->flow->layer->name]];
        foreach ($this->flow->packages() as $id => $row) {
            $items[] = ['value' => $id, 'label' => $row['label'].($row['installed'] ? ' [installed; Configure]' : ' [not installed]')];
        }
        $items[] = ['value' => 'exit', 'label' => 'Exit'];
        $this->choose($items, function (string $id): void {
            if ('exit' === $id) {
                $this->tui->stop();
            } elseif ('scope' === $id) {
                $this->choose([
                    ['value' => 'Project', 'label' => 'Project .hatfield/settings.yaml'],
                    ['value' => 'User', 'label' => 'User ~/.hatfield/settings.yaml'],
                ], function (string $value): void {
                    $this->flow->layer = SettingsLayerEnum::from(strtolower($value));
                    $this->menu();
                }, $this->menu(...));
            } elseif (!$this->flow->packages()[$id]['installed']) {
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
            } else {
                $this->form($id);
            }
        }, fn () => $this->tui->stop());
    }

    private function form(string $package): void
    {
        $this->flow->select($package);
        $items = [
            new SettingItem('parent', 'Enabled for parent', $this->flow->members['parent'], values: ['true', 'false']),
            new SettingItem('forks', 'Fork selection', $this->flow->members['forks'], values: ['off', 'enabled', 'always_on']),
            new SettingItem('agents', 'Always on for agents', $this->flow->members['agents'], values: ['true', 'false']),
        ];
        $initial = [];
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
            } : static fn (string $current): SettingsTextInputWidget => new SettingsTextInputWidget($current);
            $items[] = new SettingItem($path, $path, $display, $node instanceof BaseNode ? $node->getInfo() : null, values: $choices, submenu: [] === $choices ? $submenu : null);
        }
        $items[] = new SettingItem('save', 'Save configuration and enablement', '', values: ['save']);
        $settings = new SettingsListWidget($items, maxVisible: 16);
        $edits = [];
        $settings->onChange(function (SettingChangeEvent $event) use ($settings, $initial, &$edits): void {
            $this->attempt(function () use ($event, $settings, $initial, &$edits): void {
                $id = $event->getId();
                $value = $event->getValue();
                if ('save' === $id) {
                    $changed = $this->flow->save($edits);
                    $this->notice->setText($changed
                        ? 'Saved configuration. Parent enabled: '.$this->flow->members['parent'].'. Changes load next session.'
                        : 'No overrides changed; inherited configuration remains in effect. No settings file was written.');
                    $this->menu();
                } elseif (isset($this->flow->members[$id])) {
                    $this->flow->members[$id] = $value;
                } else {
                    // Apply overlapping mapping/leaf edits in the user's latest edit order.
                    unset($edits[$id]);
                    if ($initial[$id] !== $value) {
                        $edits[$id] = $value;
                    }
                }
                $settings->updateValue($id, $value);
            });
        });
        $settings->onCancel($this->menu(...));
        $this->show($settings);
    }

    private function attempt(\Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $error) {
            $this->logger->notice('extension.setup.refused', ['component' => 'extensions_setup', 'event_type' => 'extension.setup.refused', 'exception_class' => $error::class]);
            $this->notice->setText($this->theme->error($error->getMessage()));
        }
    }
}
