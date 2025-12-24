import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export function BotsPage() {
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Bots</h1>
          <p className="text-muted-foreground">
            Manage your chatbots and their configurations
          </p>
        </div>
        <Button>Create Bot</Button>
      </div>

      {/* Empty state */}
      <Card>
        <CardHeader className="text-center">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="24"
              height="24"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              className="text-muted-foreground"
            >
              <path d="M12 8V4H8" />
              <rect width="16" height="12" x="4" y="8" rx="2" />
              <path d="M2 14h2" />
              <path d="M20 14h2" />
              <path d="M15 13v2" />
              <path d="M9 13v2" />
            </svg>
          </div>
          <CardTitle>No bots yet</CardTitle>
          <CardDescription>
            Create your first bot to get started with AI-powered conversations
          </CardDescription>
        </CardHeader>
        <CardContent className="text-center">
          <Button>Create your first bot</Button>
        </CardContent>
      </Card>
    </div>
  );
}
