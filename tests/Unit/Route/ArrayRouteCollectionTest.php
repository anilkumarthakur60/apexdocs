<?php

declare(strict_types=1);

use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Route\Route;

test('can add and retrieve routes', function () {
    $c = new ArrayRouteCollection;
    $c->add('GET', '/api/users', 'UserController@index');
    $c->add('POST', '/api/users', 'UserController@store');

    $routes = $c->all();
    expect($routes)->toHaveCount(2);
    expect($routes[0])->toBeInstanceOf(Route::class);
    expect($routes[0]->path)->toBe('/api/users');
    expect($routes[0]->methods)->toBe(['GET']);
});

test('method is normalised to uppercase', function () {
    $c = new ArrayRouteCollection;
    $c->add('get', '/api/test', 'TestController@index');

    expect($c->all()[0]->methods[0])->toBe('GET');
});

test('route resolves handler', function () {
    $c = new ArrayRouteCollection;
    $c->add('GET', '/api/users/{id}', 'UserController@show');

    [$class, $method] = $c->all()[0]->resolveHandler();
    expect($class)->toBe('UserController');
    expect($method)->toBe('show');
});

test('invokable handler resolves to __invoke', function () {
    $c = new ArrayRouteCollection;
    $c->add('GET', '/api/users', 'UserController');

    [, $method] = $c->all()[0]->resolveHandler();
    expect($method)->toBe('__invoke');
});

test('route extracts path params', function () {
    $c = new ArrayRouteCollection;
    $c->add('GET', '/api/users/{user}/posts/{post}', 'PostController@index');

    expect($c->all()[0]->pathParamNames())->toBe(['user', 'post']);
});
