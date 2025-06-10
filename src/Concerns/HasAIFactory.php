<?php

namespace FlorianDomgjoni\AIFactory\Concerns;

use FlorianDomgjoni\AIFactory\Facades\AIFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Throwable;

trait HasAIFactory
{
    /**
     * Create models using AI-generated data
     *
     * @param array $overrides
     * @param bool $bulk
     * @param string|null $driver
     * @return Collection
     * @throws \Exception
     */
    public function createWithAI(array $overrides = [], bool $bulk = false, ?string $driver = null): Collection
    {
        $count = $this->count ?? 1;

        throw_unless(
            method_exists($this, 'aiFields'),
            new \Exception('You must define an aiFields() method in your factory.')
        );

        $fields = array_merge($this->aiFields(), $overrides);

        [$prompts, $manualFields] = $this->partitionFields($fields);

        try {
            $driver ??= config("ai-factory.defaults.{$this->model}", config('ai-factory.driver'));
            $aiData = AIFactory::driver($driver)->generateBulk($prompts, $count);

            $finalData = $this->processGeneratedData($aiData, $manualFields);

            return $bulk 
                ? $this->bulkCreate($finalData) 
                : $this->createIndividually($finalData);
        } catch (Throwable $e) {
            Log::critical('[FactoryAI] Failed to generate AI data', [
                'model' => $this->model,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception('FactoryAI: Data generation failed. Check logs for details.', 0, $e);
        }
    }

    /**
     * Partition fields into prompts and manual fields
     */
    protected function partitionFields(array $fields): array
    {
        $prompts = [];
        $manualFields = [];

        foreach ($fields as $key => $value) {
            match (true) {
                is_string($value) => $prompts[$key] = $value,
                is_callable($value) => $manualFields[$key] = $value,
                default => throw new \InvalidArgumentException(
                    "Invalid value for key '{$key}'. Must be a string (AI prompt) or callable (manual)."
                ),
            };
        }

        return [$prompts, $manualFields];
    }

    /**
     * Process generated AI data with manual fields
     */
    protected function processGeneratedData(array $aiData, array $manualFields): array
    {
        return array_map(function (array $row, int $index) use ($manualFields) {
            foreach ($manualFields as $key => $callback) {
                try {
                    $row[$key] = $callback();
                } catch (Throwable $e) {
                    Log::warning("[FactoryAI] Manual field '{$key}' failed at index {$index}", [
                        'error' => $e->getMessage(),
                    ]);
                    $row[$key] = null;
                }
            }
            return $row;
        }, $aiData, array_keys($aiData));
    }

    /**
     * Bulk create models
     */
    protected function bulkCreate(array $data): Collection
    {
        $this->model::query()->insert($data);
        return collect();
    }

    /**
     * Create models individually with events
     */
    protected function createIndividually(array $data): Collection
    {
        return Collection::make($data)->map(function (array $row, int $index) {
            try {
                return $this->model::query()->create($row);
            } catch (Throwable $e) {
                Log::error("[FactoryAI] Failed to create model at index {$index}", [
                    'model' => $this->model,
                    'row' => $row,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        })->filter();
    }
}
