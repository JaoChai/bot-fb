# หน้าแชทรองรับมือถือ (Chat Mobile) — Design

**วันที่:** 2026-07-31
**ปัญหา:** เจ้าของแจ้งว่า "หน้าแชทไม่รองรับมือถือเลย" — ตรวจแล้วพบ 2 บั๊กระดับใช้งานไม่ได้จริง กับปัญหา UX อีก 4 ข้อ

## หลักฐาน

จำลอง box model ของ `RootLayout` + `ChatPage` ที่ 390×844 (iPhone) แล้ววัดด้วย `getBoundingClientRect()` + `elementFromPoint()`:

| จุดวัด | ค่าที่ได้ |
|---|---|
| `Header` แอป | y = 0 → 56 |
| `ChatPage` root | y = **16** → 844 |
| ปุ่ม `←` (back) | y = 24 → 64 |
| `elementFromPoint()` กลางปุ่ม `←` | **`hdr`** (Header ของแอป) |
| `ChatInputArea.bottom` | **844** = ขอบล่างจอพอดี |

## ปัญหาที่พบ

### P0 — ใช้งานไม่ได้จริง (ยืนยันด้วยการวัดแล้ว)

**1. ปุ่มย้อนกลับกดไม่ได้**
`ChatPage.tsx:188` ใช้ `-mt-14 h-[calc(100%+4.5rem)]` ดึงตัวเองขึ้นไปทับ `Header.tsx:42` ซึ่งเป็น `sticky z-40` → Header วาดทับกลับลงมา ปุ่ม `←` เหลือพื้นที่กดจริงแค่ 8px ล่างสุด → เปิดห้องแชทแล้วกลับไปหน้ารายการไม่ได้ ต้องรีเฟรช
ชื่อลูกค้า, avatar และ `BotSelectorPanel` โดนทับด้วยเหตุผลเดียวกัน

**2. แถบพิมพ์ข้อความจมใต้ home indicator**
`index.html` ตั้ง `viewport-fit=cover` แต่ทั้งหน้าแชทไม่มี safe-area สักจุด — `input.bottom` = 844 = ขอบล่าง viewport พอดี → บน iPhone ปุ่มส่ง/ช่องพิมพ์อยู่ใต้แถบ home indicator (~34px)
โปรเจกต์มี `.pb-safe` ใน `index.css:199` และแพตเทิร์น `pb-[calc(...+env(safe-area-inset-bottom,0px))]` ใน `StickyActionBar.tsx:13` อยู่แล้ว แค่หน้าแชทไม่ได้ใช้

### P1 — ใช้ยาก

**3. Header ยัด 5 อย่างบนจอ 390px** — `ConnectionIndicator` + `Take Over` (ข้อความเต็ม ~110px) + ยืนยันรับเงิน + Reset context + Info ≈ 250px → ชื่อลูกค้าเหลือที่ประมาณ 36px (`ConfirmPaymentDialog:84` ซ่อนข้อความบนมือถือแล้ว แต่ `Take Over` ไม่ได้ซ่อน)

**4. คีย์บอร์ดเด้งทันทีที่เปิดแชท** — `autoFocus` อยู่ใน `LINEMessageInput:190`, `TelegramMessageInput:148`, `MessageInput:85`

**5. Touch target ต่ำกว่ามาตรฐาน** — ปุ่ม `size="icon"` = 36px (ต่ำกว่า 44px ตาม Apple HIG) เว้นห่าง `gap-1` = 4px (ต่ำกว่า 8px)

**6. คีย์บอร์ดอาจบังช่องพิมพ์ — ยังไม่ยืนยัน** จำลองบน desktop ไม่ได้ แต่ iOS Safari ไม่หด `dvh` เมื่อคีย์บอร์ดเปิด และ layout เป็น `overflow-hidden` ทั้งสาย จึงเสี่ยงสูงที่ช่องพิมพ์จะถูกดันหาย

**หมายเหตุ:** ฝั่งรายการแชททำ mobile ไว้ถูกแล้ว (`ConversationItem` `min-h-[72px]`, ช่องค้นหา `min-h-[44px]`, `text-base sm:text-sm` กัน iOS auto-zoom) — ปัญหาทั้งหมดอยู่ฝั่งหน้าต่างแชทและ layout ระดับ root

## ขอบเขต (ตามที่เจ้าของเลือก)

- **มือถือ = เต็มจอแบบ LINE/Messenger** — ซ่อน Header ของแอปเฉพาะตอนอยู่ในห้องแชท หน้ารายการแชทยังมี `☰` ตามปกติ
- **`Take Over` โชว์เป็นไอคอน ที่เหลือเข้าเมนู `⋮`** — ยืนยันรับเงิน / Reset context / ข้อมูลลูกค้า
- **แก้เรื่องคีย์บอร์ดเต็มรูปแบบด้วย `visualViewport`** ไม่ใช่แค่เติม viewport meta
- **`autoFocus` คงไว้บน desktop ตัดออกบนมือถือ**
- **ฝั่ง desktop ต้องไม่เปลี่ยนหน้าตาเลย**

นอกขอบเขต (พบแล้วแต่ตกลงว่าไม่แตะ): ปุ่ม `Reset All Contexts` เป็นปุ่มเต็มความกว้างบนสุดของรายการแชท เป็น action อันตรายที่กินพื้นที่ดีที่สุดบนจอมือถือ

## Design

### 1. รื้อ layout ที่ตีกัน (แก้ P0-1)

ต้นเหตุคือ `ChatPage` สู้กับ `RootLayout` ด้วย negative margin — แก้ที่ต้นทาง ไม่ใช่ไล่เพิ่ม z-index ทับกันไปมา

**`RootLayout.tsx`** — ให้ layout รู้จัก "route ที่กินเต็มพื้นที่":

```tsx
const { pathname } = useLocation();
const showMobileChat = useChatStore((s) => s.showMobileChat);

const isChatRoute = pathname.startsWith('/chat');
// อยู่ในห้องแชทบนมือถือ = เต็มจอ ให้ ChatHeader ทำหน้าที่ nav แทน
const hideMobileHeader = isChatRoute && showMobileChat;

{!hideMobileHeader && <Header />}
<main className={cn(
  'flex-1 min-h-0',
  isChatRoute ? 'overflow-hidden' : 'overflow-auto p-4 md:p-6'
)}>
```

**`ChatPage.tsx:188`** — negative margin หายทั้งชุด:

```diff
- -mx-4 -mb-4 -mt-14 md:-m-6 flex h-[calc(100%+4.5rem)] md:h-[calc(100%+3rem)] overflow-hidden
+ flex h-full overflow-hidden
```

**`ChatPage.tsx:163`** (state ยังไม่เลือกบอท) — `h-[calc(100dvh-3.5rem)] md:h-[calc(100dvh-64px)]` → `h-full` เพราะ `main` ให้ความสูงที่ถูกต้องอยู่แล้ว

**ทำไม desktop ไม่เปลี่ยน:** ของเดิม `main` มี `p-6` แล้ว `ChatPage` ใช้ `-m-6` + `h-[calc(100%+3rem)]` หักล้างกันพอดี = `p-0` + `h-full` ผลลัพธ์เท่ากันทุกพิกเซล

**พฤติกรรมที่ได้บนมือถือ:** เข้า `/chat` → `showMobileChat=false` → เห็น Header + รายการแชท → แตะห้องแชท (`chatStore.selectConversation` ตั้ง `showMobileChat=true` อยู่แล้ว) → Header หลบ ห้องแชทเต็มจอ → กด `←` → Header กลับมา

### 2. ChatHeader — `Take Over` โชว์ ที่เหลือเข้า `⋮` (แก้ P1-3, P1-5)

ข้อจำกัดเชิงโครงสร้าง: ตอนนี้ `ChatWindow:89-101` ประกอบ `<ConfirmPaymentDialog/>` + `<ClearContextDialog/>` เป็น ReactNode ส่งเข้า prop `actions` ของ `ChatHeader` — เอาไปใส่ใน `DropdownMenu` ตรงๆ ไม่ได้ เพราะเมนูปิดแล้ว dialog จะถูก unmount ทันที (ข้อจำกัดของ Radix: trigger ที่อยู่ใน menu item ต้องคุม open state จากข้างนอก)

สร้าง **`components/chat/ChatHeaderActions.tsx`** ที่ถือ open state ของ dialog เอง:

- รับ props: `conversation`, `showConfirmPayment`, `onConfirmPayment`, `onClearContext`, `onShowInfo`, `isTelegram`, สถานะ pending ต่างๆ
- ถือ `const [openDialog, setOpenDialog] = useState<'payment' | 'clear' | null>(null)`
- Render dialog เป็น sibling ของเมนู (ไม่ใช่ลูก) แบบ controlled
- `< 640px`: ปุ่ม `🎧 Take Over` แบบไอคอนล้วน (มี `aria-label`) + ปุ่ม `⋮` เปิด `DropdownMenu` ที่มี ยืนยันรับเงิน / Reset context / ข้อมูลลูกค้า
- `≥ 640px`: เรียงเป็นปุ่มแนวนอนเหมือนเดิมทุกประการ

`ClearContextDialog` และ `ConfirmPaymentDialog` ต้องรับ `open` / `onOpenChange` เพิ่ม และทำให้ `AlertDialogTrigger` เป็น optional (เมื่อถูกคุมจากข้างนอกก็ไม่ต้อง render trigger) — ของเดิมที่ใช้ trigger ในตัวยังทำงานได้เหมือนเดิม

`ChatWindow` เปลี่ยนจากส่ง ReactNode เป็นส่ง handler ดิบลงมา

**Touch target:** ปุ่ม `←` และ `⋮` เป็น `size-11` (44px) บนมือถือ ลดเป็น `size-9` ที่ `sm:` ขึ้นไป ระยะห่างเปลี่ยนจาก `gap-1` เป็น `gap-2` (8px)

### 3. Safe area (แก้ P0-2)

`ChatInputArea` มี 5 branch (`closed`, `bot_active`, `telegram`, `line_handover`, `handover`) ที่ห่อด้วย `<div className="flex-shrink-0 border-t bg-background">` เหมือนกันหมด → ใส่จุดเดียวครอบคลุมทุก state:

```tsx
className="flex-shrink-0 border-t bg-background pb-[env(safe-area-inset-bottom,0px)]"
```

ใช้แพตเทิร์น `env()` ตรงๆ ตาม `StickyActionBar.tsx:13` ที่โปรเจกต์ใช้อยู่แล้ว ไม่ใช้ `.pb-safe` เพราะ utility นั้นเซ็ต `padding-bottom` ทับ padding ของ input เอง

เพิ่ม safe area ซ้าย/ขวาที่ root ของ `ChatPage` สำหรับ iPhone แนวนอน (notch อยู่ด้านข้าง):
```tsx
className="... pl-[env(safe-area-inset-left,0px)] pr-[env(safe-area-inset-right,0px)]"
```

บน iOS ค่า `safe-area-inset-bottom` จะกลายเป็น 0 เองเมื่อคีย์บอร์ดเปิด จึงประกอบกับข้อ 4 ได้โดยไม่ซ้อนกัน

### 4. คีย์บอร์ด (แก้ P1-6)

Hook ใหม่ **`hooks/useKeyboardInset.ts`**:

```ts
export function useKeyboardInset(): number {
  const [inset, setInset] = useState(0);

  useEffect(() => {
    const vv = window.visualViewport;
    if (!vv) return;

    const update = () => {
      // iOS ไม่ได้แค่หด visual viewport แต่เลื่อนมันด้วย จึงต้องบวก offsetTop
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
```

ใช้ที่ root ของ `ChatPage` ผ่าน inline style: `style={{ height: `calc(100% - ${inset}px)` }}`

inline style จะทับ class `h-full` จากข้อ 1 — ตั้งใจให้เป็นแบบนั้น `h-full` คือค่าตั้งต้นตอน `inset` = 0 (ซึ่งเป็นเกือบทุกกรณี) ส่วน inline style เข้ามาแทนเฉพาะตอนคีย์บอร์ดเปิด

- ฟังทั้ง `resize` และ `scroll` เพราะ iOS Safari เลื่อน layout viewport ตอนคีย์บอร์ดเปิด ไม่ได้หดอย่างเดียว
- บน desktop ไม่มีคีย์บอร์ดจอสัมผัส `inset` = 0 ตลอด → ไม่มีผลข้างเคียง
- เบราว์เซอร์ที่ไม่มี `visualViewport` ได้ 0 เท่ากับพฤติกรรมเดิม

### 5. autoFocus (แก้ P1-4)

Hook ใหม่ **`hooks/useDesktopAutoFocus.ts`** — focus ให้เฉพาะจอ `≥768px`:

```ts
export function useDesktopAutoFocus(ref: RefObject<HTMLElement | null>) {
  useEffect(() => {
    if (window.matchMedia('(min-width: 768px)').matches) {
      ref.current?.focus();
    }
  }, [ref]);
}
```

ลบ `autoFocus` ออกจาก `LINEMessageInput:190`, `TelegramMessageInput:148`, `MessageInput:85` แล้วเรียก hook แทน (ทั้ง 3 ไฟล์มี `textareaRef` อยู่แล้ว)

ไม่แตะ `QuickReplyList.tsx:44` เพราะเป็น autoFocus ในกล่อง search ที่ผู้ใช้เปิดเองอย่างตั้งใจ — ถูกต้องแล้วบนทุกจอ

## เกณฑ์ว่าเสร็จ

| ข้อ | วิธีตรวจ | ใครตรวจ |
|---|---|---|
| ปุ่ม `←` กดได้ | repro geometry 390×844: `elementFromPoint(กลางปุ่ม) === ปุ่ม` | อัตโนมัติ |
| Input มีที่กัน safe area | เบราว์เซอร์ desktop ให้ `env(safe-area-inset-bottom)` = 0 เสมอ จำลองค่าจริงไม่ได้ → ตรวจได้แค่ว่า computed style ของ wrapper มี `padding-bottom` ที่มาจาก `env()` และเลย์เอาต์ไม่พังตอนค่าเป็น 0 | อัตโนมัติ (บางส่วน) |
| Header ไม่ทับหน้าแชท | `chatPage.top ≥ 0` และไม่มี element อื่นทับ | อัตโนมัติ |
| Desktop ไม่เปลี่ยน | `npm run build` ผ่าน + เทียบสายตาที่ ≥1280px | อัตโนมัติ + สายตา |
| Type check / lint | `npm run build`, `npx tsc --noEmit`, `npm run lint` | อัตโนมัติ |
| Test เดิมไม่พัง | `npm run test` (มี `Header.test.tsx` อยู่) | อัตโนมัติ |
| **Safe area บนเครื่องจริง** | เปิด iPhone จริง ดูว่าปุ่มส่งไม่จม home indicator | **เจ้าของ** |
| **คีย์บอร์ดไม่บังช่องพิมพ์** | เปิด iPhone + Android จริง แตะช่องพิมพ์ | **เจ้าของ** |

2 แถวล่างจำลองบน desktop ไม่ได้ — จะไม่เคลมว่างานเสร็จจนกว่าเจ้าของจะยืนยันจากเครื่องจริง

## ความเสี่ยงที่รู้ตัว

1. **`useKeyboardInset` บน iOS** — พฤติกรรม `visualViewport` ของ iOS Safari ไม่เหมือนกันทุกเวอร์ชัน ถ้าเครื่องจริงยังเพี้ยน อาจต้องเพิ่ม `interactive-widget=resizes-content` ใน viewport meta หรือบังคับ `window.scrollTo(0,0)` ตอน blur
2. **`RootLayout` อ่าน `chatStore`** — เป็นการผูก layout เข้ากับ state ของฟีเจอร์แชท ยอมรับได้เพราะ "อยู่ในห้องแชทไหม" เป็นข้อมูลที่มีอยู่แล้วที่เดียวใน `chatStore` และการสร้าง abstraction กลางเพื่อ route เดียวคือ over-engineering
3. **แก้ contract ของ `ClearContextDialog` / `ConfirmPaymentDialog`** — เช็คแล้ว: มีที่เรียกใช้แค่ `ChatWindow.tsx:81,92` กับ `ConfirmPaymentDialog.test.tsx` ซึ่งใช้แบบ uncontrolled (มี trigger ในตัว) การทำ `open`/`onOpenChange` เป็น optional โดยคง trigger เป็นค่า default จึงไม่กระทบ test เดิม
