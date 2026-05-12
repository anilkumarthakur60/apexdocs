<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Route\Route;
use Laravel\Passport\PassportServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider;
use ReflectionMethod;

/**
 * Laravel bridge: auto-detects Sanctum / Passport / JWT security schemes.
 */
final class SecurityDetector implements SecurityDetectorInterface
{
    public function schemes(): array
    {
        $schemes = [];

        if (class_exists(SanctumServiceProvider::class)) {
            $schemes['sanctum'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'token',
                'description' => 'Laravel Sanctum token. Pass in Authorization: Bearer <token>.',
            ];
        }

        if (class_exists(PassportServiceProvider::class)) {
            $appUrl = config('app.url', 'http://localhost');
            $schemes['passport'] = [
                'type' => 'oauth2',
                'description' => 'Laravel Passport OAuth2.',
                'flows' => [
                    'authorizationCode' => [
                        'authorizationUrl' => $appUrl.'/oauth/authorize',
                        'tokenUrl' => $appUrl.'/oauth/token',
                        'scopes' => [],
                    ],
                    'password' => [
                        'tokenUrl' => $appUrl.'/oauth/token',
                        'scopes' => [],
                    ],
                ],
            ];
        }

        if (class_exists(LaravelServiceProvider::class)
            || class_exists(\Tymon\JWTAuth\Providers\LaravelServiceProvider::class)
        ) {
            $schemes['jwt'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'JWT bearer token.',
            ];
        }

        return $schemes;
    }

    public function forRoute(Route $route, ReflectionMethod $handler): ?array
    {
        $security = [];

        foreach ($route->middleware as $mw) {
            $mw = is_string($mw) ? $mw : '';

            if (in_array($mw, ['auth:sanctum', 'auth', 'auth:api'], true)) {
                $security[] = ['sanctum' => []];
            } elseif (str_contains($mw, 'passport') || str_contains($mw, 'oauth')) {
                $security[] = ['passport' => []];
            } elseif (str_contains($mw, 'jwt')) {
                $security[] = ['jwt' => []];
            }
        }

        return empty($security) ? null : $security;
    }
}
