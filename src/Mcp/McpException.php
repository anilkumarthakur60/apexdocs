<?php

declare(strict_types=1);

namespace ApexDocs\Mcp;

/**
 * A JSON-RPC level error; the exception code is the JSON-RPC error code.
 */
final class McpException extends \RuntimeException {}
