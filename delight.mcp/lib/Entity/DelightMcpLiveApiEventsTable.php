<?php

namespace Delight\Mcp\Entity;

use Bitrix\Main\SystemException;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Entity;

/**
 * Таблица для хранения информации о событиях модулей (Live API)
 */
class DelightMcpLiveApiEventsTable extends Entity\DataManager
{
    /**
     * Возвращает имя таблицы
     *
     * @return string
     */
    public static function getTableName(): string
    {
        return 'delight_mcp_live_api_events';
    }

    /**
     * @return array
     * @throws SystemException
     */
    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
                'title' => 'ID'
            ]),

            new Fields\StringField('MODULE_ID', [
                'required' => true,
                'title' => 'Идентификатор модуля'
            ]),

            new Fields\StringField('MODULE_VERSION', [
                'required' => true,
                'default_value' => false,
                'title' => 'Версия модуля'
            ]),

            new Fields\StringField('NAME', [
                'required' => true,
                'title' => 'Имя события'
            ]),

            new Fields\TextField('DATA', [
                'required' => true,
                'title' => 'Данные с информацией о событии'
            ])
        ];
    }
}
