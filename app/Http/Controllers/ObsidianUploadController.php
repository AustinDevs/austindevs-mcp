<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class ObsidianUploadController extends Controller
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private ActivityLogger $logger) {}

    /**
     * Forward raw file bytes to the vault's one-time upload link. The token in the URL is the
     * only credential; mcpvault mints it for a single path and burns it on success.
     */
    public function store(Request $request, string $token): JsonResponse
    {
        $length = (int) $request->header('Content-Length', 0);
        if ($length > self::MAX_BYTES) {
            return response()->json(['error' => 'File exceeds the 10 MiB transfer limit'], 413);
        }
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BYTES) {
            return response()->json(['error' => 'File exceeds the 10 MiB transfer limit'], 413);
        }
        $url = rtrim((string) config('services.obsidian_upload.url'), '/').'/'.$token;
        $sha256 = $request->query('sha256');
        if (is_string($sha256) && preg_match('/^[a-fA-F0-9]{64}$/', $sha256)) {
            $url .= '?sha256='.$sha256;
        }
        try {
            $response = Http::withBody($body, 'application/octet-stream')->withoutRedirecting()
                ->connectTimeout(5)->timeout(60)->send('PUT', $url);
        } catch (Throwable $exception) {
            $this->logger->error('upload', 'Obsidian upload failed to reach the vault', ['exception' => $exception::class, 'bytes' => strlen($body)]);

            return response()->json(['error' => 'The vault is unreachable'], 502);
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            $this->logger->error('upload', 'Obsidian upload returned a non-JSON response', ['status' => $response->status(), 'bytes' => strlen($body)]);

            return response()->json(['error' => 'The vault returned an unexpected response'], 502);
        }
        $this->logger->info('upload', 'Obsidian upload forwarded', ['status' => $response->status(), 'bytes' => strlen($body), 'path' => $payload['path'] ?? null]);

        return response()->json($payload, $response->status());
    }
}
