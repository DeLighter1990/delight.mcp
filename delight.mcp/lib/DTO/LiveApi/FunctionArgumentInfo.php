<?php

namespace Delight\Mcp\DTO\LiveApi;

class FunctionArgumentInfo
{
    /**
     * @param string $type Тип(ы) аргумента
     * @param string $name Имя аргумента
     * @param string $defaultValue Значение по умолчанию
     */
    public function __construct(
        readonly public string $type,
        readonly public string $name,
        readonly public string $defaultValue,
    )
    {
    }
}
