<?php

namespace Delight\Mcp\DTO\Resource;

class ResourceDetailItem
{
    /**
     * @param string $uri URI ресурса (путь к файлу).
     * @param string $name Имя файла.
     * @param string $title Заголовок ресурса.
     * @param string $text Содержимое ресурса.
     * @param string $mimeType MIME-тип ресурса.
     */
    public function __construct(
        readonly string $uri,
        readonly string $name,
        readonly string $title,
        readonly string $text,
        readonly string $mimeType,
        readonly public ResourceAnnotations $annotations
    )
    {
    }
}
