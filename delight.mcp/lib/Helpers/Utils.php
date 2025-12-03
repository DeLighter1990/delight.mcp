<?php

namespace Delight\Mcp\Helpers;

class Utils
{
    /**
     * Преобразует строку с датой в формате "DD.MM.YYYY HH:MM" или "DD.MM.YYYY" в Unix timestamp.
     *
     * @param string $dateString Строка с датой.
     * @return int|null Unix timestamp или null, если строка не соответствует формату.
     */
    public static function convertDateToTimestamp(string $dateString): ?int
    {
        // Проверяем, содержит ли строка время
        if (strpos($dateString, ' ') !== false) {
            // Формат "d.m.Y H:i"
            $format = 'd.m.Y H:i';
        } else {
            // Формат "d.m.Y"
            $format = 'd.m.Y';
        }

        try {
            $dateTime = \DateTime::createFromFormat($format, $dateString);
            if ($dateTime === false) {
                return null;
            }
            return $dateTime->getTimestamp();
        } catch (\Exception $e) {
            // Если преобразование не удалось, возвращаем null
            return null;
        }
    }

    /**
     * Преобразует строку из lowerCamelCase в SCREAMING_SNAKE_CASE.
     *
     * @param string $string Исходная строка в lowerCamelCase
     * @return string Результат в SCREAMING_SNAKE_CASE
     */
    public static function toScreamingSnakeCase(string $string): string
    {
        return strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }

    /**
     * Возвращает дату изменения файла в формате ISO 8601
     *
     * @param string $filePath Путь к файлу
     * @return string Дата изменения файла в формате ISO 8601
     */
    public static function getFormattedLastModified(string $filePath): string
    {
        $timestamp = filemtime($filePath); // время последнего изменения
        return date('c', $timestamp);
    }
}
