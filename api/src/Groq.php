<?php
declare(strict_types=1);

final class Groq
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(private string $apiKey, private string $model)
    {
    }

    /**
     * Ask Groq for a JSON object. $schemaHint tells the model exactly which keys to return.
     *
     * @return array<string, mixed>
     */
    public function completeJson(string $system, string $user, float $temperature = 0.7): array
    {
        $payload = [
            'model' => $this->model,
            'temperature' => $temperature,
            'max_tokens' => 1024,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Groq request failed: ' . $curlErr);
        }
        $body = json_decode((string) $raw, true);
        if ($status >= 400) {
            $msg = $body['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Groq error: ' . $msg);
        }
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('Groq returned no content');
        }
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('Groq returned invalid JSON');
        }
        return $parsed;
    }
}
