<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/ProcRunner.php';
require_once __DIR__ . '/lib/BinaryResolver.php';
require_once __DIR__ . '/lib/DefuddleManager.php';
require_once __DIR__ . '/lib/FullTextPipeline.php';
require_once __DIR__ . '/lib/Parsedown.php';

final class FullTextContentExtension extends Minz_Extension {

	private const DATA_SUBDIR = 'fulltextcontent';

	// Default configuration values
	private const DEFAULT_NODE_BINARY = 'node';
	private const DEFAULT_DEFUDDLE_VERSION = 'latest';
	private const DEFAULT_DEFUDDLE_CHECK_INTERVAL = 168;
	private const DEFAULT_FETCH_TIMEOUT = 30;

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerHook(Minz_HookType::EntryBeforeInsert, [$this, 'onEntryBeforeInsert']);
	}

	public function onEntryBeforeInsert(FreshRSS_Entry $entry): ?FreshRSS_Entry {
		$feed = $entry->feed();
		if ($feed === null || !$feed->attributeBool('fulltextcontent_enabled')) {
			return $entry;
		}

		$url = $entry->link();
		if ($url === '') {
			return $entry;
		}

		try {
			$pipeline = $this->buildPipeline();
			$html = $pipeline->run($url);
			if ($html !== '') {
				$entry->_content($html);
			}
		} catch (Throwable $e) {
			Minz_Log::warning('[FullTextContent] ' . $e->getMessage());
		}

		return $entry;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (!Minz_Request::isPost()) {
			return;
		}

		$action = Minz_Request::paramString('fulltextcontent_action');

		if ($action === 'redownload_obscura') {
			$this->actionRedownloadObscura();
			return;
		}

		if ($action === 'update_defuddle') {
			$this->actionUpdateDefuddle();
			return;
		}

		// Save global settings
		$this->setUserConfigurationValue('node_binary', Minz_Request::paramString('node_binary'));
		$this->setUserConfigurationValue('obscura_download_url', Minz_Request::paramString('obscura_download_url'));
		$this->setUserConfigurationValue('obscura_binary', Minz_Request::paramString('obscura_binary'));
		$this->setUserConfigurationValue('defuddle_version', Minz_Request::paramString('defuddle_version'));
		$this->setUserConfigurationValue('defuddle_check_interval', (int) Minz_Request::paramString('defuddle_check_interval'));
		$this->setUserConfigurationValue('fetch_timeout', (int) Minz_Request::paramString('fetch_timeout'));

		// Save per-feed toggles
		$enabledFeeds = Minz_Request::paramArray('fulltextcontent_feeds') ?: [];
		$feedDao = FreshRSS_Factory::createFeedDao();
		foreach ($feedDao->listFeeds() as $feed) {
			$enabled = in_array((string) $feed->id(), $enabledFeeds, true);
			$feed->_attributes('fulltextcontent_enabled', $enabled);
			$feedDao->updateFeed($feed->id(), ['attributes' => $feed->attributes()]);
		}

		Minz_Request::good(_t('ext.fulltextcontent.alert.saved'));
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function actionRedownloadObscura(): void {
		try {
			$resolver = $this->buildBinaryResolver();
			$resolver->ensure(true);
			Minz_Request::good(_t('ext.fulltextcontent.alert.obscura_redownloaded'));
		} catch (Throwable $e) {
			Minz_Request::bad(_t('ext.fulltextcontent.alert.error_obscura') . $e->getMessage());
		}
	}

	private function actionUpdateDefuddle(): void {
		try {
			$manager = $this->buildDefuddleManager();
			$manager->ensureInstalled(true);
			Minz_Request::good(_t('ext.fulltextcontent.alert.defuddle_updated'));
		} catch (Throwable $e) {
			Minz_Request::bad(_t('ext.fulltextcontent.alert.error_defuddle') . $e->getMessage());
		}
	}

	public function buildPipeline(): FullTextPipeline {
		return new FullTextPipeline(
			$this->buildBinaryResolver(),
			$this->buildDefuddleManager(),
			$this->getUserConfigurationString('node_binary') ?? self::DEFAULT_NODE_BINARY,
			$this->getUserConfigurationInt('fetch_timeout') ?? self::DEFAULT_FETCH_TIMEOUT,
			$this->getUserConfigurationString('obscura_binary') ?? ''
		);
	}

	public function buildBinaryResolver(): BinaryResolver {
		return new BinaryResolver(
			$this->getExtDataDir(),
			$this->getUserConfigurationString('obscura_download_url') ?? ''
		);
	}

	public function buildDefuddleManager(): DefuddleManager {
		return new DefuddleManager(
			$this->getExtDataDir(),
			$this->getUserConfigurationString('defuddle_version') ?? self::DEFAULT_DEFUDDLE_VERSION,
			$this->getUserConfigurationInt('defuddle_check_interval') ?? self::DEFAULT_DEFUDDLE_CHECK_INTERVAL
		);
	}

	public function getExtDataDir(): string {
		return rtrim(DATA_PATH, '/') . '/' . self::DATA_SUBDIR;
	}
}
