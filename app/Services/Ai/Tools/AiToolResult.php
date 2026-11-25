<?php

namespace App\Services\Ai\Tools;

final readonly class AiToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $chart
     */
    public function __construct(
        public array $data,
        public ?array $chart = null,
    ) {}
}
