<?php

declare(strict_types=1);

namespace ApexDocs\Exception;

use Throwable;

/**
 * Marker interface for every exception thrown by ApexDocs.
 *
 * Consumers can catch this to handle any ApexDocs failure without catching
 * unrelated runtime errors. All concrete exceptions extend a SPL parent so
 * generic catch blocks ({@see \RuntimeException}) keep working too.
 */
interface ApexDocsException extends Throwable {}
