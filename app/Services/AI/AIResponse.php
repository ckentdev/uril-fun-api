<?php

namespace App\Services\AI;

final class AIResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly int $latencyMs = 0,
    ) {}

    public function withLatency(int $ms): self
    {
        return new self($this->content, $this->provider, $this->model, $this->promptTokens, $this->completionTokens, $ms);
    }
}
