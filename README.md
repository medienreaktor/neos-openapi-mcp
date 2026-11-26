# medienreaktor/neos-openapi-mcp

Exposes the operations of a [neos/openapi](https://github.com/neos/openapi) `CompiledApi` as
[Model Context Protocol](https://modelcontextprotocol.io) tools, using the official MCP PHP SDK (`mcp/sdk`).

Framework-agnostic: PSR-7/15/17 in, PSR-7 out. A framework integration (e.g. a Flow package) wires it to its
router, its request handler and its authentication.

- Every operation becomes a tool named by its `operationId`, with an input schema built from its parameters and
  JSON body and an output schema built from its successful JSON response.
- A tool call is turned into a real PSR-7 request and handed to the API's `RequestHandler`, so authentication,
  validation, serialization and problem responses work exactly as over HTTP.
- `tools/list` only shows the tools the caller's credentials satisfy.
- Speaks the **stateless MCP revision 2026-07-28** and, on the same endpoint, the older revisions (2024-11-05 to
  2025-11-25) **without sessions**: one POST in and one JSON response out, nothing kept between requests.

## Usage

```php
use Neos\OpenApi\Mcp\ExposedApi;
use Neos\OpenApi\Mcp\McpServer;
use Neos\OpenApi\Mcp\ServerInfo;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;

$server = new McpServer(
    serverInfo: new ServerInfo('neos', '9.2.0', title: 'Neos', instructions: 'Content and assets of the Neos site'),
    apis: [
        // the catalog is plain data: build it once and cache it
        ExposedApi::fromCompiledApi($compiledApi, $requestHandler, excludeTags: ['Internal'], pathPrefix: '/api'),
    ],
    serverRequestFactory: $psr17,
    responseFactory: $psr17,
    streamFactory: $psr17,
    operationMiddlewares: [$mapAccessDeniedToProblem],      // wrapped around every operation call
    authContextProvider: $authContextProvider,              // decides which tools a caller sees
    transportMiddlewares: [                                 // wrapped around the MCP endpoint
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(['neos.example.com']),
    ],
);

$response = $server->handle($serverRequest); // McpServer is a PSR-15 RequestHandlerInterface
```

### The pieces

| Class                      | Role                                                                                                                                                                                                                                                                                   |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ToolCatalog`              | `fromCompiledApi(CompiledApi, ?array $includeTags, array $excludeTags)` — the API's operations as `OperationTool`s, in dispatch-table order. `$skipped` lists the operations that cannot be tools and why. `merge()`, `with()`, `filter()`, `get()`, `names()`.                        |
| `OperationTool`            | One tool: `name`, `title`, `description`, `inputSchema`, `outputSchema`, `annotations`, `operationId`, `security` (effective), and the invoker's data — `method`, `path`, `parameters` (`ToolParameter`), `body` (`ToolRequestBody`). `toMcpTool()` gives the SDK's `Mcp\Schema\Tool`. |
| `OperationInvoker`         | `invoke(OperationTool, array $arguments, ServerRequestInterface $mcpRequest): CallToolResult`. Also `buildRequest()` (→ `OperationRequest`) and `dispatch()` separately.                                                                                                               |
| `ResponseMapper`           | `toResult(ResponseInterface): CallToolResult` — the response-to-result mapping the invoker uses.                                                                                                                                                                                       |
| `ToolVisibility`           | `isVisible(OperationTool, ServerRequestInterface)` and `visibleTools(ToolCatalog, ServerRequestInterface)`, asking an `AuthContextProvider`.                                                                                                                                           |
| `McpServer`                | The entry point: per request, registers the caller's visible tools with the SDK and answers through its Streamable HTTP transport, in either protocol era.                                                                                                                                    |
| `ExposedApi`, `ServerInfo` | Configuration value objects for `McpServer`.                                                                                                                                                                                                                                           |
| `CustomTool`               | Interface for a tool next to the operations: `definition()` (the SDK's `Tool`, with `_meta.ui` to open a view), `operations()` it uses, `call(array $arguments, OperationCaller)`.                                                                                                      |
| `OperationCaller`          | `call(string $operation, array $arguments): CallToolResult` — calls one of the custom tool's declared operations as the caller.                                                                                                                                                       |
| `UiResource`               | The HTML view of an MCP App: a `ui://` URI, a name, the HTML (a closure, read lazily) and the SDK's `UiResourceContentMeta` (CSP, permissions, domain, border).                                                                                                                         |

Everything under `Schema\` and `Internal\` except `InputSchema`/`OutputSchema` is internal.

## Tools

**Names** are operationIds and must match `^[A-Za-z0-9_.-]{1,128}$`; anything else throws an
`InvalidToolException` when the catalog is built. Two APIs on one server with the same operationId throw a
`ToolNameCollisionException`.

**Input schema**: one JSON Schema 2020-12 object with `additionalProperties: false`. Path, query, header and
cookie parameters are top-level properties under their own names (a header parameter is the argument
`Accept-Language`), path parameters are always required. The JSON request body is the property `body` — a
parameter named `body` collides with it and throws. `#/components/schemas/X` references become `#/$defs/X`, and
only the components a schema reaches are bundled into its `$defs`.

**Output schema**: the schema of the first 2xx response with a JSON body, bundled the same way; none if there is
no such response.

**Annotations** follow the HTTP method: GET/HEAD are read-only; GET/HEAD/PUT/DELETE are idempotent; every other
method is destructive, as none promises "only additive updates" (a POST may publish or flush, a PUT overwrites), so
clients that confirm destructive tools ask for all of them; nothing is open-world.

**Skipped** (listed in `ToolCatalog::$skipped`): operations whose request body is not JSON (e.g. multipart
uploads) and operations taking the raw `ServerRequestInterface`, whose input the document does not describe.

## Calls

The invoker builds the request the operation would get over HTTP:

- the path from the template with percent-encoded variables (the decoded values go to `handleOperation()`);
- query parameters in their declared style — `form` (lists exploded as `name=a&name=b`) or `deepObject`
  (`name[key]=value`, `name[key][]=value`) — and the same values as the request's query params, which is what
  the request handler reads;
- header and cookie parameters as headers and cookies; the body as JSON with its `Content-Type`;
- scheme, host, port and server params of the MCP request, plus `Authorization`, `User-Agent`, the
  `X-Forwarded-*`/`Forwarded` headers and trace context (configurable). Cookies are _not_ forwarded.

`null` or absent arguments are left out, so the operation's defaults apply.

Responses become results:

| Response                   | Result                                                                                              |
| -------------------------- | --------------------------------------------------------------------------------------------------- |
| 2xx with JSON              | `structuredContent` = the decoded body, plus the JSON as text                                       |
| 2xx without body (204)     | a sentence saying it succeeded                                                                      |
| 2xx other                  | the body as text                                                                                    |
| `application/problem+json` | `isError: true`, text with title, detail and one line per issue, the problem as `structuredContent` |
| other errors               | `isError: true`, status and body as text                                                            |

Exceptions thrown by an operation are not caught by the invoker. Map the ones you expect to responses in an
operation middleware; whatever escapes is answered by the SDK as a JSON-RPC internal error (logged, message not
disclosed).

## Custom tools and MCP Apps

`McpServer` takes `customTools:` and `uiResources:` next to the APIs. The library ships neither: they are the
application's.

A **custom tool** (`CustomTool`) is a tool that is not an operation. It works through the operations it names in
`operations()`, which an `OperationCaller` calls for it with the caller's credentials, so it can do nothing the caller
could not do with the operation tools. It is listed only to callers who may see all of those operations, and the
caller refuses any operation it did not declare.

A **UI resource** (`UiResource`) is the view of an [MCP App](https://modelcontextprotocol.io/extensions/apps/overview):
an HTML document served as a `ui://` resource with the MIME type `text/html;profile=mcp-app`. A tool opens it by
linking its URI in `_meta.ui` (`new Tool(…, meta: ['ui' => new UiToolMeta('ui://…')])`). With at least one UI resource,
the server advertises `resources` and the extension `io.modelcontextprotocol/ui`, lists the views in `resources/list`
and answers `resources/read` with the HTML and its `_meta.ui` (CSP, permissions, …). Without any, it advertises tools
only.

The server serves the views to any client and doesn't check whether the client supports MCP Apps. A host without it
ignores `_meta.ui` and shows only the tool's result, so a custom tool's result should make sense as text as well.

When the server is built, it throws if a custom tool reuses a tool name (`ToolNameCollisionException`), names an
operation none of the APIs has, links a UI resource that is not given, or if two UI resources share a URI.

## Protocol and transport

`McpServer` builds a server for both protocol eras (`Builder::build()`, modern era limited to 2026-07-28) and
serves it through `StreamableHttpTransport`, which routes each request by the revision it names. No session store
is configured, so the SDK serves the handshake era sessionless as well. Consequences:

- **2026-07-28** requests must carry `params._meta["io.modelcontextprotocol/protocolVersion"]` and
  `params._meta["io.modelcontextprotocol/clientCapabilities"]`, plus the `MCP-Protocol-Version`, `Mcp-Method`
  and (for `tools/call`) `Mcp-Name` headers, which the SDK checks against the body.
- **Handshake-era** clients (2024-11-05 to 2025-11-25) send `initialize` as usual. It is answered without an
  `Mcp-Session-Id`, and every later request is answered on its own by the revision its `MCP-Protocol-Version`
  header names (2025-03-26 without one, as the spec says). Requests to the client (sampling, elicitation, roots)
  are not possible without a session; these tools make none.
- `handshakeEra: false` serves 2026-07-28 only: `initialize` and the other methods the revision removed are
  refused, and an older protocol version gets an error naming the supported one.
- Only POST is accepted; `GET` and `DELETE` get `405`. Responses are JSON. The SDK would stream SSE only when a
  tool sends notifications _and_ the client accepts `text/event-stream`; these tools send none.
- Capabilities advertised: `tools` only.
- `tools/list` results depend on the caller, and the SDK's default cache hint (`cacheScope: private`, `ttlMs: 0`)
  says so.

**Origin validation** is the transport middlewares' job. The default (`transportMiddlewares: null`) is the SDK's:
`CorsMiddleware` (no cross-origin access) and `DnsRebindingProtectionMiddleware`, which accepts only a localhost
`Host` and same-origin requests. A deployed server has to pass its own host list (and, for browser clients on another
origin, `allowedOrigins`), or `[]` if something in front validates `Host` and `Origin`.

## License

MIT, see [LICENSE](LICENSE).
