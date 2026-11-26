<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Internal;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Neos\OpenApi\Mcp\UiResource;

/**
 * @internal
 */
final readonly class UiResourceHandler implements ResourceHandlerInterface
{
    public function __construct(
        private UiResource $resource,
    ) {}

    public function read(string $uri, ClientGateway $gateway): TextResourceContents
    {
        return $this->resource->contents();
    }
}
