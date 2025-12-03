<?php

namespace Delight\Mcp\DTO\LiveApi;

/**
 * DTO для информации о функции/методе в листинге
 */
class FunctionListItemInfo
{
    /**
     * @param string $name Имя функции
     * @param SourceInfo $source Источник, где объявлена функция
     * @param FunctionArgumentInfo[] $args Аргументы, которые принимает функция
     */
    public function __construct(
        readonly public string     $name,
        readonly public SourceInfo $source,
        readonly public array      $args,
    )
    {
    }
}
