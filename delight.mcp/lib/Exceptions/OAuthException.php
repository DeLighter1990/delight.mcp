<?php

namespace Delight\Mcp\Exceptions;

use Delight\Mcp\Services\OAuthService;

/**
 * Исключение с OAuth 2.1 кодом ошибки (RFC 6749 §5.2).
 */
class OAuthException extends \RuntimeException
{
    public function __construct(
        private readonly string $oauthError,
        string $description,
        private readonly int $httpStatus,
    ) {
        parent::__construct($description);
    }

    public static function unsupportedGrantType(string $given): self
    {
        return new self(
            'unsupported_grant_type',
            sprintf('Поддерживается только grant_type="%s", получен: "%s"', OAuthService::GRANT_CLIENT_CREDENTIALS, $given),
            400
        );
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description, 400);
    }

    public static function invalidClient(): self
    {
        return new self('invalid_client', 'Неверные учётные данные клиента', 401);
    }

    public static function invalidScope(array $unknownScopes): self
    {
        return new self(
            'invalid_scope',
            'Токен не имеет запрошенных прав: ' . implode(', ', $unknownScopes),
            400
        );
    }

    public function getOauthError(): string
    {
        return $this->oauthError;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
