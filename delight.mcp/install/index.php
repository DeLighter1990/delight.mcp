<?php

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;
use Bitrix\Main\IO\Directory;
use Delight\Mcp\Entity\DelightMcpLiveApiEventsTable;
use Delight\Mcp\Entity\DelightMcpLiveApiFunctionsTable;
use Delight\Mcp\Entity\DelightMcpTokensTable;

class delight_mcp extends CModule
{
    public $MODULE_ID = 'delight.mcp';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public $SHOW_SUPER_ADMIN_GROUP_RIGHTS;
    public $MODULE_GROUP_RIGHTS;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        if (is_array($arModuleVersion) && array_key_exists('VERSION', $arModuleVersion)) {
            $this->MODULE_VERSION = $arModuleVersion['VERSION'];
            $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        }

        $this->MODULE_NAME = 'MCP сервер';
        $this->MODULE_DESCRIPTION = 'Содержит набор методов для улучшения качества разработки с использованием искусственного интеллекта';
        $this->PARTNER_NAME = 'Дмитрий Кротов';
        $this->PARTNER_URI = 'https://dkrotov.com/';
        // Если указано, то на странице прав доступа будут показаны администраторы и группы
        $this->SHOW_SUPER_ADMIN_GROUP_RIGHTS = 'Y';
        // Если указано, то на странице редактирования групп будет отображаться этот модуль
        $this->MODULE_GROUP_RIGHTS = 'Y';
    }

    public function InstallFiles()
    {
    }

    /**
     * Работа с базой данных
     *
     * @return void
     * @throws ArgumentException
     * @throws ArgumentOutOfRangeException
     * @throws SystemException
     */
    public function InstallDB(): void
    {
        $connection = Application::getInstance()->getConnection();
        include_once(__DIR__ . '/../lib/Entity/DelightMcpTokensTable.php');
        if (!$connection->isTableExists(DelightMcpTokensTable::getTableName())) {
            DelightMcpTokensTable::getEntity()->createDbTable();
            $connection->query("CREATE INDEX IX_IDENTIFIER ON delight_mcp_tokens(IDENTIFIER)");
        }

        include_once(__DIR__ . '/../lib/Entity/DelightMcpLiveApiEventsTable.php');
        if (!$connection->isTableExists(DelightMcpLiveApiEventsTable::getTableName())) {
            DelightMcpLiveApiEventsTable::getEntity()->createDbTable();
            $connection->query("CREATE INDEX IX_NAME ON delight_mcp_live_api_events(NAME)");
            $connection->query("CREATE INDEX IX_MODULE_NAME ON delight_mcp_live_api_events(MODULE_ID, NAME)");
        }

        include_once(__DIR__ . '/../lib/Entity/DelightMcpLiveApiFunctionsTable.php');
        if (!$connection->isTableExists(DelightMcpLiveApiFunctionsTable::getTableName())) {
            DelightMcpLiveApiFunctionsTable::getEntity()->createDbTable();
            $connection->query("CREATE INDEX IX_NAME ON delight_mcp_live_api_functions(NAME)");
            $connection->query("CREATE INDEX IX_MODULE_NAME ON delight_mcp_live_api_functions(MODULE_ID, NAME)");
        }

        // Генерируем секретный ключ для токенов
        Option::set($this->MODULE_ID, 'jwt_secret_key', Random::getString(32));
    }

    public function InstallEvents()
    {
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnModuleUpdate',
            $this->MODULE_ID,
            Delight\Mcp\EventHandlers\Main::class,
            'onModuleUpdateHandler'
        );
    }

    public function InstallTasks()
    {
    }

    public function UnInstallTasks()
    {
    }

    public function UnInstallEvents()
    {
    }

    public function UnInstallDB()
    {
        if (Loader::includeModule($this->MODULE_ID)) {
            $connection = Application::getInstance()->getConnection();
            if ($connection->isTableExists(DelightMcpTokensTable::getTableName())) {
                $connection->dropTable(DelightMcpTokensTable::getTableName());
            }

            if ($connection->isTableExists(DelightMcpLiveApiEventsTable::getTableName())) {
                $connection->dropTable(DelightMcpLiveApiEventsTable::getTableName());
            }

            if ($connection->isTableExists(DelightMcpLiveApiFunctionsTable::getTableName())) {
                $connection->dropTable(DelightMcpLiveApiFunctionsTable::getTableName());
            }
        }
    }

    public function UnInstallFiles()
    {
        Directory::deleteDirectory(__DIR__ . '/data');
    }

    public function DoInstall()
    {
        global $APPLICATION, $step;

        $this->InstallFiles();
        $this->InstallDB();
        $this->InstallEvents();
        $this->InstallTasks();

        ModuleManager::registerModule($this->MODULE_ID);

        $step = IntVal($step);
        if ($step < 2) {
            $APPLICATION->IncludeAdminFile('Индексация кодовой базы модулей', $_SERVER["DOCUMENT_ROOT"] . '/bitrix/modules/' . $this->MODULE_ID . '/install/step1.php');
        }

        return true;
    }

    public function DoUninstall()
    {
        $this->UnInstallTasks();
        $this->UnInstallEvents();
        $this->UnInstallDB();
        $this->UnInstallFiles();

        ModuleManager::unRegisterModule($this->MODULE_ID);
    }
}
