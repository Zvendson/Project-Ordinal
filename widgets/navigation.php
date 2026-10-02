<?php

/** Shows shared navigation with administrator links only for verified page data. */
declare(strict_types=1);
?>
<nav class="navigation" aria-label="Main navigation">
    <a href="/">Home</a>
    <a href="/projects">Projects</a>
    <a href="/devices">Devices</a>
    <a href="/account">Account</a>
    <?php if ($data['isAdministrator'] ?? false): ?><a href="/administration">Administration</a><a href="/logs">Audit logs</a><?php endif; ?>
    <?php if (!isset($data['session'])): ?><a href="/login">Sign in</a><?php endif; ?>
</nav>
