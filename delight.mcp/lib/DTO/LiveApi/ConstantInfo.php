<?php

namespace Delight\Mcp\DTO\LiveApi;

use Delight\Mcp\Traits\JsonSerializable;

class ConstantInfo implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $name Имя константы
     * @param string|null $file Файл, в котором объявлена константа
     * @param int|null $line Строка, на которой объявлена константа
     * @param string[] $checkedBy Массив функций, в которых проверяется константа
     */
    public function __construct(
        readonly public string   $name,
        readonly public ?string  $file = null,
        readonly public ?int     $line = null,
        readonly public array    $checkedBy = []
    )
    {
    }
}
