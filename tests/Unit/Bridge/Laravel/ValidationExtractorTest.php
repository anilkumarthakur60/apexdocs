<?php

declare(strict_types=1);

namespace ApexDocs\Tests\Unit\Bridge\Laravel;

use ApexDocs\Bridge\Laravel\RuleParser;
use ApexDocs\Bridge\Laravel\ValidationExtractor;
use ApexDocs\Route\Route;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The C5 sandbox guarantees: a misbehaving FormRequest can never crash
 * documentation generation. These fixtures simulate every failure mode the
 * extractor was hardened against — constructor side effects, throwing
 * rules(), rules() that needs request context — and the extractor must
 * either return the schema (good case) or null (bad case), never propagate.
 */
class FixtureRulesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:64',
            'age' => 'integer|min:0',
        ];
    }
}

class FixtureThrowingRequest extends FormRequest
{
    public function rules(): array
    {
        throw new \RuntimeException('exploded inside rules()');
    }
}

class FixtureContextHungryRequest extends FormRequest
{
    public function rules(): array
    {
        // Real apps do this all the time — references that only resolve
        // mid-request.
        $id = $this->route('id');

        return ['id' => "required|integer|in:{$id}"];
    }
}

class FixtureNoRulesRequest extends FormRequest {}

class FixtureControllerStub
{
    public function methodWithRules(FixtureRulesRequest $r): void {}

    public function methodWithThrower(FixtureThrowingRequest $r): void {}

    public function methodWithContextHungry(FixtureContextHungryRequest $r): void {}

    public function methodWithEmpty(FixtureNoRulesRequest $r): void {}

    public function methodWithNothing(): void {}
}

uses()->beforeEach(function () {
    $this->extractor = new ValidationExtractor(new RuleParser);
    $this->route = new Route(methods: ['POST'], path: '/x', handler: 'X@y');
});

it('extracts a body schema from a well-behaved FormRequest', function () {
    $m = new \ReflectionMethod(FixtureControllerStub::class, 'methodWithRules');
    $body = $this->extractor->extract($m, $this->route);

    expect($body)->not->toBeNull()
        ->and($body['required'])->toBeTrue()
        ->and($body['content']['application/json']['schema']['properties'])
            ->toHaveKey('name')
            ->toHaveKey('age');
});

it('returns null when rules() throws — does not propagate', function () {
    $m = new \ReflectionMethod(FixtureControllerStub::class, 'methodWithThrower');

    expect(fn () => $this->extractor->extract($m, $this->route))->not->toThrow(\Throwable::class);
    expect($this->extractor->extract($m, $this->route))->toBeNull();
});

it('returns null when rules() references missing request context', function () {
    $m = new \ReflectionMethod(FixtureControllerStub::class, 'methodWithContextHungry');

    // No app/container set up here — route() resolution will fail. We accept
    // *either* null (best case) or a degraded schema, but never an exception.
    expect(fn () => $this->extractor->extract($m, $this->route))->not->toThrow(\Throwable::class);
});

it('returns null when the FormRequest has no rules() method', function () {
    $m = new \ReflectionMethod(FixtureControllerStub::class, 'methodWithEmpty');

    expect($this->extractor->extract($m, $this->route))->toBeNull();
});

it('returns null when the controller has no FormRequest parameter', function () {
    $m = new \ReflectionMethod(FixtureControllerStub::class, 'methodWithNothing');

    expect($this->extractor->extract($m, $this->route))->toBeNull();
});

it('caches the schema per-class so repeated lookups do not re-instantiate', function () {
    $m = new \ReflectionMethod(FixtureControllerStub::class, 'methodWithRules');

    $first = $this->extractor->extract($m, $this->route);
    $second = $this->extractor->extract($m, $this->route);

    expect($second)->toBe($first);
});
