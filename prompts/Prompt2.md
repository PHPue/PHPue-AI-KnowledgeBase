# Prompt 2: coding agents (Claude Code, Codex, Cursor, Copilot agent, Gemini CLI, Windsurf)

## Option A: add the rules to the project (recommended)

Save the "AGENTS.md / CLAUDE.md for PHPue projects" block from
https://ai.phpue.co.uk/docs?topic=ai-rules#agents-md-claude-md-for-phpue-projects
as `AGENTS.md` (Codex, Cursor, Copilot, Gemini CLI) and/or `CLAUDE.md` (Claude Code) in the project root.
Every session then follows PHPue's rules without being reminded.

## Option B: paste this as the first message

```text
This repository is a PHPue app (https://phpue.co.uk). Before changing any .pvue file:

1. Fetch https://ai.phpue.co.uk/llms-full.txt and treat it as the source of truth for PHPue syntax.
   PHPue compiles .pvue files to PHP on the server. It is NOT Vue.
2. Check the version: grep -m1 PHPUE_VERSION www/index.php (the reference covers 0.0.3; the
   "upgrade" topic lists the stricter 0.0.2 rules).
3. Read www/App.pvue, one sibling view and one sibling component, and follow their conventions.
4. Never edit www/index.php or www/conversion.php (the framework core). Delete www/.dist/ while developing.
5. After each change, compile and syntax-check the files you touched, from www/:
   php -r 'define("PHPUE_VERSION","0.0.3"); define("PHPUE_LANG","en"); require "conversion.php"; echo convert_pvue_file("views/<file>.pvue");' | php -l
   (use convert_pvue_file("App.pvue", true) for App.pvue)
6. Run it: cd www && php -S localhost:3000, and test @AJAX handlers with curl
   (curl -X POST -H 'Content-Type: application/json' -d '{"action":"name"}' http://localhost:3000/<page>).

Task: <describe what you want built>
```
