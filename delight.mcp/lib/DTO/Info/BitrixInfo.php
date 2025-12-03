<?php

namespace Delight\Mcp\DTO\Info;

class BitrixInfo
{
    /**
     * @param string $edition Редакция
     * @param string $version Версия главного модуля
     * @param BitrixModuleItemInfo[] $modules Установленные модули с их версиями
     */
    public function __construct(
        readonly public string $edition,
        readonly public string $version,
        readonly public array  $modules,
    )
    {
    }
}
