<?php

namespace App\Services\AI;

/** Stand-in when AI_PROVIDER names something we do not implement. */
class UnsupportedProvider implements AIProviderInterface
{
    public function __construct(private string $configured) {}

    public function name(): string
    {
        return $this->configured;
    }

    public function model(): string
    {
        return '';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function generate(AIRequest $request): AIResponse
    {
        throw AIException::notConfigured("AI provider \"{$this->configured}\" is not supported. Use \"repohive\", \"openai\" or \"mock\".");
    }

    public function ping(): void
    {
        throw AIException::notConfigured("AI provider \"{$this->configured}\" is not supported.");
    }
}
