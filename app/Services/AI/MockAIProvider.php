<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/**
 * Deterministic provider for development and tests: no network, no cost.
 * Include the text [[fail]] in a prompt to simulate a provider error (useful for testing retry UI).
 */
class MockAIProvider implements AIProviderInterface
{
    private const MARKDOWN_SAMPLE = <<<'MD'
## Markdown showcase

This reply has **bold**, *italic*, ***both***, ~~strikethrough~~ and `inline code`.

### Numbered steps
1. Learn **Linux** fundamentals
2. Practice with *Docker* and containers
3. Automate with CI/CD

### Bullet list
- Cloud hosting
- DevOps
  - Terraform
  - Ansible
- Linux infrastructure

> Tip: build a small home lab first.

| Skill | Level |
| --- | --- |
| Linux | Core |
| Kubernetes | Advanced |

```bash
sudo apt update && sudo apt install -y nginx
```

Read more at [RepoHive](https://repohive.com).
MD;

    public function name(): string
    {
        return 'mock';
    }

    public function model(): string
    {
        return 'mock-1';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function generate(AIRequest $request): AIResponse
    {
        $last = (string) (collect($request->messages)->last()['content'] ?? '');

        if (str_contains($last, '[[fail]]')) {
            throw AIException::providerError('Simulated provider failure (mock).');
        }

        // Showcase of every Markdown style the chat can render (for testing the UI).
        if (str_contains($last, '[[markdown]]')) {
            return new AIResponse(
                content: self::MARKDOWN_SAMPLE,
                provider: $this->name(),
                model: $this->model(),
                promptTokens: 1,
                completionTokens: 1,
            );
        }

        $turns = count($request->messages);
        $content = 'Mock reply: you said "'.Str::limit($last, 120).'". '
            ."(This is the built-in mock provider; {$turns} message(s) of context received"
            .(filled($request->knowledge) ? '; '.strlen($request->knowledge).' characters of knowledge applied' : '').'.)';

        return new AIResponse(
            content: $content,
            provider: $this->name(),
            model: $this->model(),
            promptTokens: (int) ceil(strlen($last) / 4),
            completionTokens: (int) ceil(strlen($content) / 4),
        );
    }

    public function ping(): void
    {
        // Always reachable.
    }
}
