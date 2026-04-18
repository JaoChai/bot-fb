# Flow Editor Simplification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ลบฟีเจอร์ Agentic Mode, Second AI, HITL, External Data Sources, Language dropdown ออกจาก Flow Editor ให้เหลือเฉพาะ Prompt / Knowledge / Model / Telegram Notifications

**Architecture:** แยกเป็น 3 PR ตามลำดับ Frontend → Backend Services → Database Migration เพื่อให้ rollback ง่าย และลดความเสี่ยง runtime error จากโค้ดยังอ้างถึง column ที่ถูกลบ

**Tech Stack:** Laravel 12 + PHP 8.4 (backend), React 19 + TypeScript + Tailwind v4 (frontend), PostgreSQL/Neon (DB), Railway (deploy)

---

## Scope & Assumptions

**ที่จะเก็บไว้:**
- Tab Prompt (name, system_prompt, is_default)
- Tab Knowledge (KB selector + kb_top_k + kb_similarity_threshold)
- Tab Model (temperature, max_tokens, presets)
- Tab "การแจ้งเตือน" (เปลี่ยนชื่อจาก Plugins — เก็บ Telegram notification plugin เท่านั้น)
- Chat Emulator แบบ streaming (ลบเฉพาะ HITL modal / Second AI display)
- `FlowPluginService` + Telegram notifications (ระบบของ flow 24 ที่ใช้อยู่)
- Chain-of-Thought auto-detection ใน `RAGService` (server-side, ฟรี ไม่มี UI)
- `HybridSearchService` / Contextual Retrieval (server-side)
- `bot_hitl_settings` table — **ห้ามลบ** เพราะเก็บข้อมูล Lead Recovery ด้วย (คอลัมน์ `hitl_*` เป็น bot-level handoff ไม่ใช่ flow-level agent)

**ที่จะลบ:**
- Agent Tab, Safety Tab ทั้งหมด
- External Data Sources จาก Plugins Tab
- Language dropdown จาก Model Tab
- Backend: `app/Services/Agent/`, `app/Services/SecondAI/`, `AgentSafetyService.php`, `ToolService.php`, `AgentApprovalController.php`
- Flow columns: `agentic_mode, max_tool_calls, enabled_tools, language, agent_timeout_seconds, agent_max_cost_per_request, hitl_enabled, hitl_dangerous_actions, second_ai_enabled, second_ai_options`
- Tables: `agent_cost_usage`, `second_ai_logs`
- Tests ที่เกี่ยวกับ Agent / SecondAI (~11 files)

**การย้าย:**
- `is_default` toggle ย้ายจาก Agent Tab → ท้าย Prompt Tab

---

## File Structure Changes

### Frontend (Phase 1)
- **ลบ:** `frontend/src/components/flow-editor/tabs/AgentTab.tsx`
- **ลบ:** `frontend/src/components/flow-editor/tabs/SafetyTab.tsx`
- **ลบ:** `frontend/src/components/flows/ToolCheckboxGrid.tsx`
- **แก้:** `frontend/src/components/flow-editor/tabs/PromptTab.tsx` (+ is_default toggle)
- **แก้:** `frontend/src/components/flow-editor/tabs/ModelTab.tsx` (- language dropdown)
- **แก้:** `frontend/src/components/flow-editor/tabs/PluginsTab.tsx` (- External Data Sources)
- **แก้:** `frontend/src/components/flow-editor/index.ts` (export barrel)
- **แก้:** `frontend/src/pages/FlowEditorPage.tsx` (state cleanup + tab list)
- **แก้:** `frontend/src/components/flows/ChatEmulator.tsx` (- approval modal)
- **แก้:** `frontend/src/hooks/useStreamingChat.ts` (- onApprovalRequired)
- **แก้:** `frontend/src/types/api.ts` (mark deprecated fields optional)

### Backend Services (Phase 2)
- **ลบ:** `backend/app/Services/Agent/` (ทั้ง directory, 6 files)
- **ลบ:** `backend/app/Services/SecondAI/` (ทั้ง directory, 10 files)
- **ลบ:** `backend/app/Services/AgentSafetyService.php`
- **ลบ:** `backend/app/Services/ToolService.php`
- **ลบ:** `backend/app/Http/Controllers/Api/AgentApprovalController.php`
- **แก้:** `backend/app/Services/RAGService.php` (ตัด agentic branch + getAgentLoopService)
- **แก้:** `backend/app/Services/AIService.php` (ตัด Second AI call)
- **แก้:** `backend/app/Http/Controllers/Api/StreamController.php` (ตัด Second AI pipeline)
- **แก้:** `backend/app/Providers/AppServiceProvider.php` (unregister bindings)
- **แก้:** `backend/routes/api.php` (ลบ /agent-approvals/* routes)
- **ลบ:** `backend/tests/Feature/SecondAI/`, `backend/tests/Unit/SecondAI/`, `backend/tests/Unit/Services/SecondAI/`, `backend/tests/Unit/Services/Agent/`

### Database (Phase 3)
- **สร้าง:** `backend/database/migrations/2026_04_18_000001_drop_agentic_and_safety_columns_from_flows.php`
- **สร้าง:** `backend/database/migrations/2026_04_18_000002_drop_agent_cost_usage_and_second_ai_logs_tables.php`
- **แก้:** `backend/app/Models/Flow.php` (ลบ fillables/casts)
- **แก้:** `backend/app/Http/Requests/Flow/StoreFlowRequest.php` (ลบ validation rules)
- **แก้:** `backend/app/Http/Requests/Flow/UpdateFlowRequest.php`
- **แก้:** `backend/app/Http/Resources/FlowResource.php`
- **แก้:** `backend/app/Http/Resources/FlowListResource.php`
- **แก้:** `backend/app/Http/Controllers/Api/FlowController.php` (RESPONSE_AFFECTING_FIELDS)
- **แก้:** `frontend/src/types/api.ts` (ลบ deprecated fields ออกจริง)

---

# PHASE 1: Frontend Cleanup

**Branch:** `feature/simplify-flow-editor-ui`
**Risk:** ต่ำ — Backend ยังรับ column เดิม ถ้าพังสามารถ revert ได้ทันที
**Estimated time:** 4-6 ชั่วโมง

### Task 1: สร้าง worktree/branch + Baseline tests

**Files:**
- Verify: `frontend/package.json` — `npm run lint`, `npx tsc --noEmit`, `npm run build`

- [ ] **Step 1: สร้าง branch ใหม่จาก main**

```bash
git checkout main
git pull origin main
git checkout -b feature/simplify-flow-editor-ui
```

- [ ] **Step 2: Baseline checks ผ่านทั้งหมด**

```bash
cd frontend
npm run lint
npx tsc --noEmit
npm run build
```
Expected: ไม่มี error (warnings ok — มี 34 pre-existing)

- [ ] **Step 3: Commit empty marker เพื่อ track branch**

```bash
git commit --allow-empty -m "chore: start flow-editor simplification phase 1"
```

---

### Task 2: ลบ External Data Sources จาก PluginsTab

**Files:**
- Modify: `frontend/src/components/flow-editor/tabs/PluginsTab.tsx`

- [ ] **Step 1: เขียน PluginsTab ใหม่ ให้เหลือแค่ PluginSection**

```tsx
import { Puzzle } from 'lucide-react';
import { Panel } from '@/components/common';
import { PluginSection } from '@/components/flow/PluginSection';

interface PluginsTabProps {
  botId: number;
  flowId: number | null;
}

export function PluginsTab({ botId, flowId }: PluginsTabProps) {
  return (
    <Panel
      icon={Puzzle}
      title="การแจ้งเตือน Telegram"
      description="ส่งการแจ้งเตือนไป Telegram เมื่อบอทตอบตามเงื่อนไขที่กำหนด"
    >
      <PluginSection botId={String(botId)} flowId={flowId} />
    </Panel>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/flow-editor/tabs/PluginsTab.tsx
git commit -m "refactor(flow-editor): remove external data sources from PluginsTab"
```

---

### Task 3: ลบ Language dropdown จาก ModelTab

**Files:**
- Modify: `frontend/src/components/flow-editor/tabs/ModelTab.tsx`

- [ ] **Step 1: ลบ prop `language` และ SettingRow language ออก**

```tsx
// แก้ interface
interface ModelTabProps {
  temperature: number;
  maxTokens: number;
  onChange: (field: 'temperature' | 'max_tokens', value: number) => void;
}

// แก้ signature function
export function ModelTab({ temperature, maxTokens, onChange }: ModelTabProps) {
```

- [ ] **Step 2: ลบ import ที่ไม่ใช้ (`Select*`, `SettingRow`)**

```tsx
// เอาออก:
// import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
// import { SettingRow } from '@/components/connections';

// เหลือ:
import { SettingSection } from '@/components/connections';
```

- [ ] **Step 3: ลบ JSX SettingRow ภาษาการตอบ** (block `<SettingRow label="ภาษาการตอบ"` ทั้งก้อน)

- [ ] **Step 4: ตรวจ tsc ผ่าน**

```bash
cd frontend && npx tsc --noEmit
```
Expected: ไม่มี error

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/flow-editor/tabs/ModelTab.tsx
git commit -m "refactor(flow-editor): remove language dropdown from ModelTab"
```

---

### Task 4: ย้าย is_default toggle ไป PromptTab

**Files:**
- Modify: `frontend/src/components/flow-editor/tabs/PromptTab.tsx`

- [ ] **Step 1: เพิ่ม prop `isDefault` + `onChange` ที่ support `is_default`**

แก้ interface:
```tsx
interface PromptTabProps {
  name: string;
  systemPrompt: string;
  isDefault: boolean;
  onChange: <K extends 'name' | 'system_prompt' | 'is_default'>(
    field: K,
    value: K extends 'is_default' ? boolean : string
  ) => void;
}
```

- [ ] **Step 2: เพิ่ม import**

```tsx
import { FileText, Maximize2, Star } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { SettingSection, SettingRow } from '@/components/connections';
```

- [ ] **Step 3: เพิ่ม section ใหม่ท้าย JSX (หลัง fullscreen Dialog)**

เพิ่มก่อน `</div>` สุดท้าย (ก่อนปิด `space-y-6`):
```tsx
<div className="border rounded-lg p-5">
  <SettingSection
    icon={Star}
    title="Flow เริ่มต้น"
    description="ใช้ Flow นี้เป็น Flow หลักของบอท"
  >
    <SettingRow label="ตั้งเป็น Flow เริ่มต้น" htmlFor="is-default-toggle">
      <Switch
        id="is-default-toggle"
        checked={isDefault}
        onCheckedChange={(checked) => onChange('is_default', checked)}
      />
    </SettingRow>
  </SettingSection>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/flow-editor/tabs/PromptTab.tsx
git commit -m "feat(flow-editor): move is_default toggle to PromptTab"
```

---

### Task 5: ลบ Agent Tab และ Safety Tab ออกจาก FlowEditorPage

**Files:**
- Modify: `frontend/src/pages/FlowEditorPage.tsx`
- Modify: `frontend/src/components/flow-editor/index.ts`

- [ ] **Step 1: แก้ `EDITOR_TABS` ให้เหลือ 4 tabs**

ใน `FlowEditorPage.tsx`:
```tsx
const EDITOR_TABS = [
  { value: 'prompt', label: 'Prompt', icon: FileText },
  { value: 'knowledge', label: 'Knowledge', icon: BookOpen },
  { value: 'model', label: 'Model', icon: Cpu },
  { value: 'plugins', label: 'การแจ้งเตือน', icon: Puzzle },
] as const;

type EditorTab = 'prompt' | 'knowledge' | 'model' | 'plugins';
```

- [ ] **Step 2: ลบ import ที่ไม่ใช้**

```tsx
// ลบ: Bot as BotIcon, Shield, AgentTab, SafetyTab, type AgentApprovalData
// ลบ: Bot ที่ import จาก lucide-react
```

- [ ] **Step 3: ลบ state ที่ไม่ใช้**

ลบบรรทัดเหล่านี้:
```tsx
const [pendingApproval, setPendingApproval] = useState<AgentApprovalData | null>(null);
const [agenticSecondAIEnabled, setAgenticSecondAIEnabled] = useState(false);
const [secondAIOptions, setSecondAIOptions] = useState({...});
const [externalDataSources, setExternalDataSources] = useState<string>('');
```

- [ ] **Step 4: ลบ handler ที่ไม่ใช้**

ลบ `handleSecondAIToggle`, `handleSecondAIOptionsChange`, `handleExternalDataSourcesChange`, `handleApprovalClose`

- [ ] **Step 5: แก้ `INITIAL_FORM_DATA`**

ลบ fields: `agentic_mode`, `max_tool_calls`, `enabled_tools`, `language`, `agent_timeout_seconds`, `agent_max_cost_per_request`, `hitl_enabled`, `hitl_dangerous_actions`

เหลือ:
```tsx
const INITIAL_FORM_DATA: CreateFlowData = {
  name: '',
  description: '',
  system_prompt: DEFAULT_SYSTEM_PROMPT,
  temperature: 0.7,
  max_tokens: 2048,
  knowledge_bases: [],
  is_default: false,
};
```

- [ ] **Step 6: แก้ `mapFlowToFormData` ตามฟิลด์ที่เหลือ**

```tsx
function mapFlowToFormData(flow: NonNullable<ReturnType<typeof useFlow>['data']>): CreateFlowData {
  const kbData: CreateFlowKnowledgeBaseData[] = flow.knowledge_bases?.map(kb => ({
    id: kb.id,
    kb_top_k: kb.kb_top_k,
    kb_similarity_threshold: kb.kb_similarity_threshold,
  })) ?? [];
  return {
    name: flow.name,
    description: flow.description || '',
    system_prompt: flow.system_prompt,
    temperature: flow.temperature,
    max_tokens: flow.max_tokens,
    knowledge_bases: kbData,
    is_default: flow.is_default,
  };
}
```

- [ ] **Step 7: ลบ useEffect ที่ set state ที่ไม่ใช้แล้ว**

ลบบรรทัด `setAgenticSecondAIEnabled(...)` / `setSecondAIOptions(...)` ใน useEffect (line 202-213)

- [ ] **Step 8: แก้ handleSave — ลบ second_ai_* จาก dataToSave**

```tsx
const dataToSave = { ...formData };
// ลบ validation agentic_mode ออก (ไม่มี agentic_mode แล้ว)
// ลบ second_ai_enabled, second_ai_options
```

ลบ validation block `if (formData.agentic_mode && ...)` ออกทั้งหมด

- [ ] **Step 9: ลบ render AgentTab, SafetyTab**

ใน JSX ลบ block:
```tsx
{activeEditorTab === 'agent' && (<AgentTab ... />)}
{activeEditorTab === 'safety' && (<SafetyTab ... />)}
```

- [ ] **Step 10: แก้ PluginsTab ให้ไม่รับ external props**

```tsx
{activeEditorTab === 'plugins' && (
  <PluginsTab botId={botId} flowId={selectedFlowId} />
)}
```

- [ ] **Step 11: ลบ `safetySettings` const ที่ไม่ใช้**

- [ ] **Step 12: แก้ `handleDiscard` — ลบ reset ของ state ที่ไม่ใช้**

- [ ] **Step 13: แก้ `frontend/src/components/flow-editor/index.ts`**

```ts
export { PromptTab } from './tabs/PromptTab';
export { KnowledgeTab } from './tabs/KnowledgeTab';
export { ModelTab } from './tabs/ModelTab';
export { PluginsTab } from './tabs/PluginsTab';
```

- [ ] **Step 14: ตรวจ tsc + lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```
Expected: ไม่มี error

- [ ] **Step 15: Commit**

```bash
git add frontend/src/pages/FlowEditorPage.tsx frontend/src/components/flow-editor/index.ts
git commit -m "refactor(flow-editor): remove agent + safety tabs, reduce to 4 tabs"
```

---

### Task 6: ลบไฟล์ AgentTab.tsx, SafetyTab.tsx, ToolCheckboxGrid.tsx

**Files:**
- Delete: `frontend/src/components/flow-editor/tabs/AgentTab.tsx`
- Delete: `frontend/src/components/flow-editor/tabs/SafetyTab.tsx`
- Delete: `frontend/src/components/flows/ToolCheckboxGrid.tsx`

- [ ] **Step 1: ตรวจก่อนลบ ว่าไม่มีไฟล์อื่น import**

```bash
grep -rn "AgentTab\|SafetyTab\|ToolCheckboxGrid" frontend/src --include="*.tsx" --include="*.ts"
```
Expected: 0 results (ควรลบ reference หมดจาก Task 5)

- [ ] **Step 2: ลบไฟล์**

```bash
rm frontend/src/components/flow-editor/tabs/AgentTab.tsx
rm frontend/src/components/flow-editor/tabs/SafetyTab.tsx
rm frontend/src/components/flows/ToolCheckboxGrid.tsx
```

- [ ] **Step 3: ตรวจ tsc + build**

```bash
cd frontend && npx tsc --noEmit && npm run build
```
Expected: build success

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor(flow-editor): delete AgentTab, SafetyTab, ToolCheckboxGrid files"
```

---

### Task 7: ลบ HITL approval modal จาก ChatEmulator (keep SSE streaming)

**Files:**
- Modify: `frontend/src/components/flows/ChatEmulator.tsx`
- Modify: `frontend/src/hooks/useStreamingChat.ts`

- [ ] **Step 1: ตรวจไฟล์ `ChatEmulator.tsx` ว่ามี approval modal ส่วนไหน**

```bash
grep -n "pendingApproval\|AgentApprovalData\|onApprovalRequired\|onApprovalClose" frontend/src/components/flows/ChatEmulator.tsx
```

- [ ] **Step 2: ลบ props `pendingApproval`, `onApprovalClose` ออกจาก interface + signature**

- [ ] **Step 3: ลบ Dialog/Modal ของ approval ออกจาก JSX (ถ้ามี)**

- [ ] **Step 4: export `AgentApprovalData` type ออก** (ไม่มีคนใช้แล้ว)

- [ ] **Step 5: ลบ `onApprovalRequired` จาก `useStreamingChat` hook**

ตัด state/callback/event handling ที่เกี่ยวกับ approval event (`hitl_approval_required`, `approval_waiting`) ออกจาก SSE event handler

- [ ] **Step 6: ตรวจ tsc**

```bash
cd frontend && npx tsc --noEmit
```

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/flows/ChatEmulator.tsx frontend/src/hooks/useStreamingChat.ts
git commit -m "refactor(flow-editor): remove HITL approval modal from ChatEmulator"
```

---

### Task 8: Update types/api.ts — mark deprecated fields optional

**Files:**
- Modify: `frontend/src/types/api.ts`

- [ ] **Step 1: ใน `Flow` interface เปลี่ยน field ที่จะลบให้เป็น optional**

```ts
// field เหล่านี้ให้เป็น optional (จะลบจริงใน Phase 3)
agentic_mode?: boolean;
max_tool_calls?: number;
enabled_tools?: string[];
language?: string;
agent_timeout_seconds?: number | null;
agent_max_cost_per_request?: number | null;
hitl_enabled?: boolean;
hitl_dangerous_actions?: string[];
second_ai_enabled?: boolean;
second_ai_options?: { fact_check?: boolean; policy?: boolean; personality?: boolean };
```

- [ ] **Step 2: แก้ `CreateFlowData` — ลบ field ที่ไม่ส่งแล้ว**

ลบ: `agentic_mode`, `max_tool_calls`, `enabled_tools`, `language`, `agent_timeout_seconds`, `agent_max_cost_per_request`, `hitl_enabled`, `hitl_dangerous_actions`

- [ ] **Step 3: ตรวจ tsc**

```bash
cd frontend && npx tsc --noEmit
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/types/api.ts
git commit -m "refactor(types): mark deprecated Flow fields optional for phase 2"
```

---

### Task 9: Smoke test + PR

- [ ] **Step 1: รัน full quality gate**

```bash
cd frontend
npm run lint
npx tsc --noEmit
npm run build
npx knip --reporter compact  # ตรวจ dead code
```

- [ ] **Step 2: Dev server + manual test**

```bash
cd frontend && npm run dev
```
Manual checks:
- เปิด `/flows/24/edit?botId=26` → เห็น 4 tabs (Prompt, Knowledge, Model, การแจ้งเตือน)
- Tab Prompt มี is_default toggle ท้าย
- Tab Model ไม่มี Language dropdown
- Tab การแจ้งเตือน เห็น Telegram plugin list
- Chat Emulator streaming text ยังทำงาน

- [ ] **Step 3: Push + create PR**

```bash
git push -u origin feature/simplify-flow-editor-ui
gh pr create --title "refactor(flow-editor): simplify to 4 tabs (Phase 1/3)" --body "$(cat <<'EOF'
## Summary
- ลบ Agent Tab, Safety Tab ออกจาก Flow Editor
- ย้าย is_default toggle ไป Prompt Tab
- ลบ External Data Sources + Language dropdown
- เปลี่ยนชื่อ Plugins Tab เป็น "การแจ้งเตือน" (เก็บ Telegram plugin)
- ChatEmulator ยัง streaming text ได้ปกติ (ลบแค่ HITL approval modal)

## Scope
Phase 1 ของ 3 — frontend เท่านั้น, backend ยังรับ/ส่ง field เดิมได้ครบ

## Test plan
- [ ] Frontend Checks CI ผ่าน
- [ ] เปิด Flow Editor → เห็น 4 tabs
- [ ] is_default toggle ใน PromptTab ทำงาน
- [ ] บันทึก flow แล้ว reload ค่าไม่หาย
- [ ] Chat Emulator ยังคุยได้

## Next
Phase 2 = ลบ backend services, Phase 3 = drop DB columns
EOF
)"
```

- [ ] **Step 4: รอ CI ผ่าน, merge เข้า main**

- [ ] **Step 5: Deploy ไป production → soak 1-2 วัน**

---

# PHASE 2: Backend Service Cleanup

**Branch:** `feature/remove-agentic-services`
**Prerequisites:** Phase 1 merged + deployed + stable 1-2 days
**Risk:** ปานกลาง — ถ้ามี branch code อ้างถึง service ที่ลบ = error; DB column ยังอยู่ rollback ได้
**Estimated time:** 4-6 ชั่วโมง

### Task 10: Branch + baseline

- [ ] **Step 1: เช็กออก branch**

```bash
git checkout main && git pull
git checkout -b feature/remove-agentic-services
```

- [ ] **Step 2: Baseline backend tests**

```bash
cd backend
php artisan test
vendor/bin/pint --test
```
บันทึกว่า test ไหนผ่าน/fail (ของเก่าอาจมี failing tests) — Task 18 จะลบ tests ที่เกี่ยวกับ Agent/SecondAI ออก ไม่ต้องกังวล tests เหล่านั้น

---

### Task 11: ลบ agentic branch ใน RAGService

**Files:**
- Modify: `backend/app/Services/RAGService.php`

- [ ] **Step 1: ลบ block line ~195-221** (agentic branch)

```php
// แทนที่ block if ($isAgentic) { ... } else { ... }
// ด้วยการเรียก openRouter ตรงๆ (คือโค้ดใน else เดิม)

// ที่มีเดิม:
// $isAgentic = $resolvedFlow && $resolvedFlow->agentic_mode && ...
// if ($isAgentic) { ... Agent branch ... } else { $result = $this->openRouter->generateBotResponse(...); }

// เปลี่ยนเป็น (เอาแค่ else branch):
$result = $this->openRouter->generateBotResponse(
    userMessage: $userMessage,
    // ... พารามิเตอร์เดิมจาก else branch
);
```

- [ ] **Step 2: ลบ `getAgentLoopService()` method** ที่อยู่ประมาณ line 280-285

- [ ] **Step 3: ลบ import/use ที่ไม่ใช้แล้ว** (`AgentLoopService`, `AgentLoopConfig`, `SyncAgentCallbacks`)

- [ ] **Step 4: ลบ optional constructor param `$toolService`** (ถ้ามี)

- [ ] **Step 5: รัน syntax check**

```bash
cd backend && php -l app/Services/RAGService.php
```
Expected: No syntax errors

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/RAGService.php
git commit -m "refactor(rag): remove agentic mode branch from RAGService"
```

---

### Task 12: ลบ Second AI call จาก AIService

**Files:**
- Modify: `backend/app/Services/AIService.php`

- [ ] **Step 1: ลบ block line 49-62** (Second AI call)

```php
// ลบ:
// if ($flow && $flow->second_ai_enabled) {
//     $secondAIResult = $this->secondAIService->process(...);
//     $result['content'] = $secondAIResult['content'];
//     $result['second_ai'] = [...];
// }
```

- [ ] **Step 2: ลบ `SecondAIService` จาก constructor + import**

```php
// ลบ: protected SecondAIService $secondAIService,
// ลบ: use App\Services\SecondAI\SecondAIService;
```

- [ ] **Step 3: ลบ metadata merging ใน `generateAndSaveResponse` (line 134-140)**

```php
// ลบ:
// if (! empty($result['second_ai']) && $result['second_ai']['applied']) {
//     $messageData['metadata'] = array_merge($messageData['metadata'] ?? [], ['second_ai' => $result['second_ai']]);
// }
```

- [ ] **Step 4: Syntax check + commit**

```bash
php -l backend/app/Services/AIService.php
git add backend/app/Services/AIService.php
git commit -m "refactor(ai): remove Second AI pipeline from AIService"
```

---

### Task 13: ลบ Second AI pipeline จาก StreamController (เก็บ streaming)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/StreamController.php`

- [ ] **Step 1: หา block ที่เกี่ยวกับ second_ai**

```bash
grep -n "second_ai\|SecondAI\|UnifiedCheck\|enabledChecks" backend/app/Http/Controllers/Api/StreamController.php
```

- [ ] **Step 2: ลบ block ทั้งหมด** (ประมาณ line 210 + line 863-959 ตามที่วิเคราะห์ไว้) — **เก็บ** streaming text/SSE event emission

- [ ] **Step 3: ลบ HITL approval event handling** — เก็บแค่ text streaming

- [ ] **Step 4: ลบ import ที่ไม่ใช้** (`UnifiedCheckService`, `SecondAIService`, `AgentLoopService`)

- [ ] **Step 5: Syntax check**

```bash
php -l backend/app/Http/Controllers/Api/StreamController.php
```

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/StreamController.php
git commit -m "refactor(stream): remove Second AI + HITL pipeline from StreamController"
```

---

### Task 14: ลบ agent-approval routes + controller

**Files:**
- Modify: `backend/routes/api.php`
- Delete: `backend/app/Http/Controllers/Api/AgentApprovalController.php`

- [ ] **Step 1: ลบ routes ออกจาก `routes/api.php` line 266-271**

```php
// ลบ block:
// Route::prefix('agent-approvals')->group(function () {
//     Route::get('/{approvalId}', ...);
//     Route::post('/{approvalId}/approve', ...);
//     Route::post('/{approvalId}/reject', ...);
// });

// + ลบ import: use App\Http\Controllers\Api\AgentApprovalController;
```

- [ ] **Step 2: ตรวจ `conversations/agent-message` route (line 242) ว่าควรเก็บหรือลบ**

```bash
grep -n "agent-message\|agentMessage" backend/app/Http/Controllers/Api/ConversationMessageController.php
```

ถ้า route นี้ใช้ agentic mode โดยเฉพาะ ให้ลบด้วย (ต้องอ่าน controller เพื่อตัดสินใจ)

- [ ] **Step 3: ลบไฟล์ AgentApprovalController**

```bash
rm backend/app/Http/Controllers/Api/AgentApprovalController.php
```

- [ ] **Step 4: รัน route:list ยืนยัน**

```bash
cd backend && php artisan route:list | grep agent
```
Expected: empty output

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(routes): remove agent-approvals routes + controller"
```

---

### Task 15: ลบ Agent services directory

**Files:**
- Delete: `backend/app/Services/Agent/` (6 files)
- Delete: `backend/app/Services/AgentSafetyService.php`
- Delete: `backend/app/Services/ToolService.php`

- [ ] **Step 1: ตรวจ grep ก่อน ว่าไม่มีไฟล์อื่นใช้**

```bash
grep -rn "AgentLoopService\|AgentSafetyService\|SyncAgentCallbacks\|SseAgentCallbacks\|ToolService\|AgentLoopConfig\|AgentLoopResult" backend/app/ backend/routes/ backend/database/
```
Expected: เหลือแค่ไฟล์ที่จะลบ + `tests/` (ลบใน Task 18)

- [ ] **Step 2: ลบไฟล์**

```bash
rm -rf backend/app/Services/Agent/
rm backend/app/Services/AgentSafetyService.php
rm backend/app/Services/ToolService.php
```

- [ ] **Step 3: ตรวจ AppServiceProvider ว่ามี binding ของไฟล์เหล่านี้หรือไม่**

```bash
grep -n "AgentLoopService\|AgentSafetyService\|ToolService" backend/app/Providers/AppServiceProvider.php
```
ถ้ามี → ลบออก

- [ ] **Step 4: Syntax + autoload refresh**

```bash
cd backend && composer dump-autoload
php artisan config:clear
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(services): delete Agent services, AgentSafetyService, ToolService"
```

---

### Task 16: ลบ SecondAI directory

**Files:**
- Delete: `backend/app/Services/SecondAI/` (10 files)

- [ ] **Step 1: ตรวจ grep**

```bash
grep -rn "SecondAIService\|UnifiedCheckService\|FactCheckService\|PolicyCheckService\|PersonalityCheckService\|PromptInjectionDetector\|SecondAIMetricsService" backend/app/ backend/routes/
```
Expected: 0 matches (ถ้ายังมี ต้องไปแก้ file นั้นก่อน)

- [ ] **Step 2: ลบ directory**

```bash
rm -rf backend/app/Services/SecondAI/
```

- [ ] **Step 3: ตรวจ `SecondAILog` model** — ลบ

```bash
rm backend/app/Models/SecondAILog.php 2>/dev/null || echo "not found"
```

- [ ] **Step 4: autoload + commit**

```bash
cd backend && composer dump-autoload
git add -A
git commit -m "refactor(services): delete SecondAI services directory"
```

---

### Task 17: ลบ Flow fillables ที่เกี่ยวกับ agent/safety/second_ai

**Files:**
- Modify: `backend/app/Models/Flow.php`

- [ ] **Step 1: แก้ `$fillable` เหลือเฉพาะที่ใช้**

```php
protected $fillable = [
    'bot_id',
    'name',
    'description',
    'system_prompt',
    'temperature',
    'max_tokens',
    'is_default',
];
```

- [ ] **Step 2: แก้ `$casts`**

```php
protected $casts = [
    'temperature' => 'decimal:2',
    'is_default' => 'boolean',
];
```

- [ ] **Step 3: Commit**

```bash
git add backend/app/Models/Flow.php
git commit -m "refactor(flow): remove agentic/safety/second_ai fillables from Flow model"
```

---

### Task 18: ลบ tests ที่เกี่ยวกับ Agent/SecondAI

**Files:**
- Delete: `backend/tests/Feature/SecondAI/` (all)
- Delete: `backend/tests/Unit/SecondAI/` (all)
- Delete: `backend/tests/Unit/Services/SecondAI/` (all)
- Delete: `backend/tests/Unit/Services/Agent/` (all)

- [ ] **Step 1: ลบ test directories**

```bash
rm -rf backend/tests/Feature/SecondAI/
rm -rf backend/tests/Unit/SecondAI/
rm -rf backend/tests/Unit/Services/SecondAI/
rm -rf backend/tests/Unit/Services/Agent/
```

- [ ] **Step 2: แก้ test ที่ยังอ้างถึง agent/second_ai (ถ้ามี)**

```bash
grep -rln "AgentLoop\|SecondAI\|UnifiedCheck\|agentic_mode\|second_ai_enabled\|hitl_" backend/tests/
```
ไฟล์ที่เจอ — แก้เอา reference ออก (อาจเป็น RAGServiceTest ที่ทดสอบ agent branch)

- [ ] **Step 3: รัน tests ทั้งหมด**

```bash
cd backend && php artisan test
```
Expected: ผ่าน 100% (ของที่เหลือ)

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "test: remove Agent + SecondAI test files"
```

---

### Task 19: ลบ validation rules ของ deprecated fields

**Files:**
- Modify: `backend/app/Http/Requests/Flow/StoreFlowRequest.php`
- Modify: `backend/app/Http/Requests/Flow/UpdateFlowRequest.php`
- Modify: `backend/app/Http/Resources/FlowResource.php`
- Modify: `backend/app/Http/Resources/FlowListResource.php`

- [ ] **Step 1: แก้ `StoreFlowRequest::rules()` เหลือเฉพาะ fields ที่ใช้**

```php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:1000'],
        'system_prompt' => ['required', 'string', 'max:50000'],
        'temperature' => ['nullable', 'numeric', 'between:0,2'],
        'max_tokens' => ['nullable', 'integer', 'min:1', 'max:128000'],
        'knowledge_bases' => ['nullable', 'array'],
        'knowledge_bases.*.id' => ['required', 'exists:knowledge_bases,id'],
        'knowledge_bases.*.kb_top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
        'knowledge_bases.*.kb_similarity_threshold' => ['nullable', 'numeric', 'between:0,1'],
        'is_default' => ['nullable', 'boolean'],
    ];
}
```

- [ ] **Step 2: แก้ `messages()` ให้ตรง rules ใหม่**

```php
public function messages(): array
{
    return [
        'name.required' => 'Flow name is required',
        'system_prompt.required' => 'System prompt is required',
        'temperature.between' => 'Temperature must be between 0 and 2',
        'knowledge_bases.*.kb_similarity_threshold.between' => 'Similarity threshold must be between 0 and 1',
    ];
}
```

- [ ] **Step 3: ตรวจ UpdateFlowRequest** (ถ้ามี extend StoreFlowRequest ก็ไม่ต้องแก้, ถ้ามี rules เอง ให้ sync)

- [ ] **Step 4: แก้ `FlowResource::toArray()` — ลบ fields**

ลบ keys: `agentic_mode`, `max_tool_calls`, `enabled_tools`, `language`, `agent_timeout_seconds`, `agent_max_cost_per_request`, `hitl_enabled`, `hitl_dangerous_actions`, `second_ai_enabled`, `second_ai_options`

- [ ] **Step 5: แก้ `FlowListResource` เช่นเดียวกัน**

- [ ] **Step 6: ตรวจ `FlowController::RESPONSE_AFFECTING_FIELDS` array — ลบ fields ที่ลบ**

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(flow): remove deprecated fields from validation + resources"
```

---

### Task 20: Smoke test + PR

- [ ] **Step 1: Full backend quality gate**

```bash
cd backend
vendor/bin/pint --test
php artisan test
```
Expected: ผ่านทั้งหมด

- [ ] **Step 2: เช็ค local — เปิด bot จริง คุยดู**

```bash
php artisan serve  # หรือ railway run
```
- ส่ง LINE webhook ปลอม → บอทตอบ
- หรือเปิด Chat Emulator ใน frontend → streaming text ทำงาน
- เช็ค flow 24 plugin → Telegram ยังส่งแจ้งเตือนได้

- [ ] **Step 3: Push + PR**

```bash
git push -u origin feature/remove-agentic-services
gh pr create --title "refactor(backend): remove Agent + SecondAI services (Phase 2/3)" --body "$(cat <<'EOF'
## Summary
- ลบ app/Services/Agent/ ทั้ง directory
- ลบ app/Services/SecondAI/ ทั้ง directory
- ลบ AgentSafetyService, ToolService, AgentApprovalController
- ลบ agentic branch จาก RAGService + AIService + StreamController
- ลบ validation rules + fillables ของ deprecated fields
- DB columns ยังอยู่ (Phase 3 จะ drop)

## Scope
Phase 2 ของ 3 — backend services cleanup, DB ยังไม่แตะ

## Test plan
- [ ] Backend Tests CI ผ่าน
- [ ] ส่ง LINE webhook → บอทตอบปกติ
- [ ] ส่ง Telegram webhook → บอทตอบปกติ  
- [ ] Chat Emulator streaming → ทำงาน
- [ ] Flow plugin (Telegram notification) → ยังส่งแจ้งเตือน
- [ ] Production DB ไม่มี error จาก deprecated columns (columns ยังอยู่)

## Next
Phase 3 = drop DB columns + tables
EOF
)"
```

- [ ] **Step 4: รอ CI ผ่าน, merge, deploy, soak 2-3 วัน**

---

# PHASE 3: Database Migration (Irreversible)

**Branch:** `feature/drop-agent-columns`
**Prerequisites:** Phase 2 merged + deployed + stable 2-3 days + ไม่มี rollback ใน pipeline
**Risk:** สูง — drop column ไม่สามารถ rollback ได้ (ต้อง restore backup), test บน Neon branch ก่อน
**Estimated time:** 2-3 ชั่วโมง

### Task 21: Branch + Neon branch test

- [ ] **Step 1: สร้าง Git branch**

```bash
git checkout main && git pull
git checkout -b feature/drop-agent-columns
```

- [ ] **Step 2: สร้าง Neon test branch จาก production**

ใช้ `mcp__plugin_neon_neon__create_branch` สำหรับ project `solitary-math-34010034`
บันทึก branch_id ไว้ใช้ทดสอบ migration

- [ ] **Step 3: Verify ไม่มี code อ้างถึง deprecated columns**

```bash
cd backend
grep -rn "agentic_mode\|max_tool_calls\|enabled_tools\|hitl_enabled\|hitl_dangerous_actions\|second_ai_enabled\|second_ai_options\|agent_timeout_seconds\|agent_max_cost_per_request" app/ config/ --include="*.php"
```
Expected: 0 matches — ถ้าเจอ = Phase 2 ไม่สมบูรณ์ ต้องหยุดก่อน

- [ ] **Step 4: Flow `language` column — เช็กพิเศษ**

```bash
grep -rn "flow->language\|'language'\s*=>" backend/app/ --include="*.php"
```
Expected: 0 matches (หรือแค่ `flows.language` column reference ในไฟล์ migration เก่า)

---

### Task 22: สร้าง migration drop columns จาก flows

**Files:**
- Create: `backend/database/migrations/2026_04_18_000001_drop_agentic_and_safety_columns_from_flows.php`

- [ ] **Step 1: สร้างไฟล์ migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn([
                'agentic_mode',
                'max_tool_calls',
                'enabled_tools',
                'language',
                'agent_timeout_seconds',
                'agent_max_cost_per_request',
                'hitl_enabled',
                'hitl_dangerous_actions',
                'second_ai_enabled',
                'second_ai_options',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('agentic_mode')->default(false);
            $table->integer('max_tool_calls')->default(10);
            $table->json('enabled_tools')->nullable();
            $table->string('language', 10)->default('th');
            $table->integer('agent_timeout_seconds')->default(120);
            $table->decimal('agent_max_cost_per_request', 8, 4)->nullable();
            $table->boolean('hitl_enabled')->default(false);
            $table->json('hitl_dangerous_actions')->nullable();
            $table->boolean('second_ai_enabled')->default(false);
            $table->jsonb('second_ai_options')->nullable();
        });
    }
};
```

- [ ] **Step 2: รัน migration บน Neon test branch ก่อน**

ใช้ connection string ของ Neon branch ที่สร้างใน Task 21:
```bash
DATABASE_URL=<neon_branch_connection> php artisan migrate --env=testing
```
Expected: migration ผ่าน, ไม่มี error

- [ ] **Step 3: Query ตรวจ columns**

```sql
SELECT column_name FROM information_schema.columns WHERE table_name='flows';
```
Expected: 11 columns (id, bot_id, name, description, system_prompt, temperature, max_tokens, is_default, created_at, updated_at, deleted_at)

- [ ] **Step 4: Commit**

```bash
git add backend/database/migrations/2026_04_18_000001_drop_agentic_and_safety_columns_from_flows.php
git commit -m "feat(migration): drop agentic + safety + second_ai columns from flows"
```

---

### Task 23: สร้าง migration drop unused tables

**Files:**
- Create: `backend/database/migrations/2026_04_18_000002_drop_agent_cost_usage_and_second_ai_logs_tables.php`

- [ ] **Step 1: สร้างไฟล์**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('agent_cost_usage');
        Schema::dropIfExists('second_ai_logs');
    }

    public function down(): void
    {
        // Irreversible — restore จาก backup เท่านั้น
        throw new \Exception('Cannot restore dropped tables, use DB backup');
    }
};
```

- [ ] **Step 2: รัน migration บน Neon test branch**

```bash
DATABASE_URL=<neon_branch_connection> php artisan migrate --env=testing
```

- [ ] **Step 3: Verify**

```sql
SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('agent_cost_usage', 'second_ai_logs');
```
Expected: 0 rows

- [ ] **Step 4: Commit**

```bash
git add backend/database/migrations/2026_04_18_000002_drop_agent_cost_usage_and_second_ai_logs_tables.php
git commit -m "feat(migration): drop agent_cost_usage + second_ai_logs tables"
```

---

### Task 24: ทำความสะอาด frontend types (ลบ optional fields จริง)

**Files:**
- Modify: `frontend/src/types/api.ts`

- [ ] **Step 1: ลบ optional fields ที่ marked ไว้ใน Task 8 ออกจาก `Flow` interface**

เปลี่ยน Flow interface เหลือ:
```ts
export interface Flow {
  id: number;
  bot_id: number;
  name: string;
  description?: string;
  system_prompt: string;
  temperature: number;
  max_tokens: number;
  is_default: boolean;
  knowledge_bases?: FlowKnowledgeBase[];
  created_at: string;
  updated_at: string;
}
```

- [ ] **Step 2: tsc + build**

```bash
cd frontend && npx tsc --noEmit && npm run build
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/types/api.ts
git commit -m "refactor(types): remove deprecated fields from Flow interface"
```

---

### Task 25: Full test + PR + staged deploy

- [ ] **Step 1: Backend full test**

```bash
cd backend
php artisan migrate:fresh --seed --env=testing  # fresh DB จาก migrations ทั้งหมด
php artisan test
vendor/bin/pint --test
```

- [ ] **Step 2: Frontend full test**

```bash
cd frontend
npm run lint
npx tsc --noEmit
npm run build
```

- [ ] **Step 3: Push + PR**

```bash
git push -u origin feature/drop-agent-columns
gh pr create --title "feat(db): drop agent + safety columns from flows (Phase 3/3)" --body "$(cat <<'EOF'
## Summary
- Drop 10 columns จาก flows table: agentic_mode, max_tool_calls, enabled_tools, language, agent_timeout_seconds, agent_max_cost_per_request, hitl_enabled, hitl_dangerous_actions, second_ai_enabled, second_ai_options
- Drop tables: agent_cost_usage, second_ai_logs
- ลบ optional deprecated fields จาก frontend Flow interface

## Scope
Phase 3 ของ 3 — DB migration (IRREVERSIBLE)

## Prerequisites
- Phase 1 + 2 merged + soak 2-3 วันไม่มี regression
- ทดสอบบน Neon test branch แล้ว

## Rollback Plan
**ไม่มี automatic rollback** — ถ้า migrate แล้วพัง ต้อง:
1. Rollback Railway deployment ไป commit ก่อน
2. Restore PostgreSQL จาก Neon point-in-time recovery

## Test plan
- [ ] Backend Tests CI ผ่าน
- [ ] Migration dry-run บน Neon test branch ผ่าน
- [ ] Production webhook จริง — LINE + Telegram ตอบปกติ
- [ ] Flow 24 Telegram notification ส่งได้
- [ ] Flow API `GET /bots/{id}/flows/{id}` return โครงสร้างใหม่
EOF
)"
```

- [ ] **Step 4: รอ CI + merge หลัง review**

- [ ] **Step 5: Deploy ด้วย staged approach**

Railway deploy จะรัน migration อัตโนมัติตอน startup ถ้า set `php artisan migrate --force` ใน release command
- ตรวจ `railway.json` / `Procfile`
- Deploy — monitor logs
- ถ้า migration fail → Railway rollback ไป deployment ก่อน, แต่ DB ไม่ rollback — ต้อง manual

- [ ] **Step 6: Post-deploy verification**

```bash
# ใช้ Neon MCP query ดู columns
# mcp__plugin_neon_neon__run_sql: SELECT column_name FROM information_schema.columns WHERE table_name='flows'
```
Expected: 11 columns เท่านั้น

- [ ] **Step 7: Monitor Sentry 24 ชม.**

ใช้ `monitoring` skill ตรวจ errors — ถ้ามี "column does not exist" = bug ต้องแก้ด่วน

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Agent Tab ลบ → Task 5, 6
- ✅ Safety Tab ลบ → Task 5, 6
- ✅ External Data Sources ลบ → Task 2
- ✅ Language dropdown ลบ → Task 3
- ✅ is_default toggle ย้าย → Task 4
- ✅ PluginsTab เหลือเฉพาะ Telegram → Task 2
- ✅ Backend services ลบ → Task 11-16
- ✅ DB columns drop → Task 22
- ✅ Tests ลบ → Task 18
- ✅ Validation + Resources อัปเดต → Task 19

**Placeholder scan:** ✅ ทุก step มีโค้ด/คำสั่งจริง ไม่มี "TBD" / "add appropriate error handling"

**Type consistency:** ✅ field names ทุกที่ตรงกัน (e.g. `is_default` ใช้ snake_case ตลอด, KnowledgeBaseConfig ใช้เดิม)

**Open item ที่ต้อง investigate ระหว่าง execution:**
1. **Task 14 Step 2**: `conversations/agent-message` route — ต้องอ่าน `ConversationMessageController::store` ว่าเป็น agent-only หรือ general chat method (ถ้า general = เก็บ, ถ้า agent = ลบ)
2. **Task 13 Step 2**: StreamController line numbers อาจเปลี่ยน — ใช้ grep หาจริง ไม่ยึด line number จาก plan

---

## Execution Plan

Plan complete ที่ `docs/superpowers/plans/2026-04-18-flow-editor-simplification.md`

**Rollout cadence แนะนำ:**

| Phase | Day | Activity |
|---|---|---|
| 1 | D+0 | Frontend cleanup → merge → deploy |
| 1 | D+1 to D+2 | Soak — ตรวจ Sentry, user feedback |
| 2 | D+3 | Backend services removal → merge → deploy |
| 2 | D+4 to D+6 | Soak — ตรวจ webhook handling, plugin execution |
| 3 | D+7 | DB migration — **irreversible** — ต้อง backup ก่อน |
| 3 | D+8 | Post-migration verification + final cleanup |

**รวมระยะเวลา:** ~1 สัปดาห์ ถ้าทุก Phase ผ่านราบรื่น
