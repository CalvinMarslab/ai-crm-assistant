# AI Office on yun.techweiser.my

Authenticated staff with `ai.use` can open **AI Office** at `/office`. Referral-only and service-only routes are unchanged. The original CRM dashboard stays at `/`.

`frontend/office-source` contains the existing Techweiser and Gaga campus, hand-drawn characters, gym, zoom, and contextual character controls. Source imported from the user's Techweiser AI Office Site, commit `3326e0274a869611dae0e5bd0b0b9e857e346658`. No Site Worker, hosting identity, environment files, or API keys are copied. `npm run dev` and `npm run build` generate the scene into `public/office-scene`; generated bundles are ignored.

The scene posts only a validated character index to its same-origin CRM parent. The parent checks both origin and iframe identity. The existing authenticated API client handles chat. Each user/role has a separate conversation ID; messages are stored by the existing CRM backend. Drafts and conversation pointers are scoped to the user and role in browser storage. Original Codex/ChatGPT links remain available; those histories are not imported. Gaga links continue to open their original chats.

The AI service must already be configured in the CRM backend. A disconnected service is displayed honestly and does not prevent scene interaction. No backend change, migration, DNS change, or new API key is required by this integration. Proposed CRM changes continue to use the existing explicit confirmation cards and backend permission checks.

Validation: `cd frontend && npm run test:office && npm run build`. Check `/office` after signing in, character selection/collapse, walk/sit/gym, zoom, role chat, existing routes, and mobile layout. Cloudflare Pages uses the existing production environment/API URL. Rollback by reverting this integration commit and letting Pages rebuild.
