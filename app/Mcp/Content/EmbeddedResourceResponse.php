<?php

namespace App\Mcp\Content;

use Laravel\Mcp\Response;

class EmbeddedResourceResponse extends Response
{
    /**
     * @param  array{uri: string, mimeType: string, blob: string}  $resource
     */
    public static function fromResource(array $resource): static
    {
        return new static(new EmbeddedResourceContent($resource));
    }
}
