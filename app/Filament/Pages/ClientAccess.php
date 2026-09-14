<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Livewire\Attributes\Locked;

class ClientAccess extends Page
{
    protected string $view = 'filament.pages.client-access';

    #[Locked]
    public ?string $generatedSecret = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('token')->label('Generate access token')->schema([
                TextInput::make('header')->label('Custom header name (optional)')->placeholder('X-MCP-Token')->regex('/^X-[A-Za-z0-9-]+$/i')->helperText('Leave blank to use Authorization: Bearer.'),
            ])->requiresConfirmation()->modalDescription('Replaces the previous static access token. OAuth connections stay active.')
                ->action(function (array $data): void {
                    $token = Str::random(80);
                    auth()->user()->forceFill(['gateway_token_hash' => hash('sha256', $token), 'gateway_header' => $data['header'] ?: null])->save();
                    $this->generatedSecret = ($data['header'] ?: 'Authorization').': '.($data['header'] ? '' : 'Bearer ').$token;
                }),
            Action::make('oauthClient')->label('Create OAuth client')->schema([
                TextInput::make('name')->required()->maxLength(100),
                TextInput::make('redirect')->label('Client callback URL')->required()->url()->rules(['url:http,https']),
            ])->action(function (array $data): void {
                $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($data['name'], [$data['redirect']], true, auth()->user());
                $this->generatedSecret = 'Client ID: '.$client->id."\nClient secret: ".$client->plainSecret;
            }),
            Action::make('revoke')->label('Revoke all client access')->color('danger')->requiresConfirmation()->action(function (): void {
                auth()->user()->forceFill(['gateway_token_hash' => null, 'gateway_header' => null])->save();
                $tokenIds = auth()->user()->tokens()->pluck('id');
                RefreshToken::whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
                auth()->user()->tokens()->update(['revoked' => true]);
                $this->generatedSecret = null;
            }),
        ];
    }
}
