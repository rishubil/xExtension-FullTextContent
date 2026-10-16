<?php

declare(strict_types=1);

final class FreshExtension_fullTextContent_Controller extends Minz_ActionController {

	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			$this->sendJson(['error' => 'Unauthorized'], 403);
		}
		if (Minz_Request::isPost() && !FreshRSS_Auth::isCsrfOk()) {
			$this->sendJson(['error' => 'Invalid CSRF token'], 403);
		}
	}

	public function refetchAction(): void {
		$this->view->_layout(null);

		if (!Minz_Request::isPost()) {
			$this->sendJson(['status' => 405, 'error' => 'POST required']);
		}

		$entry_id = Minz_Request::paramString('id');
		$entry_dao = FreshRSS_Factory::createEntryDao();
		$entry = $entry_dao->searchById($entry_id);

		if ($entry === null) {
			$this->sendJson(['status' => 404, 'error' => 'Entry not found']);
		}

		$url = $entry->link();
		if ($url === '') {
			$this->sendJson(['status' => 400, 'error' => 'Entry has no URL']);
		}

		try {
			$ext = Minz_ExtensionManager::findExtension('Full Text Content');
			if (!($ext instanceof FullTextContentExtension)) {
				$this->sendJson(['status' => 500, 'error' => 'Extension not found']);
			}

			$feed = $entry->feed();
			$pipeline = $ext->buildPipeline();
			$html = $pipeline->run(
				$url,
				$feed !== null ? $ext->feedWait($feed) : 0,
				$feed !== null ? $ext->feedWaitUntil($feed) : '',
				$feed !== null ? $ext->feedSelector($feed) : '',
				$feed !== null ? $ext->feedStealth($feed) : false,
				$feed !== null ? $ext->feedTimeout($feed) : 0
			);

			if ($html === '') {
				$this->sendJson(['status' => 500, 'error' => 'Failed to fetch content']);
			}

			$entry->_content($html);
			$entry_values = $entry->toArray();
			$success = $entry_dao->updateEntry($entry_values);

			if (!$success) {
				$this->sendJson(['status' => 500, 'error' => 'Failed to save updated content']);
			}

			$this->sendJson(['status' => 200, 'content' => $html]);
		} catch (Throwable $e) {
			Minz_Log::warning('[FullTextContent] refetch failed: ' . $e->getMessage());
			$this->sendJson(['status' => 500, 'error' => $e->getMessage()]);
		}
	}

	/**
	 * Runs the pipeline with the fetch options sent by the configure page
	 * (saved or not) on the newest article of a feed, or on a given URL, and
	 * returns the extracted HTML without storing anything.
	 */
	public function previewAction(): void {
		$this->view->_layout(null);

		if (!Minz_Request::isPost()) {
			$this->sendJson(['status' => 405, 'error' => 'POST required']);
		}

		$ext = Minz_ExtensionManager::findExtension('Full Text Content');
		if (!($ext instanceof FullTextContentExtension)) {
			$this->sendJson(['status' => 500, 'error' => 'Extension not found']);
		}
		$ext->loadTranslations();

		$feedId = Minz_Request::paramInt('feed');
		if (FreshRSS_Factory::createFeedDao()->searchById($feedId) === null) {
			$this->sendJson(['status' => 404, 'error' => _t('ext.fulltextcontent.config.test_feed_not_found')]);
		}

		$url = trim(Minz_Request::paramString('url'));
		if ($url === '') {
			foreach (FreshRSS_Factory::createEntryDao()->listWhere('f', $feedId, limit: 1) as $entry) {
				$url = $entry->link();
			}
			if ($url === '') {
				$this->sendJson(['status' => 404, 'error' => _t('ext.fulltextcontent.config.test_no_articles')]);
			}
		} elseif (preg_match('#^https?://#i', $url) !== 1) {
			$this->sendJson(['status' => 400, 'error' => _t('ext.fulltextcontent.config.test_invalid_url')]);
		}

		$waitUntil = Minz_Request::paramString('wait_until');
		if (!in_array($waitUntil, ['', 'load', 'domcontentloaded', 'networkidle0'], true)) {
			$waitUntil = '';
		}

		try {
			$start = hrtime(true);
			$html = $ext->buildPipeline()->run(
				$url,
				max(0, Minz_Request::paramInt('wait')),
				$waitUntil,
				trim(Minz_Request::paramString('selector')),
				Minz_Request::paramBoolean('stealth'),
				max(0, Minz_Request::paramInt('timeout'))
			);
			$elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);
		} catch (Throwable $e) {
			Minz_Log::warning('[FullTextContent] preview failed: ' . $e->getMessage());
			$this->sendJson(['status' => 500, 'error' => $e->getMessage(), 'url' => $url]);
		}

		if (trim(strip_tags($html)) === '') {
			$this->sendJson(['status' => 500, 'error' => _t('ext.fulltextcontent.config.test_empty'), 'url' => $url]);
		}

		$this->sendJson([
			'status'     => 200,
			'url'        => $url,
			'content'    => $html,
			'characters' => mb_strlen(trim(html_entity_decode(strip_tags($html), ENT_QUOTES))),
			'elapsed_ms' => $elapsedMs,
		]);
	}

	private function sendJson(array $data, int $code = 200): never {
		header('Content-Type: application/json', true, $code);
		echo json_encode($data);
		exit();
	}
}
