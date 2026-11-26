<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Closure;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\ResourceDefinition;

/**
 * The HTML view of an MCP App, served as a `ui://` resource with the MIME type `text/html;profile=mcp-app`.
 *
 * Tools open it by naming its URI in `_meta.ui.resourceUri` ({@see CustomTool}). The host renders it in a sandboxed
 * iframe and talks to it over `postMessage`, so the HTML has to be self-contained (scripts and styles inlined) unless
 * the content metadata's CSP allows the domains it loads from.
 *
 * @see https://modelcontextprotocol.io/extensions/apps/overview
 */
final readonly class UiResource
{
    /**
     * @param Closure(): string $html the document, read only when a client reads the resource
     * @param UiResourceContentMeta|null $contentMeta the CSP, permissions, domain and border preference the host
     *                                                applies to the view
     * @throws \InvalidArgumentException if the URI is not a `ui://` one
     */
    public function __construct(
        public string $uri,
        public string $name,
        private Closure $html,
        public string|null $title = null,
        public string|null $description = null,
        public UiResourceContentMeta|null $contentMeta = null,
    ) {
        if (!str_starts_with($uri, McpApps::URI_SCHEME . '://')) {
            throw new \InvalidArgumentException(sprintf('The UI resource URI "%s" has to start with ui://', $uri), 1795000102);
        }
    }

    public function definition(): ResourceDefinition
    {
        return new ResourceDefinition(
            uri: $this->uri,
            name: $this->name,
            title: $this->title,
            description: $this->description,
            mimeType: McpApps::MIME_TYPE,
            meta: ['ui' => McpApps::resourceMarker()],
        );
    }

    public function contents(): TextResourceContents
    {
        return new TextResourceContents(
            $this->uri,
            McpApps::MIME_TYPE,
            ($this->html)(),
            $this->contentMeta === null ? null : ['ui' => $this->contentMeta],
        );
    }
}
