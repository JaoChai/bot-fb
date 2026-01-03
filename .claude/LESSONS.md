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

## Workflow Preferences

- วางแผนก่อนทำเสมอ
- ใช้ GitHub Issue track งาน
- ดู production logs ก่อนเดา
- Test locally ก่อน deploy

---
*Auto-learning: เพิ่มบทเรียนใหม่เมื่อเจอปัญหา*
