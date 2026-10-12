<?php
global $phpue_header;
$phpue_header = <<<HTML
    <title>404 - Page Not Found</title>
    <meta name="description" content="The page you're looking for doesn't exist">
    <meta name="robots" content="noindex">
HTML;

$GLOBALS['phpue_http404_header'] = $phpue_header;
?>
