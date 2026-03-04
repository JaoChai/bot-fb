import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ArrowLeft, MessageCircle, Check, ArrowRight, Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { LineIcon, MessengerIcon, TelegramIcon } from '@/components/ui/channel-icon';

export function AddConnectionPage() {
  const navigate = useNavigate();
  const [selectedPlatform, setSelectedPlatform] = useState<string | null>(null);

  const platforms = [
    {
      id: 'line',
      name: 'LINE OA',
      fullName: 'LINE Official Account',
      description: 'เชื่อมต่อกับ LINE Official Account',
      icon: <LineIcon className="h-10 w-10" />,
      bgColor: 'bg-[#06C755]/10',
      borderColor: 'border-[#06C755]',
      requirements: ['LINE Official Account (ฟรีหรือ Premium)', 'Channel ID และ Channel Secret', 'Channel Access Token'],
    },
    {
      id: 'facebook',
      name: 'Facebook',
      fullName: 'Facebook Page',
      description: 'เชื่อมต่อกับ Facebook Page',
      icon: <MessengerIcon className="h-10 w-10" />,
      bgColor: 'bg-[#0084FF]/10',
      borderColor: 'border-[#0084FF]',
      requirements: ['Facebook Page ที่เป็นเจ้าของ', 'สิทธิ์การเข้าถึง Page Access Token', 'การอนุมัติจาก Meta (สำหรับ Production)'],
    },
    {
      id: 'telegram',
      name: 'Telegram',
      fullName: 'Telegram Bot',
      description: 'เชื่อมต่อกับ Telegram Bot',
      icon: <TelegramIcon className="h-10 w-10" />,
      bgColor: 'bg-[#0088CC]/10',
      borderColor: 'border-[#0088CC]',
      requirements: ['Telegram Bot จาก @BotFather', 'Bot Token', 'ตั้งค่า Webhook URL'],
    },
    {
      id: 'testing',
      name: 'ทดสอบ',
      fullName: 'Just Testing',
      description: 'ทดสอบก่อนเชื่อม Platform จริง',
      icon: <MessageCircle className="h-10 w-10 text-slate-500" />,
      bgColor: 'bg-slate-100 dark:bg-slate-800',
      borderColor: 'border-slate-400',
      requirements: ['ไม่ต้องมี API Key หรือ Credentials', 'ทดสอบผ่าน Chat Simulator ในระบบ', 'เหมาะสำหรับพัฒนาและทดสอบ Flow'],
    },
  ];

  const handleContinue = () => {
    if (selectedPlatform) {
      navigate(`/connections/new?platform=${selectedPlatform}`);
    }
  };

  const selectedPlatformData = platforms.find(p => p.id === selectedPlatform);

  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-3xl mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex items-center gap-4 mb-8">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => navigate('/bots')}
            aria-label="กลับไปหน้าการเชื่อมต่อ"
          >
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">เพิ่มการเชื่อมต่อใหม่</h1>
            <p className="text-muted-foreground text-sm mt-1">เลือก Platform ที่ต้องการเชื่อมต่อกับ AI Chatbot</p>
          </div>
        </div>

        {/* Platform Selection - Responsive Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
          {platforms.map((platform) => (
            <button
              key={platform.id}
              onClick={() => setSelectedPlatform(platform.id)}
              className={cn(
                'relative flex flex-col items-center p-6 rounded-xl border-2 transition-all duration-200 cursor-pointer',
                'hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-foreground',
                selectedPlatform === platform.id
                  ? 'border-foreground bg-accent shadow-sm'
                  : 'border-border hover:border-muted-foreground bg-card'
              )}
            >
              {/* Selected Indicator */}
              {selectedPlatform === platform.id && (
                <div className="absolute top-3 right-3 w-6 h-6 bg-foreground rounded-full flex items-center justify-center">
                  <Check className="h-4 w-4 text-background" />
                </div>
              )}

              {/* Icon */}
              <div className="w-16 h-16 rounded-xl flex items-center justify-center mb-3 bg-muted">
                {platform.icon}
              </div>

              {/* Name */}
              <span className="font-semibold text-base">{platform.name}</span>
              <span className="text-xs text-muted-foreground mt-1">{platform.description}</span>
            </button>
          ))}
        </div>

        {/* Requirements Info Panel */}
        {selectedPlatformData && (
          <Card className="mb-6 border-l-4 border-l-foreground animate-in fade-in slide-in-from-top-2 duration-200">
            <CardContent className="p-5">
              <div className="flex items-start gap-3">
                <div className="flex-shrink-0 w-10 h-10 bg-muted rounded-lg flex items-center justify-center">
                  <Info className="h-5 w-5 text-foreground" />
                </div>
                <div>
                  <h3 className="font-semibold mb-2">สิ่งที่ต้องมีสำหรับ {selectedPlatformData.fullName}</h3>
                  <ul className="space-y-1.5">
                    {selectedPlatformData.requirements.map((req, index) => (
                      <li key={index} className="flex items-center gap-2 text-sm text-muted-foreground">
                        <span className="w-1.5 h-1.5 bg-foreground rounded-full flex-shrink-0" />
                        {req}
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Continue Button */}
        <div className="flex justify-end">
          <Button
            size="lg"
            onClick={handleContinue}
            disabled={!selectedPlatform}
            className="min-w-[180px]"
          >
            ถัดไป
            <ArrowRight className="h-4 w-4 ml-2" />
          </Button>
        </div>

        {/* Help Text */}
        {!selectedPlatform && (
          <p className="text-center text-sm text-muted-foreground mt-8">
            เลือก Platform ด้านบนเพื่อดำเนินการต่อ
          </p>
        )}
      </div>
    </div>
  );
}
