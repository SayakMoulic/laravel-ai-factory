<?php

namespace FlorianDomgjoni\AIFactory\Drivers;

use FlorianDomgjoni\AIFactory\Contracts\AIProviderInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LocalProvider implements AIProviderInterface
{
    private const TIMEOUT = 300;
    private const JSON_REGEX = '/^```(?:json)?|```$/m';

    public function generateBulk(array $fields, int $count): array
    {
        $prompt = $this->buildPrompt($fields, $count);
        
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withToken(config('ai-factory.local.api_key'))
                ->post(config('ai-factory.local.url'), [
                    'model' => config('ai-factory.local.model'),
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'stream' => true,
                ]);

            $content = $this->processStreamedResponse($response);

            Log::info('AI response received', ['response' => $content]);

            return $this->parseResponseContent($content);
        } catch (RequestException $e) {
            Log::error('AI request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('AI request failed: ' . $e->getMessage());
        }
    }

    public function generateBulkStreamed(array $fields, int $count): StreamedResponse
    {
        $prompt = $this->buildPrompt($fields, $count);

        return new StreamedResponse(function () use ($prompt) {
            $response = Http::timeout(self::TIMEOUT)
                ->withToken(config('ai-factory.local.api_key'))
                ->post(config('ai-factory.local.url'), [
                    'model' => config('ai-factory.local.model'),
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'stream' => true,
                ]);

            foreach ($this->streamResponse($response) as $chunk) {
                echo $chunk;
                ob_flush();
                flush();
            }
        });
    }

    protected function buildPrompt(array $fields, int $count): string
    {
        $fieldList = collect($fields)
            ->map(fn ($desc, $field) => "- `$field`: $desc")
            ->implode("\n");

        return <<<PROMPT
Generate {$count} fake data records in JSON array format. Each should contain:
{$fieldList}

Return only the JSON array with no additional text or explanation.
PROMPT;
    }

    private function processStreamedResponse($response): string
    {
        $content = '';
        
        foreach ($this->streamResponse($response) as $chunk) {
            $content .= $chunk;
        }
        
        return $content;
    }

    private function streamResponse($response): \Generator
    {
        foreach ($response->stream() as $chunk) {
            $data = json_decode($chunk, true);
            
            if (isset($data['choices'][0]['delta']['content'])) {
                yield $data['choices'][0]['delta']['content'];
            }
        }
    }

    private function parseResponseContent(string $content): array
    {
        $cleaned = preg_replace(self::JSON_REGEX, '', trim($content));
        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid AI response', ['content' => $content]);
            throw new \RuntimeException('Invalid AI response: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
