<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Menu;

use Knp\Menu\ItemInterface;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function addPunchoutSection(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $catalog = $menu->getChild('catalog');
        if (!$catalog instanceof ItemInterface) {
            $catalog = $menu->addChild('catalog');
        }

        $catalog
            ->addChild('tmonier_punchout_buyers', [
                'route' => 'tmonier_punchout_admin_buyer_index',
            ])
            ->setAttribute('type', 'link')
            ->setLabel('tmonier_punchout.ui.menu.buyers')
            ->setLabelAttribute('icon', 'tabler:building-store')
        ;
        $catalog
            ->addChild('tmonier_punchout_logs', [
                'route' => 'tmonier_punchout_admin_message_log_index',
            ])
            ->setAttribute('type', 'link')
            ->setLabel('tmonier_punchout.ui.menu.logs')
            ->setLabelAttribute('icon', 'tabler:list')
        ;
    }
}
