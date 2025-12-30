import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuth } from '@/hooks/useAuth';
import { registerSchema, type RegisterFormData } from '@/lib/validations';
import { AlertCircle } from 'lucide-react';

export function RegisterPage() {
  const { register: registerUser, isRegistering, registerError } = useAuth();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<RegisterFormData>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
    },
  });

  const onSubmit = (data: RegisterFormData) => {
    registerUser(data);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-2 text-center lg:text-left">
        <h1 className="text-2xl font-semibold tracking-tight">
          สร้างบัญชีใหม่
        </h1>
        <p className="text-sm text-muted-foreground">
          กรอกข้อมูลด้านล่างเพื่อสร้างบัญชี
        </p>
      </div>

      {/* Form */}
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        {registerError && (
          <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 shrink-0" />
            <span>{(registerError as { message?: string })?.message || 'สมัครสมาชิกไม่สำเร็จ'}</span>
          </div>
        )}

        <div className="space-y-2">
          <Label htmlFor="name">ชื่อ</Label>
          <Input
            id="name"
            type="text"
            placeholder="ชื่อของคุณ"
            {...register('name')}
            aria-invalid={!!errors.name}
          />
          {errors.name && (
            <p className="text-sm text-destructive">{errors.name.message}</p>
          )}
        </div>

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
        </div>

        <div className="space-y-2">
          <Label htmlFor="password_confirmation">ยืนยันรหัสผ่าน</Label>
          <Input
            id="password_confirmation"
            type="password"
            placeholder="ยืนยันรหัสผ่าน"
            {...register('password_confirmation')}
            aria-invalid={!!errors.password_confirmation}
          />
          {errors.password_confirmation && (
            <p className="text-sm text-destructive">
              {errors.password_confirmation.message}
            </p>
          )}
        </div>

        <Button type="submit" className="w-full" disabled={isRegistering}>
          {isRegistering ? 'กำลังสร้างบัญชี...' : 'สร้างบัญชี'}
        </Button>
      </form>

      {/* Footer */}
      <p className="text-center text-sm text-muted-foreground lg:text-left">
        มีบัญชีอยู่แล้ว?{' '}
        <Link to="/login" className="font-medium text-foreground underline-offset-4 hover:underline">
          เข้าสู่ระบบ
        </Link>
      </p>
    </div>
  );
}
