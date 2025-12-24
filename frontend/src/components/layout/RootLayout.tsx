import { Outlet } from "react-router"
import { Sidebar } from "./Sidebar"
import { Header } from "./Header"

export function RootLayout() {
  return (
    <div className="flex h-screen bg-background">
      {/* Sidebar - hidden on mobile */}
      <Sidebar />

      {/* Main content area */}
      <div className="flex flex-1 flex-col overflow-hidden">
        <Header />
        <main className="flex-1 overflow-auto p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
