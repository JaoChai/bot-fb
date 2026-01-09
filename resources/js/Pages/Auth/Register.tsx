import { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { AlertCircle } from 'lucide-react';

export default function Register() {
  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });

  const submit: FormEventHandler = (e) => {
    e.preventDefault();
    post('/register', {
      onFinish: () => reset('password', 'password_confirmation'),
    });
  };

  return (
    <GuestLayout>
      <Head title="สมัครสมาชิก" />

      <div className="space-y-6">
        {/* Header */}
        <div className="space-y-2 text-center">
          <h1 className="text-2xl font-semibold tracking-tight">สร้างบัญชีใหม่</h1>
          <p className="text-sm text-muted-foreground">กรอกข้อมูลด้านล่างเพื่อสร้างบัญชี</p>
        </div>

        {/* Form */}
        <form onSubmit={submit} className="space-y-4">
          {/* Global Error */}
          {Object.keys(errors).length > 0 && !errors.name && !errors.email && !errors.password && (
            <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 shrink-0" />
              <span>สมัครสมาชิกไม่สำเร็จ</span>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="name">ชื่อ</Label>
            <Input
              id="name"
              type="text"
              placeholder="ชื่อของคุณ"
              value={data.name}
              onChange={(e) => setData('name', e.target.value)}
              aria-invalid={!!errors.name}
              autoComplete="name"
              autoFocus
            />
            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
          </div>

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
              autoComplete="new-password"
            />
            {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
          </div>

          <div className="space-y-2">
            <Label htmlFor="password_confirmation">ยืนยันรหัสผ่าน</Label>
            <Input
              id="password_confirmation"
              type="password"
              placeholder="ยืนยันรหัสผ่าน"
              value={data.password_confirmation}
              onChange={(e) => setData('password_confirmation', e.target.value)}
              aria-invalid={!!errors.password_confirmation}
              autoComplete="new-password"
            />
            {errors.password_confirmation && (
              <p className="text-sm text-destructive">{errors.password_confirmation}</p>
            )}
          </div>

          <Button type="submit" className="w-full" disabled={processing}>
            {processing ? 'กำลังสร้างบัญชี...' : 'สร้างบัญชี'}
          </Button>
        </form>

        {/* Footer */}
        <p className="text-center text-sm text-muted-foreground">
          มีบัญชีอยู่แล้ว?{' '}
          <Link href="/login" className="font-medium text-foreground underline-offset-4 hover:underline">
            เข้าสู่ระบบ
          </Link>
        </p>
      </div>
    </GuestLayout>
  );
}
