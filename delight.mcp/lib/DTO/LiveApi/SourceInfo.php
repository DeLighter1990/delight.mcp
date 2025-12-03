<?php

namespace Delight\Mcp\DTO\LiveApi;

use Delight\Mcp\Traits\JsonSerializable;

class SourceInfo implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $file Путь к файлу
     * @param int $line Номер строки в файле
     * @param string|null $moduleId Идентификатор модуля
     */
    public function __construct(
        readonly public string  $file,
        readonly public int     $line,
        readonly public ?string $moduleId = null
    )
    {
    }
}
