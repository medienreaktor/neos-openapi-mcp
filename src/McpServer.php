<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server\Server;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Builder;
use Mcp\Server\Capability\Registry\Loader\ExplicitElementLoader;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Mcp\Exception\ToolNameCollisionException;
use Neos\OpenApi\Mcp\Internal\CustomToolHandler;
use Neos\OpenApi\Mcp\Internal\OperationToolHandler;
use Neos\OpenApi\Mcp\Internal\UiResourceHandler;
use Neos\OpenApi\Mcp\Internal\VisibleToolLoader;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * An MCP endpoint exposing the operations of one or more APIs as tools: hand it the HTTP request, get the HTTP
 * response back.
 *
 * It speaks the **stateless 2026-07-28 revision**, where every request carries its protocol version and client
 * capabilities in `params._meta` and in the `MCP-Protocol-Version` header and is answered on its own — one POST
 * in, one JSON response out. Clients of the older revisions (2024-11-05 to 2025-11-25) are served on the same
 * endpoint, **without a session**: `initialize` is answered without an `Mcp-Session-Id`, every later request is
 * answered on its own by the revision its `MCP-Protocol-Version` header names, and `GET` and `DELETE` get `405`.
 * Nothing is kept between requests in either era, which is what lets it run as an ordinary PHP request handler.
 * Pass `handshakeEra: false` to serve 2026-07-28 only.
 *
 * Per request, it decides which tools the caller may see ({@see ToolVisibility}, if an {@see AuthContextProvider}
 * is given), registers those with a fresh SDK server, and lets the SDK's HTTP transport answer.
 *
 * Next to the operations it can serve {@see CustomTool}s and the views of MCP Apps ({@see UiResource}). With at least
 * one UI resource, it advertises `resources` and the extension `io.modelcontextprotocol/ui`; without, tools only.
 *
 * Origin validation: the transport middlewares default to the SDK's own — CORS (no cross-origin access) and DNS
 * rebinding protection, which rejects any `Host` that is not localhost and any cross-origin `Origin`. That is right
 * for a local server and wrong for a deployed one: pass your own list, e.g.
 * `[new CorsMiddleware(), new DnsRebindingProtectionMiddleware(['neos.example.com'])]` (with `allowedOrigins:` for
 * browser clients on another origin), or `[]` if a layer in front validates `Host` and `Origin` already.
 */
final readonly class McpServer implements RequestHandlerInterface
{
    /**
     * @var list<array{tool: OperationTool, invoker: OperationInvoker}>
     */
    private array $tools;

    /**
     * @var array<string, array{tool: OperationTool, invoker: OperationInvoker}> by tool name
     */
    private array $operations;

    /**
     * @var list<CustomTool>
     */
    private array $customTools;

    /**
     * @var list<UiResource>
     */
    private array $uiResources;

    /**
     * @var array<string, array<string, mixed>> by tool name
     */
    private array $operationToolMeta;

    /**
     * @param list<ExposedApi> $apis
     * @param list<MiddlewareInterface> $operationMiddlewares wrapped around every operation call, the first one
     *                                                        outermost — see {@see OperationInvoker}
     * @param AuthContextProvider|null $authContextProvider decides which tools a caller sees; without one, every
     *                                                      tool is listed to everyone (and refused on call if the
     *                                                      caller is not authorized)
     * @param list<MiddlewareInterface>|null $transportMiddlewares wrapped around the MCP endpoint itself; `null`
     *                                                             for the SDK's defaults (localhost only!)
     * @param list<string> $forwardedHeaders headers of the MCP request that operation calls carry over
     * @param bool $handshakeEra whether clients of the 2024/2025 revisions are served too (`initialize`, without a
     *                           session); `false` serves 2026-07-28 only
     * @param list<CustomTool> $customTools tools next to the operations, see {@see CustomTool}
     * @param list<UiResource> $uiResources the views of MCP Apps the tools link to
     * @param array<string, array<string, mixed>> $operationToolMeta the `_meta` of operation tools, by tool name: e.g.
     *                                                             `['ui' => new UiToolMeta(visibility: [ToolVisibility::App])]`
     *                                                             for an operation only the views of MCP Apps call
     * @throws ToolNameCollisionException if two tools (of the APIs or custom ones) have the same name
     * @throws \InvalidArgumentException if a custom tool names an operation no API has, a tool links a UI resource that
     *                                   is not given, two UI resources share a URI, or there is `_meta` for an
     *                                   operation no API has
     */
    public function __construct(
        private ServerInfo $serverInfo,
        array $apis,
        ServerRequestFactoryInterface $serverRequestFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        array $operationMiddlewares = [],
        private AuthContextProvider|null $authContextProvider = null,
        private array|null $transportMiddlewares = null,
        private LoggerInterface $logger = new NullLogger(),
        array $forwardedHeaders = OperationInvoker::DEFAULT_FORWARDED_HEADERS,
        private bool $handshakeEra = true,
        array $customTools = [],
        array $uiResources = [],
        array $operationToolMeta = [],
    ) {
        $all = ToolCatalog::empty();
        $tools = [];
        foreach ($apis as $api) {
            // merged only to have the collision checked up front, rather than on a request that lists both
            $all = $all->merge($api->catalog);
            $invoker = new OperationInvoker(
                $api->requestHandler,
                $serverRequestFactory,
                $streamFactory,
                $operationMiddlewares,
                $api->pathPrefix,
                $forwardedHeaders,
            );
            foreach ($api->catalog as $tool) {
                $tools[] = ['tool' => $tool, 'invoker' => $invoker];
            }
        }
        $this->tools = $tools;
        $operations = [];
        foreach ($tools as $entry) {
            $operations[$entry['tool']->name] = $entry;
        }
        $this->operations = $operations;

        $uris = [];
        foreach ($uiResources as $resource) {
            if (isset($uris[$resource->uri])) {
                throw new \InvalidArgumentException(sprintf('There are two UI resources with the URI %s', $resource->uri), 1795000103);
            }
            $uris[$resource->uri] = true;
        }
        $names = array_fill_keys(array_keys($operations), true);
        foreach ($customTools as $customTool) {
            $definition = $customTool->definition();
            if (isset($names[$definition->name])) {
                throw new ToolNameCollisionException(sprintf('There are two tools named "%s"; a custom tool must not reuse the name of another tool', $definition->name), 1795000104);
            }
            $names[$definition->name] = true;
            foreach ($customTool->operations() as $operation) {
                if (!isset($operations[$operation])) {
                    throw new \InvalidArgumentException(sprintf('The custom tool "%s" uses the operation "%s", which none of the APIs has', $definition->name, $operation), 1795000105);
                }
            }
            $uri = self::linkedUiResource($definition);
            if ($uri !== null && !isset($uris[$uri])) {
                throw new \InvalidArgumentException(sprintf('The custom tool "%s" links the UI resource %s, which is not given', $definition->name, $uri), 1795000106);
            }
        }
        foreach ($operationToolMeta as $name => $meta) {
            if (!isset($operations[$name])) {
                throw new \InvalidArgumentException(sprintf('There is _meta for the operation tool "%s", which none of the APIs has', $name), 1795000107);
            }
            $uri = self::linkedUiResource($operations[$name]['tool']->toMcpTool($meta));
            if ($uri !== null && !isset($uris[$uri])) {
                throw new \InvalidArgumentException(sprintf('The operation tool "%s" links the UI resource %s, which is not given', $name, $uri), 1795000108);
            }
        }
        $this->customTools = $customTools;
        $this->uiResources = $uiResources;
        $this->operationToolMeta = $operationToolMeta;
    }

    /**
     * The `ui://` URI a tool definition links in `_meta.ui.resourceUri`, if any.
     */
    private static function linkedUiResource(Tool $definition): string|null
    {
        $ui = $definition->meta['ui'] ?? null;
        return match (true) {
            $ui instanceof UiToolMeta => $ui->resourceUri,
            is_array($ui) && is_string($ui['resourceUri'] ?? null) => $ui['resourceUri'],
            default => null,
        };
    }

    /**
     * Answers one MCP request.
     *
     * Anything an operation throws that its middlewares do not turn into a response is caught by the SDK and
     * answered as a JSON-RPC internal error (logged to the logger given, message withheld from the client).
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $builder = $this->builderFor($request);
        if (!$this->handshakeEra) {
            $transport = new StatelessHttpTransport(
                $builder->buildStateless([ProtocolVersion::V2026_07_28]),
                $this->responseFactory,
                $this->streamFactory,
                $this->logger,
                middleware: $this->transportMiddlewares,
            );
            return $transport->handle($request);
        }
        // one transport for both eras: it routes a request naming 2026-07-28 to the stateless dispatcher and
        // anything else (an `initialize` above all) to the handshake one, which runs without a session because
        // the builder is never given a session store
        $transport = new StreamableHttpTransport(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            $this->logger,
            middleware: $this->transportMiddlewares,
        );
        $response = $builder->build()->run($transport);
        assert($response instanceof ResponseInterface);
        return $response;
    }

    /**
     * The tools the caller of this request may see.
     */
    public function visibleTools(ServerRequestInterface $request): ToolCatalog
    {
        $catalog = ToolCatalog::fromTools(...array_map(static fn(array $entry): OperationTool => $entry['tool'], $this->tools));
        return $this->authContextProvider === null
            ? $catalog
            : (new ToolVisibility($this->authContextProvider))->visibleTools($catalog, $request);
    }

    /**
     * The SDK server for this one request, with the caller's tools.
     *
     * Built per request because the tool list depends on who is asking — and because there is nothing to keep:
     * neither era is served with a session.
     */
    private function builderFor(ServerRequestInterface $request): Builder
    {
        $info = $this->serverInfo;
        $offersApps = $this->uiResources !== [];
        $builder = Server::builder()
            ->setServerInfo($info->name, $info->version, $info->description, websiteUrl: $info->websiteUrl, title: $info->title)
            ->setInstructions($info->instructions)
            // only what is served: auto-detection would advertise resources, prompts, completions and logging too
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                toolsListChanged: false,
                resources: $offersApps,
                resourcesSubscribe: false,
                resourcesListChanged: false,
                prompts: false,
                promptsListChanged: false,
                logging: false,
                completions: false,
            ))
            ->setLogger($this->logger)
            ->addLoader(new VisibleToolLoader(fn(): array => $this->registrations($request)))
            ->setModernVersions([ProtocolVersion::V2026_07_28]);
        if ($offersApps) {
            $builder
                ->enableExtension(new McpApps())
                ->addLoader(new ExplicitElementLoader(resources: array_map(
                    static fn(UiResource $resource): array => ['definition' => $resource->definition(), 'handler' => new UiResourceHandler($resource)],
                    $this->uiResources,
                )));
        }
        return $builder;
    }

    /**
     * @return list<array{definition: Tool, handler: ToolHandlerInterface}>
     */
    private function registrations(ServerRequestInterface $request): array
    {
        $visible = $this->visibleTools($request);
        $registrations = [];
        foreach ($this->tools as $entry) {
            if (!$visible->has($entry['tool']->name)) {
                continue;
            }
            $registrations[] = [
                'definition' => $entry['tool']->toMcpTool($this->operationToolMeta[$entry['tool']->name] ?? null),
                'handler' => new OperationToolHandler($entry['invoker'], $entry['tool'], $request),
            ];
        }
        foreach ($this->customTools as $customTool) {
            $operations = [];
            foreach ($customTool->operations() as $operation) {
                if (!$visible->has($operation)) {
                    // the caller could not use the tool anyway
                    continue 2;
                }
                $operations[$operation] = $this->operations[$operation];
            }
            $registrations[] = [
                'definition' => $customTool->definition(),
                'handler' => new CustomToolHandler($customTool, new OperationCaller($operations, $request)),
            ];
        }
        return $registrations;
    }
}
