<?php

namespace App\Filament\Resources\McpConnections;

use App\Filament\Resources\McpConnections\Pages\ManageMcpConnections;
use App\Models\McpConnection;
use App\Services\ConnectionEditor;
use App\Services\RemoteMcpClient;
use App\Services\RemoteUrl;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Throwable;

class McpConnectionResource extends Resource
{
    protected static ?string $model = McpConnection::class;

    protected static ?int $navigationSort = 1;

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
            KeyValue::make('credentials.authorize_params')->label('Additional authorization parameters')->keyLabel('Parameter')->valueLabel('Value')->default([])->visible(fn (Get $get): bool => $get('auth_type') === 'oauth')->helperText('For Google, use access_type=offline and prompt=consent select_account. Add login_hint to suggest an account. Standard OAuth parameters cannot be overridden.')->columnSpanFull(),
            Toggle::make('credentials.send_resource')->label('Send resource parameter')->default(true)->visible(fn (Get $get): bool => $get('auth_type') === 'oauth')->helperText('Include the MCP URL in authorization and token requests. Disable for providers that do not accept it.'),
            TextInput::make('credentials.bearer_token')->label('Bearer token')->password()->revealable()->required(fn (Get $get): bool => $get('auth_type') === 'bearer')->visible(fn (Get $get): bool => $get('auth_type') === 'bearer'),
            KeyValue::make('credentials.headers')->label('Custom headers')->keyLabel('Header')->valueLabel('Value')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            ImageColumn::make('favicon')->label('')->size(28)->state(fn (McpConnection $record): string => $record->iconUrl()),
            TextColumn::make('name')->searchable()->description(fn (McpConnection $record): string => $record->url),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'Connected' => 'success',
                'Authorization required', 'Not checked' => 'warning',
                default => 'danger',
            }),
            ToggleColumn::make('enabled')->label('Enabled'),
        ])->recordActions([
            Action::make('connect')->label(fn (McpConnection $record): string => empty($record->credentials['access_token']) ? 'Connect' : 'Reconnect')
                ->visible(fn (McpConnection $record): bool => $record->auth_type === 'oauth')
                ->url(fn (McpConnection $record): string => route('upstream.connect', $record))->postToUrl(),
            EditAction::make()
                ->mutateRecordDataUsing(function (array $data, McpConnection $record): array {
                    $data['credentials'] = Arr::only($record->credentials ?? [], ['client_id', 'client_secret', 'scope', 'authorize_params', 'send_resource', 'bearer_token', 'headers']);
                    $data['credentials'] += ['authorize_params' => [], 'send_resource' => true];

                    return $data;
                })
                ->using(fn (McpConnection $record, array $data): McpConnection => app(ConnectionEditor::class)->update($record, $data)),
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
