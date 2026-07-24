---
description: Fetch a GitHub bug issue, reproduce and fix it test-first, then commit and open a PR
argument-hint: <issue-number>
allowed-tools: Bash(gh *), Bash(git *), Bash(composer *), Agent, Read
---

Drive the full bug-fix pipeline for GitHub issue `$ARGUMENTS` in `ntm-dev/laravel-monitor`,
end to end: fetch → branch → fix → verify → commit → PR. This command is the explicit,
scoped authorization to commit and open a PR as part of running it — no need to ask again for
those two steps specifically, but stop and ask if anything below says to.

## 1. Fetch the issue

Run `gh issue view $ARGUMENTS --json number,title,body,labels,url,state`. If it's not found,
report that and stop.

- If `state` is `CLOSED`, tell the user and ask whether to proceed anyway.
- If none of the labels is `bug`, warn that this looks like it might not be a bug report (show
  its actual labels) before continuing — the issue's content is still the source of truth, this
  is just a sanity check.

## 2. Prepare a clean branch

- `git status` — if the working tree isn't clean, stop and tell the user what's dirty; do not
  stash or discard anything yourself.
- Make sure you're on the default branch and up to date: `git fetch origin`, then branch off
  `origin/master`.
- Create branch `fix/issue-<number>-<short-kebab-slug-of-title>` (mirror this repo's existing
  `fix/...` branch naming).

## 3. Fix it

Read `AGENTS.md` for architecture/conventions context, then dispatch to the `issue-bug-fixer`
subagent with the issue's number, title, body, and URL verbatim. Run it in the foreground —
you need its result before continuing.

## 4. Review before committing

- Read the subagent's report. If it says the fix is incomplete, tests don't pass, or something
  needs a manual/browser check it couldn't do itself, stop here and report that back to the
  user instead of committing.
- `git status` / `git diff` — confirm the changed files match what the subagent described.
  Don't stage anything unexpected (stray scratch files, unrelated edits).
- Re-run `composer test` yourself as a final gate before committing.

## 5. Security check

Run the `security-review` skill against the branch's diff before committing. This package
stores and re-renders framework-captured data (request bodies/headers, query bindings,
exception messages) straight into the dashboard, so pay particular attention to: any new
`{!! !!}` (unescaped Blade output — must be `{{ }}` unless the value is provably not
user-influenced), raw SQL built by string concatenation instead of the query builder / bound
parameters, and any new `Bash`/shell-out call built from stored data.

- If it finds issues, fix them and re-run `composer test`, then re-check the diff.
- If a finding is ambiguous or you're not confident the fix is complete, stop and report it to
  the user instead of committing anyway.

## 6. Commit

- `git add` the specific files the subagent changed (never `git add -A`/`.`).
- Commit message: a concise conventional summary (`fix: ...`) plus a blank line and
  `Fixes #<number>`. **Do not add a `Co-Authored-By` trailer or any Claude/session link** —
  this repo's local conventions explicitly exclude both from commits and PRs.

## 7. Push and open the PR

- `git push -u origin <branch>`.
- `gh pr create --base master --title "<same summary as the commit>" --body "<Summary of the
  fix and root cause, then 'Closes #<number>', then a Test plan section listing the regression
  test added and the `composer test` result>"`.
- Do not merge the PR yourself. Report the PR URL back to the user and stop.

## If something goes wrong

If the subagent can't reproduce the bug, or the fix requires a decision the issue doesn't
answer (e.g. two valid interpretations of the expected behavior), stop and ask the user rather
than guessing — do not commit a speculative fix.
