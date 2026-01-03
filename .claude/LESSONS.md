# Lessons Learned

## Critical Patterns (ห้ามละเมิด)

| Category | Problem | Fix |
|----------|---------|-----|
| **Migration** | ลืม import DB facade | `use Illuminate\Support\Facades\DB;` |
| **Migration** | ไม่เช็ค column | `Schema::hasColumn()` ก่อน add |
| **Production** | Guess error | ดู actual logs ก่อนเสมอ |
| **Frontend** | ไม่ build | `npm run build` ก่อน commit |
| **Frontend** | Unused vars | ลบหรือใช้งานให้ครบ |
| **Error** | ไม่มี try-catch | ครอบทุก API call |
| **API** | Response wrapper | ใช้ `response.data` |
| **Cache** | serve.json fail | ใช้ Express server |
| **Config** | `config('x','')` null | `config('x') ?? ''` |
| **React Query** | Optimistic update ซับซ้อน UI ไม่ sync | ลบ optimistic ใช้แค่ `refetchQueries` ใน onSuccess |
| **Debugging** | Fix ซ้อน fix ไม่ work | หยุด หา root cause ลบ complexity |

## Workflow Preferences

- วางแผนก่อนทำเสมอ
- ใช้ GitHub Issue track งาน
- ดู production logs ก่อนเดา
- Test locally ก่อน deploy
- **2 fix ไม่ work = หยุด** → recheck root cause หรือถาม user
- **อย่า fix ซ้อน fix** → ลบ complexity แทนเพิ่ม code
- **Self-learning = ทำเลย** → ไม่ต้องขออนุญาต update LESSONS.md
- **Deploy ≠ Done** → ต้อง verify ก่อนบอกว่าเสร็จ

---
*Auto-learning: เพิ่มบทเรียนใหม่เมื่อเจอปัญหา*
