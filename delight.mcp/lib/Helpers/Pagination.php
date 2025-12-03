<?php

namespace Delight\Mcp\Helpers;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;

class Pagination
{
    /**
     * Декодирует пагинацию в nextCursor
     *
     * @param int $offset Текущее смещение
     * @param int $limit Текущий лимит
     * @return string Строка nextCursor
     * @throws ArgumentException
     */
    public static function makeNextCursor(int $offset, int $limit): string
    {
        return base64_encode(Json::encode([
            'offset' => $offset,
            'limit' => $limit
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Декодирует курсор в массив вида ['offset' => ..., 'limit' => ...]
     * @param string $cursor
     * @return int[]
     * @throws ArgumentException
     */
    public static function getFromCursor(string $cursor): array
    {
        return Json::decode(base64_decode($cursor));
    }
}
