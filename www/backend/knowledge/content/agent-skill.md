# PHPue Agent Skill for Claude Code and Codex

> Download the **PHPue skill** ([phpue.zip](/skills/phpue.zip)) to teach a coding agent PHPue. It is the same skill the PHPue author uses with Claude Code and Codex: a `SKILL.md` with the hard rules and workflow, detailed references verified against the 0.0.3 compiler, a starter app to copy, and two PHP scripts that compile and lint `.pvue` files with your project's own `conversion.php`. The agent loads it automatically whenever it works on a PHPue project.

## What's inside

```text
phpue/
├── SKILL.md                       rules, workflow and reference map (loaded by the agent)
├── agents/openai.yaml             display metadata for Codex
├── assets/                        icons
├── references/
│   ├── vanilla.md                 complete PHPue Vanilla reference (blocks, directives, components, AJAX, backend, scope)
│   ├── alpine-xssr.md             AlpineJS + the x-ssr directive in depth
│   ├── runtime-build-deploy.md    serving modes, dev tools, .dist builds, deployment, 404 pages, security
│   ├── engine-gotchas.md          verified input -> output table of compiler behaviour (0.0.3 and 0.0.2)
│   ├── extensions.md              MetaControl, Matrix CMS, x-ssr, LanguageSelector
│   └── htmx-spa.md                HTMX navigation, partials, NGINX caching and lazy sessions
├── templates/                     starter app that passes the linter: App.pvue, views, components,
│                                  backend singleton, 404 pages, .htaccess, Tailwind, Alpine + x-ssr,
│                                  docker-compose.yaml, nginx config
└── scripts/
    ├── phpue-compile.php          print (and php -l) the PHP a .pvue file compiles to
    └── phpue-lint.php             version-aware linter for a whole PHPue project
```

The scripts need only PHP 8 on the command line. The references cover the same ground as this site in more depth; this site's topics are the shorter, public-facing version.

## Install for Claude Code

User-wide (every project):

```bash
curl -fsSL https://ai.phpue.co.uk/skills/phpue.zip -o phpue-skill.zip
unzip -o phpue-skill.zip -d ~/.claude/skills
```

Or for one project only (commit it so your whole team gets it): unzip into `.claude/skills` in the project root instead of `~/.claude/skills`.

Check it loaded: start Claude Code in a PHPue project and ask "which skills do you have?", or type `/phpue`.

## Install for Codex

```bash
curl -fsSL https://ai.phpue.co.uk/skills/phpue.zip -o phpue-skill.zip
unzip -o phpue-skill.zip -d ~/.codex/skills
```

Restart Codex so it picks up the new skill.

## Windows (PowerShell)

```powershell
Invoke-WebRequest https://ai.phpue.co.uk/skills/phpue.zip -OutFile phpue-skill.zip
Expand-Archive phpue-skill.zip -DestinationPath "$HOME\.claude\skills" -Force
```

Use `"$HOME\.codex\skills"` for Codex.

## Claude apps (claude.ai, desktop)

Upload `phpue.zip` as a custom skill in the app's skills settings (Settings, then Capabilities). The zip already has the expected layout: one `phpue/` folder with `SKILL.md` at its top.

## Let the agent install it

Paste this into Claude Code or Codex:

```text
Download https://ai.phpue.co.uk/skills/phpue.zip and unzip it into my user skills folder
(~/.claude/skills for Claude Code, ~/.codex/skills for Codex), replacing any older phpue skill.
Then list the files in the phpue skill folder.
```

## Using the scripts directly

From your project's `www/` folder (replace the path with where you installed the skill):

```bash
php ~/.claude/skills/phpue/scripts/phpue-compile.php views/page.pvue --lint
php ~/.claude/skills/phpue/scripts/phpue-compile.php App.pvue --root --lint
php ~/.claude/skills/phpue/scripts/phpue-lint.php .
```

The linter reads `PHPUE_VERSION` from `index.php` and applies the 0.0.2 or 0.0.3 rules (override with `--version=0.0.3`). It exits with code 1 when it finds errors, so it also works in CI.

## Updating

Download the zip again and unzip over the old folder (`unzip -o` replaces files). Start new projects from `templates/` in the skill folder.

## Other agents

Agents without skill support (Cursor, Copilot, Gemini CLI, Windsurf) can use the same knowledge through an `AGENTS.md` file ([template](/docs?topic=ai-rules)) or by reading [/llms-full.txt](/llms-full.txt).
