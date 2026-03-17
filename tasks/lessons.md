# Lessons and Working Rules

## User Workflow Preferences (Captured 2026-03-17)
- Default to explicit planning for non-trivial tasks and re-plan immediately if execution deviates.
- Use subagents liberally for parallel research/implementation to keep primary context focused.
- Track work in `tasks/todo.md` with checkable progress and a review section.
- After user corrections, update this file with prevention rules.
- Verify before completion (tests/build/logs) and do not mark done without proof.
- Prefer elegant but minimal-impact solutions; avoid hacky temporary fixes.
- For bug reports, fix autonomously with minimal user context switching.

## Prevention Rules
- Before coding substantial changes: write plan + verification criteria.
- During execution: keep progress updated in task checklist.
- Before final response: include verification evidence and known limits.
- For this project, do not use or start local MySQL for deployment/migration tasks; execute deployment flow via API/server environment only.
