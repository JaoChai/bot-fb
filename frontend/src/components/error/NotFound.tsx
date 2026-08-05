import { Link } from 'react-router';
import { Button } from '@/components/ui/button';

/**
 * Shown when a URL matches no route, instead of react-router's default
 * "Unexpected Application Error!" screen which looks like the whole app crashed.
 */
export function NotFound() {
  return (
    <div className="flex flex-col items-center justify-center gap-4 py-20 text-center">
      <p className="text-lg font-medium">ไม่พบหน้านี้</p>
      <p className="text-sm text-muted-foreground">
        ลิงก์อาจเปลี่ยนไปแล้ว หรือพิมพ์ที่อยู่ไม่ตรง
      </p>
      <Button asChild variant="outline">
        <Link to="/dashboard">กลับไปแดชบอร์ด</Link>
      </Button>
    </div>
  );
}
