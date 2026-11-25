<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;

interface AiToolInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * OpenAPI/Gemini Schema array for function parameters.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * @param  array<string, mixed>  $args
     */
    public function execute(Admin $admin, array $args): AiToolResult;
}
