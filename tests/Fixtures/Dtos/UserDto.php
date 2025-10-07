<?php

declare(strict_types=1);

namespace ApexDocs\Tests\Fixtures\Dtos;

use ApexDocs\Attribute\Schema;

#[Schema(title: 'User', description: 'A registered user')]
final class UserDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $email,
        public readonly bool $isAdmin = false,
    ) {}
}
