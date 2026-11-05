import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { aiApi } from '@/api/endpoints'
import { ActionRequestCard } from '@/components/ActionRequestCard'
import { Button, Spinner } from '@/components/ui'
import { OFFICE_AGENTS, OFFICE_MESSAGE_LIMIT, conversationStorageKey, officeMessage, officePrompt, type OfficeAgent } from '@/lib/office'

function read(key: string) { try { return localStorage.getItem(key) || '' } catch { return '' } }
function write(key: string, value: string) { try { localStorage.setItem(key, value) } catch { /* The current session still works without storage. */ } }

export function OfficeChat({ userId, agent, onSelect, onClose }: {
  userId: string; agent: OfficeAgent; onSelect: (index: number) => void; onClose: () => void
}) {
  const dialog = useRef<HTMLDialogElement>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { dialog.current?.showModal() }, [])
  return <dialog className="crm-office-dialog" ref={dialog} aria-labelledby="office-chat-title"
    onCancel={event => { event.preventDefault(); onClose() }} onClick={event => { if (event.target === dialog.current) onClose() }}>
    <header className="crm-office-chat-heading">
      <div><p className="text-xs font-semibold tracking-widest text-brand-600">TECHWEISER / AI OFFICE</p><h2 id="office-chat-title">安排下一件工作</h2></div>
      <button type="button" onClick={onClose} aria-label="关闭 Office 对话" className="crm-office-close">×</button>
      <label className="crm-office-role">与你协作的助理
        <select value={agent.id} disabled={busy} onChange={event => onSelect(OFFICE_AGENTS.findIndex(item => item.id === event.target.value))}>
          {OFFICE_AGENTS.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
        </select>
      </label>
      <p className="text-xs text-slate-500">对话保存在你的 CRM 账户中，不包含原聊天历史。CRM 变更会先列出，待你确认。</p>
      {agent.url && <a href={agent.url} target="_blank" rel="noopener noreferrer" className="text-xs text-brand-600">打开原聊天 ↗</a>}
    </header>
    <Conversation key={agent.id} userId={userId} agent={agent} onBusy={setBusy} />
  </dialog>
}

function Conversation({ userId, agent, onBusy }: { userId: string; agent: OfficeAgent; onBusy: (busy: boolean) => void }) {
  const storageKey = conversationStorageKey(userId, agent)
  const [conversationId, setConversationId] = useState<string | null>(() => read(storageKey) || null)
  const [draft, setDraft] = useState(() => read(`${storageKey}:draft`))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const lock = useRef(false)
  const request = useRef<{ text: string; key: string; conversationId: string } | null>(null)
  const log = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()
  const { data: status, isPending: statusPending, error: statusError, refetch: checkStatus } = useQuery({ queryKey: ['ai', 'status'], queryFn: aiApi.status })
  const { data: messages = [], isFetching: fetchingMessages, error: historyError } = useQuery({
    queryKey: ['ai', 'messages', conversationId], queryFn: () => aiApi.messages(conversationId!), enabled: conversationId !== null,
  })
  const { data: proposals = [] } = useQuery({ queryKey: ['ai', 'action-requests'], queryFn: () => aiApi.actionRequests() })
  useEffect(() => { log.current?.scrollTo({ top: log.current.scrollHeight }) }, [messages.length, busy])

  async function submit(event: FormEvent) {
    event.preventDefault()
    const text = draft.trim()
    if (!text || text.length > OFFICE_MESSAGE_LIMIT || lock.current || !status?.chat_available || historyError) return
    lock.current = true; setBusy(true); onBusy(true); setError('')
    try {
      let id = conversationId
      if (!id) {
        id = (await aiApi.startConversation()).id
        write(storageKey, id); setConversationId(id)
      }
      // Keep the same key for an uncertain retry of the same request.
      if (!request.current) {
        try {
          const saved = JSON.parse(read(`${storageKey}:request`))
          if (saved && typeof saved.key === 'string' && /^[a-f0-9-]{36}$/.test(saved.key) && saved.text === text && saved.conversationId === id) request.current = saved
        } catch { /* No previous uncertain request. */ }
      }
      if (request.current?.text !== text || request.current?.conversationId !== id) request.current = { text, key: crypto.randomUUID(), conversationId: id }
      write(`${storageKey}:request`, JSON.stringify(request.current))
      await aiApi.send(id, officePrompt(agent, text), request.current.key)
      request.current = null; write(`${storageKey}:request`, ''); setDraft(''); write(`${storageKey}:draft`, '')
    } catch {
      setError('回复未确认，草稿已保留。请先查看对话记录，再重试。')
    } finally {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['ai', 'messages'] }),
        queryClient.invalidateQueries({ queryKey: ['ai', 'conversations'] }),
        queryClient.invalidateQueries({ queryKey: ['ai', 'action-requests'] }),
      ])
      lock.current = false; setBusy(false); onBusy(false)
    }
  }

  return <>
    <div className="crm-office-chat-service" role="status">
      {statusPending ? '正在检查 AI 服务…' : statusError ? '暂时无法连接 CRM AI 服务。' : status?.chat_available ? '● CRM AI 服务已连接' : 'CRM AI 服务尚未启用；可以先保存工作草稿。'}
      {(!status?.chat_available || statusError) && !statusPending && <button onClick={() => void checkStatus()}>重新检查</button>}
      <button disabled={busy} onClick={() => { setConversationId(null); write(storageKey, ''); write(`${storageKey}:request`, ''); request.current = null; setError('') }}>新对话</button>
    </div>
    <div ref={log} className="crm-office-chat-log" role="log" aria-live="polite" aria-label="Office 对话记录">
      {!conversationId && <div className="crm-office-chat-empty"><h3>把想法变成下一步</h3><p>告诉 {agent.name} 你的目标、已有资料和期限。</p></div>}
      {fetchingMessages && !messages.length && <Spinner />}
      {historyError && <p role="alert">无法读取这段对话。请重新登录或点击「新对话」。</p>}
      {messages.map(message => <div key={message.id} className={`crm-office-message ${message.role}`}><strong>{message.role === 'user' ? '你' : agent.name}</strong><p>{message.role === 'user' ? officeMessage(agent, message.content || '') : message.content}</p></div>)}
      {busy && <p className="text-sm text-slate-500" role="status">正在回复…</p>}
      {proposals.filter(item => item.status === 'pending').map(item => <ActionRequestCard key={item.id} request={item} />)}
    </div>
    <form className="crm-office-chat-form" onSubmit={event => void submit(event)}>
      <label htmlFor="office-work-request">工作要求</label>
      <textarea id="office-work-request" value={draft} maxLength={OFFICE_MESSAGE_LIMIT} disabled={busy}
        placeholder="例如：帮我制定本周销售跟进计划，列出优先级和每日交付。"
        onChange={event => { setDraft(event.target.value); write(`${storageKey}:draft`, event.target.value) }}
        onKeyDown={event => { if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); event.currentTarget.form?.requestSubmit() } }} />
      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      <div className="crm-office-chat-actions"><span>Ctrl / ⌘ + Enter 发送 · 草稿保存在此浏览器</span><Button type="submit" disabled={busy || !status?.chat_available || !!historyError || !draft.trim()}>{busy ? '正在回复…' : '发送工作 →'}</Button></div>
    </form>
  </>
}
