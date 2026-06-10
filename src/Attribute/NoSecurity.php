<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Mark this endpoint as publicly accessible  overrides global security. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class NoSecurity {}
