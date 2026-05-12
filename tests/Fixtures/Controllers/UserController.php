<?php

declare(strict_types=1);

namespace ApexDocs\Tests\Fixtures\Controllers;

use ApexDocs\Attribute\ApiResponse;
use ApexDocs\Attribute\Endpoint;
use ApexDocs\Attribute\QueryParam;
use ApexDocs\Attribute\Tag;
use ApexDocs\Tests\Fixtures\Dtos\UserDto;

#[Tag('Users')]
final class UserController
{
    /**
     * List all users.
     *
     * Returns a paginated **list** of every user in the system.
     */
    #[Endpoint(summary: 'List users')]
    #[QueryParam('page', type: 'integer', description: 'Page number', example: 1)]
    #[ApiResponse(200, resource: UserDto::class, collection: true)]
    public function index(): array
    {
        return [];
    }

    #[Endpoint(summary: 'Show a user')]
    #[ApiResponse(200, resource: UserDto::class)]
    #[ApiResponse(404, description: 'User not found')]
    public function show(int $id): UserDto
    {
        return new UserDto($id, 'Ada', 'ada@example.com');
    }
}
