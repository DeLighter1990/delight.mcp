<?php

namespace Delight\Mcp\DTO\LiveApi;

use Delight\Mcp\Traits\JsonSerializable;

class FunctionDetailInfo implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $name Полное имя функции с классом
     * @param string|null $code Код функции с PHPDoc
     */
    public function __construct(
        readonly public string  $name,
        readonly public ?string $code = null,
    )
    {
    }
}
