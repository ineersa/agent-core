<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TabItem;
use Symfony\Component\Tui\Widget\TabsWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final readonly class UnifiedSetupScreen
{
    public function __construct(private TuiTheme $theme)
    {
    }

    /** @param \Closure(SettingsLayerEnum): array{string, CoreSettingsScreen, ProvidersSetupFlowInterface, SetupScreen, ExtensionsSetupScreen} $build */
    public function mount(Tui $tui, SettingsLayerEnum $layer, \Closure $build): void
    {
        $body = new ContainerWidget();
        $body->setStyle(new Style(padding: Padding::xy(2, 0), gap: 1));
        $title = new TextWidget($this->theme->color(ThemeColorEnum::Accent, 'Hatfield setup'));
        $title->setStyle(new Style(bold: true));
        $target = new TextWidget('');
        $notice = new TextWidget('');
        $confirmation = new ContainerWidget();
        $tabSlot = new ContainerWidget();
        $body->add($title)->add($target)->add($notice)->add($confirmation)->add($tabSlot);
        $tui->add($body);
        $tui->add(new TextWidget($this->theme->muted('Tab / Shift+Tab  Switch tab  ·  F2  Switch scope  ·  Esc  Back / Exit')));

        $tabs = null;
        $focus = [];
        $canLeave = static fn (): bool => true;
        $load = function (?int $index) use ($tui, $tabSlot, $target, $notice, $confirmation, $build, &$layer, &$tabs, &$focus, &$canLeave): void {
            [$path, $settings, $flow, $providers, $extensions] = $build($layer);
            $canLeave = static fn (): bool => !$providers->hasDraft() && !$extensions->hasDraft();
            $exit = function () use ($tui, $confirmation, $canLeave): void {
                if ($canLeave()) {
                    $tui->stop();
                } else {
                    $previous = $tui->getFocus();
                    $list = new SelectListWidget([
                        ['value' => 'stay', 'label' => 'Keep editing'],
                        ['value' => 'exit', 'label' => 'Discard unsaved changes and exit'],
                    ]);
                    $close = static function () use ($confirmation, $tui, $previous): void {
                        $confirmation->clear();
                        $tui->setFocus($previous);
                    };
                    $list->onCancel($close);
                    $list->onSelect(static function (SelectEvent $event) use ($close, $tui): void {
                        if ('exit' === $event->getValue()) {
                            $tui->stop();
                        } else {
                            $close();
                        }
                    });
                    $confirmation->add($this->heading('Exit setup?'))->add($list);
                    $tui->setFocus($list);
                }
            };
            $target->setText($this->theme->color(ThemeColorEnum::Accent, $layer->name).' scope  ·  '.$path);
            $notice->setText('');
            $panes = [new ContainerWidget(), new ContainerWidget(), new ContainerWidget()];
            foreach ($panes as $pane) {
                $pane->setStyle(new Style(padding: Padding::xy(2, 1), gap: 1));
            }
            $tabs = new TabsWidget([
                new TabItem('settings', 'Settings', $panes[0]),
                new TabItem('providers', 'Providers', $panes[1]),
                new TabItem('extensions', 'Extensions', $panes[2]),
            ]);
            $tabSlot->clear()->add($tabs);
            // Mount each pane while attached so the native focus manager can accept its control.
            for ($i = 0; $i < 3; ++$i) {
                $tabs->setActiveTab($i);
                match ($i) {
                    0 => $settings->mount($tui, $panes[0], $layer, $flow, $exit, $this->heading(...)),
                    1 => $providers->mount($tui, $panes[1], $exit),
                    2 => $extensions->mount($tui, $panes[2], $exit),
                };
                $focus[$i] = $tui->getFocus();
            }
            $index ??= [] === $flow->configuredModelRefs() ? 1 : 0;
            $tabs->setActiveTab($index);
            $tui->setFocus($focus[$index]);
        };
        $load(null);
        $keys = new Keybindings(['setup_next' => [Key::TAB], 'setup_previous' => ['shift+tab'], 'setup_scope' => [Key::F2]]);
        $tui->addListener(function (InputEvent $event) use ($tui, $keys, $load, $notice, $confirmation, &$tabs, &$focus, &$layer, &$canLeave): void {
            $key = $event->getData();
            $scope = $keys->matches($key, 'setup_scope');
            $next = $keys->matches($key, 'setup_next');
            if (!$scope && !$next && !$keys->matches($key, 'setup_previous')) {
                return;
            }
            $event->stopPropagation();
            if ([] !== $confirmation->all()) {
                return;
            }
            $current = $tui->getFocus();
            $index = $tabs->getActiveTabIndex();
            // Symfony detaches and closes a SettingsList submenu when its tab is hidden.
            if ($current instanceof SettingsListWidget && [] !== $current->all()) {
                $notice->setText($this->theme->warning('Confirm or cancel the open field first.'));
            } elseif ($scope && !$canLeave()) {
                $notice->setText($this->theme->warning('Save or discard open forms before switching scope.'));
            } elseif ($scope) {
                $layer = SettingsLayerEnum::User === $layer ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
                $load($index);
            } else {
                $notice->setText('');
                $focus[$index] = $current;
                $index = ($index + ($next ? 1 : 2)) % 3;
                $tabs->setActiveTab($index);
                $tui->setFocus($focus[$index]);
            }
            $tui->requestRender();
        });
    }

    private function heading(string $text): TextWidget
    {
        return (new TextWidget($this->theme->color(ThemeColorEnum::Accent, $text)))->setStyle(new Style(bold: true));
    }
}
