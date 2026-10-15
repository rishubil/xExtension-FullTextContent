<?php

return [
	'fulltextcontent' => [
		'title' => 'Full Text Content',

		'config' => [
			'global' => 'Global Settings',
			'node_binary' => 'Node.js binary path',
			'node_binary_help' => 'Path to the node executable (default: node)',
			'obscura_download_url' => 'Obscura download URL template',
			'obscura_download_url_help' => 'URL template for downloading the obscura binary. Use {arch} for architecture (e.g. x86_64-linux).',
			'obscura_binary' => 'Obscura binary path override',
			'obscura_binary_help' => 'Override the resolved obscura binary path. Leave blank to use the auto-downloaded binary.',
			'defuddle_version' => 'Defuddle version',
			'defuddle_version_help' => 'Use "latest" to always install the newest version, or enter an exact version (e.g. 0.6.2) to pin.',
			'defuddle_check_interval' => 'Defuddle update check interval (hours)',
			'defuddle_check_interval_help' => 'How often to check for a newer defuddle version when set to "latest". Ignored when pinned.',
			'fetch_timeout' => 'Fetch timeout (seconds)',
			'fetch_timeout_help' => 'Maximum time (PHP process) to wait for obscura to fetch a URL.',
			'allow_private_network' => 'Allow private network access',
			'allow_private_network_help' => 'Let obscura fetch articles on loopback, private (RFC 1918) and link-local addresses, e.g. self-hosted sites on your LAN. Off by default to prevent SSRF; when enabled it applies to every fetch, including resources loaded by the article page.',

			'status' => 'Status & Actions',
			'obscura_status' => 'Obscura binary',
			'obscura_version' => 'Obscura version',
			'obscura_not_downloaded' => 'Not downloaded',
			'obscura_path' => 'Path',
			'defuddle_installed' => 'Defuddle installed',
			'defuddle_latest_known' => 'Latest known on npm',
			'defuddle_last_check' => 'Last checked',
			'defuddle_never_checked' => 'Never',
			'defuddle_not_installed' => 'Not installed',

			'version_unknown' => 'of unknown version',
			'warn_obscura_old' => 'The obscura binary in use (obscura %s) is older than the minimum supported version %s. Click “Redownload obscura binary” or update the binary set as override.',
			'warn_defuddle_installed_old' => 'The installed defuddle %s is older than the minimum supported version %s. Set the defuddle version to “latest” or at least %2$s, then click “Update defuddle now”.',
			'warn_defuddle_pinned_old' => 'The configured defuddle version %s is older than the minimum supported version %s.',

			'update_defuddle' => 'Update defuddle now',
			'redownload_obscura' => 'Redownload obscura binary',

			'per_feed' => 'Per-feed Settings',
			'per_feed_help' => 'Enable full-text fetching and configure obscura fetch options per feed.',
			'col_feed'       => 'Feed',
			'col_enabled'    => 'Enabled',
			'col_wait'       => 'Wait (sec)',
			'col_wait_until' => 'Wait Until',
			'col_selector'   => 'Selector',
			'col_stealth'    => 'Stealth',
			'col_timeout'    => 'Timeout (sec)',
			'no_feeds' => 'No feeds found.',

			'save' => 'Save',
		],

		'ui' => [
			'refetch_button' => 'Re-fetch Content',
			'fetching'       => 'Fetching content…',
			'success'        => 'Content updated.',
			'error'          => 'Failed to fetch content.',
		],

		'alert' => [
			'saved' => 'Settings saved.',
			'obscura_redownloaded' => 'Obscura binary re-downloaded successfully.',
			'defuddle_updated' => 'Defuddle updated successfully.',
			'error_defuddle' => 'Failed to update defuddle: ',
		],
	],
];
