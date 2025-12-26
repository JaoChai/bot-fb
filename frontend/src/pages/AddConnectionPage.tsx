import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, MessageCircle } from 'lucide-react';

// LINE icon component
function LineIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      className={className}
      fill="#06C755"
    >
      <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63.349 0 .631.285.631.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
    </svg>
  );
}

// Facebook Messenger icon component
function MessengerIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      className={className}
      fill="#0084FF"
    >
      <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.497 1.745 6.616 4.472 8.652V24l4.086-2.242c1.09.301 2.246.464 3.442.464 6.627 0 12-4.974 12-11.111C24 4.974 18.627 0 12 0zm1.193 14.963l-3.056-3.259-5.963 3.259 6.559-6.963 3.13 3.259 5.889-3.259-6.559 6.963z"/>
    </svg>
  );
}

export function AddConnectionPage() {
  const navigate = useNavigate();

  const platforms = [
    {
      id: 'facebook',
      name: 'Facebook Page',
      description: 'เชื่อมต่อ Facebook Page ของคุณ',
      icon: <MessengerIcon className="h-12 w-12" />,
      color: 'text-blue-600',
    },
    {
      id: 'line',
      name: 'LINE Official Account',
      description: 'เชื่อมต่อ LINE OA ของคุณ',
      icon: <LineIcon className="h-12 w-12" />,
      color: 'text-green-600',
    },
    {
      id: 'testing',
      name: 'Just Testing',
      description: 'ทดสอบบอทโดยไม่เชื่อม Platform',
      icon: <MessageCircle className="h-12 w-12 text-muted-foreground" />,
      color: 'text-muted-foreground',
    },
  ];

  const handleSelectPlatform = (platformId: string) => {
    // TODO: Navigate to edit connection page with platform preset
    // For now, just show a notification that this will be implemented in next step
    console.log('Selected platform:', platformId);
    // This will be updated in Issue #54 (Edit Connection Page)
    // navigate(`/bots/new?platform=${platformId}`);
    alert(`เลือก ${platformId} แล้ว (จะสมบูรณ์ใน Issue #54)`);
  };

  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-6xl mx-auto px-4 py-8">
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
            <h1 className="text-3xl font-bold tracking-tight">เพิ่มการเชื่อมต่อใหม่</h1>
            <p className="text-muted-foreground mt-1">เลือก Platform ที่คุณต้องการเชื่อมต่อ</p>
          </div>
        </div>

        {/* Platform Selection Cards */}
        <div className="grid gap-6 md:grid-cols-3 sm:grid-cols-1">
          {platforms.map((platform) => (
            <Card
              key={platform.id}
              className="cursor-pointer transition-all duration-200 hover:border-amber-500 hover:shadow-lg"
              onClick={() => handleSelectPlatform(platform.id)}
            >
              <CardHeader className="text-center pb-2">
                <div className={`flex justify-center mb-4 ${platform.color}`}>
                  {platform.icon}
                </div>
                <CardTitle className="text-lg">{platform.name}</CardTitle>
              </CardHeader>
              <CardContent className="text-center">
                <p className="text-sm text-muted-foreground mb-6">{platform.description}</p>
                <Button
                  variant="orange"
                  className="w-full"
                  onClick={(e) => {
                    e.stopPropagation();
                    handleSelectPlatform(platform.id);
                  }}
                >
                  เลือก {platform.name}
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Additional Info */}
        <div className="mt-12 p-6 bg-card border rounded-lg">
          <h3 className="font-semibold mb-3">💡 คำแนะนำ</h3>
          <ul className="space-y-2 text-sm text-muted-foreground">
            <li>• <strong>Facebook Page</strong>: ต้องมี Facebook Page และการอนุมัติจาก Meta</li>
            <li>• <strong>LINE Official Account</strong>: ต้องมี LINE OA และ Channel ID & Access Token</li>
            <li>• <strong>Just Testing</strong>: ใช้สำหรับทดสอบ โดยไม่ต้องเชื่อมต่อ Platform จริง</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
