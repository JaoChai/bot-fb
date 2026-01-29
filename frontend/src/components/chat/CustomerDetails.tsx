import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';
import {
  Mail,
  Phone,
  Hash,
  Calendar,
  Clock,
  MessagesSquare,
  Users,
  User,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import { useChannelInfo } from '@/hooks/useChannelInfo';
import type { Conversation } from '@/types/api';

interface CustomerDetailsProps {
  conversation: Conversation;
}

/**
 * T031: Customer profile info display component
 * Shows customer avatar, name, contact info, and interaction stats
 */
export function CustomerDetails({ conversation }: CustomerDetailsProps) {
  const customer = conversation.customer_profile;

  // Channel detection - using centralized hook
  const { isTelegram, isGroup, displayName } = useChannelInfo(conversation);

  return (
    <div className="space-y-6">
      {/* Customer/Chat Profile */}
      <div className="flex items-center gap-4">
        <Avatar className="h-16 w-16">
          <AvatarImage src={customer?.picture_url || undefined} />
          <AvatarFallback className={`text-lg ${isTelegram ? 'bg-[#0088CC]/10 text-[#0088CC]' : ''}`}>
            {isGroup ? (
              <Users className="h-6 w-6" />
            ) : (
              customer?.display_name?.charAt(0).toUpperCase() || '?'
            )}
          </AvatarFallback>
        </Avatar>
        <div>
          <h3 className="font-semibold text-lg">
            {isGroup
              ? conversation.telegram_chat_title || 'Group'
              : customer?.display_name || 'Customer'}
          </h3>
          {isTelegram ? (
            <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
              {isGroup ? (
                <>
                  <Users className="h-3.5 w-3.5" />
                  <span>
                    {conversation.telegram_chat_type === 'supergroup' ? 'Supergroup' : 'Group'}
                  </span>
                </>
              ) : (
                <>
                  <User className="h-3.5 w-3.5" />
                  <span>Private Chat</span>
                </>
              )}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">
              {displayName}
            </p>
          )}
        </div>
      </div>

      {/* Contact Info */}
      <div className="space-y-3">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          Contact Info
        </h4>

        {customer?.email && (
          <div className="flex items-center gap-2 text-sm">
            <Mail className="h-4 w-4 text-muted-foreground" />
            <span>{customer.email}</span>
          </div>
        )}

        {customer?.phone && (
          <div className="flex items-center gap-2 text-sm">
            <Phone className="h-4 w-4 text-muted-foreground" />
            <span>{customer.phone}</span>
          </div>
        )}

        <div className="flex items-center gap-2 text-sm">
          <Hash className="h-4 w-4 text-muted-foreground" />
          <span className="font-mono text-xs truncate">{conversation.external_customer_id}</span>
        </div>
      </div>

      {/* Interaction Stats */}
      <div className="space-y-3">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          Interaction Stats
        </h4>

        <div className="grid grid-cols-2 gap-3">
          <Card>
            <CardContent className="p-3">
              <div className="flex items-center gap-2">
                <MessagesSquare className="h-4 w-4 text-muted-foreground" />
                <div>
                  <p className="text-2xl font-bold">{conversation.message_count}</p>
                  <p className="text-xs text-muted-foreground">Messages</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-3">
              <div className="flex items-center gap-2">
                <Hash className="h-4 w-4 text-muted-foreground" />
                <div>
                  <p className="text-2xl font-bold">{customer?.interaction_count || 1}</p>
                  <p className="text-xs text-muted-foreground">Sessions</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="flex items-center gap-2 text-sm">
          <Calendar className="h-4 w-4 text-muted-foreground" />
          <span>
            First contact:{' '}
            {customer?.first_interaction_at
              ? formatDistanceToNow(new Date(customer.first_interaction_at), {
                  addSuffix: true,
                  locale: th,
                })
              : 'Unknown'}
          </span>
        </div>

        <div className="flex items-center gap-2 text-sm">
          <Clock className="h-4 w-4 text-muted-foreground" />
          <span>
            Last message:{' '}
            {conversation.last_message_at
              ? formatDistanceToNow(new Date(conversation.last_message_at), {
                  addSuffix: true,
                  locale: th,
                })
              : 'Unknown'}
          </span>
        </div>
      </div>
    </div>
  );
}
