<?php

namespace Delight\Mcp\Helpers;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Delight\Mcp\Entity\DelightMcpTokensTable;
use Delight\Mcp\Enum\TokenGrants;

class Options
{
    /**
     * Формирует список токенов для вывода в таблице через компонент bitrix:main.ui.grid
     *
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function buildTokensTableRows(): array
    {
        $resultList = [];
        $res = DelightMcpTokensTable::getList();
        foreach ($res->fetchAll() as $row) {
            /** @var array<string> $grantsKeys */
            $grantsKeys = $row['GRANTS'];

            $grantsDescriptions = [];
            foreach ($grantsKeys as $grantKey) {
                $caseConstant = TokenGrants::class . '::' . $grantKey;
                if (defined($caseConstant)) {
                    /** @var TokenGrants $grantEnumCase */
                    $grantEnumCase = constant($caseConstant);
                    $grantsDescriptions[] = $grantEnumCase->value;
                }
            }

            $grantsList = '<ul class="delight-mcp-grants-list">';
            foreach ($grantsDescriptions as $description) {
                $grantsList .= '<li>' . htmlspecialcharsbx($description) . '</li>';
            }
            $grantsList .= '</ul>';

            $resultList[] = [
                'data' => [
                    'ID' => $row['ID'],
                    'IDENTIFIER' => $row['IDENTIFIER'],
                    'GRANTS' => $grantsList,
                    'EXPIRATION' => $row['EXPIRATION'],
                    'DATE_CREATE' => $row['DATE_CREATE'],
                    'LAST_USED' => $row['LAST_USED'],
                ],
                'actions' => [
                    [
                        'text' => 'Удалить',
                        'default' => true,
                        'onclick' => 'window.delightMcpOptionsComponent.deleteTokens([' . $row['ID'] . ']);'
                    ]
                ]
            ];
        }

        return $resultList;
    }
}
