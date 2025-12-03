<?php

namespace Delight\Mcp\Entity;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Entity;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Fields;

/**
 * Таблица для хранения JWT-токенов
 */
class DelightMcpTokensTable extends Entity\DataManager
{
    /**
     * Возвращает имя таблицы
     *
     * @return string
     */
    public static function getTableName(): string
    {
        return 'delight_mcp_tokens';
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

            new Fields\StringField('IDENTIFIER', [
                'required' => true,
                'validation' => [__CLASS__, 'validateIdentifier'],
                'title' => 'Идентификатор токена'
            ]),

            new Fields\ArrayField('GRANTS', [
                'required' => true,
                'default_value' => false,
                'title' => 'Права'
            ]),

            new Fields\DatetimeField('EXPIRATION', [
                'title' => 'Срок действия',
                'nullable' => true,
            ]),

            new Fields\DatetimeField('DATE_CREATE', [
                'required' => true,
                'default_value' => function() {
                    return new DateTime();
                },
                'title' => 'Дата создания'
            ]),

            new Fields\DatetimeField('DATE_MODIFY', [
                'title' => 'Дата изменения'
            ]),

            new Fields\DatetimeField('LAST_USED', [
                'title' => 'Последнее использование'
            ]),
        ];
    }

    /**
     * Валидация идентификатора
     *
     * @return array
     * @throws ArgumentTypeException
     */
    public static function validateIdentifier(): array
    {
        return [
            new Entity\Validator\Length(1, 255),
            new Entity\Validator\Unique('Идентификатор должен быть уникальным'),
        ];
    }
}
