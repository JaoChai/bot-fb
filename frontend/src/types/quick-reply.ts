export interface QuickReply {
  id: number;
  user_id: number;
  shortcut: string;
  title: string;
  content: string;
  category: string | null;
  sort_order: number;
  is_active: boolean;
  created_by: number;
  created_at: string;
  updated_at: string;
}

export interface QuickReplyInput {
  shortcut: string;
  title: string;
  content: string;
  category?: string | null;
  sort_order?: number;
  is_active?: boolean;
}

export interface QuickReplyListParams {
  is_active?: boolean;
  category?: string;
  search?: string;
}

export interface QuickReplySearchParams {
  q: string;
}

export interface ReorderQuickRepliesInput {
  ids: number[];
}
