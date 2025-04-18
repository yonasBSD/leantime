<?php

namespace Leantime\Core\Support\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class AITool
{
    public function __construct(
        public string $name,
        public string $description,
        public ?string $htmxEvent = null,
        public array $parameters = []
    ) {}
}
