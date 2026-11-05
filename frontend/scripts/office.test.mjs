import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { readFileSync } from 'node:fs'
import { OFFICE_AGENTS, OFFICE_MESSAGE_LIMIT, chatIndex, conversationStorageKey, officePrompt, officeMessage } from '../src/lib/office.ts'

test('only a valid Office character can request the CRM chat', () => {
  for (const index of [0, 1, 2, 3, 4]) assert.equal(chatIndex({ type: 'techweiser-office-chat', index }), index)
  for (const input of [null, {}, { type: 'wrong', index: 0 }, ...[-1, 5, 6, 1.5, '0', NaN, Infinity].map(index => ({ type: 'techweiser-office-chat', index }))]) assert.equal(chatIndex(input), null)
})
test('each user and role has an independent conversation pointer', () => {
  const keys = ['user-a', 'user-b'].flatMap(user => OFFICE_AGENTS.map(agent => conversationStorageKey(user, agent)))
  assert.equal(new Set(keys).size, 12)
})
test('role context fits the existing backend limit and does not alter displayed user input', () => {
  for (const agent of OFFICE_AGENTS) {
    const text = '你好\n'.repeat(1000)
    assert.equal(officeMessage(agent, officePrompt(agent, text)), text)
    assert.equal(officeMessage(agent, 'Unrelated conversation'), 'Unrelated conversation')
    assert.ok(officePrompt(agent, 'x'.repeat(OFFICE_MESSAGE_LIMIT)).length <= 4000)
  }
})
test('scene bridge sends no tokens or API requests and ignores human/Gaga indices', () => {
  const sent = []
  const window = { parent: { postMessage: (...args) => sent.push(args) }, location: { origin: 'https://yun.techweiser.my' } }
  vm.runInNewContext(readFileSync(new URL('../office-source/crm-bridge.js', import.meta.url), 'utf8'), { window })
  window.openOfficeChat(0); window.openOfficeChat(5); window.openOfficeChat(6); window.openOfficeChat('0')
  assert.equal(sent.length, 1)
  assert.equal(JSON.stringify(sent[0]), JSON.stringify([{ type: 'techweiser-office-chat', index: 0 }, 'https://yun.techweiser.my']))
})
