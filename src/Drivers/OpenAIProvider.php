<?php

namespace FlorianDomgjoni\AIFactory\Drivers;

use FlorianDomgjoni\AIFactory\Contracts\AIProviderInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIProvider implements AIProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    /**
     * @throws RequestException
     * @throws RuntimeException
     */
    public function generateBulk(array $fields, int $count): array
    {
        $response = $this->makeStreamRequest($fields, $count);
        
        return $this->processResponse($response);
    }

    /**
     * @throws RequestException
     */
    private function makeStreamRequest(array $fields, int $count): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->withOptions(['stream' => true])
            ->post(self::API_URL, [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => $this->buildPrompt($fields, $count)],
                ],
                'stream' => true,
            ])
            ->throw()
            ->toPsrResponse();

        $content = '';
        $body = $response->getBody();
        
        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $content .= $chunk;
            
            // Process chunk as soon as it's received
            // Here you could emit events or call a callback for each chunk
        }

        return $content;
    }

    /**
     * @throws RuntimeException
     */
    private function processResponse(string $content): array
    {
        // Parse streamed response (may need adjustment based on OpenAI's stream format)
        $jsonContent = $this->extractJsonFromStream($content);
        
        $cleaned = preg_replace('/^```(?:json)?|```$/m', '', trim($jsonContent));
        
        $result = json_decode($cleaned, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid AI response: JSON decoding failed - ' . json_last_error_msg());
        }

        return $result;
    }

    private function extractJsonFromStream(string $streamContent): string
    {
        // OpenAI stream format: data: {"id":"...","object":"...","created":...,"model":"...","choices":[{"delta":{"content":"..."}}]}
        $lines = explode("\n", $streamContent);
        $jsonContent = '';
        
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), 'data:')) {
                $data = json_decode(trim(substr($line, 5)), true);
                $jsonContent .= $data['choices'][0]['delta']['content'] ?? '';
            }
        }
        
        return $jsonContent;
    }

    private function buildPrompt(array $fields, int $count): string
    {
        $fieldList = collect($fields)
            ->map(fn ($desc, $field) => "- `{$field}`: {$desc}")
            ->implode("\n");

        return <<<PROMPT
Generate {$count} fake data records in JSON array format. Each should contain:
{$fieldList}

Return only the JSON array.
PROMPT;
    }
}
