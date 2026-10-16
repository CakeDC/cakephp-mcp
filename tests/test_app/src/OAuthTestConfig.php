<?php
declare(strict_types=1);

namespace TestApp;

/**
 * The authorization server of the test app (cakedc/cakephp-oauth-server) and the `Mcp.auth`
 * settings of the OAuth tests.
 */
final class OAuthTestConfig
{
    public const MCP = 'http://localhost/mcp';
    public const API = 'http://localhost/api';

    /**
     * The `OAuthServer` configuration.
     *
     * @var array<string, mixed>
     */
    public const SERVER = [
        'resources' => [
            self::MCP => [
                'name' => 'Test MCP',
                'scopes' => [
                    'notes:read' => 'Read your notes',
                    'notes:write' => 'Write notes',
                    'admin' => 'Administer the application',
                ],
                'implies' => ['notes:write' => ['notes:read']],
                'defaultScopes' => ['notes:read'],
            ],
            self::API => [
                'name' => 'Test API',
                'scopes' => ['api' => 'Use the API'],
                'defaultScopes' => ['api'],
            ],
        ],
        'tokenPrefix' => 'test',
        'userProvider' => ['table' => 'Users', 'finder' => 'active'],
        'cimd' => ['enabled' => false],
        // Rate limiting would count across the tests of one process.
        'rateLimit' => ['cache' => null],
    ];

    /**
     * `Mcp.auth` of the oauth mode tests: every tool call needs `notes:read`, `create_note` also
     * `notes:write`, `admin_only` also `admin`.
     *
     * @var array<string, mixed>
     */
    public const MCP_AUTH = [
        'mode' => 'oauth',
        'scopes' => [
            'methods' => ['tools/call' => ['notes:read']],
            'tools' => ['create_note' => ['notes:write'], 'admin_only' => ['admin']],
        ],
    ];
}
