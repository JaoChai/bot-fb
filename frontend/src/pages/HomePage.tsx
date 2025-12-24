import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"

export function HomePage() {
  return (
    <div className="flex flex-col gap-8">
      <div className="text-center">
        <h1 className="text-4xl font-bold tracking-tight">Welcome to BotFacebook</h1>
        <p className="mt-4 text-lg text-muted-foreground">
          AI-powered chatbot management platform
        </p>
      </div>

      <div className="grid gap-6 md:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Bots</CardTitle>
            <CardDescription>Manage your chatbots</CardDescription>
          </CardHeader>
          <CardContent>
            <Button className="w-full">View Bots</Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Knowledge Base</CardTitle>
            <CardDescription>Upload and manage documents</CardDescription>
          </CardHeader>
          <CardContent>
            <Button variant="secondary" className="w-full">
              Manage KB
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Analytics</CardTitle>
            <CardDescription>View conversation analytics</CardDescription>
          </CardHeader>
          <CardContent>
            <Button variant="outline" className="w-full">
              View Stats
            </Button>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
