<?php

namespace Delight\Mcp\DTO\LiveApi;

class SearchItemsDTO
{
    /**
     * @param array $items Найденные элементы
     * @param string $searchPhrase Исходная поисковая фраза
     */
    public function __construct(
        readonly public array $items,
        readonly public string $searchPhrase
    )
    {
    }
}
