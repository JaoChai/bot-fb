import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ArrowLeft, MessageCircle, Check, ArrowRight, Info } from 'lucide-react';
import { cn } from '@/lib/utils';

// LINE icon component - uses CSS variable for color
function LineIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      className={cn(className, 'fill-[#06C755]')}
    >
      <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63.349 0 .631.285.631.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
    </svg>
  );
}

// Facebook Messenger icon component - uses CSS variable for color
function MessengerIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      className={cn(className, 'fill-[#0084FF]')}
    >
      <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.497 1.745 6.616 4.472 8.652V24l4.086-2.242c1.09.301 2.246.464 3.442.464 6.627 0 12-4.974 12-11.111C24 4.974 18.627 0 12 0zm1.193 14.963l-3.056-3.259-5.963 3.259 6.559-6.963 3.13 3.259 5.889-3.259-6.559 6.963z"/>
    </svg>
  );
}

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
