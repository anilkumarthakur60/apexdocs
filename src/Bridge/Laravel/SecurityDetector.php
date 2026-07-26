<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Route\Route;
use ReflectionMethod;

/**
 * Laravel bridge: auto-detects Sanctum / Passport / JWT security schemes.
 *
 * Every scheme name handed out by {@see forRoute()} is guaranteed to exist in
 * {@see schemes()}. An operation that referenced an undefined scheme would make
 * the whole document invalid, so when none of the known packages is installed
 * the detector falls back to a plain `bearerAuth` definition it declares itself.
 */
final class SecurityDetector implements SecurityDetectorInterface
{
    private const SANCTUM_PROVIDER = 'Laravel\\Sanctum\\SanctumServiceProvider';

    private const PASSPORT_PROVIDER = 'Laravel\\Passport\\PassportServiceProvider';

    private const JWT_PROVIDERS = [
        'PHPOpenSourceSaver\\JWTAuth\\Providers\\LaravelServiceProvider',
        'Tymon\\JWTAuth\\Providers\\LaravelServiceProvider',
    ];

    private const FALLBACK = 'bearerAuth';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemes(): array
    {
        $schemes = [];

        if ($this->hasSanctum()) {
            $schemes['sanctum'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'token',
                'description' => 'Laravel Sanctum token. Pass in Authorization: Bearer <token>.',
            ];
        }

        if ($this->hasPassport()) {
            $appUrl = rtrim((string) $this->appUrl(), '/');
            $schemes['passport'] = [
                'type' => 'oauth2',
                'description' => 'Laravel Passport OAuth2.',
                'flows' => [
                    'authorizationCode' => [
                        'authorizationUrl' => $appUrl.'/oauth/authorize',
                        'tokenUrl' => $appUrl.'/oauth/token',
                        'refreshUrl' => $appUrl.'/oauth/token',
                        'scopes' => new \stdClass,
                    ],
                    'clientCredentials' => [
                        'tokenUrl' => $appUrl.'/oauth/token',
                        'scopes' => new \stdClass,
                    ],
                ],
            ];
        }

        if ($this->hasJwt()) {
            $schemes['jwt'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'JWT bearer token.',
            ];
        }

        if ($schemes === []) {
            $schemes[self::FALLBACK] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'Bearer token. Pass in Authorization: Bearer <token>.',
            ];
        }

        return $schemes;
    }

    /**
     * @return list<array<string, list<string>>>|null
     */
    public function forRoute(Route $route, ReflectionMethod $handler): ?array
    {
        $names = [];

        foreach ($route->middleware as $mw) {
            if (! is_string($mw)) {
                continue;
            }
            $name = $this->schemeForMiddleware($mw);
            if ($name !== null) {
                $names[$name] = true;
            }
        }

        if ($names === []) {
            return null;
        }

        return array_values(array_map(
            static fn (string $name): array => [$name => []],
            array_keys($names),
        ));
    }

    private function schemeForMiddleware(string $middleware): ?string
    {
        $mw = strtolower($middleware);

        // `auth:sanctum`, `auth`, `auth.basic`, `auth:api`, class-name form.
        $isAuth = $mw === 'auth'
            || str_starts_with($mw, 'auth:')
            || str_starts_with($mw, 'auth.')
            || str_contains($mw, 'authenticate');

        if (str_contains($mw, 'jwt')) {
            return $this->hasJwt() ? 'jwt' : self::FALLBACK;
        }
        if (str_contains($mw, 'passport') || str_contains($mw, 'oauth') || str_contains($mw, 'client_credentials')) {
            return $this->hasPassport() ? 'passport' : self::FALLBACK;
        }
        if (! $isAuth) {
            return null;
        }
        if (str_contains($mw, 'sanctum')) {
            return $this->hasSanctum() ? 'sanctum' : self::FALLBACK;
        }

        // Generic auth guard — name the first scheme we actually defined.
        return array_key_first($this->schemes()) ?? self::FALLBACK;
    }

    private function hasSanctum(): bool
    {
        return class_exists(self::SANCTUM_PROVIDER);
    }

    private function hasPassport(): bool
    {
        return class_exists(self::PASSPORT_PROVIDER);
    }

    private function hasJwt(): bool
    {
        foreach (self::JWT_PROVIDERS as $provider) {
            if (class_exists($provider)) {
                return true;
            }
        }

        return false;
    }

    private function appUrl(): string
    {
        if (! function_exists('config')) {
            return 'http://localhost';
        }

        $url = config('app.url', 'http://localhost');

        return is_string($url) && $url !== '' ? $url : 'http://localhost';
    }
}
