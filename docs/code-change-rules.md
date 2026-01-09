# Code Change Rules

กฎการแก้ไข Code (Minimal Change Principle)

## Core Principle

**แก้เฉพาะสิ่งที่จำเป็น - ไม่มากไป ไม่น้อยไป**

---

## The Rules

### 1. แก้เฉพาะจุดที่เกี่ยวข้อง

```php
// ❌ Bad - แก้ไขเกินความจำเป็น
public function store(Request $request)
{
    // แก้ bug: validation ไม่ทำงาน
    $validated = $request->validate([
        'name' => 'required|string|max:255',
    ]);

    // แต่แก้ไขทั้งหมด ไม่เกี่ยว!
    $bot = Bot::create($validated);
    $bot->generateApiKey();  // ← ไม่เกี่ยวกับ bug
    $bot->notifyOwner();     // ← ไม่เกี่ยวกับ bug
    Cache::forget('bots');   // ← ไม่เกี่ยวกับ bug

    return response()->json(['data' => $bot], 201);
}
```

```php
// ✅ Good - แก้เฉพาะปัญหา
public function store(Request $request)
{
    // แก้แค่ validation rule ที่ผิด
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        // เพิ่ม rule ที่ขาดไป
        'channel_type' => 'required|in:line,telegram',
    ]);

    $bot = Bot::create($validated);
    return response()->json(['data' => $bot], 201);
}
```

---

### 2. ห้าม Refactor ในขณะแก้ Bug

```typescript
// ❌ Bad - refactor พร้อมกับแก้ bug
function BotList() {
  const { data: bots } = useQuery({
    queryKey: ['bots'],
    queryFn: fetchBots,
    // แก้ bug: cache เก่าเกินไป
    staleTime: 5 * 60 * 1000, // ✅ นี่คือการแก้ bug

    // แต่ refactor เพิ่ม!
    retry: 3,
    refetchOnWindowFocus: false,
    cacheTime: 10 * 60 * 1000,
  });

  // และ refactor component structure ด้วย!
  return <BotListView bots={bots} />;  // แยก component ใหม่
}
```

```typescript
// ✅ Good - แก้แค่ bug
function BotList() {
  const { data: bots } = useQuery({
    queryKey: ['bots'],
    queryFn: fetchBots,
    // แก้เฉพาะ staleTime ที่เป็นปัญหา
    staleTime: 5 * 60 * 1000,
  });

  return (
    <div>
      {bots?.map(bot => <BotCard key={bot.id} bot={bot} />)}
    </div>
  );
}
```

**If you want to refactor:** สร้าง task แยกต่างหาก
```bash
# แก้ bug ก่อน
git commit -m "fix: update staleTime for bots query"

# Refactor ทีหลัง
git checkout -b refactor/extract-bot-list-view
# ... refactor ...
git commit -m "refactor: extract BotListView component"
```

---

### 3. ห้ามเพิ่ม Feature ใหม่

```php
// Task: แก้ bug - user ลบ bot ไม่ได้

// ❌ Bad - เพิ่ม feature ระหว่างแก้ bug
public function destroy(Bot $bot)
{
    $this->authorize('delete', $bot);

    // แก้ bug: ลืม delete conversations ด้วย
    $bot->conversations()->delete();  // ✅ นี่คือการแก้ bug

    // แต่เพิ่ม feature ใหม่!
    $bot->archive();                  // ← feature ใหม่
    $bot->notifyOwnerDeleted();       // ← feature ใหม่
    Log::info('Bot deleted', [...]);  // ← feature ใหม่

    $bot->delete();

    return response()->json(null, 204);
}
```

```php
// ✅ Good - แก้เฉพาะ bug
public function destroy(Bot $bot)
{
    $this->authorize('delete', $bot);

    // แก้แค่ลืม delete conversations
    $bot->conversations()->delete();

    $bot->delete();

    return response()->json(null, 204);
}
```

**If you want new features:** สร้าง task แยก
```bash
# แก้ bug ก่อน
git commit -m "fix: delete conversations when deleting bot"

# Feature ใหม่ทีหลัง
git checkout -b feature/bot-archive-system
# ... implement feature ...
git commit -m "feat: add bot archive system"
```

---

### 4. ตรวจสอบ git diff ก่อน Commit

```bash
# ดู changes ทั้งหมด
git diff

# ถามตัวเอง:
# - ไฟล์ที่แก้ไขทุกไฟล์เกี่ยวข้องกับ task หรือไม่?
# - มี "ขณะที่แก้อยู่เลยปรับ..." หรือไม่?
# - มีการเพิ่ม feature ที่ไม่ได้ขอหรือไม่?
```

### Example: Good git diff
```diff
// Task: แก้ bug - validation ผิด

// app/Http/Requests/StoreBotRequest.php
+ 'channel_type' => 'required|in:line,telegram',
- 'channel_type' => 'required',

// ✅ เกี่ยวข้องกับ task โดยตรง
```

### Example: Bad git diff
```diff
// Task: แก้ bug - validation ผิด

// app/Http/Requests/StoreBotRequest.php
+ 'channel_type' => 'required|in:line,telegram',
- 'channel_type' => 'required',

// app/Services/BotService.php  ← ไม่เกี่ยวกับ validation
+ public function generateApiKey() { ... }

// app/Models/Bot.php  ← ไม่เกี่ยวกับ validation
+ protected $fillable = [..., 'api_key'];

// ❌ แก้ไขไม่เกี่ยวข้องกับ task
```

---

## Before Commit Checklist

ก่อนทุก commit ตรวจสอบ:

```bash
# 1. ดู changes
git diff

# 2. ถามตัวเอง:
```

- [ ] **ทุกไฟล์ที่แก้ไขเกี่ยวข้องกับ task โดยตรง?**
- [ ] **ไม่มีการ refactor/cleanup ที่ไม่เกี่ยวข้อง?**
- [ ] **ไม่มีการเพิ่ม feature ใหม่ที่ไม่ได้ขอ?**
- [ ] **ไม่มีไฟล์ที่แก้ไข "ขณะที่แก้อยู่เลย..."?**

```bash
# 3. ถ้าตอบ Yes ทั้งหมด → commit
git add .
git commit -m "fix: ..."

# 4. ถ้ามีอะไรไม่เกี่ยวข้อง → แยกออก
git add -p  # เลือกทีละส่วน
# หรือ
git stash   # เก็บไว้ทำทีหลัง
```

---

## Examples

### ✅ Good: Minimal Bug Fix

**Task:** แก้ bug - race condition ใน customer profile creation

```php
// Before
public function createProfile(User $user)
{
    CustomerProfile::create(['user_id' => $user->id]);
}

// After (แก้เฉพาะปัญหา)
public function createProfile(User $user)
{
    DB::transaction(function () use ($user) {
        CustomerProfile::firstOrCreate(['user_id' => $user->id]);
    });
}
```

**Git diff:**
```diff
- CustomerProfile::create(['user_id' => $user->id]);
+ DB::transaction(function () use ($user) {
+     CustomerProfile::firstOrCreate(['user_id' => $user->id]);
+ });
```

**Files changed:** 1 file (เฉพาะ service ที่มีปัญหา)

---

### ❌ Bad: Over-fixing

**Task:** แก้ bug เดียวกัน

```php
// After (แก้มากเกินไป!)
public function createProfile(User $user)
{
    // แก้ bug: race condition
    DB::transaction(function () use ($user) {
        $profile = CustomerProfile::firstOrCreate([
            'user_id' => $user->id
        ]);

        // แต่เพิ่มอีกเยอะ!
        $profile->initializeDefaults();        // ← ไม่เกี่ยวกับ bug
        $profile->generateApiKey();            // ← feature ใหม่
        Cache::forget('profiles.' . $user->id); // ← ไม่จำเป็น
        Log::info('Profile created', [...]);    // ← ไม่จำเป็น

        return $profile;
    });
}
```

**Files changed:** 4 files
- Service (ที่มีปัญหา)
- Model (เพิ่ม methods ใหม่)
- Migration (เพิ่ม column ใหม่)
- Test (เพิ่ม test cases)

**ปัญหา:** แก้มากกว่าที่ควร - ถ้ามีปัญหาจะหา root cause ยาก

---

## When Can You Make Extra Changes?

### Exceptions (ยกเว้นเมื่อ):

1. **Fix เล็กน้อย ที่เห็นชัดว่าผิด (Obvious bugs)**
   ```php
   // Task: แก้ validation

   // เห็น typo → แก้ได้
   - 'emial' => 'required|email',  // typo
   + 'email' => 'required|email',
   ```

2. **Formatting ตามมาตรฐาน (Auto-formatting)**
   ```php
   // Auto-format by IDE → OK
   - public function store(Request $request){
   + public function store(Request $request)
   + {
   ```

3. **Import/Use statements ที่จำเป็น**
   ```php
   + use Illuminate\Support\Facades\DB;  // จำเป็นสำหรับ code ใหม่
   ```

4. **Comments ที่อธิบาย fix**
   ```php
   // แก้ race condition โดยใช้ transaction
   + DB::transaction(function () use ($user) {
       // ...
   + });
   ```

---

## Why These Rules?

### 1. Easier to Review
```
Small change = Review เร็ว = Merge เร็ว
Large change = Review นาน = Merge ช้า
```

### 2. Easier to Debug
```
ถ้า bug เกิดหลัง merge
→ แก้ไขเฉพาะจุด = หา root cause ง่าย
→ แก้ไขหลายจุด = หา root cause ยาก
```

### 3. Easier to Revert
```
git revert <commit>
→ แก้เฉพาะจุด = Revert ปลอดภัย
→ แก้หลายจุด = Revert อาจทำลายของดี
```

### 4. Better Git History
```
git log --oneline
feat: add user profile
fix: validation error      ← ชัดเจน
refactor: extract service
```

```
git log --oneline
feat: add profile + fix validation + refactor  ← งง
```

---

## Team Workflow

### Code Review Process

**Reviewer ตรวจ:**
1. ดู PR description - task คืออะไร?
2. ดู Files changed - แต่ละไฟล์เกี่ยวข้องหรือไม่?
3. ถ้าเห็น unrelated changes:
   ```
   💬 Comment: "โค้ดส่วนนี้ไม่เกี่ยวกับ task นะ
                 สามารถแยก PR ได้ไหม?"
   ```

**Author ตอบ:**
```bash
# Amend commit (ถ้ายังไม่ push)
git reset HEAD~1
# แยก changes ออก
git add -p
git commit -m "fix: validation only"

# Or create new PR
git stash
# ... create new branch for extra changes
```

---

## Summary

### ✅ Do
- แก้เฉพาะปัญหาที่ได้รับมอบหมาย
- Focus on the task
- Keep changes minimal
- Check git diff before commit

### ❌ Don't
- แก้ไขโค้ดที่ไม่เกี่ยวข้อง
- Refactor ขณะแก้ bug
- เพิ่ม feature ที่ไม่ได้ขอ
- "แก้ไขขณะที่ผ่านมา"

### 💡 Remember
> "Perfect is the enemy of good"
>
> แก้เฉพาะที่จำเป็น ไม่ใช่ทำให้ perfect

---

## Quick Reference

| สถานการณ์ | ทำไหม? | เหตุผล |
|-----------|--------|---------|
| แก้ validation bug | ✅ | เป็น task |
| เห็น typo ใน comment | ✅ | Obvious fix |
| Refactor service layer | ❌ | ไม่เกี่ยวกับ task |
| เพิ่ม logging | ❌ | Feature ใหม่ |
| เพิ่ม test cases | ⚠️ | ถ้าเป็น test สำหรับ fix = OK |
| Update documentation | ⚠️ | ถ้าเกี่ยวกับ fix = OK |
