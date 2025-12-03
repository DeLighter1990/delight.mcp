<?php

namespace Delight\Mcp\DTO\Info;

use Delight\Mcp\Traits\JsonSerializable;

class SystemInfo implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $os Операционная система
     * @param string $hostname Хост
     * @param string $user Текущий пользователь
     * @param int|null $totalDiskSpace Объем диска в байтах
     * @param int|null $freeDiskSpace Объем свободного места на диске в байтах
     */
    public function __construct(
        readonly public string  $os,
        readonly public string  $hostname,
        readonly public string  $user,
        readonly public ?int $totalDiskSpace,
        readonly public ?int $freeDiskSpace,
    )
    {
    }
}
