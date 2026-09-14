<?php

namespace App\Filament\Resources\McpConnections;

use App\Filament\Resources\McpConnections\Pages\ManageMcpConnections;
use App\Models\McpConnection;
use App\Services\RemoteMcpClient;
use App\Services\RemoteUrl;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Throwable;

class McpConnectionResource extends Resource
{
    protected static ?string $model = McpConnection::class;

    protected static ?string $modelLabel = 'MCP server';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100),
            TextInput::make('url')->label('MCP URL')->required()->url()->maxLength(2048)->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        RemoteUrl::validate($value);
                    } catch (Throwable) {
                        $fail('Enter an HTTP or HTTPS URL without embedded credentials.');
                    }
                },
            ]),
            Select::make('auth_type')->label('Authentication')->options(['none' => 'None / custom headers', 'oauth' => 'OAuth (automatic discovery)', 'bearer' => 'Bearer token'])->default('oauth')->required()->live(),
            TextInput::make('credentials.client_id')->label('OAuth client ID')->visible(fn (Get $get): bool => $get('auth_type') === 'oauth')->helperText(fn (): string => 'Leave blank for dynamic registration. For manual registration, use callback URL: '.route('upstream.callback')),
            TextInput::make('credentials.client_secret')->label('OAuth client secret')->password()->revealable()->visible(fn (Get $get): bool => $get('auth_type') === 'oauth'),
            TextInput::make('credentials.scope')->label('OAuth scopes (optional)')->visible(fn (Get $get): bool => $get('auth_type') === 'oauth')->helperText('Space-separated; leave blank to use advertised scopes.'),
            TextInput::make('credentials.bearer_token')->label('Bearer token')->password()->revealable()->required(fn (Get $get): bool => $get('auth_type') === 'bearer')->visible(fn (Get $get): bool => $get('auth_type') === 'bearer'),
            KeyValue::make('credentials.headers')->label('Custom headers')->keyLabel('Header')->valueLabel('Value')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            ImageColumn::make('favicon')->label('')->size(28),
            TextColumn::make('name')->searchable()->description(fn (McpConnection $record): string => $record->url),
            TextColumn::make('status')->badge(),
            ToggleColumn::make('enabled')->label('Enabled'),
        ])->recordActions([
            Action::make('connect')->label(fn (McpConnection $record): string => empty($record->credentials['access_token']) ? 'Connect' : 'Reconnect')
                ->visible(fn (McpConnection $record): bool => $record->auth_type === 'oauth')
                ->url(fn (McpConnection $record): string => route('upstream.connect', $record))->postToUrl(),
            Action::make('check')->label('Check')->action(function (McpConnection $record): void {
                try {
                    $client = new RemoteMcpClient($record);
                    $client->initialize();
                    $count = count($client->tools());
                    $record->update(['status' => 'Connected']);
                    Notification::make()->success()->title($count.' tools available')->send();
                } catch (Throwable) {
                    $record->update(['status' => 'Connection needs attention']);
                    Notification::make()->danger()->title('Connection failed')->body('Check your credentials or reconnect with OAuth.')->send();
                }
            })->disabled(fn (McpConnection $record): bool => ! $record->enabled),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMcpConnections::route('/')];
    }
}
