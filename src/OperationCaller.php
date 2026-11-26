<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Schema\Result\CallToolResult;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Calls operations for a {@see CustomTool}, as the caller of the MCP request: the same credentials, the same checks
 * and the same result as calling the operation's tool directly.
 *
 * Only the operations the custom tool declares can be called.
 */
final readonly class OperationCaller
{
    /**
     * @param array<string, array{tool: OperationTool, invoker: OperationInvoker}> $operations by tool name
     */
    public function __construct(
        private array $operations,
        private ServerRequestInterface $mcpRequest,
    ) {}

    /**
     * @param array<string, mixed> $arguments the operation tool's arguments: parameters top-level, the JSON body
     *                                        under `body`
     * @throws \InvalidArgumentException if the operation is not one the custom tool declared
     */
    public function call(string $operation, array $arguments = []): CallToolResult
    {
        $entry = $this->operations[$operation] ?? throw new \InvalidArgumentException(sprintf(
            'The operation "%s" is not one this tool declared, it can call: %s',
            $operation,
            implode(', ', array_keys($this->operations)) ?: 'none',
        ), 1795000101);
        return $entry['invoker']->invoke($entry['tool'], $arguments, $this->mcpRequest);
    }
}
