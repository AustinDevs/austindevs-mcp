<?php

namespace App\Filament\Resources\GatewayLogs\Pages;

use App\Filament\Resources\GatewayLogs\GatewayLogResource;
use App\Models\GatewayLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListGatewayLogs extends ListRecords
{
    protected static string $resource = GatewayLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clear')->label('Clear logs')->color('danger')->requiresConfirmation()
                ->modalDescription('Deletes every activity log entry. This cannot be undone.')
                ->action(function (): void {
                    GatewayLog::query()->delete();
                    Notification::make()->success()->title('Activity log cleared')->send();
                }),
        ];
    }
}
