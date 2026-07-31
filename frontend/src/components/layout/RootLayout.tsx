import { Outlet, useLocation } from "react-router"
import { Sidebar } from "./Sidebar"
import { Header } from "./Header"
import { useConnectionStatus } from "@/hooks/useConnectionStatus"
import { useChatStore } from "@/stores/chatStore"
import { cn } from "@/lib/utils"

export function RootLayout() {
  // Monitor WebSocket connection globally - shows toast on disconnect/reconnect
  useConnectionStatus();

  const { pathname } = useLocation();
  const showMobileChat = useChatStore((s) => s.showMobileChat);

  // หน้าแชทจัดการพื้นที่ทั้งหมดเอง (สกรอลล์แยกซ้าย/ขวา) จึงไม่รับ padding จาก main
  const isChatRoute = pathname.startsWith('/chat');
  // อยู่ในห้องแชทบนมือถือ = เต็มจอแบบ LINE ให้ ChatHeader ทำหน้าที่ nav แทน
  const hideMobileHeader = isChatRoute && showMobileChat;

  return (
    <div className="flex h-dvh bg-background">
      {/* Sidebar - hidden on mobile */}
      <Sidebar />

      {/* Main content area */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {!hideMobileHeader && <Header />}
        <main
          className={cn(
            "flex-1 min-h-0",
            isChatRoute ? "overflow-hidden" : "overflow-auto p-4 md:p-6"
          )}
        >
          <Outlet />
        </main>
      </div>
    </div>
  )
}
