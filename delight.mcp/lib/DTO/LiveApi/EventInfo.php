<?php

namespace Delight\Mcp\DTO\LiveApi;

class EventInfo
{
    /**
     * @param string $name Имя события
     * @param SourceInfo $source Откуда вызывается событие
     * @param string $firedInFunc Вызывается в функции
     */
    public function __construct(
        readonly public string     $name,
        readonly public SourceInfo $source,
        readonly public string     $firedInFunc
    )
    {
    }
}
