<?php

namespace Delight\Mcp\DTO;

class SqlResult
{
    /**
     * @param string[] $columns Названия столбцов
     * @param array<array> $rows Строки
     * @param int $rowCount Количество отданных строк
     * @param string $query Исходный запрос
     */
    public function __construct(
        readonly public array  $columns,
        readonly public array  $rows,
        readonly public int    $rowCount,
        readonly public string $query
    )
    {
    }
}
