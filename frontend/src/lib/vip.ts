import type { ConversationNote, VipSource } from '@/types/api';

const SOURCE_MANUAL: VipSource = 'vip_manual';
const SOURCE_AUTO: VipSource = 'vip_auto';

export type VipVariant = 'auto' | 'manual';

export interface VipInfo {
  variant: VipVariant;
  content: string;
}

export function getVipNote(notes: ConversationNote[] | null | undefined): VipInfo | null {
  if (!Array.isArray(notes)) return null;
  let auto: VipInfo | null = null;
  for (const note of notes) {
    if (note?.source === SOURCE_MANUAL) {
      return { variant: 'manual', content: note.content };
    }
    if (!auto && note?.source === SOURCE_AUTO) {
      auto = { variant: 'auto', content: note.content };
    }
  }
  return auto;
}
