<?php

namespace App\Filament\Resources\GatewayLogs;

use App\Filament\Resources\GatewayLogs\Pages\ListGatewayLogs;
use App\Models\GatewayLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class GatewayLogResource extends Resource
{
    protected static ?string $model = GatewayLog::class;

    protected static ?string $modelLabel = 'log entry';

    protected static ?string $pluralModelLabel = 'activity log';

    protected static ?string $navigationLabel = 'Activity log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('10s')->columns([
            TextColumn::make('created_at')->label('Time')->since()->tooltip(fn (GatewayLog $record): string => $record->created_at->toDateTimeString().' UTC')->sortable(),
            TextColumn::make('level')->badge()->color(fn (string $state): string => match ($state) {
                'error' => 'danger',
                'warning' => 'warning',
                default => 'gray',
            }),
            TextColumn::make('category')->badge()->color('info'),
            TextColumn::make('connection.name')->label('Connection')->placeholder('—'),
            TextColumn::make('message')->searchable()->wrap(),
        ])->filters([
            SelectFilter::make('level')->options(array_combine(GatewayLog::LEVELS, GatewayLog::LEVELS)),
            SelectFilter::make('category')->options(array_combine(GatewayLog::CATEGORIES, GatewayLog::CATEGORIES)),
            SelectFilter::make('mcp_connection_id')->label('Connection')->relationship('connection', 'name'),
            Filter::make('created_at')->schema([
                DateTimePicker::make('from'),
                DateTimePicker::make('until'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('created_at', '>=', $from))
                ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('created_at', '<=', $until))),
        ])->recordActions([
            Action::make('details')->label('Details')->icon(Heroicon::OutlinedEye)
                ->modalHeading(fn (GatewayLog $record): string => $record->message)
                ->modalContent(fn (GatewayLog $record): View => view('filament.gateway-logs.details', ['record' => $record]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListGatewayLogs::route('/')];
    }
}
