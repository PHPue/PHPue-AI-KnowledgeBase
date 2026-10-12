<?php
    /*
        PHPue AI Knowledge - backend bootstrap.

        PHPue only requires this file for the knowledge/ folder (autoload.php stops the recursive scan),
        so the load order below is fixed and the markdown in content/ is never treated as PHP.

        content/*.md is the single source of truth. It feeds the HTML pages (/, /docs, /faq),
        the agent endpoints (/llms.txt, /llms-full.txt, /knowledge/<slug>.md), sitemap.xml,
        JSON-LD, the downloadable Agent Skill (skill/) and the Gemini chat's grounding.
    */
    require_once __DIR__ . '/../PHPuePrivate.php';   // private logs/cache (never the shared /tmp)
    require_once __DIR__ . '/PHPueMarkdown.php';
    require_once __DIR__ . '/PHPueKnowledge.php';
    require_once __DIR__ . '/PHPueSkill.php';
    require_once __DIR__ . '/PHPueAgentEndpoints.php';

    // Plain-text/markdown/XML endpoints answer here and exit, before any page renders.
    PHPueAgentEndpoints::handle();
?>
