import { useEffect, useState } from 'react';

/**
 * ความสูง (px) ที่คีย์บอร์ดจอสัมผัสกินไปจากขอบล่างของ layout viewport
 *
 * iOS Safari ไม่หด `dvh` เมื่อคีย์บอร์ดเปิด และไม่ได้แค่หด visual viewport
 * แต่เลื่อนมันขึ้นด้วย จึงต้องคิด `offsetTop` เข้าไปในสมการ และต้องฟัง
 * `scroll` ควบคู่กับ `resize`
 *
 * คืน 0 เสมอบน desktop และบนเบราว์เซอร์ที่ไม่มี `visualViewport`
 */
export function useKeyboardInset(): number {
  const [inset, setInset] = useState(0);

  useEffect(() => {
    const vv = window.visualViewport;
    if (!vv) return;

    const update = () => {
      const gap = window.innerHeight - (vv.height + vv.offsetTop);
      setInset(Math.max(0, Math.round(gap)));
    };

    update();
    vv.addEventListener('resize', update);
    vv.addEventListener('scroll', update);

    return () => {
      vv.removeEventListener('resize', update);
      vv.removeEventListener('scroll', update);
    };
  }, []);

  return inset;
}
