import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { aiApi } from '@/api/endpoints'
import { errorMessage } from '@/api/client'
import { PageHeader } from '@/components/PageHeader'
import { Button, Card, Spinner, Textarea, cx } from '@/components/ui'
import { ActionRequestCard } from '@/components/ActionRequestCard'
import { relative } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'
import type { AiActionRequest } from '@/types'

const STARTERS = [
  'What should I focus on today?',
  'Which leads have not been contacted this week?',
  'Show me quotations waiting more than 3 days',
  'Which projects are waiting for the customer?',
]

export default function AssistantPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [conversationId, setConversationId] = useState<string | null>(null)
  const [draft, setDraft] = useState('')
  const scrollRef = useRef<HTMLDivElement>(null)

  const { data: status, isLoading: loadingStatus } = useQuery({ queryKey: ['ai', 'status'], queryFn: aiApi.status })
  const { data: conversations = [] } = useQuery({ queryKey: ['ai', 'conversations'], queryFn: aiApi.conversations })

  const { data: messages = [], isLoading: loadingMessages } = useQuery({
    queryKey: ['ai', 'messages', conversationId],
    queryFn: () => aiApi.messages(conversationId!),
    enabled: conversationId !== null,
  })

  const { data: actionRequests = [] } = useQuery({
    queryKey: ['ai', 'action-requests'],
    queryFn: () => aiApi.actionRequests(),
  })

  const send = useMutation({
    mutationFn: async (message: string) => {
      const id = conversationId ?? (await aiApi.startConversation()).id
      setConversationId(id)

      return aiApi.send(id, message)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['ai', 'messages'] })
      void queryClient.invalidateQueries({ queryKey: ['ai', 'conversations'] })
      void queryClient.invalidateQueries({ queryKey: ['ai', 'action-requests'] })
    },
  })

  // Follow the conversation as it grows.
  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages.length, send.isPending])

  function submit(event: FormEvent) {
    event.preventDefault()
    const message = draft.trim()

    if (message === '' || send.isPending) return

    setDraft('')
    send.mutate(message)
  }

  const pendingActions = actionRequests.filter((request: AiActionRequest) => request.status === 'pending')

  const pendingBanner =
    pendingActions.length === 0 ? null : (
      <div className="mb-3 space-y-2">
        {pendingActions.map((request) => (
          <ActionRequestCard key={request.id} request={request} />
        ))}
      </div>
    )

  if (loadingStatus) return <Spinner />

  if (status && !status.chat_available) {
    return (
      <>
        <PageHeader title="Assistant" />
        {pendingBanner}
        <Card className="p-6">
          <p className="text-sm font-medium text-slate-900">The assistant is not connected yet.</p>
          <p className="mt-1 max-w-prose text-sm text-slate-600">
            Chat needs a language model. Set <code className="rounded bg-slate-100 px-1">AI_API_KEY</code> and{' '}
            <code className="rounded bg-slate-100 px-1">AI_MODEL</code> in the backend environment to switch it on.
          </p>
          <p className="mt-3 text-sm text-slate-600">
            Your <a href="/brief" className="font-medium text-brand-600 hover:underline">daily brief</a> works without
            it — it is calculated from your own records, not written by a model.
          </p>
        </Card>
      </>
    )
  }

  return (
    <div className="flex h-[calc(100vh-8rem)] flex-col">
      <PageHeader
        title="Assistant"
        subtitle="Reads your CRM to answer questions. Any change it proposes waits for your confirmation."
        action={
          conversationId && (
            <Button variant="secondary" size="sm" onClick={() => setConversationId(null)}>
              New conversation
            </Button>
          )
        }
      />

      {pendingBanner}

      <Card className="flex min-h-0 flex-1 flex-col">
        <div ref={scrollRef} className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
          {conversationId === null || (messages.length === 0 && !send.isPending) ? (
            <div className="py-8 text-center">
              <p className="text-sm font-medium text-slate-900">
                Ask about your pipeline, {user?.name.split(' ')[0]}
              </p>
              <p className="mt-1 text-sm text-slate-500">It answers from your records, and says so when it cannot.</p>
              <div className="mx-auto mt-5 flex max-w-lg flex-wrap justify-center gap-2">
                {STARTERS.map((starter) => (
                  <button
                    key={starter}
                    onClick={() => send.mutate(starter)}
                    className="rounded-full bg-slate-100 px-3 py-1.5 text-xs text-slate-700 transition hover:bg-slate-200"
                  >
                    {starter}
                  </button>
                ))}
              </div>
            </div>
          ) : loadingMessages ? (
            <Spinner />
          ) : (
            messages.map((message) => (
              <div
                key={message.id}
                className={cx('flex', message.role === 'user' ? 'justify-end' : 'justify-start')}
              >
                <div
                  className={cx(
                    'max-w-2xl rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap',
                    message.role === 'user'
                      ? 'bg-brand-600 text-white'
                      : 'bg-slate-100 text-slate-900',
                  )}
                >
                  {message.content}
                  {message.tools_used.length > 0 && (
                    <p className="mt-2 text-xs text-slate-500">
                      Checked: {message.tools_used.map((t) => t.replace(/_/g, ' ')).join(', ')}
                    </p>
                  )}
                </div>
              </div>
            ))
          )}

          {send.isPending && (
            <div className="flex justify-start">
              <div className="rounded-2xl bg-slate-100 px-4 py-2.5 text-sm text-slate-500">Looking that up…</div>
            </div>
          )}

          {send.isError && (
            <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
              {errorMessage(send.error)}
            </p>
          )}
        </div>

        <form onSubmit={submit} className="border-t border-slate-100 p-3">
          <div className="flex gap-2">
            <Textarea
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && !event.shiftKey) submit(event)
              }}
              placeholder="Ask about leads, follow-ups, agents, projects…"
              rows={2}
              className="min-h-0 resize-none"
            />
            <Button type="submit" disabled={draft.trim() === '' || send.isPending}>
              Send
            </Button>
          </div>
        </form>
      </Card>

      {conversations.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2">
          {conversations.slice(0, 6).map((conversation) => (
            <button
              key={conversation.id}
              onClick={() => setConversationId(conversation.id)}
              className={cx(
                'max-w-56 truncate rounded-lg px-2.5 py-1 text-xs transition',
                conversation.id === conversationId
                  ? 'bg-brand-50 text-brand-700'
                  : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50',
              )}
              title={conversation.title}
            >
              {conversation.title}
              {conversation.last_message_at && (
                <span className="ml-1 text-slate-400">· {relative(conversation.last_message_at)}</span>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
