import { mkdirSync, readFileSync, writeFileSync, copyFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { join, dirname } from 'node:path'
import { buildSync } from 'esbuild'

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const source = join(root, 'office-source')
const output = join(root, 'public/office-scene')
mkdirSync(output, { recursive: true })
for (const file of ['style.css', 'app.js', 'agent-links.js', 'characters-handdrawn.svg', 'icon.svg', 'crm-bridge.js', 'crm-office.css']) {
  copyFileSync(join(source, file), join(output, file))
}
for (const entry of ['office-3d', 'gaga-team']) {
  buildSync({ entryPoints: [join(source, `${entry}.mjs`)], bundle: true, minify: true, format: 'iife', target: 'es2022', outfile: join(output, `${entry}.js`), legalComments: 'eof' })
}
const html = readFileSync(join(source, 'index.html'), 'utf8')
  .replace('<html lang="en">', '<html lang="zh-CN">')
  .replace('<body>', '<body class="crm-office">')
  .replace('<link rel="stylesheet" href="office-chat.css">', '')
  .replace('</head>', '<link rel="stylesheet" href="crm-office.css"></head>')
  .replace('<script src="office-chat.js"></script>', '<script src="crm-bridge.js"></script>')
writeFileSync(join(output, 'index.html'), html)
console.log('Built Office scene with CRM chat bridge; no server credentials or runtime data included.')
