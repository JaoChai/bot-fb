import { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { AlertCircle } from 'lucide-react';

export default function Login() {
  const { data, setData, post, processing, errors, reset } = useForm({
    email: '',
    password: '',
    remember: false,
  });

  const submit: FormEventHandler = (e) => {
    e.preventDefault();
    post('/login', {
      onFinish: () => reset('password'),
    });
  };

  return (
    <GuestLayout>
      <Head title="เข้าสู่ระบบ" />

      <div className="space-y-6">
        {/* Header */}
        <div className="space-y-2 text-center">
          <h1 className="text-2xl font-semibold tracking-tight">เข้าสู่ระบบ</h1>
          <p className="text-sm text-muted-foreground">กรอกอีเมลและรหัสผ่านเพื่อเข้าสู่ระบบ</p>
        </div>

        {/* Form */}
        <form onSubmit={submit} className="space-y-4">
          {/* Global Error */}
          {errors.email && !data.email && (
            <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 shrink-0" />
              <span>เข้าสู่ระบบไม่สำเร็จ</span>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="email">อีเมล</Label>
            <Input
              id="email"
              type="email"
              placeholder="name@example.com"
              value={data.email}
              onChange={(e) => setData('email', e.target.value)}
              aria-invalid={!!errors.email}
              autoComplete="email"
              autoFocus
            />
            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">รหัสผ่าน</Label>
            <Input
              id="password"
              type="password"
              placeholder="รหัสผ่าน"
              value={data.password}
              onChange={(e) => setData('password', e.target.value)}
              aria-invalid={!!errors.password}
              autoComplete="current-password"
            />
            {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
          </div>

          <Button type="submit" className="w-full" disabled={processing}>
            {processing ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ'}
          </Button>
        </form>

        {/* Footer */}
        <p className="text-center text-sm text-muted-foreground">
          ยังไม่มีบัญชี?{' '}
          <Link href="/register" className="font-medium text-foreground underline-offset-4 hover:underline">
            สมัครสมาชิก
          </Link>
        </p>
      </div>
    </GuestLayout>
  );
}
