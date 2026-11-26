<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_10_0(BlocksignEsign $module): bool
{
    return $module->installPreorder();
}
