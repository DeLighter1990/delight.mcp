<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\FileNotFoundException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Delight\Mcp\DTO\BxDocsSearchResults;
use Delight\Mcp\DTO\Resource\ResourceAnnotations;
use Delight\Mcp\DTO\Resource\ResourceListItem;
use Delight\Mcp\Enum\ResourceAudience;
use Delight\Mcp\Helpers\Utils;
use Exception;

/**
 * Сервис для работы с документацией Bitrix Framework
 */
class BxDocsService
{
    /** @var string Базовая директория с markdown файлами документации. */
    public string $baseDocsDir;

    /**
     * @var EmbeddingService Сервис для генерации эмбеддингов.
     */
    private EmbeddingService $embeddingService;

    /**
     * @var string Путь к файлу с полными данными эмбеддингов.
     */
    private string $embeddingsFilePath;

    /**
     * @var array|null Данные эмбеддингов.
     */
    private ?array $embeddingsData = null;

    public function __construct()
    {
        $this->baseDocsDir = dirname(__DIR__, 2) . '/vendor/bitrix-tools/framework-docs/pages';
        if (!Directory::isDirectoryExists($this->baseDocsDir)) {
            throw new \Exception("Директория документации не найдена: {$this->baseDocsDir}");
        }

        $this->embeddingService = new EmbeddingService();
        $this->embeddingsFilePath = dirname(__DIR__, 2) . '/data/embeddings/embeddings_full.json';
    }

    /**
     * Возвращает первоначальный список Markdown файлов для обработки
     * @return string[]
     */
    public function getAllMdFiles(): array
    {
        $allMdFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDocsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'md') {
                $allMdFiles[] = $item->getPathname();
            }
        }
        sort($allMdFiles);

        return $allMdFiles;
    }

    /**
     * Находит все уникальные markdown-файлы в директории, исключая дубликаты по содержимому.
     *
     * @param bool $withoutCache Запуск без чтения из кеша
     * @return string[] Относительные пути к файлам
     */
    public function getUniqueFiles(bool $withoutCache = false): array
    {
        $obCache = Application::getInstance()->getManagedCache();
        $cacheId = 'delight_mcp_unique_files_for_embeddings';
        if (!$withoutCache && $obCache->read(60 * 60 * 24, $cacheId)) {
            return $obCache->get($cacheId);
        }

        $uniqueFiles = [];
        $allMdFiles = $this->getAllMdFiles();

        $processedContentHashes = [];
        foreach ($allMdFiles as $filePath) {
            $content = File::getFileContents($filePath);
            if (empty(trim($content))) {
                continue;
            }
            $contentHash = $this->generateContentHash($content);

            if (isset($processedContentHashes[$contentHash])) {
                // Пропускаем файл, если он дубликат
                continue;
            }

            $processedContentHashes[$contentHash] = $filePath;
            $uniqueFiles[] = $filePath;
        }

        // Странно, что без clean может считаться старое содержимое кеша
        $obCache->clean($cacheId);
        $obCache->set($cacheId, $uniqueFiles);

        return $uniqueFiles;
    }

    /**
     * Получает контент файла документации
     *
     * @param string $filePath Абсолютный путь к файлу
     * @return string Контент файла
     */
    public function getFileContents(string $filePath): string
    {
        return File::getFileContents($filePath);
    }

    /**
     * Генерирует хэш для содержимого файла для обнаружения дубликатов.
     *
     * @param string $content Контент файла
     * @return string Хэш
     */
    private function generateContentHash(string $content): string
    {
        $normalizedContent = MarkdownService::normalizeChunk($content);
        // Приводим к нижнему регистру
        $normalizedContent = mb_strtolower($normalizedContent, 'UTF-8');
        // Удаляем все пробельные символы и знаки препинания
        $normalizedContent = preg_replace('/[\\s\\p{P}]+/u', '', $normalizedContent);

        return md5($normalizedContent);
    }

    /**
     * Выполняет поиск наиболее релевантных документов для данного запроса.
     *
     * @param string $query Поисковый запрос.
     * @param int $topK Количество возвращаемых наиболее релевантных результатов.
     * @return BxDocsSearchResults Массив наиболее релевантных документов.
     * @throws FileNotFoundException
     */
    public function search(string $query, int $topK = 5): BxDocsSearchResults
    {
        // 1. Загружаем эмбеддинги из файла
        $this->loadEmbeddings();

        if (empty($this->embeddingsData)) {
            throw new FileNotFoundException('Не удалось загрузить эмбеддинги документации Bitrix Framework');
        }

        // 2. Генерируем эмбеддинг для поискового запроса
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        if (empty($queryEmbedding)) {
            throw new Exception('Не удалось сгенерировать эмбеддинг для запроса.');
        }

        // 3. Рассчитываем косинусное сходство для каждого документа
        $results = [];
        foreach ($this->embeddingsData as $item) {
            if (empty($item['embedding'])) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryEmbedding, $item['embedding']);
            $results[] = new ResourceListItem(
                uri: 'bx-docs://' . $item['file_path'],
                description: $item['chunk_text'],
                mimeType: 'text/markdown',
                annotations: new ResourceAnnotations(
                    audience: [ResourceAudience::ASSISTANT->value],
                    lastModified: Utils::getFormattedLastModified($this->baseDocsDir . DIRECTORY_SEPARATOR . $item['file_path']),
                    score: $similarity
                )
            );
        }

        // 4. Сортируем результаты по убыванию сходства
        usort($results, static function ($a, $b) {
            return $b->annotations->score <=> $a->annotations->score;
        });

        // 5. Возвращаем топ-K результатов
        $results = array_slice($results, 0, $topK);

        $formattedSearchResults = [];
        foreach ($results as $searchResult) {
            $formattedSearchResults[] = (object)[
                'type' => 'resource',
                'resource' => $searchResult,
            ];
        }

        return new BxDocsSearchResults(
            results: $formattedSearchResults,
            query: $query
        );
    }

    /**
     * Загружает данные эмбеддингов из JSON-файла
     *
     * @throws FileNotFoundException|Exception Если файл не найден или содержит некорректные данные.
     */
    private function loadEmbeddings(): void
    {
        if ($this->embeddingsData !== null) {
            return; // Данные уже загружены
        }

        $file = new File($this->embeddingsFilePath);
        if (!$file->isExists()) {
            throw new FileNotFoundException($this->embeddingsFilePath);
        }

        $content = $file->getContents();
        if ($content === false) {
            throw new Exception("Не удалось прочитать файл: {$this->embeddingsFilePath}");
        }

        $data = Json::decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка декодирования JSON из файла: ' . json_last_error_msg());
        }

        $this->embeddingsData = $data;
    }

    /**
     * Рассчитывает косинусное сходство между двумя векторами.
     *
     * @param array $vecA Первый вектор.
     * @param array $vecB Второй вектор.
     * @return float Значение косинусного сходства.
     */
    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = count($vecA);

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        $magnitude = sqrt($normA) * sqrt($normB);

        return $magnitude === 0.0 ? 0.0 : $dotProduct / $magnitude;
    }
}
