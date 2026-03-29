<?php

namespace Delight\Mcp\DTO\OAuth;

/**
 * DTO ответа token endpoint (RFC 6749 §5.1).
 */
final class OAuthTokenResponseDTO
{
    public function __construct(
        public readonly string  $accessToken,
        public readonly string  $tokenType,
        public readonly string  $scope,
        public readonly ?int    $expiresIn = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'access_token' => $this->accessToken,
            'token_type'   => $this->tokenType,
            'scope'        => $this->scope,
        ];

        if ($this->expiresIn !== null) {
            $data['expires_in'] = $this->expiresIn;
        }

        return $data;
    }
}
