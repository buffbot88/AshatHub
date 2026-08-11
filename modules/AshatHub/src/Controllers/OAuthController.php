<?php
declare(strict_types=1);
namespace Controllers;

use Core\AuthService;
use Core\JwtCodec;
use Core\OAuthServer;
use Core\RequestContext;

/**
 * OIDC issuer controller. All endpoints live under /api/oauth/*.
 */
final class OAuthController
{
    public function authorize(RequestContext $ctx): void
    {
        $params = $this->readAuthorizeParams($ctx);
        if ($params === null) {
            // Display the error inline so a popup user sees it too.
            $this->renderErrorPage($ctx, 'Invalid /authorize request.', 400);
            return;
        }

        $client = OAuthServer::findClient($params['client_id']);
        if ($client === null || !OAuthServer::redirectUriAllowed($client, $params['redirect_uri'])) {
            $this->renderErrorPage($ctx, 'Unknown client_id or redirect_uri not allowed.', 400);
            return;
        }

        if ($ctx->server('REQUEST_METHOD') === 'GET') {
            if (!$ctx->check()) {
                // Render a minimal login form preserving the OIDC query params.
                $this->renderLoginForm($ctx, $params);
                return;
            }
            $this->issueCodeAndRedirect($ctx, $client, $params);
            return;
        }

        // POST — form submission from the login form above
        $this->renderLoginForm($ctx, $params);
    }

    private function readAuthorizeParams(RequestContext $ctx): ?array
    {
        $method = (string) $ctx->server('REQUEST_METHOD', 'GET');
        $isPost = $method === 'POST';
        $get = fn (string $k, string $d = '') => (string) ($isPost ? $ctx->str($k, $d) : $ctx->query($k, $d));
        $clientId     = trim($get('client_id'));
        $redirectUri  = trim($get('redirect_uri'));
        $state        = trim($get('state'));
        $challenge    = trim($get('code_challenge'));
        $methodParam  = strtoupper(trim($get('code_challenge_method', 'S256')));
        $responseType = trim($get('response_type', 'code'));

        if ($responseType !== 'code') return null;
        if ($clientId === '' || $redirectUri === '' || $challenge === '' || $state === '') return null;
        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => $methodParam,
            'response_type' => $responseType,
        ];
    }

    private function renderLoginForm(RequestContext $ctx, array $params): void
    {
        $method = (string) $ctx->server('REQUEST_METHOD', 'GET');
        $error = null;

        if ($method === 'POST') {
            $username = (string) ($ctx->str('username'));
            $password = (string) ($ctx->str('password'));
            $result = AuthService::login($username, $password);
            if ($result === null) {
                $error = 'Wrong username or password.';
            } else {
                $client = OAuthServer::findClient($params['client_id']);
                $this->issueCodeAndRedirect($ctx, $client ?? ['client_id' => $params['client_id'], 'name' => ''], $params);
                return;
            }
        }
        $ctx->view(
            'pages/oauth_authorize',
            [
                'title' => 'Sign in · ' . APP_NAME,
                'params' => $params,
                'error' => $error,
                'clientName' => (OAuthServer::findClient($params['client_id'])['name'] ?? 'an app'),
            ],
            'raw',
        );
    }

    private function issueCodeAndRedirect(RequestContext $ctx, array $client, array $params): void
    {
        if (!$ctx->check()) {
            // Edge: a stale session caused auth to fail. Send them to login again.
            $this->renderLoginForm($ctx, $params);
            return;
        }
        $user = $ctx->user();
        try {
            $code = OAuthServer::createAuthorizationCode(
                $client['client_id'],
                (string) $user['id'],
                $params['redirect_uri'],
                $params['code_challenge'],
                $params['code_challenge_method'],
            );
        } catch (\InvalidArgumentException $e) {
            $this->renderErrorPage($ctx, $e->getMessage(), 400);
            return;
        }
        $separator = str_contains($params['redirect_uri'], '?') ? '&' : '?';
        $target = $params['redirect_uri']
            . $separator . 'code=' . rawurlencode($code)
            . '&state=' . rawurlencode($params['state']);
        $ctx->redirect($target);
    }

    public function token(RequestContext $ctx): void
    {
        if (strtoupper((string) $ctx->server('REQUEST_METHOD')) !== 'POST') {
            $ctx->jsonResponse(['error' => 'method_not_allowed'], 405);
            return;
        }
        $body = $ctx->jsonBody();
        if (!is_array($body)) {
            $ctx->jsonResponse(['error' => 'invalid_request', 'error_description' => 'JSON body required'], 400);
            return;
        }

        $grantType    = trim((string) ($body['grant_type'] ?? ''));
        $code         = trim((string) ($body['code'] ?? ''));
        $redirectUri  = trim((string) ($body['redirect_uri'] ?? ''));
        $clientId     = trim((string) ($body['client_id'] ?? ''));
        $codeVerifier = trim((string) ($body['code_verifier'] ?? ''));

        if ($grantType !== 'authorization_code') {
            $ctx->jsonResponse(['error' => 'unsupported_grant_type'], 400);
            return;
        }
        if ($code === '' || $redirectUri === '' || $clientId === '' || $codeVerifier === '') {
            $ctx->jsonResponse(['error' => 'invalid_request', 'error_description' => 'Missing required fields.'], 400);
            return;
        }

        $client = OAuthServer::findClient($clientId);
        if ($client === null || !OAuthServer::redirectUriAllowed($client, $redirectUri)) {
            $ctx->jsonResponse(['error' => 'invalid_client'], 400);
            return;
        }

        $userId = OAuthServer::consumeAuthorizationCode($code, $clientId, $redirectUri, $codeVerifier);
        if ($userId === null) {
            $ctx->jsonResponse(['error' => 'invalid_grant'], 400);
            return;
        }
        $user = \Repositories\RepositoryRegistry::user()->find($userId);
        if ($user === null) {
            $ctx->jsonResponse(['error' => 'invalid_grant'], 400);
            return;
        }

        $issuer = rtrim((string) APP_URL, '/') . '/api/oauth';
        $accessToken = OAuthServer::issueIdToken($user, $clientId, $issuer);
        $idToken = OAuthServer::issueIdToken($user, $clientId, $issuer);

        $ctx->jsonResponse([
            'access_token' => $accessToken,
            'id_token' => $idToken,
            'token_type' => 'Bearer',
            'expires_in' => OAuthServer::ID_TOKEN_TTL_SECONDS,
            'scope' => 'openid profile',
        ]);
    }

    public function userinfo(RequestContext $ctx): void
    {
        $authHeader = (string) ($ctx->server('HTTP_AUTHORIZATION') ?? '');
        $token = (str_starts_with($authHeader, 'Bearer ')) ? trim(substr($authHeader, 7)) : '';
        if ($token === '') {
            $ctx->jsonResponse(['error' => 'invalid_token'], 401);
            return;
        }
        $issuer = rtrim((string) APP_URL, '/') . '/api/oauth';
        $claims = JwtCodec::verify($token, $issuer, $ctx->json('client_id', ''));
        // userinfo only verifies signature + iss + aud + exp; it accepts any
        // audience that we minted. The aud claim is the original client_id.
        $claims = JwtCodec::verify($token, $issuer, isset($claims['aud']) ? (string) $claims['aud'] : '');
        if ($claims === null) {
            $ctx->jsonResponse(['error' => 'invalid_token'], 401);
            return;
        }
        $ctx->jsonResponse([
            'sub' => $claims['sub'] ?? null,
            'username' => $claims['username'] ?? null,
            'role' => $claims['role'] ?? null,
            'display_name' => $claims['display_name'] ?? null,
        ]);
    }

    public function jwks(RequestContext $ctx): void
    {
        $ctx->jsonResponse(['keys' => [JwtCodec::jwk()]]);
    }

    public function discovery(RequestContext $ctx): void
    {
        $base = rtrim((string) APP_URL, '/');
        $ctx->jsonResponse([
            'issuer' => $base,
            'authorization_endpoint' => $base . '/api/oauth/authorize',
            'token_endpoint' => $base . '/api/oauth/token',
            'userinfo_endpoint' => $base . '/api/oauth/userinfo',
            'jwks_uri' => $base . '/api/oauth/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'profile'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    private function renderErrorPage(RequestContext $ctx, string $message, int $status): void
    {
        http_response_code($status);
        $ctx->view(
            'pages/oauth_error',
            ['title' => 'OIDC error · ' . APP_NAME, 'status' => $status, 'message' => $message],
            'raw',
        );
    }
}
