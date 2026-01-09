// Inertia.js Shared Props & Page Props Types

// User Role
export type UserRole = 'owner' | 'admin';

// Authenticated User
export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
}

// Flash Messages
export interface FlashMessages {
  success?: string;
  error?: string;
  warning?: string;
  info?: string;
}

// Pagination Meta (Laravel)
export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

// Pagination Links (Laravel)
export interface PaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

// Paginated Data
export interface PaginatedData<T> {
  data: T[];
  meta: PaginationMeta;
  links: PaginationLinks;
}

// Simple Paginated Response (Laravel simple pagination)
export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  first_page_url: string;
  last_page_url: string;
  next_page_url: string | null;
  prev_page_url: string | null;
  path: string;
}

// Channel Type
export type ChannelType = 'line' | 'telegram' | 'facebook' | 'testing';

// Bot Status
export type BotStatus = 'active' | 'inactive' | 'paused';

// Bot
export interface Bot {
  id: number;
  name: string;
  description?: string | null;
  channel_type: ChannelType;
  status: BotStatus;
  webhook_token: string;
  webhook_url?: string | null;
  settings?: Record<string, unknown> | null;
  user_id: number;
  created_at: string;
  updated_at: string;
  // Relationships (optional)
  flow?: { id: number; name: string; is_active: boolean } | null;
  knowledge_bases?: Array<{ id: number; name: string; document_count: number }>;
  // Counts (optional)
  conversations_count?: number;
  messages_count?: number;
  customers_count?: number;
}

// Conversation
export interface Conversation {
  id: number;
  bot_id: number;
  customer_id: number;
  status: 'open' | 'closed' | 'pending';
  last_message_at: string | null;
  created_at: string;
  updated_at: string;
  // Relationships
  customer?: Customer;
  bot?: Bot;
  messages?: Message[];
  messages_count?: number;
}

// Customer
export interface Customer {
  id: number;
  platform_id: string;
  display_name: string;
  picture_url?: string | null;
  channel_type: ChannelType;
  metadata?: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

// Message
export interface Message {
  id: number;
  conversation_id: number;
  sender_type: 'customer' | 'bot' | 'agent';
  sender_id?: number | null;
  content: string;
  message_type: 'text' | 'image' | 'sticker' | 'file' | 'location' | 'template';
  metadata?: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

// Shared Props - Data available on every page via HandleInertiaRequests
export interface SharedProps {
  auth: {
    user: User | null;
  };
  flash: FlashMessages;
  errors: Record<string, string>;
  ziggy?: {
    url: string;
    port: number | null;
    defaults: Record<string, unknown>;
    routes: Record<string, unknown>;
  };
  [key: string]: unknown; // Index signature for PageProps compatibility
}

// Base Page Props - Extend this for specific pages
export interface PageProps extends SharedProps {}

// Inertia Form Error Bag
export type ErrorBag = Record<string, string>;

// Route function type (from Ziggy)
declare global {
  function route(name: string, params?: Record<string, unknown>, absolute?: boolean): string;
  function route(): { current: (name?: string) => boolean };
}

// Vite environment variables
interface ImportMetaEnv {
  readonly VITE_APP_NAME: string;
  readonly VITE_PUSHER_APP_KEY: string;
  readonly VITE_PUSHER_HOST: string;
  readonly VITE_PUSHER_PORT: string;
  readonly VITE_PUSHER_SCHEME: string;
  readonly VITE_PUSHER_APP_CLUSTER: string;
  readonly VITE_REVERB_APP_KEY: string;
  readonly VITE_REVERB_HOST: string;
  readonly VITE_REVERB_PORT: string;
  readonly VITE_REVERB_SCHEME: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
