<?php

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ChatConversationPolicy
{
    /** Other users' conversations answer 404, so their existence is not revealed. */
    private function owns(User $user, ChatConversation $conversation): Response
    {
        return $conversation->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, ChatConversation $conversation): Response
    {
        return $this->owns($user, $conversation);
    }

    public function update(User $user, ChatConversation $conversation): Response
    {
        return $this->owns($user, $conversation);
    }

    public function delete(User $user, ChatConversation $conversation): Response
    {
        return $this->owns($user, $conversation);
    }
}
