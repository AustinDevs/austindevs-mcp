<?php

namespace App\Mcp\Content;

use InvalidArgumentException;
use Laravel\Mcp\Server\Concerns\HasMeta;
use Laravel\Mcp\Server\Contracts\Content;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

class EmbeddedResourceContent implements Content
{
    use HasMeta;

    /**
     * @param  array{uri: string, mimeType: string, blob: string}  $resource
     */
    public function __construct(private array $resource) {}

    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    public function toPrompt(Prompt $prompt): array
    {
        return $this->toArray();
    }

    public function toResource(Resource $resource): array
    {
        throw new InvalidArgumentException('Embedded resource content may not be used as a resource response.');
    }

    public function toArray(): array
    {
        return $this->mergeMeta(['type' => 'resource', 'resource' => $this->resource]);
    }

    public function __toString(): string
    {
        return $this->resource['uri'];
    }
}
