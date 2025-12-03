<?php

namespace Delight\Mcp\DTO\LiveApi;

class ModuleEventsDTO
{
    /**
     * @param string $id Идентификатор (код) модуля
     * @param string $version Версия модуля
     * @param EventInfo[] $events События модуля
     */
    public function __construct(
        readonly public string $id,
        readonly public string $version,
        readonly public array  $events
    )
    {
    }
}
