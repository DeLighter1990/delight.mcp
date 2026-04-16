<?php

namespace Delight\Mcp\Traits;

/**
 * Trait BulkInsertTrait
 *
 * Добавляет пакетную вставку записей в ORM-таблицу с fallback на построчную вставку,
 * если addMulti недоступен или завершился ошибкой.
 */
trait BulkInsertTrait
{
    /**
     * Выполняет пакетную вставку записей в таблицу.
     *
     * Метод разбивает входные данные на чанки и пытается вставить каждый чанк через
     * `addMulti()`. Если пакетная вставка завершилась ошибкой, выполняется fallback
     * на построчную вставку через `add()`.
     *
     * @param array<int, array<string, mixed>> $rows Набор строк для вставки.
     * @param int $chunkSize Размер чанка для одной пакетной вставки.
     * @param bool $ignoreEvents Игнорировать события ORM? (Ускоряет вставку в ~2 раза)
     * @return void
     */
    public static function bulkInsert(array $rows, int $chunkSize, bool $ignoreEvents): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            try {
                static::addMulti($chunk, $ignoreEvents);
            } catch (\Throwable $e) {
                foreach ($chunk as $row) {
                    static::add($row);
                }
            }
        }
    }
}
