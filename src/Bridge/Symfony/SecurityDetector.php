<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony;

use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Route\Route;
use ReflectionMethod;

/**
 * Symfony bridge: detects security requirements from Symfony's security attributes.
 *
 * Detects:
 *  - #[IsGranted] (symfony/security-http)
 *  - #[Security] (sensio/framework-extra-bundle)
 *  - 'IS_AUTHENTICATED_*' firewall expressions
 */
final class SecurityDetector implements SecurityDetectorInterface
{
    private const BEARER_SCHEME = [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
    ];

    public function schemes(): array
    {
        return ['bearer' => self::BEARER_SCHEME];
    }

    public function forRoute(Route $route, ReflectionMethod $method): ?array
    {
        // Check #[IsGranted] on the method or its declaring class
        if ($this->hasSecurityAttribute($method)) {
            return [['bearer' => []]];
        }

        $class = $method->getDeclaringClass();
        if ($this->hasSecurityAttributeOnClass($class)) {
            return [['bearer' => []]];
        }

        // Route-level middleware hint (from metadata if injected by bridge)
        foreach ($route->middleware as $mw) {
            if (str_contains($mw, 'auth') || str_contains($mw, 'security')) {
                return [['bearer' => []]];
            }
        }

        return null;
    }

    private function hasSecurityAttribute(ReflectionMethod $method): bool
    {
        return $this->reflectionHasSecurityAttr($method);
    }

    private function hasSecurityAttributeOnClass(\ReflectionClass $class): bool
    {
        return $this->reflectionHasSecurityAttr($class);
    }

    private function reflectionHasSecurityAttr(\ReflectionClass|\ReflectionMethod $ref): bool
    {
        $securityAttrs = [
            'Symfony\\Component\\Security\\Http\\Attribute\\IsGranted',
            'Sensio\\Bundle\\FrameworkExtraBundle\\Configuration\\Security',
            'Sensio\\Bundle\\FrameworkExtraBundle\\Configuration\\IsGranted',
        ];

        foreach ($securityAttrs as $attrClass) {
            if ($ref->getAttributes($attrClass)) {
                return true;
            }
        }

        return false;
    }
}
