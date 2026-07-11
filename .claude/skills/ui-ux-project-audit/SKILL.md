---
name: ui-ux-project-audit
description: Read-only UI/UX discovery of all user-facing pages — inventory, flows, forms, states, mobile, accessibility, consistency — ending in a page-by-page findings table and a proposed design direction. Never changes Blade, CSS, JS, or business logic.
disable-model-invocation: true
---

# UI/UX project audit (read-only discovery)

Use manually before any redesign or UI milestone. This skill never modifies Blade, CSS, JavaScript, or business logic.

## Steps

1. Load current context: `/project-context` equivalents (vault via MCP, Git state) and the UI rule `.claude/rules/ui-ux-project.md`.
2. Inventory every user-facing page (from `routes/web.php` and `resources/views/`) and its main flow: entry point, purpose, primary action.
3. Review per page: navigation; information order; forms; status states; loading/empty/error/success states; mobile behavior; keyboard access; contrast; component consistency.
4. Read the actual Blade and inline-JS sources — do not audit from memory or screenshots alone.
5. If no browser smoke evidence exists for a flow, record that explicitly — markup review does not prove runtime behavior.
6. Distinguish UI problems from backend/security/authorization problems; report the latter separately, not as design findings.
7. The generic documents-module defect (schema mismatch, missing owner scope) is a separate blocker — never fold it into a UI redesign scope.
8. Propose: design direction; design tokens; type scale; spacing scale; colors and semantic statuses; navigation structure; components; responsive behavior; accessibility fixes; a phased implementation plan.
9. Produce a page-by-page table: current state | problem | proposed state | risk | priority.
10. Do **not** create `design-system/MASTER.md` (or any design-system file) until the user approves the proposed direction.

## External ui-ux-pro-max plugin

Check whether the external `ui-ux-pro-max` skill/plugin is actually installed and available under its real (namespaced) name before using it.

- If available: use it **only as advisory design intelligence**, with queries oriented to: legal document workflow, contract management, trust and authority, accessible enterprise forms, Laravel Blade, HTML + Tailwind. Project rules, code, security, and the actual UX always outrank its generic recommendations.
- If not available: do not install it; do not run npm/npx/pip or any installer; do not copy or vendor external repository content. Complete the audit with this skill alone and report that the plugin is unavailable, listing the official manual install commands for the user to approve separately.

## Constraints

- Read-only: no Blade/CSS/JS/business-logic changes, no vault writes, no Git changes.
