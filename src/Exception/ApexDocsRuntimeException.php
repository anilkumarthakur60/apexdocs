<?php

declare(strict_types=1);

namespace ApexDocs\Exception;

use RuntimeException;

/**
 * Base class for any runtime failure inside ApexDocs.
 *
 * Extends {@see \RuntimeException} so generic catch sites stay working;
 * implements {@see ApexDocsException} so consumers can target our errors
 * specifically.
 */
abstract class ApexDocsRuntimeException extends RuntimeException implements ApexDocsException {}
