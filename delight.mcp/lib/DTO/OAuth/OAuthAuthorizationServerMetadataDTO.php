<?php

namespace Delight\Mcp\DTO\OAuth;

/**
 * OAuth 2.0 Authorization Server Metadata (RFC 8414).
 * Используется для публикации данных через /.well-known/oauth-authorization-server
 * и /.well-known/openid-configuration.
 * Фактически используется для подключения к ChatGPT.
 */
final class OAuthAuthorizationServerMetadataDTO
{
    /**
     * @param string $issuer Идентификатор OAuth-провайдера (issuer).
     * @param string $authorizationEndpoint Endpoint для получения authorization code.
     * @param string $tokenEndpoint Endpoint для обмена authorization code на access token.
     * @param string[] $grantTypesSupported Поддерживаемые OAuth grant types.
     * @param string[] $tokenEndpointAuthMethodsSupported Способы аутентификации клиента на token endpoint.
     * @param string[] $tokenEndpointAuthSigningAlgValuesSupported Алгоритмы подписи для JWT-аутентификации клиента.
     * @param string[] $scopesSupported Поддерживаемые scope’ы.
     * @param string[] $codeChallengeMethodsSupported Поддерживаемые методы PKCE.
     */
    public function __construct(
        readonly public string $issuer,
        readonly public string $authorizationEndpoint,
        readonly public string $tokenEndpoint,
        readonly public array  $grantTypesSupported,
        readonly public array  $tokenEndpointAuthMethodsSupported,
        readonly public array  $tokenEndpointAuthSigningAlgValuesSupported,
        readonly public array  $scopesSupported,
        readonly public array  $codeChallengeMethodsSupported,
    )
    {
    }

    /**
     * Приводит DTO к массиву в требуемом формате
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'grant_types_supported' => $this->grantTypesSupported,
            'token_endpoint_auth_methods_supported' => $this->tokenEndpointAuthMethodsSupported,
            'token_endpoint_auth_signing_alg_values_supported' => $this->tokenEndpointAuthSigningAlgValuesSupported,
            'scopes_supported' => $this->scopesSupported,
            'code_challenge_methods_supported' => $this->codeChallengeMethodsSupported,
        ];
    }
}
