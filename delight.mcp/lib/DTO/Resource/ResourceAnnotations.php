<?php

namespace Delight\Mcp\DTO\Resource;

use Delight\Mcp\Traits\JsonSerializable;

class ResourceAnnotations implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string[] $audience Целевая аудитория ресурса (user|assistant)
     * @param string $lastModified Дата изменения документа
     * @param float|null $score Релевантность для поискового запроса
     */
    public function __construct(
        readonly public array $audience,
        readonly public string $lastModified,
        readonly public ?float $score = null
    )
    {
    }
}
