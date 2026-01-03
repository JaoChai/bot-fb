import { z } from 'zod';

export const loginSchema = z.object({
  email: z
    .string()
    .min(1, 'กรุณากรอกอีเมล')
    .email('รูปแบบอีเมลไม่ถูกต้อง'),
  password: z
    .string()
    .min(1, 'กรุณากรอกรหัสผ่าน')
    .min(8, 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'),
});

export const registerSchema = z
  .object({
    name: z
      .string()
      .min(1, 'กรุณากรอกชื่อ')
      .min(2, 'ชื่อต้องมีอย่างน้อย 2 ตัวอักษร'),
    email: z
      .string()
      .min(1, 'กรุณากรอกอีเมล')
      .email('รูปแบบอีเมลไม่ถูกต้อง'),
    password: z
      .string()
      .min(1, 'กรุณากรอกรหัสผ่าน')
      .min(8, 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'),
    password_confirmation: z
      .string()
      .min(1, 'กรุณายืนยันรหัสผ่าน'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'รหัสผ่านไม่ตรงกัน',
    path: ['password_confirmation'],
  });

export type LoginFormData = z.infer<typeof loginSchema>;
export type RegisterFormData = z.infer<typeof registerSchema>;
