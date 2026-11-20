<?php
declare(strict_types=1);

/**
 * @var \Cake\View\View $this
 * @var int $count
 */
?>
<h1>Inbox: <?= h($count) ?> orders to read</h1>
<script type="module">
    const app = new McpApps.App({name: 'Inbox', version: '1.0.0'});
    await app.connect();
</script>
