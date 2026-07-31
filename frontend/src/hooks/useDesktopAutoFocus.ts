import { useEffect } from 'react';
import type { RefObject } from 'react';

/**
 * Focus ช่องพิมพ์ให้อัตโนมัติเฉพาะบน desktop
 *
 * บนมือถือการ focus ตอน mount ทำให้คีย์บอร์ดเด้งขึ้นมากินครึ่งจอทันที
 * ที่เปิดห้องแชท ก่อนที่ผู้ใช้จะได้อ่านข้อความด้วยซ้ำ
 */
export function useDesktopAutoFocus(ref: RefObject<HTMLElement | null>): void {
  useEffect(() => {
    if (window.matchMedia?.('(min-width: 768px)').matches) {
      ref.current?.focus();
    }
  }, [ref]);
}
