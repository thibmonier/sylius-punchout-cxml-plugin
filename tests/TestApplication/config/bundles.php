<?php

declare(strict_types=1);

return [
    Tmonier\SyliusPunchoutCxmlPlugin\TmonierSyliusPunchoutCxmlPlugin::class => ['all' => true],
    // Optional companion plugin (US-006): buyers attached to a company.
    Tmonier\SyliusCompanyAccountsPlugin\TmonierSyliusCompanyAccountsPlugin::class => ['all' => true],
    winzou\Bundle\StateMachineBundle\winzouStateMachineBundle::class => ['all' => true],
];
