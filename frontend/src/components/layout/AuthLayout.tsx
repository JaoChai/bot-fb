import { Outlet } from 'react-router';
import { Sparkles, CheckCircle2 } from 'lucide-react';

const features = [
  'เชื่อมต่อ LINE Official & Telegram ใน 5 นาที',
  'ฐานความรู้ RAG ที่ AI ใช้ตอบลูกค้าอย่างแม่นยำ',
  'แดชบอร์ดยอดขาย + ต้นทุน API แบบเรียลไทม์',
  'จัดการทีม Admin + VIP อัตโนมัติ',
];

export function AuthLayout() {
  return (
    <div className="min-h-screen lg:grid lg:grid-cols-2">
      {/* Left side - Branding (hidden on mobile) */}
      <div className="hidden lg:flex flex-col justify-between bg-foreground p-10 text-background">
        {/* Logo */}
        <div className="flex items-center gap-3">
          <div className="flex h-9 w-9 items-center justify-center rounded-md bg-background/10 border border-background/20">
            <Sparkles className="h-4 w-4" strokeWidth={2} />
          </div>
          <span className="text-lg font-semibold tracking-tight">BotJao</span>
        </div>

        {/* Value prop */}
        <div className="space-y-8">
          <div className="space-y-3">
            <h2 className="text-3xl font-semibold tracking-tight leading-tight">
              AI Chatbot
              <br />
              สำหรับธุรกิจไทย
            </h2>
            <p className="text-base text-background/70 leading-relaxed max-w-md">
              จัดการแชท LINE และ Telegram ด้วย AI ที่เรียนรู้จากฐานความรู้ของคุณ ตอบลูกค้าได้ 24 ชั่วโมง
            </p>
          </div>

          <ul className="space-y-3 text-sm text-background/80">
            {features.map((f) => (
              <li key={f} className="flex items-start gap-2">
                <CheckCircle2
                  className="h-4 w-4 text-background/60 mt-0.5 shrink-0"
                  strokeWidth={1.5}
                />
                <span>{f}</span>
              </li>
            ))}
          </ul>
        </div>

        {/* Footer */}
        <p className="text-xs text-background/50">
          © {new Date().getFullYear()} BotJao · AI Chatbot Platform
        </p>
      </div>

      {/* Right side - Form */}
      <div className="flex flex-col items-center justify-center p-6 lg:p-10">
        {/* Mobile logo */}
        <div className="mb-8 text-center lg:hidden">
          <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary border border-primary/20">
            <Sparkles className="h-5 w-5" strokeWidth={2} />
          </div>
          <h1 className="text-xl font-semibold tracking-tight">BotJao</h1>
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
