<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Neos\OpenApi\Mcp\CustomTool;
use Neos\OpenApi\Mcp\OperationCaller;

/**
 * A custom tool that opens a view of an article: it reads the article through `getArticle` (or whichever operations
 * it is given) and answers with what it got.
 */
final readonly class ArticleViewTool implements CustomTool
{
    /**
     * @param list<string> $operations
     */
    public function __construct(
        private string $name = 'showArticle',
        private array $operations = ['getArticle'],
        private string|null $resourceUri = 'ui://articles/view',
    ) {}

    public function definition(): Tool
    {
        return new Tool(
            name: $this->name,
            title: 'Show an article',
            inputSchema: [
                'type' => 'object',
                'properties' => ['articleId' => ['type' => 'string'], 'operation' => ['type' => 'string']],
                'required' => ['articleId'],
            ],
            description: 'Shows an article in a view',
            annotations: null,
            meta: $this->resourceUri === null ? null : ['ui' => new UiToolMeta($this->resourceUri)],
        );
    }

    public function operations(): array
    {
        return $this->operations;
    }

    public function call(array $arguments, OperationCaller $operations): CallToolResult
    {
        $result = $operations->call($arguments['operation'] ?? $this->operations[0], ['articleId' => $arguments['articleId']]);
        return new CallToolResult(
            [new TextContent('Showing the article')],
            $result->isError,
            ['article' => $result->structuredContent],
        );
    }
}
