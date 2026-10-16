<?php
declare(strict_types=1);

namespace TestApp\Authenticator;

use Authentication\Authenticator\Result;
use Authentication\Authenticator\ResultInterface;
use CakeDC\Mcp\McpConfig;
use CakeDC\OAuthServer\Authenticator\TokenAuthenticator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The oauth plugin's Token authenticator for the API, kept off the MCP endpoint. docs/oauth.md (cakedc/users)
 * shows this class: keep the two in sync.
 */
class ApiTokenAuthenticator extends TokenAuthenticator
{
    /**
     * @inheritDoc
     */
    public function authenticate(ServerRequestInterface $request): ResultInterface
    {
        if (McpConfig::isEndpoint($request)) {
            return new Result(null, Result::FAILURE_CREDENTIALS_MISSING);
        }

        return parent::authenticate($request);
    }

    /**
     * @inheritDoc
     */
    public function unauthorizedChallenge(ServerRequestInterface $request): void
    {
        if (!McpConfig::isEndpoint($request)) {
            parent::unauthorizedChallenge($request);
        }
    }
}
