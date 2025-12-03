<?php

namespace Delight\Mcp\DTO;

class BxDocsSearchResults
{
    /**
     * @param array $results Результаты поиска
     * @param string $query Поисковый запрос
     */
    public function __construct(
        readonly public array  $results,
        readonly public string $query
    )
    {
    }
}
