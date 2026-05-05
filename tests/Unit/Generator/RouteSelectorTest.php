<?php

declare(strict_types=1);

use ApexDocs\Config;
use ApexDocs\Generator\RouteSelector;
use ApexDocs\Route\Route;
use ApexDocs\Tests\Fixtures\Controllers\UserController;

function selectorRoute(string $path, string $handler = UserController::class.'@index'): Route
{
    return new Route(['GET'], $path, $handler);
}

it('explains why each route is excluded, in gate order', function () {
    $selector = new RouteSelector(
        new Config(pathPrefixes: ['api'], excludePaths: ['api/internal/*'], specGroup: 'partner'),
        fn (Route $r) => ! str_contains($r->path, 'filtered'),
    );

    expect($selector->exclusionReason(selectorRoute('/web/home')))->toBe(RouteSelector::REASON_PREFIX)
        ->and($selector->exclusionReason(selectorRoute('/api/internal/ping')))->toBe(RouteSelector::REASON_EXCLUDED)
        ->and($selector->exclusionReason(selectorRoute('/api/filtered')))->toBe(RouteSelector::REASON_FILTER)
        ->and($selector->exclusionReason(selectorRoute('/api/users')))->toBeNull();
});

it('reports the spec_group gate for routes carrying another ApiGroup', function () {
    $grouped = new class
    {
        #[\ApexDocs\Attribute\ApiGroup('internal')]
        public function index(): void {}
    };

    $selector = new RouteSelector(new Config(specGroup: 'partner'));

    expect($selector->exclusionReason(selectorRoute('/api/x', $grouped::class.'@index')))->toBe(RouteSelector::REASON_GROUP)
        ->and($selector->exclusionReason(selectorRoute('/api/users')))->toBeNull();
});

it('documents nothing when every prefix is unusable, and everything when a prefix is empty', function () {
    expect((new RouteSelector(new Config(pathPrefixes: [null])))->exclusionReason(selectorRoute('/api/users')))
        ->toBe(RouteSelector::REASON_NO_PREFIX_USABLE);

    expect((new RouteSelector(new Config(pathPrefixes: ['api', ''])))->select([selectorRoute('/anything')]))->toHaveCount(1);
});
