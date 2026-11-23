<?php
/**
 * The page of an MCP App: a self-contained document whose first script defines `McpApps`, the official MCP Apps
 * client, so the template's own module scripts can use `new McpApps.App(...)`.
 *
 * @var \Cake\View\View $this
 */
use CakeDC\Mcp\App\AppBridge;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($this->fetch('title')) ?></title>
<?= $this->fetch('css') ?>
<script type="module"><?= AppBridge::script() ?></script>
</head>
<body>
<?= $this->fetch('content') ?>
<?= $this->fetch('script') ?>
</body>
</html>
