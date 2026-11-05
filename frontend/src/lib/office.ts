export const OFFICE_AGENTS = [
  { id: 'sales', name: 'Kai · 销售', role: '整理客户需求、销售跟进、报价前资料和销售文案。', url: 'https://chatgpt.com/c/6aa9fa5d-8480-83ec-9311-e4918ada9c38' },
  { id: 'delivery', name: 'Nova · 交付规划', role: '整理项目范围、里程碑、任务清单和验收标准。', url: 'codex://threads/01a171c8-b50c-7942-a3d1-b12a9ed94c9f' },
  { id: 'design', name: 'Pixel · 设计', role: '制定设计简报、创意方向、文案和页面结构。', url: null },
  { id: 'operations', name: 'Atlas · 运营', role: '将经营目标拆成工作计划，识别依赖、优先级和待决定事项。', url: 'codex://threads/01a171d5-a0aa-7b10-be55-bf817f540410' },
  { id: 'finance', name: 'Ledger · 财务估算', role: '依据提供的数据估算成本、利润和预算，明确列出假设。', url: 'codex://threads/01a17196-632a-7460-9ec4-167c9dfa87e1' },
  { id: 'account', name: 'AI 会计', role: '整理发票、收款、对账与待核对清单，不编造记录。', url: 'codex://threads/01a17196-7112-7e23-b365-ad5ffd78ad80' },
] as const

export type OfficeAgent = typeof OFFICE_AGENTS[number]
export const OFFICE_MESSAGE_LIMIT = 3500

export function chatIndex(data: unknown): number | null {
  if (!data || typeof data !== 'object') return null
  const message = data as { type?: unknown; index?: unknown }
  return message.type === 'techweiser-office-chat' && Number.isInteger(message.index) &&
    typeof message.index === 'number' && message.index >= 0 && message.index < 5 ? message.index : null
}

export function officePrompt(agent: OfficeAgent, message: string): string {
  return `【AI Office · ${agent.name}】\n请以 Techweiser ${agent.name} 的角色协助我：${agent.role} 默认使用中文。遵守 CRM 权限；业务变更仍须经过确认。这是 CRM 内的独立对话，不包含原 Codex 或 ChatGPT 历史。\n\n工作要求：\n${message}`
}

export function officeMessage(agent: OfficeAgent, content: string): string {
  const prefix = officePrompt(agent, '')
  return content.startsWith(prefix) ? content.slice(prefix.length) : content
}

export function conversationStorageKey(userId: string, agent: OfficeAgent): string {
  return `techweiser:office:conversation:v1:${userId}:${agent.id}`
}
