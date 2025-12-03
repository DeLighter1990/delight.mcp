<?php

namespace Delight\Mcp\Services;

class MarkdownService
{
    /**
     * Разбивает Markdown-текст на чанки по заголовкам '##'.
     *
     * @param string $text Исходный текст для разбиения.
     * @return string[] Массив нормализованных текстовых чанков.
     */
    public function splitTextIntoChunks(string $text): array
    {
        // Используем lookahead, чтобы разбить по '##', но сохранить заголовки в чанках.
        // Разбиваем по переводу строки, за которым следует '##' и опциональные пробелы
        $rawChunks = preg_split('/\n(?=##\s+)/', $text);

        $chunks = [];
        foreach ($rawChunks as $chunk) {
            // Убираем лишние пробелы и переводы строк в начале и конце
            $strippedChunk = trim($chunk);
            if (!empty($strippedChunk)) {
                // Применяем основную нормализацию
                $normalizedChunk = self::normalizeChunk($strippedChunk);
                if (!empty($normalizedChunk)) {
                    $chunks[] = $normalizedChunk;
                }
            }
        }

        return $chunks;
    }

    /**
     * Нормализует текст чанков Markdown-файлов - убирает спецсимволы и лишние пробелы.
     *
     * @param string $code Исходный текст чанка.
     * @return string Нормализованный текст чанка.
     */
    public static function normalizeChunk(string $code): string
    {
        // 0. Удаляем Markdown Front Matter (метаданные в начале файла)
        $code = preg_replace('/^---\s*.*?---\s*/s', '', $code);

        // 1. Удаляем блоки {% note ... %} и {% endnote %} и {% cut ... %} и {% endcut %}
        $code = preg_replace('/{% (note|cut).*?%}.*?{% end\1 %}/s', '', $code);

        // 2. Удаляем блоки :::quote ... ::: и другие подобные
        $code = preg_replace('/:::[a-z]+.*?:::/s', '', $code);

        // 3. Удаляем Markdown изображения, например ![](...){...} или ![](...)
        $code = preg_replace('/!\[.*?\]\(.*?\)(?:\{.*?\})?/', '', $code);

        // 4. Удаляем Markdown ссылки, например [текст ссылки](./путь/к/файлу)
        $code = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '', $code);

        // 5. Удаляем заголовки Markdown (символы #)
        $code = preg_replace('/#+\s*/', '', $code);

        // 6. Удаляем символы выделения Markdown: *, `
        $code = str_replace(['*', '`'], '', $code);

        // 7. Заменяем -- на тире
        //$code = str_replace('--', '—', $code);

        // 8. Заменяем переносы строк и табы на пробелы
        $code = str_replace(["\n", "\t"], ' ', $code);

        // 9. Убираем множественные пробелы
        $code = preg_replace('/\s{2,}/', ' ', $code);

        return trim($code);
    }

    /**
     * Извлекает заголовок из содержимого markdown-файла.
     *
     * @param string $content Содержимое файла.
     * @param string $filePath Путь к файлу (для использования в качестве запасного варианта).
     * @return string
     */
    public static function extractTitle(string $content, string $filePath): string
    {
        // Ищем YAML Front Matter блок в начале файла
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $frontMatterMatches)) {
            $yaml = $frontMatterMatches[1];
            // Ищем ключ "title" в YAML-блоке
            if (preg_match('/^title:\s*(.*)/m', $yaml, $titleMatches)) {
                return trim($titleMatches[1]);
            }
        }

        // Если заголовок в метаданных не найден, ищем заголовок первого уровня в остальном тексте
        if (preg_match('/^#\s+(.*)/m', $content, $matches)) {
            return trim($matches[1]);
        }

        // Если ничего не найдено, возвращаем имя файла
        return pathinfo($filePath, PATHINFO_FILENAME);
    }

    /**
     * Извлекает краткое описание из содержимого markdown-файла.
     *
     * @param string $content Содержимое файла.
     * @return string
     */
    public static function extractDescription(string $content): string
    {
        // 1. Удаляем YAML Front Matter, чтобы он не попал в описание
        $contentWithoutMeta = preg_replace('/^---\s*.*?---\s*\n/s', '', $content);

        // 2. Удаляем заголовок H1, если он есть
        $contentWithoutTitle = preg_replace('/^#\s+.*\n/m', '', $contentWithoutMeta);
        $contentWithoutTitle = trim($contentWithoutTitle);

        // 3. Разбиваем на абзацы (по двойным переносам строк) и берем первый непустой
        $paragraphs = preg_split('/\n\s*\n/', $contentWithoutTitle, -1, PREG_SPLIT_NO_EMPTY);
        $firstParagraph = '';
        if (!empty($paragraphs)) {
            $firstParagraph = trim($paragraphs[0]);
        }

        if (empty($firstParagraph)) {
            return '';
        }

        // 4. Нормализуем только первый абзац, чтобы очистить от Markdown-разметки
        $description = self::normalizeChunk($firstParagraph);

        // 5. Обрезаем до 255 символов
        $maxLength = 255;
        if (mb_strlen($description) > $maxLength) {
            $description = mb_substr($description, 0, $maxLength - 3) . '...';
        }

        return $description;
    }
}
