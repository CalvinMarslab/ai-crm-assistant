'use strict';
// The frame only requests a UI action. The authenticated CRM owns all API calls.
window.openOfficeChat = index => {
  if (!Number.isInteger(index) || index < 0 || index > 4) return;
  if (window.parent === window) { window.location.assign('/office'); return; }
  window.parent.postMessage({ type: 'techweiser-office-chat', index }, window.location.origin);
};
