<?php

namespace romanavr\contentops\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use romanavr\contentops\ContentOps;
use romanavr\contentops\mcp\McpContext;
use romanavr\contentops\mcp\ServerFactory;
use yii\web\Response;

/**
 * The HTTP MCP endpoint (Streamable HTTP), authenticated with per-user bearer tokens.
 *
 * `POST /actions/content-ops/mcp` with `Authorization: Bearer co_…`. The session acts as the token's user,
 * with that user's permissions, in the token's mode.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class McpController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Requests per token per minute.
     */
    public const RATE_LIMIT = 120;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['index'];

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * Handles an MCP request.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $plugin = ContentOps::getInstance();
        $bearer = $this->_bearerToken();
        $token = $bearer !== null ? $plugin->getTokens()->authenticate($bearer) : null;
        $user = $token ? $plugin->getTokens()->user($token) : null;

        if ($token === null || $user === null || !$user->can('contentOps:mcp')) {
            return $this->_jsonError(401, 'Invalid or missing access token.', ['WWW-Authenticate' => 'Bearer']);
        }

        if (!$this->_withinRateLimit($token->id)) {
            return $this->_jsonError(429, 'Too many requests. Try again in a minute.', ['Retry-After' => '60']);
        }

        // Tools check permissions against this user.
        Craft::$app->getUser()->setIdentity($user);

        $factory = new HttpFactory();
        $server = ServerFactory::create(
            new McpContext($token->mode, $user, $token->name),
            new FileSessionStore(Craft::getAlias('@storage/runtime/content-ops/mcp-sessions')),
        );
        $psrResponse = $server->run(new StreamableHttpTransport(
            $this->_psrRequest(),
            $factory,
            $factory,
            middleware: [
                new CorsMiddleware(),
                // DNS-rebinding protection, allowing this install's own site/CP hosts.
                new DnsRebindingProtectionMiddleware($this->_allowedHosts(), $factory, $factory),
            ],
        ));

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->setStatusCode($psrResponse->getStatusCode());

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->getHeaders()->set($name, implode(', ', $values));
        }

        $response->content = (string)$psrResponse->getBody();

        return $response;
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds a PSR-7 request from Craft's request (not PHP globals, so it also works for simulated requests).
     *
     * @return ServerRequest
     */
    private function _psrRequest(): ServerRequest
    {
        $headers = [];

        foreach ($this->request->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }

        return new ServerRequest(
            $this->request->getMethod(),
            $this->request->getAbsoluteUrl(),
            $headers,
            $this->request->getRawBody(),
            '1.1',
            $_SERVER,
        );
    }

    /**
     * @return string[] Hostnames of all sites and the control panel
     */
    private function _allowedHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', '[::1]'];
        $urls = array_map(fn($site) => $site->getBaseUrl(), Craft::$app->getSites()->getAllSites());
        $urls[] = UrlHelper::cpUrl();
        $urls[] = UrlHelper::actionUrl('content-ops/mcp');

        foreach ($urls as $url) {
            $host = $url ? parse_url($url, PHP_URL_HOST) : null;

            if ($host) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return string|null
     */
    private function _bearerToken(): ?string
    {
        $header = (string)$this->request->getHeaders()->get('Authorization', '');

        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }

    /**
     * @param int $tokenId
     * @return bool
     */
    private function _withinRateLimit(int $tokenId): bool
    {
        $cache = Craft::$app->getCache();
        $key = sprintf('content-ops:mcp-rate:%d:%d', $tokenId, intdiv(time(), 60));
        $count = (int)$cache->get($key) + 1;
        $cache->set($key, $count, 120);

        return $count <= self::RATE_LIMIT;
    }

    /**
     * @param int $status
     * @param string $message
     * @param array<string, string> $headers
     * @return Response
     */
    private function _jsonError(int $status, string $message, array $headers = []): Response
    {
        $response = $this->asJson(['error' => $message]);
        $response->setStatusCode($status);

        foreach ($headers as $name => $value) {
            $response->getHeaders()->set($name, $value);
        }

        return $response;
    }
}
