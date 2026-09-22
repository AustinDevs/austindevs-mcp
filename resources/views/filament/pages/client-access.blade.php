<x-filament-panels::page>
    <x-filament::section heading="One endpoint. All your tools." icon="heroicon-o-command-line">
        <div class="flex flex-col gap-5">
            <p class="ad-copy">Connect Codex, Claude, or another MCP client to Austin Devs MCP.</p>
            <div class="ad-endpoint" x-data="{ copied: false }">
                <code>{{ url('/mcp') }}</code>
                <x-filament::button color="gray" icon="heroicon-o-clipboard-document" x-on:click="navigator.clipboard.writeText(@js(url('/mcp'))).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <span x-text="copied ? 'Copied' : 'Copy endpoint'">Copy endpoint</span>
                </x-filament::button>
            </div>
            <div class="ad-methods">
                <div class="ad-method">
                    <x-filament::badge color="success">Recommended</x-filament::badge>
                    <h3>Sign in with OAuth</h3>
                    <p class="ad-copy">Paste the endpoint into your client and choose OAuth. Sign in with Google to approve access. Most clients register automatically.</p>
                    <p class="ad-copy">Only create an OAuth client manually if your app asks for a client ID and secret.</p>
                </div>
                <div class="ad-method">
                    <x-filament::badge color="gray">For token-based clients</x-filament::badge>
                    <h3>Use a named access token</h3>
                    <p class="ad-copy">Generate one token per client, then copy its authorization header. You can revoke a single client below without disconnecting the others.</p>
                    <p class="ad-copy">Secrets are shown once. Token records remain available here.</p>
                </div>
            </div>
        </div>
    </x-filament::section>

    @if ($generatedSecret)
        <x-filament::section heading="Copy your credentials" icon="heroicon-o-key">
            <div class="flex flex-col gap-3">
                <p class="ad-copy">Store these now. They won’t be available after you leave this page.</p>
                <pre class="ad-secret">{{ $generatedSecret }}</pre>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Access tokens" description="A separate credential for each of your clients." icon="heroicon-o-key">
        @if ($legacyToken)
            <div class="ad-record">
                <div><strong>Legacy access token</strong><p class="ad-record-meta">Created before named tokens · still active</p></div>
                {{ $this->revokeLegacyAction }}
            </div>
        @endif
        @forelse ($tokens as $token)
            <div class="ad-record" wire:key="token-{{ $token->id }}">
                <div>
                    <strong>{{ $token->name }}</strong>
                    <p class="ad-record-meta">Created {{ $token->created_at->diffForHumans() }} · {{ $token->header ?: 'Authorization: Bearer' }} · Last used {{ $token->last_used_at?->diffForHumans() ?? 'never' }}</p>
                </div>
                <div class="ad-record-actions">
                    <x-filament::badge :color="$token->revoked_at ? 'gray' : 'success'">{{ $token->revoked_at ? 'Revoked' : 'Active' }}</x-filament::badge>
                    @unless ($token->revoked_at)
                        {{ ($this->revokeTokenAction)(['id' => $token->id]) }}
                    @endunless
                </div>
            </div>
        @empty
            @unless ($legacyToken)<p class="ad-copy">No access tokens yet. Generate one above to connect a client.</p>@endunless
        @endforelse
    </x-filament::section>

    <x-filament::section heading="OAuth sessions" description="Access granted when you signed into a connected client." icon="heroicon-o-link">
        @forelse ($oauthTokens as $token)
            <div class="ad-record" wire:key="oauth-{{ $token->id }}">
                <div><strong>{{ $token->client?->name ?? $token->name ?? 'OAuth client' }}</strong><p class="ad-record-meta">Created {{ $token->created_at?->diffForHumans() }} · Expires {{ $token->expires_at?->diffForHumans() ?? 'never' }}</p></div>
                <div class="ad-record-actions">
                    <x-filament::badge :color="$token->revoked || $token->expires_at?->isPast() ? 'gray' : 'success'">{{ $token->revoked ? 'Revoked' : ($token->expires_at?->isPast() ? 'Expired' : 'Active') }}</x-filament::badge>
                    @unless ($token->revoked){{ ($this->revokeOAuthAction)(['id' => $token->id]) }}@endunless
                </div>
            </div>
        @empty
            <p class="ad-copy">No OAuth sessions yet. They appear after you authorize a client.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="OAuth clients" description="Applications you registered manually." icon="heroicon-o-computer-desktop" collapsible>
        @forelse ($oauthClients as $client)
            <div class="ad-record" wire:key="client-{{ $client->id }}">
                <div><strong>{{ $client->name }}</strong><p class="ad-record-meta">Client ID: {{ $client->id }}</p></div>
                @if ($client->revoked)<x-filament::badge color="gray">Revoked</x-filament::badge>@else{{ ($this->revokeClientAction)(['id' => $client->id]) }}@endif
            </div>
        @empty
            <p class="ad-copy">No manually registered clients. Most MCP apps register automatically.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
