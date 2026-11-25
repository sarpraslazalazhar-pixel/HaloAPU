<?php

namespace App\Services\Ai;

use App\Models\Admin;
use App\Services\Ai\Tools\AiToolRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function isConfigured(): bool
    {
        return trim((string) config('services.gemini.key', '')) !== '';
    }

    public function modelName(): string
    {
        $model = trim((string) config('services.gemini.model', ''));

        return $model !== '' ? $model : 'gemini-3.6-flash';
    }

    /**
     * Send a single generateContent request with API key in header (never in URL).
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: ?array<string, mixed>, error: ?string}
     */
    public function generateContent(array $payload, int $timeoutSeconds = 25): array
    {
        $apiKey = trim((string) config('services.gemini.key', ''));
        if ($apiKey === '') {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'error' => 'API Key Gemini belum dikonfigurasi.',
            ];
        }

        $model = $this->modelName();
        $url = self::BASE_URL . '/' . rawurlencode($model) . ':generateContent';

        try {
            $response = Http::timeout($timeoutSeconds)
                ->connectTimeout(8)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                $json = $response->json();

                return [
                    'ok' => true,
                    'status' => $response->status(),
                    'data' => is_array($json) ? $json : null,
                    'error' => null,
                ];
            }

            $errorMessage = (string) ($response->json('error.message') ?? ('HTTP ' . $response->status()));
            $errorMessage = str_replace($apiKey, '[REDACTED]', $errorMessage);

            Log::warning('Gemini API non-success response', [
                'status' => $response->status(),
                'model' => $model,
                'error' => $errorMessage,
            ]);

            return [
                'ok' => false,
                'status' => $response->status(),
                'data' => null,
                'error' => $errorMessage,
            ];
        } catch (\Throwable $e) {
            $safeError = str_replace($apiKey, '[REDACTED]', $e->getMessage());

            Log::error('Gemini API exception', [
                'model' => $model,
                'error' => $safeError,
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'error' => $safeError,
            ];
        }
    }

    /**
     * Multi-turn function-calling loop against Gemini REST API, preserving thoughtSignature & call id.
     *
     * @param  array<int, array<string, mixed>>  $contents
     * @return array{ok: bool, reply: string, tools_used: array<int, string>, charts: array<int, array<string, mixed>>, error: ?string}
     */
    public function runWithTools(
        string $systemInstruction,
        array $contents,
        AiToolRegistry $registry,
        Admin $admin,
        int $maxRounds = 4
    ): array {
        $toolsUsed = [];
        $charts = [];
        $chartIndexById = [];

        $declarations = $registry->declarations();

        $basePayload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];

        if (! empty($declarations)) {
            $basePayload['tools'] = [
                ['functionDeclarations' => $declarations],
            ];
        }

        $hitMaxRoundsWithCalls = false;

        for ($round = 0; $round < $maxRounds; $round++) {
            $payload = $basePayload;
            $payload['contents'] = $contents;

            $result = $this->generateContent($payload);
            if (! $result['ok']) {
                return [
                    'ok' => false,
                    'reply' => '',
                    'tools_used' => $toolsUsed,
                    'charts' => $charts,
                    'error' => $result['error'] ?? 'Permintaan ke Gemini gagal.',
                ];
            }

            $parts = $result['data']['candidates'][0]['content']['parts'] ?? [];
            if (! is_array($parts)) {
                $parts = [];
            }

            $functionCalls = array_values(array_filter(
                $parts,
                static fn ($part): bool => is_array($part) && isset($part['functionCall']) && is_array($part['functionCall'])
            ));

            if (empty($functionCalls)) {
                $text = $this->extractTextFromParts($parts);
                if ($text === '') {
                    return [
                        'ok' => false,
                        'reply' => '',
                        'tools_used' => $toolsUsed,
                        'charts' => $charts,
                        'error' => 'Respons Gemini kosong.',
                    ];
                }

                return [
                    'ok' => true,
                    'reply' => $text,
                    'tools_used' => $toolsUsed,
                    'charts' => $charts,
                    'error' => null,
                ];
            }

            // Preserve the exact model parts (including thoughtSignature & id)
            $contents[] = [
                'role' => 'model',
                'parts' => $parts,
            ];

            $responseParts = [];
            foreach ($functionCalls as $callPart) {
                $call = $callPart['functionCall'];
                $name = (string) ($call['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                $toolResult = $registry->execute($name, $admin, $args);

                if (! in_array($name, $toolsUsed, true)) {
                    $toolsUsed[] = $name;
                }

                if ($toolResult->chart !== null && isset($toolResult->chart['id'])) {
                    $chartId = (string) $toolResult->chart['id'];
                    if (array_key_exists($chartId, $chartIndexById)) {
                        $charts[$chartIndexById[$chartId]] = $toolResult->chart;
                    } else {
                        $chartIndexById[$chartId] = count($charts);
                        $charts[] = $toolResult->chart;
                    }
                }

                $responseItem = array_filter([
                    'name' => $name,
                    'id' => $call['id'] ?? null,
                    'response' => ['result' => $toolResult->data],
                ], static fn ($value) => $value !== null);

                $responseParts[] = ['functionResponse' => $responseItem];
            }

            if (empty($responseParts)) {
                return [
                    'ok' => false,
                    'reply' => '',
                    'tools_used' => $toolsUsed,
                    'charts' => $charts,
                    'error' => 'Pemanggilan tool Gemini tidak valid.',
                ];
            }

            $contents[] = [
                'role' => 'user',
                'parts' => $responseParts,
            ];

            if ($round === $maxRounds - 1) {
                $hitMaxRoundsWithCalls = true;
            }
        }

        if ($hitMaxRoundsWithCalls) {
            // Append an instruction part to request final synthesis, and invoke once without `tools`.
            $lastIdx = count($contents) - 1;
            $contents[$lastIdx]['parts'][] = [
                'text' => 'Rangkum hasil seluruh tool yang sudah diperoleh di atas menjadi jawaban akhir yang ringkas, akurat, dan terstruktur.',
            ];

            $finalPayload = [
                'systemInstruction' => [
                    'parts' => [['text' => $systemInstruction]],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.2,
                ],
            ];

            $finalResult = $this->generateContent($finalPayload);
            if (! $finalResult['ok']) {
                return [
                    'ok' => false,
                    'reply' => '',
                    'tools_used' => $toolsUsed,
                    'charts' => $charts,
                    'error' => $finalResult['error'] ?? 'Gagal merangkum hasil tool Gemini.',
                ];
            }

            $finalParts = $finalResult['data']['candidates'][0]['content']['parts'] ?? [];
            $finalText = $this->extractTextFromParts(is_array($finalParts) ? $finalParts : []);

            if ($finalText === '') {
                return [
                    'ok' => false,
                    'reply' => '',
                    'tools_used' => $toolsUsed,
                    'charts' => $charts,
                    'error' => 'Respons rangkuman akhir Gemini kosong.',
                ];
            }

            return [
                'ok' => true,
                'reply' => $finalText,
                'tools_used' => $toolsUsed,
                'charts' => $charts,
                'error' => null,
            ];
        }

        return [
            'ok' => false,
            'reply' => '',
            'tools_used' => $toolsUsed,
            'charts' => $charts,
            'error' => 'Batas putaran pemanggilan tool tercapai.',
        ];
    }

    /**
     * Single-shot text generation helper (e.g. for scheduled AI briefings).
     *
     * @return array{ok: bool, reply: string, error: ?string}
     */
    public function generateText(string $systemInstruction, string $userPrompt, int $timeoutSeconds = 25): array
    {
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
            ],
        ];

        $result = $this->generateContent($payload, $timeoutSeconds);
        if (! $result['ok']) {
            return [
                'ok' => false,
                'reply' => '',
                'error' => $result['error'],
            ];
        }

        $parts = $result['data']['candidates'][0]['content']['parts'] ?? [];
        $text = $this->extractTextFromParts(is_array($parts) ? $parts : []);

        if ($text === '') {
            return [
                'ok' => false,
                'reply' => '',
                'error' => 'Respons teks Gemini kosong.',
            ];
        }

        return [
            'ok' => true,
            'reply' => $text,
            'error' => null,
        ];
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private function extractTextFromParts(array $parts): string
    {
        $chunks = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (! empty($part['thought'])) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                $chunks[] = $part['text'];
            }
        }

        return trim(implode('', $chunks));
    }
}
