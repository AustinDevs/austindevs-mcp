<?php

namespace App\Filament\Pages;

use App\Models\GatewayToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Livewire\Attributes\Locked;

class ClientAccess extends Page
{
    protected string $view = 'filament.pages.client-access';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 3;

    #[Locked]
    public ?string $generatedSecret = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('token')->label('Generate access token')->icon(Heroicon::OutlinedPlus)->schema([
                TextInput::make('name')->label('Client name')->placeholder('Codex on my Mac')->default('AI client')->required()->maxLength(100),
                TextInput::make('header')->label('Custom header name (optional)')->placeholder('X-MCP-Token')->regex('/^X-[A-Za-z0-9-]+$/i')->helperText('Leave blank to use Authorization: Bearer.'),
            ])->modalDescription('Create a separate token for each client. Existing tokens stay active.')
                ->action(function (array $data): void {
                    $token = Str::random(80);
                    GatewayToken::create(['user_id' => auth()->id(), 'name' => $data['name'], 'token_hash' => hash('sha256', $token), 'header' => $data['header'] ?: null]);
                    $this->generatedSecret = ($data['header'] ?: 'Authorization').': '.($data['header'] ? '' : 'Bearer ').$token;
                }),
            Action::make('oauthClient')->label('Create OAuth client')->icon(Heroicon::OutlinedComputerDesktop)->color('gray')->schema([
                TextInput::make('name')->required()->maxLength(100),
                TextInput::make('redirect')->label('Client callback URL')->required()->url()->rules(['url:http,https']),
            ])->action(function (array $data): void {
                $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($data['name'], [$data['redirect']], true, auth()->user());
                $this->generatedSecret = 'Client ID: '.$client->id."\nClient secret: ".$client->plainSecret;
            }),
            Action::make('revoke')->label('Revoke all access')->color('danger')->outlined()->requiresConfirmation()->action(function (): void {
                DB::transaction(function (): void {
                    auth()->user()->forceFill(['gateway_token_hash' => null, 'gateway_header' => null])->save();
                    GatewayToken::where('user_id', auth()->id())->whereNull('revoked_at')->update(['revoked_at' => now()]);
                    $this->revokeOAuthTokens(auth()->user()->tokens()->pluck('id')->all());
                });
                $this->generatedSecret = null;
            }),
        ];
    }

    public function revokeTokenAction(): Action
    {
        return Action::make('revokeToken')->label('Revoke')->color('danger')->requiresConfirmation()->action(function (array $arguments): void {
            GatewayToken::where('user_id', auth()->id())->findOrFail($arguments['id'])->update(['revoked_at' => now()]);
            $this->generatedSecret = null;
        });
    }

    public function revokeLegacyAction(): Action
    {
        return Action::make('revokeLegacy')->label('Revoke')->color('danger')->requiresConfirmation()->action(function (): void {
            auth()->user()->forceFill(['gateway_token_hash' => null, 'gateway_header' => null])->save();
        });
    }

    public function revokeOAuthAction(): Action
    {
        return Action::make('revokeOAuth')->label('Revoke')->color('danger')->requiresConfirmation()->action(function (array $arguments): void {
            $token = auth()->user()->tokens()->findOrFail($arguments['id']);
            DB::transaction(fn () => $this->revokeOAuthTokens([$token->id]));
        });
    }

    public function revokeClientAction(): Action
    {
        return Action::make('revokeClient')->label('Revoke client')->color('danger')->requiresConfirmation()
            ->modalDescription('Prevents new authorizations and revokes this client’s existing access and refresh tokens.')
            ->action(function (array $arguments): void {
                $client = Client::query()->whereMorphedTo('owner', auth()->user())->findOrFail($arguments['id']);
                DB::transaction(function () use ($client): void {
                    $client->forceFill(['revoked' => true])->save();
                    $this->revokeOAuthTokens($client->tokens()->pluck('id')->all());
                });
                $this->generatedSecret = null;
            });
    }

    /** @param list<string> $ids */
    private function revokeOAuthTokens(array $ids): void
    {
        RefreshToken::whereIn('access_token_id', $ids)->update(['revoked' => true]);
        Token::whereIn('id', $ids)->update(['revoked' => true]);
    }

    protected function getViewData(): array
    {
        return [
            'tokens' => GatewayToken::where('user_id', auth()->id())->latest()->get(),
            'legacyToken' => (bool) auth()->user()->gateway_token_hash,
            'oauthTokens' => auth()->user()->tokens()->with('client')->latest()->get(),
            'oauthClients' => Client::query()->whereMorphedTo('owner', auth()->user())->latest()->get(),
        ];
    }
}
