<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server\Server;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Builder;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Mcp\Exception\ToolNameCollisionException;
use Neos\OpenApi\Mcp\Internal\OperationToolHandler;
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
     * @throws ToolNameCollisionException if two of the APIs have a tool of the same name
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
        return Server::builder()
            ->setServerInfo($info->name, $info->version, $info->description, websiteUrl: $info->websiteUrl, title: $info->title)
            ->setInstructions($info->instructions)
            // only tools: auto-detection would advertise resources, prompts, completions and logging too
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                toolsListChanged: false,
                resources: false,
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
                'definition' => $entry['tool']->toMcpTool(),
                'handler' => new OperationToolHandler($entry['invoker'], $entry['tool'], $request),
            ];
        }
        return $registrations;
    }
}
