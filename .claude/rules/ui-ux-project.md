---
paths:
  - "resources/views/**/*.blade.php"
  - "resources/js/**/*.js"
  - "resources/css/**/*.css"
  - "public/**/*.js"
  - "public/**/*.css"
---

# UI/UX project rule

## Technical boundaries

- The runtime Blade workflow does **not** use a Vite build. Follow the existing Laravel Blade + Tailwind Browser CDN approach until the architecture is explicitly changed.
- Do not introduce npm, Vite, React, Vue, Livewire, or Inertia without an explicit decision.
- Do not add a new runtime dependency, font CDN, or icon CDN without approval.
- A UI-only task must not change business logic, authorization, migrations, or security invariants.
- The final PDF and the web UI are separate renderings; do not touch the PDF templates (`resources/views/contracts/pdf/**`) unless they are explicitly in scope.

## Design direction

This is a serious document/contract workflow. Priorities, in order: clarity, trust, readability, visible status + next action, easy form completion, visible security notices, consistency.

Avoid: decorative glassmorphism without function; neon or AI purple/pink gradients; excessive animation; emoji as functional icons; icon-only controls without labels; overcrowded cards; hidden primary actions; color as the only status indicator; poor contrast or tiny text.

## Accessibility and responsive minimum

- Semantic HTML; correct `label for`; visible keyboard focus; logical tab order.
- Contrast at least WCAG AA; body text at least 16px on mobile; touch targets ~44×44px.
- Errors adjacent to their field; `aria-live`/`role="alert"` where needed; never rely on placeholder alone.
- Do not block browser zoom; no horizontal scroll at 375px; check at 375, 768, 1024, and 1440px.
- Respect `prefers-reduced-motion`; animate only to explain a state change.
