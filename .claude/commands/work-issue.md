---
description: Auto-detect a GitHub issue's type from its labels and run the matching fix/implement pipeline
argument-hint: <issue-number>
allowed-tools: Bash(gh *), Bash(git *), Bash(composer *), Agent, Read
---

Look up GitHub issue `$ARGUMENTS` in `ntm-dev/laravel-monitor`, decide which workflow it needs,
and run that workflow to completion. This is the "just handle it" entry point — for explicit
control over which workflow runs, use `/fix-issue` or `/implement-issue` directly instead.

## 1. Fetch and classify

Run `gh issue view $ARGUMENTS --json number,title,body,labels,url,state`.

- Labeled `bug` → this is a bug fix.
- Labeled `enhancement` → this is a feature.
- Labeled `documentation`, `question`, `duplicate`, `invalid`, or `wontfix` → not something this
  automation handles. Report that and stop; don't force it into the wrong pipeline.
- Both `bug` and `enhancement`, or neither label present → read the title and body yourself and
  decide which pipeline fits based on content (does it describe something broken, or something
  new?). If it's genuinely ambiguous, ask the user to pick rather than guessing.
- `state: CLOSED` → tell the user and confirm before proceeding.

## 2. Run the matching pipeline

Once classified, carry out the exact same pipeline as the matching command, using issue
`$ARGUMENTS`:

- Bug → read and follow `.claude/commands/fix-issue.md` in full (branch as `fix/issue-...`,
  dispatch to the `issue-bug-fixer` subagent, commit `fix: ...` / `Fixes #<number>`).
- Feature → read and follow `.claude/commands/implement-issue.md` in full (branch as
  `feat/issue-...`, dispatch to the `issue-feature-builder` subagent, commit `feat: ...` /
  `Closes #<number>`).

Do not skip or shortcut any step in the chosen command — this command only replaces the
classification decision, not the pipeline itself.
