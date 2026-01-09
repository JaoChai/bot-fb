import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Slider } from '@/Components/ui/slider';
import { Textarea } from '@/Components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import {
  ArrowLeft,
  Save,
  Loader2,
  Brain,
  CheckCircle2,
  AlertCircle,
} from 'lucide-react';
import type { SharedProps, ChannelType } from '@/types';

interface Props extends SharedProps {
  bot: {
    id: number;
    name: string;
    channel_type: ChannelType;
    status: string;
    settings?: {
      ai_model?: string;
      temperature?: number;
      max_tokens?: number;
      system_prompt?: string;
    };
  };
  aiModels: Array<{
    id: string;
    name: string;
    provider: string;
  }>;
}

export default function AI() {
  const { bot, aiModels, flash } = usePage<Props>().props;

  const { data, setData, put, processing, errors } = useForm({
    settings: {
      ai_model: bot.settings?.ai_model || '',
      temperature: bot.settings?.temperature ?? 0.7,
      max_tokens: bot.settings?.max_tokens ?? 1024,
      system_prompt: bot.settings?.system_prompt || '',
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(`/bots/${bot.id}/settings/ai`);
  };

  return (
    <AuthenticatedLayout header="ตั้งค่า AI">
      <Head title={`ตั้งค่า AI - ${bot.name}`} />

      <div className="space-y-6 max-w-2xl mx-auto">
        {/* Back Button */}
        <Button variant="ghost" size="sm" asChild>
          <Link href={`/bots/${bot.id}`}>
            <ArrowLeft className="h-4 w-4 mr-2" />
            กลับไปตั้งค่าบอท
          </Link>
        </Button>

        {/* Flash Messages */}
        {flash?.success && (
          <div className="rounded-lg border border-green-200 bg-green-50 dark:bg-green-950 p-4 text-green-700 dark:text-green-300 flex items-center gap-2">
            <CheckCircle2 className="h-5 w-5" />
            {flash.success}
          </div>
        )}
        {flash?.error && (
          <div className="rounded-lg border border-red-200 bg-red-50 dark:bg-red-950 p-4 text-red-700 dark:text-red-300 flex items-center gap-2">
            <AlertCircle className="h-5 w-5" />
            {flash.error}
          </div>
        )}

        {/* Header */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-3">
              <div className="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                <Brain className="h-5 w-5 text-blue-600 dark:text-blue-400" />
              </div>
              <div className="flex-1">
                <CardTitle>ตั้งค่า AI</CardTitle>
                <CardDescription>
                  <div className="flex items-center gap-2 mt-1">
                    <ChannelIcon channel={bot.channel_type} className="h-4 w-4" />
                    <span>{bot.name}</span>
                  </div>
                </CardDescription>
              </div>
            </div>
          </CardHeader>
        </Card>

        {/* AI Settings Form */}
        <form onSubmit={handleSubmit}>
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">พารามิเตอร์ AI</CardTitle>
              <CardDescription>
                ปรับแต่งพฤติกรรมและการตอบสนองของ AI
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* AI Model */}
              <div className="space-y-2">
                <Label htmlFor="ai_model">โมเดล AI</Label>
                <Select
                  value={data.settings.ai_model}
                  onValueChange={(value) =>
                    setData('settings', { ...data.settings, ai_model: value })
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="เลือกโมเดล AI" />
                  </SelectTrigger>
                  <SelectContent>
                    {aiModels.map((model) => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name} ({model.provider})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  เลือกโมเดล AI ที่จะใช้ในการตอบสนอง
                </p>
                {errors['settings.ai_model'] && (
                  <p className="text-sm text-destructive">{errors['settings.ai_model']}</p>
                )}
              </div>

              {/* Temperature */}
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <Label htmlFor="temperature">Temperature</Label>
                  <span className="text-sm text-muted-foreground">
                    {data.settings.temperature.toFixed(1)}
                  </span>
                </div>
                <Slider
                  id="temperature"
                  value={[data.settings.temperature]}
                  onValueChange={([value]) =>
                    setData('settings', { ...data.settings, temperature: value })
                  }
                  min={0}
                  max={2}
                  step={0.1}
                  className="w-full"
                />
                <p className="text-xs text-muted-foreground">
                  ค่าต่ำ = ตอบตรงประเด็น, ค่าสูง = ตอบหลากหลายกว่า
                </p>
                {errors['settings.temperature'] && (
                  <p className="text-sm text-destructive">{errors['settings.temperature']}</p>
                )}
              </div>

              {/* Max Tokens */}
              <div className="space-y-2">
                <Label htmlFor="max_tokens">จำนวน Token สูงสุด</Label>
                <Input
                  id="max_tokens"
                  type="number"
                  value={data.settings.max_tokens}
                  onChange={(e) =>
                    setData('settings', {
                      ...data.settings,
                      max_tokens: parseInt(e.target.value) || 0,
                    })
                  }
                  min={1}
                  max={4096}
                />
                <p className="text-xs text-muted-foreground">
                  จำกัดความยาวของคำตอบ (1-4096)
                </p>
                {errors['settings.max_tokens'] && (
                  <p className="text-sm text-destructive">{errors['settings.max_tokens']}</p>
                )}
              </div>

              {/* System Prompt */}
              <div className="space-y-2">
                <Label htmlFor="system_prompt">System Prompt</Label>
                <Textarea
                  id="system_prompt"
                  value={data.settings.system_prompt}
                  onChange={(e) =>
                    setData('settings', { ...data.settings, system_prompt: e.target.value })
                  }
                  placeholder="กำหนดบุคลิกและบริบทของ AI..."
                  rows={6}
                />
                <p className="text-xs text-muted-foreground">
                  กำหนดบุคลิก บริบท และข้อจำกัดของ AI
                </p>
                {errors['settings.system_prompt'] && (
                  <p className="text-sm text-destructive">{errors['settings.system_prompt']}</p>
                )}
              </div>

              <div className="pt-4">
                <Button type="submit" disabled={processing}>
                  {processing ? (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  ) : (
                    <Save className="h-4 w-4 mr-2" />
                  )}
                  บันทึก
                </Button>
              </div>
            </CardContent>
          </Card>
        </form>
      </div>
    </AuthenticatedLayout>
  );
}
