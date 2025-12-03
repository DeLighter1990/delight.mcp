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
            $filePath = str_replace('bx-docs://', $this->bxDocsService->baseDocsDir . DIRECTORY_SEPARATOR, $uri);
            $fileName = pathinfo($filePath, PATHINFO_BASENAME);
            //$lastModified =
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
}
