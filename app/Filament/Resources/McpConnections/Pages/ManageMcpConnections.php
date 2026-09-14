<?php

namespace App\Filament\Resources\McpConnections\Pages;

use App\Filament\Resources\McpConnections\McpConnectionResource;
use App\Services\FaviconFetcher;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMcpConnections extends ManageRecords
{
    protected static string $resource = McpConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(function (array $data): array {
                $data['favicon'] = app(FaviconFetcher::class)->fetch($data['url']);
                $data['status'] = $data['auth_type'] === 'oauth' ? 'Authorization required' : 'Not checked';

                return $data;
            }),
        ];
    }
}
