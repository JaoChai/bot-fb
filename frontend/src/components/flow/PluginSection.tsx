import { useState, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import { apiGet, apiPost, apiPut, apiDelete, getErrorMessage } from '@/lib/api';
import {
  Send,
  Plus,
  Trash2,
  Pencil,
  Zap,
  Loader2,
} from 'lucide-react';

// --- Types ---

interface FlowPluginConfig {
  access_token: string;
  chat_id: string;
  message_template: string;
  trigger_keywords?: string[];
}

interface FlowPlugin {
  id: number;
  type: 'telegram';
  name: string | null;
  enabled: boolean;
  trigger_condition: string;
  config: FlowPluginConfig;
}

interface PluginSectionProps {
  botId: string;
  flowId: number | null;
}

// --- Empty form state ---

const EMPTY_FORM: Omit<FlowPlugin, 'id'> = {
  type: 'telegram',
  name: null,
  enabled: true,
  trigger_condition: '',
  config: {
    access_token: '',
    chat_id: '',
    message_template: '',
    trigger_keywords: [],
  },
};

// --- Component ---

export function PluginSection({ botId, flowId }: PluginSectionProps) {
  const { toast } = useToast();

  // Plugin list state
  const [plugins, setPlugins] = useState<FlowPlugin[]>([]);
  const [isLoading, setIsLoading] = useState(false);

  // Dialog state
  const [showTypeDialog, setShowTypeDialog] = useState(false);
  const [showConfigDialog, setShowConfigDialog] = useState(false);
  const [editingPlugin, setEditingPlugin] = useState<FlowPlugin | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  // Form state
  const [form, setForm] = useState<Omit<FlowPlugin, 'id'>>(EMPTY_FORM);

  // --- Fetch plugins ---

  const fetchPlugins = useCallback(async () => {
    if (!flowId) {
      setPlugins([]);
      return;
    }
    setIsLoading(true);
    try {
      const response = await apiGet<{ data: FlowPlugin[] }>(
        `/bots/${botId}/flows/${flowId}/plugins`
      );
      setPlugins(response.data);
    } catch {
      // Silently fail on fetch - plugins section is optional
      setPlugins([]);
    } finally {
      setIsLoading(false);
    }
  }, [botId, flowId]);

  useEffect(() => {
    fetchPlugins();
  }, [fetchPlugins]);

  // --- Handlers ---

  const handleSelectType = () => {
    // Only Telegram is available for now
    setShowTypeDialog(false);
    setEditingPlugin(null);
    setForm({ ...EMPTY_FORM });
    setShowConfigDialog(true);
  };

  const handleEdit = (plugin: FlowPlugin) => {
    setEditingPlugin(plugin);
    setForm({
      type: plugin.type,
      name: plugin.name,
      enabled: plugin.enabled,
      trigger_condition: plugin.trigger_condition,
      config: { ...plugin.config },
    });
    setShowConfigDialog(true);
  };

  const handleToggleEnabled = async (plugin: FlowPlugin) => {
    if (!flowId) return;
    try {
      await apiPut(`/bots/${botId}/flows/${flowId}/plugins/${plugin.id}`, {
        ...plugin,
        enabled: !plugin.enabled,
      });
      setPlugins((prev) =>
        prev.map((p) =>
          p.id === plugin.id ? { ...p, enabled: !p.enabled } : p
        )
      );
    } catch (err) {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: getErrorMessage(err),
        variant: 'destructive',
      });
    }
  };

  const handleDelete = async (pluginId: number) => {
    if (!flowId) return;
    try {
      await apiDelete(`/bots/${botId}/flows/${flowId}/plugins/${pluginId}`);
      setPlugins((prev) => prev.filter((p) => p.id !== pluginId));
      toast({ title: 'ลบปลั๊กอินเรียบร้อย' });
    } catch (err) {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: getErrorMessage(err),
        variant: 'destructive',
      });
    }
  };

  const handleSave = async () => {
    if (!flowId) {
      toast({
        title: 'กรุณาบันทึก Flow ก่อน',
        description: 'ต้องบันทึก Flow ก่อนจึงจะเพิ่มปลั๊กอินได้',
        variant: 'destructive',
      });
      return;
    }

    // Validate required fields
    if (!form.trigger_condition.trim()) {
      toast({
        title: 'กรุณากรอกเงื่อนไขการทำงาน',
        variant: 'destructive',
      });
      return;
    }
    if (!form.config.access_token.trim()) {
      toast({
        title: 'กรุณากรอก Access Token',
        variant: 'destructive',
      });
      return;
    }
    if (!form.config.chat_id.trim()) {
      toast({
        title: 'กรุณากรอก User ID หรือ Group ID',
        variant: 'destructive',
      });
      return;
    }
    if (!form.config.message_template.trim()) {
      toast({
        title: 'กรุณากรอกข้อความที่ต้องการส่ง',
        variant: 'destructive',
      });
      return;
    }

    setIsSaving(true);
    try {
      if (editingPlugin) {
        // Update
        const response = await apiPut<{ data: FlowPlugin }>(
          `/bots/${botId}/flows/${flowId}/plugins/${editingPlugin.id}`,
          form
        );
        setPlugins((prev) =>
          prev.map((p) => (p.id === editingPlugin.id ? response.data : p))
        );
        toast({ title: 'บันทึกปลั๊กอินเรียบร้อย' });
      } else {
        // Create
        const response = await apiPost<{ data: FlowPlugin }>(
          `/bots/${botId}/flows/${flowId}/plugins`,
          form
        );
        setPlugins((prev) => [...prev, response.data]);
        toast({ title: 'เพิ่มปลั๊กอินเรียบร้อย' });
      }
      setShowConfigDialog(false);
      setEditingPlugin(null);
    } catch (err) {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: getErrorMessage(err),
        variant: 'destructive',
      });
    } finally {
      setIsSaving(false);
    }
  };

  // --- Render ---

  return (
    <>
      <div className="border rounded-lg p-6">
        <div className="flex items-center justify-between mb-4">
          <div>
            <Label className="font-medium flex items-center gap-2">
              <Zap className="h-4 w-4" />
              Plugins
            </Label>
            <p className="text-sm text-muted-foreground mt-1">
              เพิ่มฟังก์ชันเพิ่มเติมให้ AI ผ่าน plugins
            </p>
          </div>
        </div>

        {isLoading ? (
          <div className="flex justify-center py-8">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : plugins.length === 0 ? (
          <div className="border-2 border-dashed rounded-lg p-6 text-center">
            <Plus className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
            <p className="text-sm text-muted-foreground mb-3">
              {flowId ? 'ยังไม่มี plugins' : 'บันทึก Flow ก่อนเพิ่ม plugins'}
            </p>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setShowTypeDialog(true)}
              disabled={!flowId}
            >
              <Plus className="h-4 w-4 mr-2" />
              เพิ่มปลั๊กอิน
            </Button>
          </div>
        ) : (
          <div className="space-y-2">
            {plugins.map((plugin) => (
              <div
                key={plugin.id}
                className="flex items-center justify-between p-3 border rounded-lg bg-muted/30"
              >
                <div className="flex items-center gap-3 min-w-0 flex-1">
                  <Send className="h-4 w-4 text-blue-500 flex-shrink-0" />
                  <div className="min-w-0">
                    <span className="text-sm font-medium block truncate">
                      {plugin.name || 'Telegram Notification'}
                    </span>
                    <span className="text-xs text-muted-foreground block truncate">
                      {plugin.trigger_condition}
                    </span>
                  </div>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <Switch
                    checked={plugin.enabled}
                    onCheckedChange={() => handleToggleEnabled(plugin)}
                  />
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 p-0"
                    onClick={() => handleEdit(plugin)}
                  >
                    <Pencil className="h-3.5 w-3.5" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 p-0 text-destructive hover:text-destructive/80"
                    onClick={() => handleDelete(plugin.id)}
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </Button>
                </div>
              </div>
            ))}
            <Button
              variant="outline"
              size="sm"
              className="w-full mt-3"
              onClick={() => setShowTypeDialog(true)}
            >
              <Plus className="h-4 w-4 mr-2" />
              เพิ่มปลั๊กอิน
            </Button>
          </div>
        )}
      </div>

      {/* Type Selection Dialog */}
      <Dialog open={showTypeDialog} onOpenChange={setShowTypeDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>เลือกประเภทปลั๊กอิน</DialogTitle>
            <DialogDescription>
              เลือกปลั๊กอินที่ต้องการเพิ่มให้กับ Flow นี้
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <button
              onClick={handleSelectType}
              className="w-full flex items-start gap-3 p-4 border rounded-lg hover:bg-muted/50 transition-colors text-left"
            >
              <div className="p-2 bg-blue-500/10 rounded">
                <Send className="h-5 w-5 text-blue-500" />
              </div>
              <div>
                <span className="text-sm font-medium block">
                  Telegram Notification
                </span>
                <span className="text-xs text-muted-foreground">
                  ส่งแจ้งเตือนผ่าน Telegram
                </span>
              </div>
            </button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Plugin Config Dialog */}
      <Dialog open={showConfigDialog} onOpenChange={setShowConfigDialog}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {editingPlugin
                ? 'แก้ไข Telegram Notification'
                : 'เพิ่ม Telegram Notification'}
            </DialogTitle>
            <DialogDescription>
              ตั้งค่าการแจ้งเตือนผ่าน Telegram เมื่อเข้าเงื่อนไขที่กำหนด
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {/* Name */}
            <div className="space-y-2">
              <Label htmlFor="plugin-name">ชื่อเรียก</Label>
              <Input
                id="plugin-name"
                placeholder="เช่น แจ้งเตือนออเดอร์ใหม่"
                value={form.name ?? ''}
                onChange={(e) =>
                  setForm((prev) => ({
                    ...prev,
                    name: e.target.value || null,
                  }))
                }
              />
            </div>

            {/* Trigger Condition */}
            <div className="space-y-2">
              <Label htmlFor="plugin-trigger">เงื่อนไขการทำงาน *</Label>
              <Textarea
                id="plugin-trigger"
                placeholder="เช่น เมื่อลูกค้าสั่งซื้อสินค้าใหม่"
                value={form.trigger_condition}
                onChange={(e) =>
                  setForm((prev) => ({
                    ...prev,
                    trigger_condition: e.target.value,
                  }))
                }
                className="min-h-[80px]"
              />
              <p className="text-xs text-muted-foreground">
                อธิบายเงื่อนไขเป็นภาษาธรรมชาติ AI
                จะประเมินจากบทสนทนาว่าเข้าเงื่อนไขหรือไม่
              </p>
            </div>

            {/* Trigger Keywords */}
            <div className="space-y-2">
              <Label htmlFor="plugin-keywords">
                คำสำคัญ (Keyword Pre-filter)
              </Label>
              <Input
                id="plugin-keywords"
                placeholder="เช่น ยืนยัน, เข้าบัญชี, จัดส่ง"
                value={(form.config.trigger_keywords ?? []).join(', ')}
                onChange={(e) => {
                  const keywords = e.target.value
                    .split(',')
                    .map((k) => k.trim())
                    .filter(Boolean);
                  setForm((prev) => ({
                    ...prev,
                    config: { ...prev.config, trigger_keywords: keywords },
                  }));
                }}
              />
              <p className="text-xs text-muted-foreground">
                คั่นด้วยคอมม่า
                ระบบจะเรียก AI ประเมินเฉพาะเมื่อข้อความ bot
                มีคำเหล่านี้เท่านั้น (ประหยัดค่าใช้จ่าย)
                ถ้าเว้นว่างจะประเมินทุกข้อความ
              </p>
            </div>

            {/* Access Token */}
            <div className="space-y-2">
              <Label htmlFor="plugin-token">Access Token *</Label>
              <Input
                id="plugin-token"
                placeholder="Telegram Bot Access Token"
                value={form.config.access_token}
                onChange={(e) =>
                  setForm((prev) => ({
                    ...prev,
                    config: { ...prev.config, access_token: e.target.value },
                  }))
                }
              />
            </div>

            {/* Chat ID */}
            <div className="space-y-2">
              <Label htmlFor="plugin-chat-id">User ID หรือ Group ID *</Label>
              <Input
                id="plugin-chat-id"
                placeholder="-100xxxx หรือ 123456789"
                value={form.config.chat_id}
                onChange={(e) =>
                  setForm((prev) => ({
                    ...prev,
                    config: { ...prev.config, chat_id: e.target.value },
                  }))
                }
              />
            </div>

            {/* Message Template */}
            <div className="space-y-2">
              <Label htmlFor="plugin-message">ข้อความที่ต้องการส่ง *</Label>
              <Textarea
                id="plugin-message"
                placeholder={
                  'มีออเดอร์ใหม่! ชื่อลูกค้า: {customer_name} สินค้า: {product_name}'
                }
                value={form.config.message_template}
                onChange={(e) =>
                  setForm((prev) => ({
                    ...prev,
                    config: {
                      ...prev.config,
                      message_template: e.target.value,
                    },
                  }))
                }
                className="min-h-[80px]"
              />
              <p className="text-xs text-muted-foreground">
                สามารถใช้ตัวแปรในรูปแบบ {'{ชื่อตัวแปร}'}{' '}
                เพื่อแทรกข้อมูลที่ดึงจากข้อความสนทนา
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setShowConfigDialog(false)}
            >
              ยกเลิก
            </Button>
            <Button onClick={handleSave} disabled={isSaving}>
              {isSaving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
              {editingPlugin ? 'บันทึก' : 'เพิ่มปลั๊กอิน'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
