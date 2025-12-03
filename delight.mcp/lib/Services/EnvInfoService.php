<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;
use Delight\Mcp\DTO\Info\BitrixInfo;
use Delight\Mcp\DTO\Info\BitrixModuleItemInfo;
use Delight\Mcp\DTO\Info\DatabaseInfo;
use Delight\Mcp\DTO\Info\EnvInfo;
use Delight\Mcp\DTO\Info\PhpInfo;
use Delight\Mcp\DTO\Info\SystemInfo;

class EnvInfoService
{
    /**
     * Возвращает информацию об окружении
     *
     * @return EnvInfo
     */
    public function getInfo(): EnvInfo
    {
        return new EnvInfo(
            system: $this->getSystemInfo(),
            bitrix: $this->getBitrixInfo(),
            php: $this->getPhpInfo(),
            database: $this->getDatabaseInfo()
        );
    }

    /**
     * Возвращает информацию о Битрикс-окружении
     *
     * @return BitrixInfo
     */
    protected function getBitrixInfo(): BitrixInfo
    {
        $modules = [];
        $bxModules = ModuleManager::getModulesFromDisk();
        foreach ($bxModules as $module => $info) {
            if (!empty($info['name']) && !empty($info['version'])) {
                $modules[] = new BitrixModuleItemInfo(
                    code: $module,
                    name: $info['name'],
                    version: $info['version'],
                );
            }
        }

        return new BitrixInfo(
            edition: Option::get('main', '~license_name', ''),
            version: SM_VERSION,
            modules: $modules
        );
    }

    /**
     * Возвращает информацию о базе данных
     *
     * @return DatabaseInfo
     */
    protected function getDatabaseInfo(): DatabaseInfo
    {
        global $DB;
        return new DatabaseInfo(
            type: $DB->type,
            version: $DB->GetVersion(),
            host: $DB->DBHost,
            name: $DB->DBName
        );
    }

    /**
     * Возвращает информацию о PHP
     *
     * @return PhpInfo
     */
    protected function getPhpInfo(): PhpInfo
    {
        return new PhpInfo(
            version: PHP_VERSION,
            extensions: get_loaded_extensions(),
            memoryLimit: ini_get('memory_limit'),
            maxExecutionTime: ini_get('max_execution_time')
        );
    }

    /**
     * Возвращает информацию о системе
     *
     * @return SystemInfo
     */
    protected function getSystemInfo(): SystemInfo
    {
        return new SystemInfo(
            os: php_uname(),
            hostname: gethostname(),
            user: get_current_user(),
            totalDiskSpace: @disk_total_space(Application::getDocumentRoot()) ?: null,
            freeDiskSpace: @disk_free_space(Application::getDocumentRoot()) ?: null,
        );
    }
}
