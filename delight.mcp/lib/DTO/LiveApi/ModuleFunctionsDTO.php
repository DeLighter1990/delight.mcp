<?php

namespace Delight\Mcp\DTO\LiveApi;

use Delight\Mcp\Traits\JsonSerializable;

class ModuleFunctionsDTO implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $id Идентификатор (код) модуля
     * @param string $version Версия модуля
     * @param FunctionListItemInfo[] $functions Функции модуля
     * @param string|null $nextCursor Постраничная навигация cursor-based
     */
    public function __construct(
        readonly public string  $id,
        readonly public string  $version,
        readonly public array   $functions,
        readonly public ?string $nextCursor
    )
    {
    }
}
