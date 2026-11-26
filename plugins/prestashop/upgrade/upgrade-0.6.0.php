<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_6_0(BlocksignEsign $module): bool
{
    return $module->registerProductHooks();
}
