<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\EndToEnd;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Neos\OpenApi\Mcp\Exception\ToolNameCollisionException;
use Neos\OpenApi\Mcp\ExposedApi;
use Neos\OpenApi\Mcp\McpServer;
use Neos\OpenApi\Mcp\OperationCaller;
use Neos\OpenApi\Mcp\ServerInfo;
use Neos\OpenApi\Mcp\Tests\Fixtures\AccessDeniedMiddleware;
use Neos\OpenApi\Mcp\Tests\Fixtures\ArticleApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\ArticleViewTool;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestApis;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestAuthContextProvider;
use Neos\OpenApi\Mcp\UiResource;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Custom tools and the views of MCP Apps, served next to the operation tools.
 */
final class McpAppsTest extends TestCase
{
    private const VERSION = '2026-07-28';
    private const HTML = '<!doctype html><title>Article</title>';

    private ArticleApi $api;
    private int $id = 0;
    private int $htmlReads = 0;

    protected function setUp(): void
    {
        $this->api = new ArticleApi();
    }

    /**
     * @param list<\Neos\OpenApi\Mcp\CustomTool>|null $customTools
     * @param list<UiResource>|null $uiResources
     */
    private function server(array|null $customTools = null, array|null $uiResources = null): McpServer
    {
        $compiled = TestApis::articles();
        $factory = new HttpFactory();
        return new McpServer(
            new ServerInfo('neos', '9.2.0'),
            [ExposedApi::fromCompiledApi($compiled, TestApis::handler($compiled, $this->api), pathPrefix: '/api')],
            $factory,
            $factory,
            $factory,
            [new AccessDeniedMiddleware()],
            new TestAuthContextProvider(),
            customTools: $customTools ?? [new ArticleViewTool(), new ArticleViewTool('createFromView', ['createArticle'], null)],
            uiResources: $uiResources ?? [$this->view()],
        );
    }

    private function view(string $uri = 'ui://articles/view'): UiResource
    {
        return new UiResource(
            $uri,
            'article-view',
            function (): string {
                $this->htmlReads++;
                return self::HTML;
            },
            title: 'Article',
            contentMeta: new UiResourceContentMeta(new UiResourceCsp(resourceDomains: ['https://cdn.example.com']), prefersBorder: true),
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function post(string $method, array $params = [], array $headers = [], McpServer|null $server = null): ResponseInterface
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::VERSION,
            'io.modelcontextprotocol/clientCapabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]]],
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test-host', 'version' => '1.0'],
        ];
        $headers += [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::VERSION,
            'Mcp-Method' => $method,
        ];
        if (isset($params['name']) && is_string($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        if (isset($params['uri']) && is_string($params['uri'])) {
            $headers['Mcp-Name'] = $params['uri'];
        }
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params]);
        return ($server ?? $this->server())->handle(new ServerRequest('POST', 'http://localhost/mcp', $headers, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private static function rpcResult(ResponseInterface $response): array
    {
        $message = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($message);
        self::assertArrayHasKey('result', $message, (string) json_encode($message));
        return $message['result'];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, array<string, mixed>> by name
     */
    private function listedTools(array $headers = []): array
    {
        $tools = self::rpcResult($this->post('tools/list', [], $headers))['tools'];
        return array_column($tools, null, 'name');
    }

    public function testServerDiscoverAdvertisesResourcesAndTheAppsExtension(): void
    {
        $capabilities = self::rpcResult($this->post('server/discover'))['capabilities'];

        self::assertEqualsCanonicalizing(['tools', 'resources', 'extensions'], array_keys($capabilities));
        self::assertSame(['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]], $capabilities['extensions']);
    }

    public function testWithoutUiResourcesOnlyToolsAreAdvertised(): void
    {
        $server = $this->server([new ArticleViewTool(resourceUri: null)], []);

        $capabilities = self::rpcResult($this->post('server/discover', [], [], $server))['capabilities'];

        self::assertSame(['tools'], array_keys($capabilities));
        self::assertArrayHasKey('showArticle', array_column(self::rpcResult($this->post('tools/list', [], [], $server))['tools'], null, 'name'));
    }

    public function testTheHandshakeEraIsToldAboutTheExtensionToo(): void
    {
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => new \stdClass()]],
            'clientInfo' => ['name' => 'old', 'version' => '1'],
        ]]);
        $response = $this->server()->handle(new ServerRequest('POST', 'http://localhost/mcp', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
        ], $body));

        $capabilities = self::rpcResult($response)['capabilities'];
        self::assertArrayHasKey('resources', $capabilities);
        self::assertArrayHasKey('io.modelcontextprotocol/ui', $capabilities['extensions']);
    }

    public function testResourcesListShowsTheViewsWithoutReadingThem(): void
    {
        $resources = self::rpcResult($this->post('resources/list'))['resources'];

        self::assertCount(1, $resources);
        self::assertSame('ui://articles/view', $resources[0]['uri']);
        self::assertSame('article-view', $resources[0]['name']);
        self::assertSame('text/html;profile=mcp-app', $resources[0]['mimeType']);
        self::assertSame([], $resources[0]['_meta']['ui']);
        self::assertSame(0, $this->htmlReads);
    }

    public function testResourcesReadAnswersWithTheHtmlAndItsContentMetadata(): void
    {
        $contents = self::rpcResult($this->post('resources/read', ['uri' => 'ui://articles/view']))['contents'];

        self::assertEquals([[
            'uri' => 'ui://articles/view',
            'mimeType' => 'text/html;profile=mcp-app',
            '_meta' => ['ui' => ['csp' => ['resourceDomains' => ['https://cdn.example.com']], 'prefersBorder' => true]],
            'text' => self::HTML,
        ]], $contents);
    }

    public function testACustomToolIsListedWithItsLinkToTheView(): void
    {
        $tool = $this->listedTools()['showArticle'];

        self::assertSame('Show an article', $tool['title']);
        self::assertSame(['resourceUri' => 'ui://articles/view'], $tool['_meta']['ui']);
    }

    public function testACustomToolIsListedOnlyIfTheCallerMaySeeAllItsOperations(): void
    {
        self::assertArrayNotHasKey('createFromView', $this->listedTools());
        self::assertArrayHasKey('createFromView', $this->listedTools(['Authorization' => 'Bearer alice']));
    }

    public function testACustomToolCallsItsOperationsAsTheCaller(): void
    {
        $result = self::rpcResult($this->post('tools/call', ['name' => 'showArticle', 'arguments' => ['articleId' => 'article-7']]));

        self::assertFalse($result['isError']);
        self::assertSame([['type' => 'text', 'text' => 'Showing the article']], $result['content']);
        self::assertSame('article-7', $result['structuredContent']['article']['id']);
        self::assertSame('article-7', $this->api->lastArguments['articleId']->value);
    }

    public function testAnOperationsProblemReachesTheCustomTool(): void
    {
        $result = self::rpcResult($this->post('tools/call', ['name' => 'showArticle', 'arguments' => ['articleId' => 'secret']]));

        self::assertTrue($result['isError']);
    }

    public function testAHiddenCustomToolCannotBeCalled(): void
    {
        $message = json_decode((string) $this->post('tools/call', ['name' => 'createFromView', 'arguments' => ['articleId' => 'x']])->getBody(), true);

        self::assertIsArray($message);
        self::assertArrayHasKey('error', $message);
    }

    public function testTheOperationCallerRefusesAnOperationTheToolDidNotDeclare(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1795000101);

        (new OperationCaller([], new ServerRequest('POST', 'http://localhost/mcp')))->call('deleteArticle', ['articleId' => 'x']);
    }

    public function testACustomToolMustNotReuseAToolName(): void
    {
        $this->expectException(ToolNameCollisionException::class);

        $this->server([new ArticleViewTool('getArticle')]);
    }

    public function testACustomToolMustNameExistingOperations(): void
    {
        $this->expectExceptionCode(1795000105);

        $this->server([new ArticleViewTool(operations: ['noSuchOperation'])]);
    }

    public function testACustomToolMustLinkAGivenView(): void
    {
        $this->expectExceptionCode(1795000106);

        $this->server([new ArticleViewTool(resourceUri: 'ui://articles/other')]);
    }

    public function testTwoViewsMustNotShareAUri(): void
    {
        $this->expectExceptionCode(1795000103);

        $this->server(uiResources: [$this->view(), $this->view()]);
    }

    public function testAViewNeedsAUiUri(): void
    {
        $this->expectExceptionCode(1795000102);

        $this->view('https://example.com/view.html');
    }
}
