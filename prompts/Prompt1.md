# Prompt 1: chat assistants (ChatGPT, Claude, Gemini, Perplexity, Copilot Chat)

Paste this at the start of a conversation with an assistant that can browse the web.

```text
Before answering, read https://ai.phpue.co.uk/llms-full.txt (if you can only fetch small pages, start
with https://ai.phpue.co.uk/llms.txt and open the topics you need, e.g.
https://ai.phpue.co.uk/knowledge/ai-rules.md).

It is the canonical, LLM-ready reference for PHPue 0.0.3: a server-side PHP framework that compiles
Vue-like .pvue files (<script> = PHP, <header>, <template>, <cscript> = browser JS) into plain PHP.

Rules:
- PHPue is new. Do not rely on general knowledge, and do not assume Vue behaviour: there is no
  reactivity, no props, no slots, no v-model, no p-else, no p-show and no p-model.
- Use only syntax documented in that reference. Quote or adapt its code examples when relevant.
- Check the project's version (grep -m1 PHPUE_VERSION www/index.php). The reference covers 0.0.3
  and lists the stricter 0.0.2 rules in its "upgrade" topic.
- If the reference does not cover something, say so, suggest plain PHP (always valid in PHPue),
  and point to https://phpue.co.uk/docs.
- Link the topic you used, e.g. https://ai.phpue.co.uk/docs?topic=ajax.
```
