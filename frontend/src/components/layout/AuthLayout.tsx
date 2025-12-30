import { Outlet } from 'react-router';
import { Sparkles } from 'lucide-react';

export function AuthLayout() {
  return (
    <div className="min-h-screen lg:grid lg:grid-cols-2">
      {/* Left side - Branding (hidden on mobile) */}
      <div className="hidden lg:flex flex-col justify-between bg-foreground p-10 text-background">
        {/* Logo */}
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-background text-foreground">
            <Sparkles className="h-5 w-5" />
          </div>
          <span className="text-xl font-semibold">BotFacebook</span>
        </div>

        {/* Testimonial */}
        <div className="space-y-4">
          <blockquote className="text-lg leading-relaxed">
            "ระบบ AI Chatbot ที่ช่วยให้ธุรกิจของเราตอบลูกค้าได้ 24 ชั่วโมง
            ลดภาระงานของทีม และเพิ่มยอดขายได้อย่างมีประสิทธิภาพ"
          </blockquote>
          <div>
            <p className="font-medium">คุณสมชาย ใจดี</p>
            <p className="text-sm text-background/60">CEO, Example Company</p>
          </div>
        </div>

        {/* Footer */}
        <p className="text-sm text-background/60">
          AI Chatbot Platform
        </p>
      </div>

      {/* Right side - Form */}
      <div className="flex flex-col items-center justify-center p-6 lg:p-10">
        {/* Mobile logo (visible on mobile only) */}
        <div className="mb-8 text-center lg:hidden">
          <div className="flex items-center justify-center gap-3 mb-2">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-foreground text-background">
              <Sparkles className="h-5 w-5" />
            </div>
          </div>
          <h1 className="text-2xl font-bold">BotFacebook</h1>
          <p className="text-sm text-muted-foreground">AI Chatbot Platform</p>
        </div>

        {/* Form container */}
        <div className="w-full max-w-sm">
          <Outlet />
        </div>
      </div>
    </div>
  );
}
