<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Internal;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Neos\OpenApi\Mcp\CustomTool;
use Neos\OpenApi\Mcp\OperationCaller;

/**
 * Binds a custom tool to the operations it may call in this MCP request.
 *
 * @internal
 */
final readonly class CustomToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private CustomTool $tool,
        private OperationCaller $operations,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        return $this->tool->call($arguments, $this->operations);
    }
}
