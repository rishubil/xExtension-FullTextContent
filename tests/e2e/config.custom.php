<?php
// Merged into data/config.php by do-install.php. The suite publishes feed
// items and refreshes immediately, so SimplePie's feed cache (800 s by
// default, 60 s minimum) must be disabled. array_merge() is shallow, hence
// the whole `limits` block is rebuilt from the defaults.
$defaults = include '/var/www/FreshRSS/config.default.php';
$limits = $defaults['limits'];
$limits['cache_duration'] = 0;
$limits['cache_duration_min'] = 0;
return ['limits' => $limits];
