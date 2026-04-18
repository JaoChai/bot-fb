import { BookOpen } from 'lucide-react';
import { SettingSection } from '@/components/connections';
import { KnowledgeBaseSelector } from '@/components/flows/KnowledgeBaseSelector';
import type { KnowledgeBaseConfig } from '@/components/flows/KnowledgeBaseSelector';
import type { KnowledgeBaseListItem } from '@/hooks/useKnowledgeBase';

interface KnowledgeTabProps {
  allKnowledgeBases: KnowledgeBaseListItem[];
  selectedKnowledgeBases: KnowledgeBaseConfig[];
  isLoading: boolean;
  onChange: (kbs: KnowledgeBaseConfig[]) => void;
}

export function KnowledgeTab({
  allKnowledgeBases,
  selectedKnowledgeBases,
  isLoading,
  onChange,
}: KnowledgeTabProps) {
  return (
    <div className="border rounded-lg p-5 space-y-4">
      <SettingSection
        icon={BookOpen}
        title="Knowledge Base"
        description="เอกสารที่ AI ใช้ค้นหาข้อมูลประกอบการตอบ"
      >
        <KnowledgeBaseSelector
          allKnowledgeBases={allKnowledgeBases}
          selectedKnowledgeBases={selectedKnowledgeBases}
          isLoading={isLoading}
          onChange={onChange}
        />
      </SettingSection>
    </div>
  );
}
