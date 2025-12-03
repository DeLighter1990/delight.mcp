<?php

namespace Delight\Mcp\Controller;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Delight\Mcp\ActionFilter\Prefilters\CheckRights;
use Delight\Mcp\DTO\ProgressBarValues;
use Delight\Mcp\DTO\Tests\TestResultDto;
use Delight\Mcp\Generators\BxDocsEmbeddingsGenerator;
use Delight\Mcp\Helpers\Utils;
use Delight\Mcp\Services\LiveApiService;
use Delight\Mcp\Services\TokenService;
use Delight\Mcp\Tests\ExternalApiTest;

/**
 * Внутренний контроллер, предназначен для вызовов обработчиков внутри модуля (из админки)
 */
class Internal extends Controller
{
    public function configureActions(): array
    {
        return [
            'reindexDocs' => [
                'prefilters' => [
                    new Authentication(),
                    new Csrf(),
                    new CheckRights()
                ]
            ],
            'reindexLiveAPI' => [
                'prefilters' => [
                    new Authentication(),
                    new Csrf(),
                    new CheckRights()
                ]
            ],
            'createToken' => [
                'prefilters' => [
                    new HttpMethod([HttpMethod::METHOD_POST]),
                    new Authentication(),
                    new Csrf(),
                    new CheckRights()
                ]
            ],
            'deleteTokens' => [
                'prefilters' => [
                    new HttpMethod([HttpMethod::METHOD_POST]),
                    new Authentication(),
                    new Csrf(),
                    new CheckRights()
                ]
            ],
            'testExternalApi' => [
                'prefilters' => [
                    new Authentication(),
                    new Csrf(),
                    new CheckRights()
                ]
            ]
        ];
    }

    /**
     * Переиндексирует документацию Bitrix Framework
     * /bitrix/services/main/ajax.php?action=delight:mcp.Internal.reindexDocs
     *
     * @param int $start С какого по счёту файла начать
     * @param int $limit Количество обрабатываемых файлов за один проход
     * @return ProgressBarValues Статистика для прогресс-бара
     * @throws \Exception
     */
    public function reindexDocsAction(int $start, int $limit): ProgressBarValues
    {
        return (new BxDocsEmbeddingsGenerator())->generateEmbeddingsForDocs($start, $limit);
    }

    /**
     * Переиндексирует LiveAPI
     * /bitrix/services/main/ajax.php?action=delight:mcp.Internal.reindexLiveAPI
     *
     * @param int $start С какого по счёту модуля начать переиндексацию.
     * @param int $limit Количество модулей, обрабатываемых за один проход.
     * @return ProgressBarValues
     * @throws \JsonException
     * @throws \Exception
     */
    public function reindexLiveAPIAction(int $start, int $limit): ProgressBarValues
    {
        return (new LiveApiService())->reindex($start, $limit);
    }

    /**
     * Выпускает JWT-токен
     * /bitrix/services/main/ajax.php?action=delight:mcp.Internal.createToken
     *
     * @param string $identifier Идентификатор токена
     * @param string[] $grants Массив строковых представлений прав токена (например, ["ENV_INFO", "SEARCH_BX_DOCS"])
     * @param string|null $exp Срок действия токена в формате ДД.ММ.ГГГГ или ДД.ММ.ГГГГ ЧЧ:ММ
     * @return string
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function createTokenAction(string $identifier, array $grants, ?string $exp): string
    {
        $expirationTime = $exp ? Utils::convertDateToTimestamp($exp) : null;

        return (new TokenService())->createToken($identifier, $grants, $expirationTime);
    }

    /**
     * Удаление токена
     * /bitrix/services/main/ajax.php?action=delight:mcp.Internal.deleteTokens
     *
     * @param array $ids ID токенов
     * @return void
     * @throws ArgumentException
     * @throws SystemException
     */
    public function deleteTokensAction(array $ids): void
    {
        $tokenService = new TokenService();
        foreach ($ids as $id) {
            $tokenService->deleteToken($id);
        }
    }

    /**
     * Запускает тестирование внешнего API
     * /bitrix/services/main/ajax.php?action=delight:mcp.Internal.testExternalApi
     *
     * @param string $token Токен
     * @return TestResultDto
     */
    public function testExternalApiAction(string $token): TestResultDto
    {
        return (new ExternalApiTest($token))->run();
    }
}
