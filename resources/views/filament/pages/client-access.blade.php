<x-filament-panels::page>
    <x-filament::section heading="Connect your AI clients">
        <p>Use this MCP endpoint in each client:</p>
        <p><code>{{ url('/mcp') }}</code></p>
        <p>Choose OAuth to sign in with your dashboard account. Clients supporting dynamic registration only need the URL. Create an OAuth client above if your client asks for a client ID and secret.</p>
        <p>For token authentication, generate an access token and supply the displayed header. Custom headers work with clients that support them.</p>
    </x-filament::section>
    @if ($generatedSecret)
        <x-filament::section heading="Copy these credentials now">
            <p>These credentials are shown only in this page session.</p>
            <pre>{{ $generatedSecret }}</pre>
        </x-filament::section>
    @endif
</x-filament-panels::page>
