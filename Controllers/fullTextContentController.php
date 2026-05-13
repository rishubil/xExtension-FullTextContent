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
			echo json_encode(['status' => 404, 'error' => 'Entry not found']);
			return;
		}

		$url = $entry->link();
		if ($url === '') {
			echo json_encode(['status' => 400, 'error' => 'Entry has no URL']);
			return;
		}

		try {
			$ext = Minz_ExtensionManager::findExtension('Full Text Content');
			if (!($ext instanceof FullTextContentExtension)) {
				echo json_encode(['status' => 500, 'error' => 'Extension not found']);
				return;
			}

			$pipeline = $ext->buildPipeline();
			$html = $pipeline->run($url);

			if ($html === '') {
				echo json_encode(['status' => 500, 'error' => 'Failed to fetch content']);
				return;
			}

			$entry->_content($html);
			$entry_values = $entry->toArray();
			$success = $entry_dao->updateEntry($entry_values);

			if (!$success) {
				echo json_encode(['status' => 500, 'error' => 'Failed to save updated content']);
				return;
			}

			echo json_encode(['status' => 200, 'content' => $html]);
		} catch (Throwable $e) {
			Minz_Log::warning('[FullTextContent] refetch failed: ' . $e->getMessage());
			echo json_encode(['status' => 500, 'error' => $e->getMessage()]);
		}
	}

	private function sendJson(array $data, int $code = 200): never {
		header('Content-Type: application/json', true, $code);
		echo json_encode($data);
		exit();
	}
}
