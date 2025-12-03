<?php

namespace Delight\Mcp\Generators;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\IO\FileNotFoundException;
use Bitrix\Main\Web\Json;
use Delight\Mcp\DTO\ProgressBarValues;
use Delight\Mcp\Services\BxDocsService;
use Delight\Mcp\Services\EmbeddingService;
use Delight\Mcp\Services\MarkdownService;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;

/**
 * Генератор эмбеддингов для документации Bitrix Framework.
 */
class BxDocsEmbeddingsGenerator
{
    /** @var string Директория для сохранения сгенерированных эмбеддингов. */
    public string $embeddingsOutputDir;

    /** @var EmbeddingService */
    private EmbeddingService $embeddingService;

    /** @var BxDocsService */
    private BxDocsService $bxDocsService;

    /** @var MarkdownService */
    private MarkdownService $markdownService;

    public function __construct()
    {
        $this->embeddingService = new EmbeddingService();
        $this->markdownService = new MarkdownService();
        $this->bxDocsService = new BxDocsService();
        $this->embeddingsOutputDir = dirname(__DIR__, 2) . '/data/embeddings';
    }

    /**
     * Генерирует и сохраняет эмбеддинги для markdown файлов документации.
     *
     * @param int $start Индекс начального файла для обработки (начиная с 0).
     * @param int $limit Максимальное количество файлов для обработки за один вызов (0 для всех).
     *
     * @return ProgressBarValues Данные для прогресс-бара (общее количество файлов и количество обработанных)
     * @throws \Exception Если директория документации не найдена или возникла ошибка при генерации.
     */
    public function generateEmbeddingsForDocs(int $start = 0, int $limit = 0): ProgressBarValues
    {
        if ($start === 0) {
            $this->clearEmbeddingsDirectory();
            $uniqueFiles = $this->bxDocsService->getUniqueFiles(true);
        } else {
            $uniqueFiles = $this->bxDocsService->getUniqueFiles();
        }

        $totalUniqueFiles = count($uniqueFiles);
        $filesToProcess = ($limit > 0) ? array_slice($uniqueFiles, $start, $limit) : $uniqueFiles;
        $embeddingsData = [];

        foreach ($filesToProcess as $filePath) {
            $file = new File($filePath);
            $content = $file->getContents();

            if (empty(trim($content))) {
                continue;
            }

            $chunks = $this->markdownService->splitTextIntoChunks($content);
            $relativePath = str_replace($this->bxDocsService->baseDocsDir . DIRECTORY_SEPARATOR, '', $filePath);
            $fileId = md5($relativePath);

            foreach ($chunks as $chunkIndex => $chunk) {
                $embeddingResult = $this->embeddingService->generateEmbedding($chunk);
                $embeddingsData[] = [
                    'id' => "{$fileId}_{$chunkIndex}",
                    'file_path' => $relativePath,
                    'file_name' => $file->getName(),
                    'chunk_index' => $chunkIndex,
                    'chunk_text' => $chunk,
                    'embedding' => $embeddingResult
                ];
            }
        }

        if (!empty($embeddingsData)) {
            $this->saveEmbeddings($embeddingsData);
        }

        return new ProgressBarValues(
            total: $totalUniqueFiles,
            processed: count($filesToProcess),
        );
    }

    /**
     * Очищает директорию с эмбеддингами от старых .json файлов.
     *
     * @throws FileNotFoundException
     */
    private function clearEmbeddingsDirectory(): void
    {
        if (!Directory::isDirectoryExists($this->embeddingsOutputDir)) {
            return;
        }

        $directory = new Directory($this->embeddingsOutputDir);
        foreach ($directory->getChildren() as $file) {
            if ($file instanceof File && $file->getExtension() === 'json') {
                $file->delete();
            }
        }
    }

    /**
     * Сохраняет сгенерированные данные в JSON-файлы.
     * При пакетной обработке дописывает данные в существующие файлы.
     *
     * @param array $embeddingsData Массив с новыми данными эмбеддингов для текущего пакета.
     * @throws FileNotFoundException
     * @throws ArgumentException
     */
    private function saveEmbeddings(array $embeddingsData): void
    {
        Directory::createDirectory($this->embeddingsOutputDir);

        $fullOutputFilePath = $this->embeddingsOutputDir . '/embeddings_full.json';
        $indexOutputFilePath = $this->embeddingsOutputDir . '/embeddings_index.json';
        $statsOutputFilePath = $this->embeddingsOutputDir . '/embeddings_stats.json';

        // --- Чтение существующих данных ---
        $existingEmbeddings = [];
        if (File::isFileExists($fullOutputFilePath)) {
            $file = new File($fullOutputFilePath);
            $existingContent = $file->getContents();
            if ($existingContent) {
                $decoded = Json::decode($existingContent);
                if (is_array($decoded)) {
                    $existingEmbeddings = $decoded;
                }
            }
        }

        $existingIndex = [];
        if (File::isFileExists($indexOutputFilePath)) {
            $file = new File($indexOutputFilePath);
            $existingContent = $file->getContents();
            if ($existingContent) {
                $decoded = Json::decode($existingContent);
                if (is_array($decoded)) {
                    $existingIndex = $decoded;
                }
            }
        }

        // --- Объединение данных ---
        $mergedEmbeddingsData = array_merge($existingEmbeddings, $embeddingsData);

        $newIndexData = array_map(static function ($item) {
            return [
                'id' => $item['id'],
                'file_path' => $item['file_path'],
                'file_name' => $item['file_name'],
                'chunk_index' => $item['chunk_index'],
                'chunk_text' => mb_substr($item['chunk_text'], 0, 200) . (mb_strlen($item['chunk_text']) > 200 ? '...' : ''),
            ];
        }, $embeddingsData);
        $mergedIndexData = array_merge($existingIndex, $newIndexData);

        // --- Сохранение объединенных данных ---
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

        (new File($fullOutputFilePath))->putContents(Json::encode($mergedEmbeddingsData, $jsonFlags));
        (new File($indexOutputFilePath))->putContents(Json::encode($mergedIndexData, $jsonFlags));

        // --- Обновление статистики ---
        $stats = [
            'datetime' => date('d.m.Y H:i:s'),
            'total_chunks' => count($mergedEmbeddingsData),
            'total_processed_files' => count(array_unique(array_column($mergedEmbeddingsData, 'file_path'))),
            'total_files' => count($this->bxDocsService->getAllMdFiles())
        ];
        (new File($statsOutputFilePath))->putContents(Json::encode($stats, $jsonFlags));
    }
}
