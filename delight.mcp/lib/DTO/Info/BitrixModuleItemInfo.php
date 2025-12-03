<?php

namespace Delight\Mcp\DTO\Info;

class BitrixModuleItemInfo
{
    /**
     * @param string $code Код модуля
     * @param string $name Имя модуля
     * @param string $version Версия модуля
     */
    public function __construct(
        readonly public string $code,
        readonly public string $name,
        readonly public string $version
    )
    {
    }
}
