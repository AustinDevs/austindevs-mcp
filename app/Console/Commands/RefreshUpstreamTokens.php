<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Services\ActivityLogger;
use App\Services\UpstreamOAuth;
use Illuminate\Console\Command;
use Throwable;

class RefreshUpstreamTokens extends Command
{
    protected $signature = 'oauth:refresh-upstream';

    protected $description = 'Refresh expiring upstream OAuth tokens and keep idle connections authorized';

    public function handle(UpstreamOAuth $oauth, ActivityLogger $logger): int
    {
        $failed = false;
        foreach (McpConnection::where('enabled', true)->where('auth_type', 'oauth')->lazyById(100) as $connection) {
            if (empty($connection->credentials['access_token']) || empty($connection->credentials['refresh_token'])) {
                continue;
            }
            try {
                $oauth->refresh($connection, scheduled: true);
            } catch (Throwable $exception) {
                $failed = true;
                $logger->warning('oauth', 'Scheduled OAuth refresh failed for '.$connection->name, ['exception' => $exception::class], $connection);
                $this->warn('Connection '.$connection->id.' could not refresh. See the activity log.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
