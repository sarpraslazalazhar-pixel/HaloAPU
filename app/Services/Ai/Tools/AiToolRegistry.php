<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Services\Ai\AiAccessScope;
use App\Services\OperatorPerformanceReportService;
use Illuminate\Support\Facades\Log;

class AiToolRegistry
{
    /** @var array<string, AiToolInterface> */
    private array $tools = [];

    public function __construct(
        ?AiAccessScope $scope = null,
        ?OperatorPerformanceReportService $operatorReport = null,
    ) {
        $scope ??= new AiAccessScope();
        $operatorReport ??= new OperatorPerformanceReportService();

        foreach ([
            new GetTicketSummaryTool($scope),
            new ListAtRiskSlaTicketsTool($scope),
            new GetSlaComplianceTool($scope),
            new GetOperatorPerformanceTool($scope, $operatorReport),
            new GetCsatAnalysisTool($scope),
            new SearchTicketsTool($scope),
            new GetTicketDetailTool($scope),
            new ReadTicketAttachmentTool(),
            new GetBookingScheduleTool($scope),
            new ComparePeriodsTool($scope),
        ] as $tool) {
            $this->register($tool);
        }
    }

    public function register(AiToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, AiToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Gemini functionDeclarations array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function declarations(): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            $list[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parametersSchema(),
            ];
        }

        return $list;
    }

    /**
     * Execute a named tool safely under the given admin's access scope.
     *
     * @param  array<string, mixed>  $args
     */
    public function execute(string $name, Admin $admin, array $args = []): AiToolResult
    {
        $tool = $this->get($name);
        if (! $tool) {
            return new AiToolResult([
                'error' => "Tool '{$name}' tidak tersedia.",
            ]);
        }

        try {
            return $tool->execute($admin, $args);
        } catch (\Throwable $e) {
            Log::warning("HaloAPU AI tool '{$name}' failed", [
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return new AiToolResult([
                'error' => 'Gagal mengeksekusi tool: ' . $e->getMessage(),
            ]);
        }
    }
}
