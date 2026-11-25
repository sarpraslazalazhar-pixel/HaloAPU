<?php

namespace Tests\Unit\Ai;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\Tools\AiToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);

        $this->superAdmin = Admin::factory()->create([
            'name' => 'Super Admin AI',
            'username' => 'superadmin_ai',
            'email' => 'superadmin_ai@example.com',
        ]);
        $this->superAdmin->assignRole('superadmin');

        $unit = Unit::create(['nama_unit' => 'Unit IT', 'deskripsi' => 'IT', 'aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Support IT',
            'deskripsi' => 'Support',
            'aktif' => true,
        ]);
        $user = User::factory()->create();

        Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'form_data' => ['Keluhan' => 'Laptop bermasalah'],
            'status' => 'open',
            'priority' => 'Tinggi',
            'source' => 'web',
        ]);
    }

    public function test_sends_gemini_api_key_only_in_header_and_not_in_url_query_string(): void
    {
        $apiKey = 'AIzaTestSecretKeyHeaderOnly123';
        config([
            'services.gemini.key' => $apiKey,
            'services.gemini.model' => 'gemini-3.6-flash',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Respons berhasil.']],
                    ],
                ]],
            ], 200),
        ]);

        $client = new GeminiClient();
        $result = $client->generateText('Instruksi sistem', 'Halo HaloAPU');

        $this->assertTrue($result['ok']);
        $this->assertSame('Respons berhasil.', $result['reply']);

        Http::assertSent(function (Request $request) use ($apiKey): bool {
            $url = $request->url();
            $parsedQuery = parse_url($url, PHP_URL_QUERY);

            return $url === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent'
                && empty($parsedQuery)
                && ! str_contains($url, $apiKey)
                && ! str_contains($url, 'key=')
                && $request->header('x-goog-api-key') === [$apiKey];
        });
    }

    public function test_multi_turn_function_calling_preserves_raw_model_parts_thought_signature_and_call_id(): void
    {
        config([
            'services.gemini.key' => 'AIzaTestMultiTurnKey',
            'services.gemini.model' => 'gemini-3.6-flash',
        ]);

        $rawModelParts = [
            [
                'thought' => true,
                'text' => 'Menghitung ringkasan tiket terlebih dahulu.',
                'thoughtSignature' => 'sig-thought-alpha-111',
            ],
            [
                'thoughtSignature' => 'sig-call-beta-222',
                'functionCall' => [
                    'id' => 'call_summary_001',
                    'name' => 'get_ticket_summary',
                    'args' => ['period' => '30d'],
                ],
            ],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => $rawModelParts,
                        ],
                    ]],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [['text' => 'Total terdapat 1 tiket open pada periode 30 hari terakhir.']],
                        ],
                    ]],
                ], 200),
        ]);

        $client = new GeminiClient();
        $registry = new AiToolRegistry();

        $result = $client->runWithTools(
            'Sistem analis',
            [['role' => 'user', 'parts' => [['text' => 'Ringkas tiket']]]],
            $registry,
            $this->superAdmin
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('Total terdapat 1 tiket open pada periode 30 hari terakhir.', $result['reply']);
        $this->assertSame(['get_ticket_summary'], $result['tools_used']);
        $this->assertNotEmpty($result['charts']);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        /** @var Request $secondRequest */
        $secondRequest = $recorded[1][0];
        $payload = $secondRequest->data();

        $this->assertCount(3, $payload['contents']);
        $this->assertSame('model', $payload['contents'][1]['role']);
        $this->assertSame($rawModelParts, $payload['contents'][1]['parts']);

        $this->assertSame('user', $payload['contents'][2]['role']);
        $functionResponse = $payload['contents'][2]['parts'][0]['functionResponse'] ?? null;
        $this->assertIsArray($functionResponse);
        $this->assertSame('get_ticket_summary', $functionResponse['name']);
        $this->assertSame('call_summary_001', $functionResponse['id']);
        $this->assertSame(1, $functionResponse['response']['result']['total_tickets']);
    }

    public function test_max_rounds_stops_cleanly_and_performs_one_final_call_without_tools(): void
    {
        config([
            'services.gemini.key' => 'AIzaTestMaxRoundsKey',
            'services.gemini.model' => 'gemini-3.6-flash',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'thoughtSignature' => 'sig-round-1',
                                'functionCall' => [
                                    'id' => 'call_r1',
                                    'name' => 'get_ticket_summary',
                                    'args' => ['period' => '30d'],
                                ],
                            ]],
                        ],
                    ]],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'thoughtSignature' => 'sig-round-2',
                                'functionCall' => [
                                    'id' => 'call_r2',
                                    'name' => 'get_sla_compliance',
                                    'args' => ['period' => '30d'],
                                ],
                            ]],
                        ],
                    ]],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [['text' => 'Rangkuman akhir seluruh metrik berhasil disusun.']],
                        ],
                    ]],
                ], 200),
        ]);

        $client = new GeminiClient();
        $registry = new AiToolRegistry();

        $result = $client->runWithTools(
            'Sistem analis',
            [['role' => 'user', 'parts' => [['text' => 'Analisis penuh']]]],
            $registry,
            $this->superAdmin,
            maxRounds: 2
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('Rangkuman akhir seluruh metrik berhasil disusun.', $result['reply']);
        $this->assertSame(['get_ticket_summary', 'get_sla_compliance'], $result['tools_used']);

        $recorded = Http::recorded();
        $this->assertCount(3, $recorded);

        $this->assertArrayHasKey('tools', $recorded[0][0]->data());
        $this->assertArrayHasKey('tools', $recorded[1][0]->data());

        $finalPayload = $recorded[2][0]->data();
        $this->assertArrayNotHasKey('tools', $finalPayload);

        $lastContent = end($finalPayload['contents']);
        $lastTextPart = end($lastContent['parts']);
        $this->assertArrayHasKey('text', $lastTextPart);
        $this->assertStringContainsString('Rangkum hasil seluruh tool', $lastTextPart['text']);
    }

    public function test_http_429_and_500_return_ok_false_without_leaking_api_key(): void
    {
        $secretKey = 'AIzaSyVerySecretLeakCheck999';
        config([
            'services.gemini.key' => $secretKey,
            'services.gemini.model' => 'gemini-3.6-flash',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'error' => ['message' => "Rate limit exceeded for key {$secretKey}"],
                ], 429)
                ->push([
                    'error' => ['message' => "Internal server error using {$secretKey}"],
                ], 500)
                ->push([
                    'error' => ['message' => "Tool loop failed for {$secretKey}"],
                ], 500),
        ]);

        $client = new GeminiClient();

        $res429 = $client->generateContent(['contents' => [['role' => 'user', 'parts' => [['text' => 'test']]]]]);
        $this->assertFalse($res429['ok']);
        $this->assertSame(429, $res429['status']);
        $this->assertNotNull($res429['error']);
        $this->assertStringNotContainsString($secretKey, (string) $res429['error']);
        $this->assertStringContainsString('[REDACTED]', (string) $res429['error']);

        $res500 = $client->generateContent(['contents' => [['role' => 'user', 'parts' => [['text' => 'test']]]]]);
        $this->assertFalse($res500['ok']);
        $this->assertSame(500, $res500['status']);
        $this->assertStringNotContainsString($secretKey, (string) $res500['error']);
        $this->assertStringContainsString('[REDACTED]', (string) $res500['error']);

        $registry = new AiToolRegistry();
        $toolFail = $client->runWithTools(
            'Sistem',
            [['role' => 'user', 'parts' => [['text' => 'test']]]],
            $registry,
            $this->superAdmin
        );
        $this->assertFalse($toolFail['ok']);
        $this->assertStringNotContainsString($secretKey, (string) $toolFail['error']);
    }
}
