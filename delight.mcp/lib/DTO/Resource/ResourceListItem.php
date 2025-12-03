<?php

namespace Delight\Mcp\DTO\Resource;

use Delight\Mcp\Traits\JsonSerializable;

/**
 * DTO для элемента списка ресурсов.
 */
class ResourceListItem implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $uri URI ресурса (путь к файлу).
     * @param string|null $name Имя файла.
     * @param string|null $title Заголовок ресурса.
     * @param string $description Описание ресурса (нормализованное содержимое).
     * @param string $mimeType MIME-тип ресурса.
     * @param ResourceAnnotations $annotations
     */
    public function __construct(
        readonly public string              $uri,
        readonly public string              $description,
        readonly public string              $mimeType,
        readonly public ResourceAnnotations $annotations,
        readonly public ?string             $name = null,
        readonly public ?string             $title = null,
    )
    {
    }
}
