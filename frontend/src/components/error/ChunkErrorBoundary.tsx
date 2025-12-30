import { Component } from "react"
import type { ErrorInfo, ReactNode } from "react"
import { RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { hasAttemptedChunkReload } from "@/lib/lazyWithRetry"

interface Props {
  children: ReactNode
}

interface State {
  hasError: boolean
  isChunkError: boolean
}

function isChunkLoadError(error: Error): boolean {
  const message = error.message.toLowerCase()
  return (
    message.includes("failed to fetch dynamically imported module") ||
    message.includes("loading chunk") ||
    message.includes("loading css chunk") ||
    message.includes("unable to preload")
  )
}

export class ChunkErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props)
    this.state = { hasError: false, isChunkError: false }
  }

  static getDerivedStateFromError(error: Error): State {
    return {
      hasError: true,
      isChunkError: isChunkLoadError(error),
    }
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    console.error("ChunkErrorBoundary caught an error:", error, errorInfo)
  }

  handleReload = (): void => {
    window.location.reload()
  }

  render(): ReactNode {
    if (this.state.hasError) {
      const hasTriedReload = hasAttemptedChunkReload()

      if (this.state.isChunkError) {
        return (
          <div className="flex min-h-[400px] flex-col items-center justify-center gap-4 p-8 text-center">
            <div className="rounded-full bg-primary/10 p-4">
              <RefreshCw className="h-8 w-8 text-primary" />
            </div>
            <div className="space-y-2">
              <h2 className="text-xl font-semibold">
                {hasTriedReload ? "ยังคงพบปัญหา" : "มีเวอร์ชันใหม่"}
              </h2>
              <p className="max-w-md text-muted-foreground">
                {hasTriedReload
                  ? "ไม่สามารถโหลดหน้าได้ กรุณาลอง clear cache แล้วรีโหลดอีกครั้ง"
                  : "แอปพลิเคชันได้รับการอัปเดต กรุณารีโหลดเพื่อใช้งานเวอร์ชันล่าสุด"}
              </p>
            </div>
            <Button onClick={this.handleReload} className="gap-2">
              <RefreshCw className="h-4 w-4" />
              รีโหลดหน้า
            </Button>
          </div>
        )
      }

      // Generic error fallback
      return (
        <div className="flex min-h-[400px] flex-col items-center justify-center gap-4 p-8 text-center">
          <div className="space-y-2">
            <h2 className="text-xl font-semibold">เกิดข้อผิดพลาด</h2>
            <p className="max-w-md text-muted-foreground">
              ไม่สามารถโหลดหน้านี้ได้ กรุณาลองรีโหลดอีกครั้ง
            </p>
          </div>
          <Button onClick={this.handleReload} variant="outline" className="gap-2">
            <RefreshCw className="h-4 w-4" />
            รีโหลดหน้า
          </Button>
        </div>
      )
    }

    return this.props.children
  }
}
