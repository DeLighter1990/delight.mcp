<?php

namespace Delight\Mcp\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\JWT;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Delight\Mcp\Entity\DelightMcpTokensTable;
use Delight\Mcp\Enum\TokenGrants;

/**
 * Class TokensService
 *
 * Сервис для работы с JWT-токенами
 *
 * @package Delight\Mcp\Services
 */
class TokenService
{
    private const MODULE_ID = 'delight.mcp';
    private const SECRET_KEY_OPTION = 'jwt_secret_key';
    private const ALGORITHM = 'HS256';

    /**
     * Получить секретный ключ для подписи JWT
     *
     * @return string
     * @throws SystemException
     */
    private function getSecretKey(): string
    {
        $secretKey = Option::get(self::MODULE_ID, self::SECRET_KEY_OPTION);

        if (empty($secretKey)) {
            throw new SystemException('Секретный ключ JWT не указан в настройках модуля');
        }

        return $secretKey;
    }

    /**
     * Создать и выдать JWT-токен
     *
     * @param string $identifier Уникальный идентификатор (например, имя разработчика)
     * @param string[] $grants Массив прав
     * @param int|null $expiresIn Срок действия токена в секундах (null = без ограничений)
     * @return string JWT-токен
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function createToken(string $identifier, array $grants = [], ?int $expiresIn = null): string
    {
        if (empty($identifier)) {
            throw new ArgumentException('Идентификатор не может быть пустым');
        }

        $cases = array_column(TokenGrants::cases(), null, 'name');

        foreach ($grants as $grant) {
            if (!isset($cases[$grant])) {
                throw new ArgumentException(sprintf('Неизвестное право токена: %s', $grant));
            }
        }

        // Проверяем, существует ли уже такой идентификатор
        $existing = DelightMcpTokensTable::getList([
            'filter' => ['=IDENTIFIER' => $identifier],
            'select' => ['ID']
        ])->fetch();

        if ($existing) {
            throw new ArgumentException('Такой идентификатор уже существует. Используйте уникальный идентификатор.');
        }

        // Сохраняем в базу данных
        $result = DelightMcpTokensTable::add([
            'IDENTIFIER' => $identifier,
            'GRANTS' => $grants,
            'DATE_CREATE' => new DateTime(),
            'EXPIRATION' => $expiresIn ? DateTime::createFromTimestamp($expiresIn) : null,
        ]);

        if (!$result->isSuccess()) {
            throw new SystemException(implode(', ', $result->getErrorMessages()));
        }

        // Создаём payload для JWT
        $payload = [
            'identifier' => $identifier,
            'iat' => time(), // Issued at
        ];

        // Добавляем срок действия, если указан
        if ($expiresIn !== null) {
            $payload['exp'] = time() + $expiresIn;
        }

        // Генерируем JWT
        return JWT::encode($payload, $this->getSecretKey(), self::ALGORITHM);
    }

    /**
     * Удалить идентификатор токена
     *
     * @param int $id ID токена
     * @return bool
     * @throws ArgumentException
     * @throws SystemException
     * @throws \Exception
     */
    public function deleteToken(int $id): bool
    {
        $result = DelightMcpTokensTable::delete($id);

        if (!$result->isSuccess()) {
            throw new SystemException(implode(', ', $result->getErrorMessages()));
        }

        return true;
    }

    /**
     * Достаёт токен из заголовка
     *
     * @return string|null Токен
     */
    public static function extractTokenFromHeader(): ?string
    {
        $request = Application::getInstance()->getContext()->getRequest();

        // 1. Стандартный метод Битрикс
        $header = $request->getHeader('Authorization');

        // 2. Через apache_request_headers() для Apache
        if (!$header && function_exists('apache_request_headers')) {
            $allHeaders = apache_request_headers();
            // apache_request_headers() может возвращать ключи в разном регистре
            $authHeaderKey = array_key_exists('Authorization', $allHeaders)
                ? 'Authorization'
                : (array_key_exists('authorization', $allHeaders) ? 'authorization' : null);

            if ($authHeaderKey) {
                $header = $allHeaders[$authHeaderKey];
            }
        }

        // 3. Для CGI/FastCGI
        if (!$header) {
            $header = $request->getServer()->get('REDIRECT_HTTP_AUTHORIZATION');
        }

        if ($header && preg_match('/^Bearer\s+(.*?)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Валидировать JWT-токен и получить права
     *
     * @param string $token JWT-токен
     * @return string[] Массив с правами
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function validateToken(string $token): array
    {
        if (empty($token)) {
            throw new ArgumentException('Токен не может быть пустым');
        }

        try {
            $payload = JWT::decode($token, $this->getSecretKey(), [self::ALGORITHM]);
        } catch (\Exception $e) {
            throw new SystemException('Неверный JWT токен: ' . $e->getMessage());
        }

        // Проверяем срок действия
        if (isset($payload->exp) && $payload->exp < time()) {
            throw new SystemException('Срок действия токена истек');
        }

        $identifier = $payload->identifier ?? null;

        if (empty($identifier)) {
            throw new SystemException('Токен не содержит идентификатор');
        }

        // Проверяем, существует ли идентификатор в базе
        $tokenData = DelightMcpTokensTable::getList([
            'filter' => [
                '=IDENTIFIER' => $identifier,
            ],
            'select' => ['ID', 'IDENTIFIER', 'GRANTS']
        ])->fetch();

        if (!$tokenData) {
            throw new SystemException('Идентификатор токена не найден');
        }

        // Обновляем время последнего использования
        DelightMcpTokensTable::update($tokenData['ID'], [
            'LAST_USED' => new DateTime()
        ]);

        // Возвращаем права токена
        return $tokenData['GRANTS'];
    }

    /**
     * Проверить, есть ли у токена конкретное право
     *
     * @param string $token JWT-токен
     * @param TokenGrants $grant Название права
     * @return bool
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function hasPermission(string $token, TokenGrants $grant): bool
    {
        $tokenData = $this->validateToken($token);

        return $tokenData['GRANTS'][$grant->name] ?? false;
    }

    /**
     * Получить информацию о токене по идентификатору
     *
     * @param string $identifier Идентификатор токена
     * @return array|null
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getTokenInfo(string $identifier): ?array
    {
        $tokenData = DelightMcpTokensTable::getList([
            'filter' => ['=IDENTIFIER' => $identifier],
            'select' => ['*']
        ])->fetch();

        if (!$tokenData) {
            return null;
        }

        return [
            'id' => $tokenData['ID'],
            'identifier' => $tokenData['IDENTIFIER'],
            'grants' => $tokenData['GRANTS'],
            'date_create' => $tokenData['DATE_CREATE'],
            'date_modify' => $tokenData['DATE_MODIFY'],
            'last_used' => $tokenData['LAST_USED'],
        ];
    }

    /**
     * Получает идентификатор токена из JWT-токена
     *
     * @param string $token JWT-токен.
     * @return string Идентификатор токена или null, если токен не найден или некорректен.
     * @throws SystemException
     */
    public function getTokenIdentifier(string $token): string
    {
        return JWT::decode($token, $this->getSecretKey(), [self::ALGORITHM])->identifier;
    }
}
