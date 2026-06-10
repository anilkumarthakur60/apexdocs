# Testing the generated spec (Pest)

## Laravel (Orchestra Testbench or the app's own test suite)

```php
use ApexDocs\ApexDocs;
use ApexDocs\Validation\SpecValidator;

it('generates a valid spec', function () {
    $spec = app(ApexDocs::class)->generate()->toArray();

    $result = (new SpecValidator)->validate($spec);

    expect($result['errors'])->toBe([])
        ->and($spec['openapi'])->toBe('3.1.0');
});

it('documents the user endpoints', function () {
    $spec = app(ApexDocs::class)->generate()->toArray();

    $op = $spec['paths']['/api/users/{id}']['get'];

    expect($op['operationId'])->toBe('users_show')
        ->and($op['tags'])->toBe(['Users'])
        ->and($op['responses'])->toHaveKeys(['200', '401', '404'])
        ->and($op['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'])
            ->toBe('#/components/schemas/UserDto')
        ->and($op['security'])->toBe([['sanctum' => []]]);
});

it('derives the store body from the FormRequest', function () {
    $schema = app(ApexDocs::class)->generate()->toArray()
        ['paths']['/api/users']['post']['requestBody']['content']['application/json']['schema'];

    expect($schema['required'])->toContain('name', 'email')
        ->and($schema['properties']['email']['format'])->toBe('email');
});

it('has no breaking changes against the baseline', function () {
    $base = json_decode(file_get_contents(base_path('docs/openapi.baseline.json')), true);
    $diff = (new \ApexDocs\Diff\SpecDiff)->compare($base, app(ApexDocs::class)->generate()->toArray());

    expect($diff['breaking'])->toBe([]);
});
```

HTTP-level: `$this->get('/documentation/api/spec.json')->assertOk()->assertJsonPath('info.title', 'My API');`
 set `config(['apexdocs.environments' => ['testing']])` (or `[]`) so the routes exist, and
`apexdocs.cache.enabled = false` to avoid cross-test caching.

## Framework-agnostic

```php
$routes = (new ArrayRouteCollection)->add('GET', '/api/users/{id}', UserController::class.'@show');
$spec = ApexDocs::make(new Config(title: 'T', version: '1'))->routes($routes)->generate()->toArray();
```
Fast (no container); use for extractor/attribute behaviour tests.
