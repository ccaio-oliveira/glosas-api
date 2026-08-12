<?php

namespace App\Services\Appeals;

use App\Models\AppealTemplate;

class GeneratedAppeal
{
    public function __construct(
        public readonly ?string $text,
        public readonly ?AppealTemplate $template,
        public readonly string $source, // template_code|template_group|template_category|needs_ai
        public readonly array $attachments = [],
        public readonly bool $requiresClinicalInput = false,
        public readonly bool $missingLegalBasis = false,
    ){}

    public function isGenerated(): bool
    {
        return $this->text !== null;
    }
}
