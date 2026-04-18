import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { MessageCircle, Send, Facebook, TestTube, ArrowRight, ArrowLeft, Info, Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PageHeader } from '@/components/connections';

const PLATFORMS = [
  {
    id: 'line',
    name: 'LINE Official Account',
    description: 'เชื่อมต่อกับลูกค้าผ่าน LINE OA — ต้องมี Channel จาก LINE Developers Console',
    icon: MessageCircle,
    accentClass: 'border-l-emerald-500',
    iconTone: 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-900',
    requirements: [
      'LINE Official Account (ฟรีหรือ Premium)',
      'Channel ID และ Channel Secret',
      'Channel Access Token',
    ],
  },
  {
    id: 'telegram',
    name: 'Telegram Bot',
    description: 'สร้าง Bot ผ่าน @BotFather บน Telegram',
    icon: Send,
    accentClass: 'border-l-sky-500',
    iconTone: 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-400 border-sky-200 dark:border-sky-900',
    requirements: [
      'Telegram Bot จาก @BotFather',
      'Bot Token',
      'ตั้งค่า Webhook URL',
    ],
  },
  {
    id: 'facebook',
    name: 'Facebook Page',
    description: 'เชื่อมต่อกับ Facebook Page Messenger',
    icon: Facebook,
    accentClass: 'border-l-blue-500',
    iconTone: 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-900',
    requirements: [
      'Facebook Page ที่เป็นเจ้าของ',
      'สิทธิ์การเข้าถึง Page Access Token',
      'การอนุมัติจาก Meta (สำหรับ Production)',
    ],
  },
  {
    id: 'testing',
    name: 'Just Testing',
    description: 'ทดลองใช้ภายในก่อน — ไม่ต้องเชื่อมต่อแพลตฟอร์มจริง',
    icon: TestTube,
    accentClass: 'border-l-muted-foreground/40',
    iconTone: 'bg-muted text-muted-foreground border-border',
    requirements: [
      'ไม่ต้องมี API Key หรือ Credentials',
      'ทดสอบผ่าน Chat Simulator ในระบบ',
      'เหมาะสำหรับพัฒนาและทดสอบ Flow',
    ],
  },
];

export function AddConnectionPage() {
  const navigate = useNavigate();
  const [selectedPlatform, setSelectedPlatform] = useState<string | null>(null);

  const handleContinue = () => {
    if (selectedPlatform) {
      navigate(`/connections/new?platform=${selectedPlatform}`);
    }
  };

  const selectedPlatformData = PLATFORMS.find(p => p.id === selectedPlatform);

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <PageHeader
        title="เพิ่มการเชื่อมต่อใหม่"
        description="เลือก Platform ที่ต้องการเชื่อมต่อกับ AI Chatbot"
        backTo="/bots"
      />

      {!selectedPlatform ? (
        <>
          {/* Step 1 indicator */}
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-primary text-primary-foreground text-xs font-semibold">1</span>
            <span className="font-medium text-foreground">เลือกแพลตฟอร์ม</span>
            <div className="h-px flex-1 bg-border max-w-[80px]" />
            <span className="inline-flex h-6 w-6 items-center justify-center rounded-full border text-xs font-semibold text-muted-foreground">2</span>
            <span>ตั้งค่าการเชื่อมต่อ</span>
          </div>

          {/* Platform grid */}
          <div className="grid gap-3 md:grid-cols-2">
            {PLATFORMS.map((platform) => (
              <button
                key={platform.id}
                onClick={() => setSelectedPlatform(platform.id)}
                className={cn(
                  'group relative flex gap-4 rounded-lg border border-l-2 bg-card p-4 text-left transition-colors',
                  platform.accentClass,
                  'hover:border-primary/40 hover:bg-muted/30',
                  'focus:outline-none focus-visible:ring-2 focus-visible:ring-foreground focus-visible:ring-offset-2',
                )}
              >
                <div className={cn('flex h-10 w-10 items-center justify-center rounded-md border shrink-0', platform.iconTone)}>
                  <platform.icon className="h-5 w-5" strokeWidth={1.75} />
                </div>
                <div className="flex-1 min-w-0">
                  <h3 className="font-medium text-sm">{platform.name}</h3>
                  <p className="mt-1 text-xs text-muted-foreground leading-relaxed">{platform.description}</p>
                </div>
                <ArrowRight className="h-4 w-4 text-muted-foreground shrink-0 opacity-0 group-hover:opacity-100 transition-opacity self-center" strokeWidth={1.5} />
              </button>
            ))}
          </div>
        </>
      ) : (
        <>
          {/* Step 2 indicator + change platform */}
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <span className="inline-flex h-6 w-6 items-center justify-center rounded-full border text-xs font-semibold text-muted-foreground">✓</span>
              <span>{selectedPlatformData?.name}</span>
              <div className="h-px flex-1 bg-border max-w-[80px]" />
              <span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-primary text-primary-foreground text-xs font-semibold">2</span>
              <span className="font-medium text-foreground">ตั้งค่าการเชื่อมต่อ</span>
            </div>
            <Button variant="ghost" size="sm" onClick={() => setSelectedPlatform(null)}>
              <ArrowLeft className="h-4 w-4 mr-1" strokeWidth={1.5} />
              เปลี่ยนแพลตฟอร์ม
            </Button>
          </div>

          {/* Requirements panel */}
          {selectedPlatformData && (
            <div className="rounded-xl border border-border bg-card p-5 animate-in fade-in slide-in-from-top-1 duration-150">
              <div className="flex items-start gap-3">
                <span className="shrink-0 w-8 h-8 rounded-md bg-muted/60 flex items-center justify-center">
                  <Info className="h-4 w-4 text-foreground" strokeWidth={1.5} />
                </span>
                <div className="min-w-0">
                  <p className="font-medium text-sm mb-2">
                    สิ่งที่ต้องมีสำหรับ {selectedPlatformData.name}
                  </p>
                  <ul className="space-y-1.5">
                    {selectedPlatformData.requirements.map((req, index) => (
                      <li key={index} className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Check className="h-3.5 w-3.5 text-foreground/40 shrink-0" strokeWidth={1.5} />
                        {req}
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </div>
          )}

          {/* Continue action */}
          <div className="flex justify-end">
            <Button
              size="lg"
              onClick={handleContinue}
              className="w-full sm:w-auto min-w-[160px]"
            >
              ถัดไป
              <ArrowRight className="h-4 w-4 ml-2" strokeWidth={1.5} />
            </Button>
          </div>
        </>
      )}
    </div>
  );
}
