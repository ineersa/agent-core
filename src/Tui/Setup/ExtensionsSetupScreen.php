<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\IntegerNode;
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
    private bool $editing = false;

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

    public function hasDraft(): bool
    {
        return $this->editing;
    }

    private function leave(): void
    {
        if ($this->editing) {
            $this->notice->setText('Choose Save configuration or Discard changes.');
        } elseif (null !== $this->back) {
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
        $this->editing = false;
        $items = [];
        foreach ($this->flow->packages() as $id => $row) {
            $items[] = ['value' => $id, 'label' => $row['label'], 'description' => $row['installed'] ? $this->theme->success('Installed · Configure') : $this->theme->muted('Available · Install')];
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
            } else {
                $this->form($id);
            }
        }, $this->leave(...));
    }

    private function form(string $package): void
    {
        $this->flow->select($package);
        $this->editing = true;
        $items = [
            new SettingItem('parent', 'Enabled for parent', $this->flow->members['parent'], values: ['true', 'false']),
            new SettingItem('forks', 'Fork selection', $this->flow->members['forks'], values: ['off', 'enabled', 'always_on']),
            new SettingItem('agents', 'Always on for agents', $this->flow->members['agents'], values: ['true', 'false']),
        ];
        $initial = [];
        $hidden = [];
        $clearActions = [];
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
            // Untyped values, including nested YAML, may contain secrets. Never prefill
            // a malformed on-disk string even when the schema declares a number.
            $numeric = ($node instanceof IntegerNode || $node instanceof FloatNode)
                && (null === $value || \is_int($value) || \is_float($value));
            if ([] === $choices && !$picker && !$numeric) {
                $hidden[$path] = true;
                $submenu = static fn (): SettingsTextInputWidget => new SettingsTextInputWidget(secret: true);
                $display = '' === $display ? '(not configured)' : '(configured)';
                $clearActions['clear:'.$path] = $path;
            }
            $label = ucfirst(str_replace('_', ' ', str_replace('.', ' / ', $path)));
            $items[] = new SettingItem($path, $label, $display, $node instanceof BaseNode ? $node->getInfo() : null, values: $choices, submenu: [] === $choices ? $submenu : null);
            if (isset($hidden[$path])) {
                $items[] = new SettingItem('clear:'.$path, 'Clear '.$label, '', values: ['clear']);
            }
        }
        $items[] = new SettingItem('save', 'Save configuration and enablement', '', values: ['save']);
        $items[] = new SettingItem('discard', 'Discard changes', '', values: ['discard']);
        $settings = new SettingsListWidget($items, maxVisible: 16);
        $edits = [];
        $settings->onChange(function (SettingChangeEvent $event) use ($settings, $initial, $hidden, $clearActions, &$edits): void {
            $this->attempt(function () use ($event, $settings, $initial, $hidden, $clearActions, &$edits): void {
                $id = $event->getId();
                $value = $event->getValue();
                if (isset($clearActions[$id])) {
                    $path = $clearActions[$id];
                    $edits[$path] = '';
                    $settings->updateValue($path, '(cleared in draft)');
                    $settings->updateValue($id, '');

                    return;
                }
                if ('discard' === $id) {
                    $this->menu();
                } elseif ('save' === $id) {
                    $changed = $this->flow->save($edits);
                    $this->notice->setText($changed
                        ? 'Saved configuration. Parent enabled: '.$this->flow->members['parent'].'. Changes load next session.'
                        : 'No overrides changed; inherited configuration remains in effect. No settings file was written.');
                    $this->menu();
                } elseif (isset($this->flow->members[$id])) {
                    $this->flow->members[$id] = $value;
                    if ('parent' === $id && 'false' === $value) {
                        // Child-selected extensions must also be available in the parent.
                        foreach (['forks' => 'off', 'agents' => 'false'] as $child => $disabled) {
                            $this->flow->members[$child] = $disabled;
                            $settings->updateValue($child, $disabled);
                        }
                    }
                } else {
                    // Apply overlapping mapping/leaf edits in the user's latest edit order.
                    unset($edits[$id]);
                    if ($initial[$id] !== $value) {
                        $edits[$id] = $value;
                    }
                }
                $settings->updateValue($id, isset($hidden[$id]) ? '(edited)' : $value);
            });
        });
        $settings->onCancel($this->leave(...));
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
