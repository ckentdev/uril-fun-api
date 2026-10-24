<?php

namespace App\Services\AI;

final class AIRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  oldest first, ending with the new user message
     * @param  ?string  $knowledge  reference text the assistant should rely on
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $system = null,
        public readonly ?string $knowledge = null,
    ) {}

    /** System instruction with the knowledge appended, for providers without a native knowledge field. */
    public function systemWithKnowledge(): ?string
    {
        if (! filled($this->knowledge)) {
            return $this->system;
        }

        return trim(($this->system ?? '')."\n\nReference knowledge (use it to answer; say so if it does not cover the question):\n".$this->knowledge);
    }
}
