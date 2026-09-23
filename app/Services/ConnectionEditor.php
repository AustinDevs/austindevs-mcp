<?php

namespace App\Services;

use App\Models\McpConnection;
use Illuminate\Support\Arr;

class ConnectionEditor
{
    private const FORM_CREDENTIAL_KEYS = ['client_id', 'client_secret', 'scope', 'authorize_params', 'send_resource', 'issuer', 'bearer_token', 'headers'];

    private const SESSION_CREDENTIAL_KEYS = ['access_token', 'refresh_token', 'expires_at', 'refresh_token_expires_at', 'last_token_refresh_at', 'metadata'];

    public function __construct(private FaviconFetcher $favicons) {}

    /**
     * Apply dashboard edits, merging form credentials into stored credentials so OAuth tokens survive unrelated edits.
     *
     * @param  array{name: string, url: string, auth_type: string, credentials?: array<string, mixed>}  $data
     */
    public function update(McpConnection $connection, array $data): McpConnection
    {
        $credentials = $connection->credentials ?? [];
        foreach (self::FORM_CREDENTIAL_KEYS as $key) {
            $value = $data['credentials'][$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                unset($credentials[$key]);
            } else {
                $credentials[$key] = $value;
            }
        }
        $attributes = Arr::only($data, ['name', 'url', 'auth_type']);
        if ($data['url'] !== $connection->url || $data['auth_type'] !== $connection->auth_type || ($credentials['issuer'] ?? null) !== ($connection->credentials['issuer'] ?? null)) {
            $credentials = Arr::except($credentials, self::SESSION_CREDENTIAL_KEYS);
            $attributes['status'] = $data['auth_type'] === 'oauth' ? 'Authorization required' : 'Not checked';
        }
        if ($data['url'] !== $connection->url) {
            $attributes['favicon'] = $this->favicons->fetch($data['url']);
        }
        $connection->update([...$attributes, 'credentials' => $credentials]);

        return $connection;
    }
}
