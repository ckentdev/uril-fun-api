<?php

namespace App\Services\AI;

interface AIProviderInterface
{
    /** Short identifier, e.g. "openai" or "mock". */
    public function name(): string;

    /** Model used for requests (informational). */
    public function model(): string;

    /** True when all credentials/settings needed to call the provider are present. */
    public function isConfigured(): bool;

    /**
     * @throws AIException with a safe, user-presentable message
     */
    public function generate(AIRequest $request): AIResponse;

    /**
     * Cheap, non-billable reachability + credentials check.
     *
     * @throws AIException
     */
    public function ping(): void;
}
