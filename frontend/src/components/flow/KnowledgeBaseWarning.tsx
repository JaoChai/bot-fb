import { AlertTriangle } from 'lucide-react';
import { useNavigate, useSearchParams } from 'react-router';

interface KnowledgeBaseWarningProps {
  visible: boolean;
}

export function KnowledgeBaseWarning({ visible }: KnowledgeBaseWarningProps) {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  if (!visible) {
    return null;
  }

  const handleNavigate = () => {
    const botId = searchParams.get('botId');
    navigate(`/knowledge-bases${botId ? `?botId=${botId}` : ''}`);
  };

  return (
    <div className="mt-2 p-3 bg-yellow-50 border border-yellow-200 rounded-lg flex items-start gap-3">
      <AlertTriangle className="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" />
      <div className="flex-1">
        <p className="text-sm text-yellow-800">
          <strong>ต้องมี Knowledge Base เพื่อใช้ Fact Check</strong>
        </p>
        <p className="text-xs text-yellow-700 mt-1">
          Fact Check ต้องการ Knowledge Base เพื่อตรวจสอบความถูกต้องของข้อมูล
          กรุณาเพิ่ม Knowledge Base ก่อนเปิดใช้งาน
        </p>
        <button
          onClick={handleNavigate}
          className="mt-2 text-xs font-medium text-yellow-900 underline hover:text-yellow-700 transition-colors"
        >
          เพิ่ม Knowledge Base →
        </button>
      </div>
    </div>
  );
}
