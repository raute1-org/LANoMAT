#!/usr/bin/env bash
# PreToolUse (Write|Edit) hook for LANoMAT.
# On any frontend file edit (.vue / .css / resources/js|css), inject a reminder
# to follow the "Signalpult" design system (docs/design.md) and to use the
# frontend-design skill for design decisions. Non-frontend edits pass through
# untouched. Never blocks — emits {} (or nothing) on anything unexpected.
jq -c '
  (.tool_input.file_path // "") as $f
  | if ($f | test("\\.(vue|css)$")) or ($f | test("resources/(js|css)/"))
    then {hookSpecificOutput: {hookEventName: "PreToolUse", additionalContext: "LANoMAT frontend edit — follow the Signalpult design system (docs/design.md): use semantic token utilities only (no raw hex); Space Grotesk + JetBrains Mono (mono for machine data); one rationed signal-amber accent; LiveIndicator for live state; provide empty/loading/error/normal states; respect prefers-reduced-motion. For any design decision invoke the frontend-design skill. (Binding per CLAUDE.md Conventions.)"}}
    else {} end
' 2>/dev/null || true
