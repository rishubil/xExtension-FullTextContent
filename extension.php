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
		$this->registerTranslates();
		$this->registerHook(Minz_HookType::EntryBeforeInsert, [$this, 'onEntryBeforeInsert']);
		if (!FreshRSS_Context::$isCli) {
			$this->registerHook(Minz_HookType::EntryBeforeDisplay, [$this, 'addRefetchButton']);
			Minz_View::appendScript($this->getFileUrl('script.js', 'js'), false, false, false);
			Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
			$this->registerController('fullTextContent');
		}
	}

	public function addRefetchButton(FreshRSS_Entry $entry): FreshRSS_Entry {
		$this->registerTranslates();

		$feed = $entry->feed();
		if ($feed === null || !$feed->attributeBoolean('fulltextcontent_enabled')) {
			return $entry;
		}

		$url = Minz_Url::display([
			'c'      => 'fullTextContent',
			'a'      => 'refetch',
			'params' => ['id' => (string) $entry->id()],
		]);

		$entry->_content(
			'<div class="ftc-refetch">'
			. '<a class="btn" href="' . $url . '">'
			. _t('ext.fulltextcontent.ui.refetch_button')
			. '</a>'
			. '<span class="ftc-status hidden"></span>'
			. '</div>'
			. $entry->content()
		);

		return $entry;
	}

	public function onEntryBeforeInsert(FreshRSS_Entry $entry): ?FreshRSS_Entry {
		$feed = $entry->feed();
		if ($feed === null || !$feed->attributeBoolean('fulltextcontent_enabled')) {
			return $entry;
		}

		$url = $entry->link();
		if ($url === '') {
			return $entry;
		}

		try {
			$pipeline = $this->buildPipeline();
			$html = $pipeline->run($url, $this->feedWait($feed), $this->feedWaitUntil($feed));
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

		// Save per-feed toggles and wait settings
		$enabledFeeds  = Minz_Request::paramArray('fulltextcontent_feeds') ?: [];
		$waitValues    = Minz_Request::paramArray('fulltextcontent_wait') ?: [];
		$waitUntilValues = Minz_Request::paramArray('fulltextcontent_wait_until') ?: [];

		$feedDao = FreshRSS_Factory::createFeedDao();
		foreach ($feedDao->listFeeds() as $feed) {
			$id = (string) $feed->id();
			$feed->_attribute('fulltextcontent_enabled', in_array($id, $enabledFeeds, true));

			$wait = isset($waitValues[$id]) ? (int) $waitValues[$id] : 0;
			$feed->_attribute('fulltextcontent_wait', $wait > 0 ? $wait : null);

			$waitUntil = isset($waitUntilValues[$id]) ? trim($waitUntilValues[$id]) : '';
			$feed->_attribute('fulltextcontent_wait_until', $waitUntil !== '' ? $waitUntil : null);

			$feedDao->updateFeed($feed->id(), ['attributes' => $feed->attributes()]);
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function actionRedownloadObscura(): void {
		try {
			$resolver = $this->buildBinaryResolver();
			$resolver->ensure(true);
		} catch (Throwable $e) {
			Minz_Log::warning('[FullTextContent] Failed to redownload obscura: ' . $e->getMessage());
		}
	}

	private function actionUpdateDefuddle(): void {
		try {
			$manager = $this->buildDefuddleManager();
			$manager->ensureInstalled(true);
		} catch (Throwable $e) {
			Minz_Log::warning('[FullTextContent] Failed to update defuddle: ' . $e->getMessage());
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

	public function feedWait(FreshRSS_Feed $feed): int {
		return (int) ($feed->attributeInt('fulltextcontent_wait') ?? 0);
	}

	public function feedWaitUntil(FreshRSS_Feed $feed): string {
		return (string) ($feed->attributeString('fulltextcontent_wait_until') ?? '');
	}
}
