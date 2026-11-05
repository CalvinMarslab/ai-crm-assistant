import { useEffect, useRef, useState } from 'react'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui'
import { OfficeChat } from '@/components/OfficeChat'
import { chatIndex, OFFICE_AGENTS } from '@/lib/office'
import { useAuth } from '@/hooks/useAuth'
import './office.css'

export default function OfficePage() {
  const frame = useRef<HTMLIFrameElement>(null)
  const { user } = useAuth()
  const [agentIndex, setAgentIndex] = useState<number | null>(null)
  const [loaded, setLoaded] = useState(false)

  useEffect(() => {
    function receive(event: MessageEvent) {
      if (event.origin !== window.location.origin || event.source !== frame.current?.contentWindow) return
      const index = chatIndex(event.data)
      if (index !== null) setAgentIndex(index)
    }
    window.addEventListener('message', receive)
    return () => window.removeEventListener('message', receive)
  }, [])

  return <div className="crm-office-page">
    <PageHeader title="AI Office" subtitle="Techweiser · Gaga · 共享健身房。点击人物展开操作台，再安排工作。"
      action={<Button variant="secondary" size="sm" onClick={() => setAgentIndex(0)}>安排工作 ↗</Button>} />
    <div className="crm-office-scene">
      {!loaded && <p className="crm-office-loading" role="status">正在进入办公室…</p>}
      <iframe ref={frame} title="Techweiser 与 Gaga 的 3D AI Office" src="/office-scene/index.html"
        onLoad={() => setLoaded(true)} allowFullScreen />
    </div>
    <p className="mt-3 text-xs text-slate-500">滚轮缩放 · 拖动旋转 · 右键拖动平移。人物动作是场景展示；实际工作以对话及 CRM 记录为准。</p>
    {agentIndex !== null && user && <OfficeChat key={user.id} userId={user.id}
      agent={OFFICE_AGENTS[agentIndex]} onSelect={setAgentIndex} onClose={() => { setAgentIndex(null); frame.current?.focus() }} />}
  </div>
}
