<?php

declare(strict_types=1);

namespace ApexDocs\Tests\Fixtures\Controllers;

use ApexDocs\Attribute\ApiResponse;
use ApexDocs\Attribute\BodyParam;
use ApexDocs\Attribute\Example;
use ApexDocs\Attribute\Produces;
use ApexDocs\Attribute\QueryParam;
use ApexDocs\Attribute\ResponseHeader;
use ApexDocs\Attribute\Tag;

/**
 * Fixture exercising the attribute surface that used to be mis-handled:
 * explicit 2xx statuses, #[Produces], #[ResponseHeader], #[Example], repeated
 * #[Tag], and a nullable #[BodyParam].
 */
#[QueryParam(name: 'trace', type: 'string', description: 'class level')]
class ReportController
{
    /** Create a report */
    #[ApiResponse(status: 201, description: 'Created')]
    #[BodyParam('name', required: true)]
    #[BodyParam('note', nullable: true)]
    #[Example(name: 'minimal', value: ['name' => 'Q1'], for: 'request')]
    #[Example(name: 'created', value: ['id' => 1])]
    public function store(): void {}

    #[Produces('application/pdf', description: 'Rendered PDF')]
    #[ResponseHeader('X-Request-Id', description: 'Trace id')]
    #[Tag('Reports')]
    #[Tag('Legacy')]
    public function download(): void {}

    /**
     * Show one report.
     *
     * @param  int  $id  The report identifier
     */
    #[QueryParam(name: 'trace', type: 'string', description: 'method level')]
    public function show(int $id, string $slug): void {}

    public function anyVerb(): void {}
}
