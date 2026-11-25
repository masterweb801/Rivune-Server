<?php
declare(strict_types=1);
// The legacy static frontend is no longer the deployed entry point.
// Send directory visitors through the PHP homepage, where maintenance checks run.
header('Location: ../', true, 302);
exit;
