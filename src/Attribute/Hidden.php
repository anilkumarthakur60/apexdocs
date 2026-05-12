<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Exclude this controller or method from the generated spec. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Hidden {}
