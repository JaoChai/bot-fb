<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateMcpToken extends Command
{
    protected $signature = 'mcp:create-token
                            {--user= : User ID or email to create token for}
                            {--name=mcp-cli-token : Token name}
                            {--expires= : Days until expiration (default: 365, use 0 for never)}';

    protected $description = 'Create a long-lived API token for MCP CLI usage';

    public function handle(): int
    {
        $userIdentifier = $this->option('user');
        $tokenName = $this->option('name');
        $expiresDays = $this->option('expires') ?? 365;

        // Find user
        if ($userIdentifier) {
            $user = is_numeric($userIdentifier)
                ? User::find($userIdentifier)
                : User::where('email', $userIdentifier)->first();
        } else {
            // Get first user or let user choose
            $users = User::all(['id', 'name', 'email']);

            if ($users->isEmpty()) {
                $this->error('No users found. Please create a user first.');

                return self::FAILURE;
            }

            if ($users->count() === 1) {
                $user = $users->first();
            } else {
                $choices = $users->map(fn ($u) => "{$u->id}: {$u->name} ({$u->email})")->toArray();
                $selected = $this->choice('Select user to create token for:', $choices);
                $userId = (int) explode(':', $selected)[0];
                $user = User::find($userId);
            }
        }

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $this->info("Creating token for: {$user->name} ({$user->email})");

        // Create token with expiration
        $expiration = $expiresDays > 0 ? now()->addDays((int) $expiresDays) : null;

        $token = $user->createToken(
            $tokenName,
            ['*'], // All abilities
            $expiration
        );

        $this->newLine();
        $this->info('✅ Token created successfully!');
        $this->newLine();

        $this->line('Token Details:');
        $this->table(
            ['Property', 'Value'],
            [
                ['Name', $tokenName],
                ['User', "{$user->name} ({$user->email})"],
                ['Expires', $expiration ? $expiration->format('Y-m-d H:i:s') : 'Never'],
            ]
        );

        $this->newLine();
        $this->warn('⚠️  Copy this token now - it will not be shown again!');
        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info($token->plainTextToken);
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $this->line('Add to .mcp.json:');
        $this->line('"LARAVEL_AUTH_TOKEN": "'.$token->plainTextToken.'"');

        return self::SUCCESS;
    }
}
