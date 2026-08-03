<?php

namespace App\Services\Tiss;

class ParsedTissFile
{
    public function __construct(
        public readonly ?string $ansRegistryCode,
        public readonly array $claims,
    ) {}
}
