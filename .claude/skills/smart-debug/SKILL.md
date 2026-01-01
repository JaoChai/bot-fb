# Smart Debug Skill

Debug with memory - ค้นหาความจำก่อนแก้ปัญหา

## Trigger

ใช้เมื่อ user พูดถึง: error, bug, fix, แก้, ปัญหา, ล่ม, 500, 404, crash

## Workflow

เมื่อ trigger skill นี้ ให้ทำตามลำดับ:

### Step 1: Search Memory First

```
search(query="<error_keyword>", project="BotFacebook", obs_type="bugfix", limit=10)
```

ค้นหาว่าเคยเจอปัญหานี้หรือไม่

### Step 2: Check Timeline Context

ถ้าเจอ observation ที่เกี่ยวข้อง:
```
timeline(anchor=<observation_id>, depth_before=3, depth_after=3, project="BotFacebook")
```

### Step 3: Fetch Solution Details

```
get_observations(ids=[<relevant_ids>])
```

### Step 4: Apply or Adapt Solution

- ถ้าเคยแก้แล้ว → ใช้ solution เดิม
- ถ้าคล้ายกัน → adapt solution
- ถ้าใหม่ → diagnose ใหม่ แต่จำไว้ว่าต้องบันทึก

## Output Format

```markdown
## Memory Search Results

**Found similar issue:** [Yes/No]

| ID | Date | Title | Relevance |
|----|------|-------|-----------|
| #xxx | ... | ... | High/Medium/Low |

**Recommended Action:**
- [Action based on memory]

**Previous Solution:**
- [If found, show solution]
```

## Example

User: "เว็บ error 500"

1. Search: `search(query="500 error", project="BotFacebook", obs_type="bugfix")`
2. Found: #11264 - Migration failure
3. Solution: Check migration idempotency
4. Apply: Run `diagnose(action="logs")` to confirm
