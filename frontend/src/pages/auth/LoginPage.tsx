import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuth } from '@/hooks/useAuth';
import { loginSchema, type LoginFormData } from '@/lib/validations';
import { AlertCircle } from 'lucide-react';

export function LoginPage() {
  const { login, isLoggingIn, loginError } = useAuth();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginFormData>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: '',
      password: '',
    },
  });

  const onSubmit = (data: LoginFormData) => {
    login(data);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-2 text-center lg:text-left">
        <h1 className="text-2xl font-semibold tracking-tight">
          เข้าสู่ระบบ
        </h1>
        <p className="text-sm text-muted-foreground">
          กรอกอีเมลและรหัสผ่านเพื่อเข้าสู่ระบบ
        </p>
      </div>

      {/* Form */}
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        {loginError && (
          <div className="flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 shrink-0" strokeWidth={1.5} />
            <span>{(loginError as { message?: string })?.message || 'เข้าสู่ระบบไม่สำเร็จ'}</span>
          </div>
        )}

        <div className="space-y-2">
          <Label htmlFor="email">อีเมล</Label>
          <Input
            id="email"
            type="email"
            placeholder="name@example.com"
            {...register('email')}
            aria-invalid={!!errors.email}
          />
          {errors.email && (
            <p className="text-sm text-destructive">{errors.email.message}</p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="password">รหัสผ่าน</Label>
          <Input
            id="password"
            type="password"
            placeholder="รหัสผ่าน"
            {...register('password')}
            aria-invalid={!!errors.password}
          />
          {errors.password && (
            <p className="text-sm text-destructive">{errors.password.message}</p>
          )}
          <div className="flex justify-end">
            <Link
              to="/forgot-password"
              className="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
              ลืมรหัสผ่าน?
            </Link>
          </div>
        </div>

        <Button type="submit" className="w-full" disabled={isLoggingIn}>
          {isLoggingIn ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ'}
        </Button>
      </form>

      {/* Footer */}
      <p className="text-center text-sm text-muted-foreground lg:text-left">
        ยังไม่มีบัญชี?{' '}
        <Link to="/register" className="font-medium text-foreground underline-offset-4 hover:underline">
          สมัครสมาชิก
        </Link>
      </p>
    </div>
  );
}
