<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;

/**
 * A tool that is not one of the API's operations, served next to them by an {@see McpServer}.
 *
 * It does its work through the operations it names in {@see operations()}, called with the caller's own credentials,
 * so it can do nothing the caller could not do with the operation tools themselves. It is listed only to callers who
 * may see all of those operations.
 *
 * The typical use is a tool that opens an MCP App: its definition links a `ui://` resource in `_meta.ui`
 * (`Mcp\Schema\Extension\Apps\UiToolMeta`), registered as a {@see UiResource} on the same server. A host without MCP
 * Apps shows the result only, so the result should make sense as text as well.
 */
interface CustomTool
{
    /**
     * The tool as clients see it. Its name must not be one of the operation tools'.
     */
    public function definition(): Tool;

    /**
     * The tool names of the operations {@see call()} uses. The tool is listed only if the caller may see all of them,
     * and the {@see OperationCaller} refuses any other.
     *
     * @return list<string>
     */
    public function operations(): array;

    /**
     * @param array<string, mixed> $arguments validated against the definition's input schema
     */
    public function call(array $arguments, OperationCaller $operations): CallToolResult;
}
