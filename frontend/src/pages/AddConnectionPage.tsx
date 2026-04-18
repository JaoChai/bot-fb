import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { MessageCircle, Check, ArrowRight, Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { LineIcon, MessengerIcon, TelegramIcon } from '@/components/ui/channel-icon';
import { PageHeader } from '@/components/connections';

export function AddConnectionPage() {
  const navigate = useNavigate();
  const [selectedPlatform, setSelectedPlatform] = useState<string | null>(null);

  const platforms = [
    {
      id: 'line',
      name: 'LINE OA',
      fullName: 'LINE Official Account',
      description: 'เชื่อมต่อกับ LINE Official Account',
      icon: <LineIcon className="h-7 w-7" />,
      bgColor: 'bg-[#06C755]/10',
      requirements: [
        'LINE Official Account (ฟรีหรือ Premium)',
        'Channel ID และ Channel Secret',
        'Channel Access Token',
      ],
    },
    {
      id: 'facebook',
      name: 'Facebook',
      fullName: 'Facebook Page',
      description: 'เชื่อมต่อกับ Facebook Page',
      icon: <MessengerIcon className="h-7 w-7" />,
      bgColor: 'bg-[#0084FF]/10',
      requirements: [
        'Facebook Page ที่เป็นเจ้าของ',
        'สิทธิ์การเข้าถึง Page Access Token',
        'การอนุมัติจาก Meta (สำหรับ Production)',
      ],
    },
    {
      id: 'telegram',
      name: 'Telegram',
      fullName: 'Telegram Bot',
      description: 'เชื่อมต่อกับ Telegram Bot',
      icon: <TelegramIcon className="h-7 w-7" />,
      bgColor: 'bg-[#0088CC]/10',
      requirements: [
        'Telegram Bot จาก @BotFather',
        'Bot Token',
        'ตั้งค่า Webhook URL',
      ],
    },
    {
      id: 'testing',
      name: 'ทดสอบ',
      fullName: 'Just Testing',
      description: 'ทดสอบก่อนเชื่อม Platform จริง',
      icon: <MessageCircle className="h-7 w-7 text-muted-foreground" />,
      bgColor: 'bg-muted/60',
      requirements: [
        'ไม่ต้องมี API Key หรือ Credentials',
        'ทดสอบผ่าน Chat Simulator ในระบบ',
        'เหมาะสำหรับพัฒนาและทดสอบ Flow',
      ],
    },
  ];

  const handleContinue = () => {
    if (selectedPlatform) {
      navigate(`/connections/new?platform=${selectedPlatform}`);
    }
  };

  const selectedPlatformData = platforms.find(p => p.id === selectedPlatform);

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <PageHeader
        title="เพิ่มการเชื่อมต่อใหม่"
        description="เลือก Platform ที่ต้องการเชื่อมต่อกับ AI Chatbot"
        backTo="/bots"
      />

      {/* Platform Grid — 2x2 on mobile, 4 columns on lg */}
      <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {platforms.map((platform) => (
          <button
            key={platform.id}
            onClick={() => setSelectedPlatform(platform.id)}
            className={cn(
              'relative flex flex-col items-center gap-2.5 p-5 rounded-xl border',
              'transition-all duration-150 cursor-pointer min-h-[10rem]',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-foreground focus-visible:ring-offset-2',
              selectedPlatform === platform.id
                ? 'ring-2 ring-foreground border-foreground bg-accent/40'
                : 'border-border bg-card hover:border-foreground/30 hover:bg-accent/40'
            )}
          >
            {/* Selected indicator */}
            {selectedPlatform === platform.id && (
              <span className="absolute top-2.5 right-2.5 w-5 h-5 bg-foreground rounded-full flex items-center justify-center">
                <Check className="h-3 w-3 text-background" />
              </span>
            )}

            {/* Icon container */}
            <span className={cn('w-12 h-12 rounded-lg flex items-center justify-center', platform.bgColor)}>
              {platform.icon}
            </span>

            {/* Text */}
            <span className="font-semibold text-sm leading-tight">{platform.name}</span>
            <span className="text-xs text-muted-foreground text-center leading-snug">{platform.description}</span>
          </button>
        ))}
      </div>

      {/* Requirements panel */}
      {selectedPlatformData && (
        <div className="rounded-xl border border-border bg-card p-5 animate-in fade-in slide-in-from-top-1 duration-150">
          <div className="flex items-start gap-3">
            <span className="shrink-0 w-8 h-8 rounded-md bg-muted/60 flex items-center justify-center">
              <Info className="h-4 w-4 text-foreground" />
            </span>
            <div className="min-w-0">
              <p className="font-medium text-sm mb-2">
                สิ่งที่ต้องมีสำหรับ {selectedPlatformData.fullName}
              </p>
              <ul className="space-y-1.5">
                {selectedPlatformData.requirements.map((req, index) => (
                  <li key={index} className="flex items-center gap-2 text-sm text-muted-foreground">
                    <span className="w-1.5 h-1.5 rounded-full bg-foreground/40 shrink-0" />
                    {req}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      )}

      {/* Continue action */}
      <div className="flex flex-col sm:flex-row sm:justify-end gap-2">
        {!selectedPlatform && (
          <p className="text-sm text-muted-foreground sm:self-center">
            เลือก Platform ด้านบนเพื่อดำเนินการต่อ
          </p>
        )}
        <Button
          size="lg"
          onClick={handleContinue}
          disabled={!selectedPlatform}
          className="w-full sm:w-auto min-w-[160px]"
        >
          ถัดไป
          <ArrowRight className="h-4 w-4 ml-2" />
        </Button>
      </div>
    </div>
  );
}
