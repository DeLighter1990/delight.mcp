<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\IO\File;
use Delight\Mcp\DTO\Resource\ResourceAnnotations;
use Delight\Mcp\DTO\Resource\ResourceDetailItem;
use Delight\Mcp\DTO\Resource\ResourceListItem;
use Delight\Mcp\Enum\ResourceAudience;
use Delight\Mcp\Helpers\Utils;

class ResourceService
{
    private BxDocsService $bxDocsService;

    public function __construct()
    {
        $this->bxDocsService = new BxDocsService();
    }

    /**
     * Возвращает список ресурсов для MCP.
     *
     * @return ResourceListItem[]
     */
    public function getResourceList(): array
    {
        $resources = [];
        $files = $this->bxDocsService->getUniqueFiles();

        foreach ($files as $filePath) {
            $content = File::getFileContents($filePath);
            $title = MarkdownService::extractTitle($content, $filePath);
            $description = MarkdownService::extractDescription($content);
            $fileName = pathinfo($filePath, PATHINFO_BASENAME);
            $relativePath = str_replace($this->bxDocsService->baseDocsDir . DIRECTORY_SEPARATOR, '', $filePath);
            $fileUri = 'bx-docs://' . str_replace('\\', '/', $relativePath);

            $resources[] = new ResourceListItem(
                uri: $fileUri,
                name: $fileName,
                title: $title,
                description: $description,
                mimeType: 'text/markdown',
                annotations: new ResourceAnnotations(
                    audience: [ResourceAudience::ASSISTANT->value],
                    lastModified: Utils::getFormattedLastModified($filePath)
                )
            );
        }

        return $resources;
    }

    /**
     * Возвращает ресурс для MCP
     *
     * @param string $uri Путь к файлу
     * @return ResourceDetailItem Объект ресурса
     */
    public function getResource(string $uri): ResourceDetailItem
    {
        if (str_starts_with($uri, 'bx-docs://')) {
            $filePath = $this->resolveBxDocsResourcePath($uri);
            $fileName = pathinfo($filePath, PATHINFO_BASENAME);
            $content = $this->bxDocsService->getFileContents($filePath);

            return new ResourceDetailItem(
                uri: $uri,
                name: $fileName,
                title: MarkdownService::extractTitle($content, $filePath),
                text: $content,
                mimeType: 'text/markdown',
                annotations: new ResourceAnnotations(
                    audience: [ResourceAudience::ASSISTANT->value],
                    lastModified: Utils::getFormattedLastModified($filePath)
                )
            );
        }

        throw new \InvalidArgumentException('Неизвестный тип ресурса: ' . $uri);
    }

    /**
     * Проверяет корректность переданного пути к ресурсу
     *
     * @param string $uri
     * @return string
     */
    private function resolveBxDocsResourcePath(string $uri): string
    {
        $relativePath = substr($uri, strlen('bx-docs://'));

        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_starts_with($relativePath, '/')
            || str_starts_with($relativePath, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $relativePath)
        ) {
            throw new \InvalidArgumentException('Invalid resource path.');
        }

        $pathSegments = preg_split('#[\\\\/]+#', $relativePath);
        if (in_array('..', $pathSegments, true)) {
            throw new \InvalidArgumentException('Invalid resource path.');
        }

        $basePath = realpath($this->bxDocsService->baseDocsDir);
        if ($basePath === false) {
            throw new \InvalidArgumentException('Invalid documentation base path.');
        }

        $filePath = realpath($basePath . DIRECTORY_SEPARATOR . $relativePath);
        $basePrefix = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($filePath === false || !str_starts_with($filePath, $basePrefix) || !is_file($filePath)) {
            throw new \InvalidArgumentException('Invalid resource path.');
        }

        return $filePath;
    }
}
