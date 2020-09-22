<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Robin Appelman <robin@icewind.nl>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files\BackgroundJob;

use OC\Files\Utils\Scanner;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IUserManager;

/**
 * Class ScanFiles is a background job used to run the file scanner over the user
 * accounts to ensure integrity of the file cache.
 *
 * @package OCA\Files\BackgroundJob
 */
class ScanFiles extends \OC\BackgroundJob\TimedJob {
	/** @var IConfig */
	private $config;
	/** @var IUserManager */
	private $userManager;
	/** @var IEventDispatcher */
	private $dispatcher;
	/** @var ILogger */
	private $logger;
	private $connection;

	/** Amount of users that should get scanned per execution */
	public const USERS_PER_SESSION = 500;

	/**
	 * @param IConfig|null $config
	 * @param IUserManager|null $userManager
	 * @param IEventDispatcher|null $dispatcher
	 * @param ILogger|null $logger
	 * @param IDBConnection|null $connection
	 */
	public function __construct(
		IConfig $config = null,
		IUserManager $userManager = null,
		IEventDispatcher $dispatcher = null,
		ILogger $logger = null,
		IDBConnection $connection = null
	) {
		// Run once per 10 minutes
		$this->setInterval(60 * 10);

		$this->config = $config ?? \OC::$server->getConfig();
		$this->userManager = $userManager ?? \OC::$server->getUserManager();
		$this->dispatcher = $dispatcher ?? \OC::$server->query(IEventDispatcher::class);
		$this->logger = $logger ?? \OC::$server->getLogger();
		$this->connection = $connection ?? \OC::$server->getDatabaseConnection();
	}

	/**
	 * @param string $user
	 */
	protected function runScanner(string $user) {
		try {
			$scanner = new Scanner(
					$user,
					null,
					$this->dispatcher,
					$this->logger
			);
			$scanner->backgroundScan('');
		} catch (\Exception $e) {
			$this->logger->logException($e, ['app' => 'files']);
		}
		\OC_Util::tearDownFS();
	}

	/**
	 * Find all storages which have unindexed files and return a user for each
	 *
	 * @return string[]
	 */
	private function getUsersToScan(): array {
		$query = $this->connection->getQueryBuilder();
		$query->select($query->func()->max('user_id'))
			->from('filecache', 'f')
			->innerJoin('f', 'mounts', 'm', $query->expr()->eq('storage_id', 'storage'))
			->where($query->expr()->lt('size', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('storage_id');

		return $query->execute()->fetchAll(\PDO::FETCH_COLUMN);
	}

	/**
	 * @param $argument
	 * @throws \Exception
	 */
	protected function run($argument) {
		if ($this->config->getSystemValueBool('files_no_background_scan', false)) {
			return;
		}

		$users = $this->getUsersToScan();

		foreach ($users as $user) {
			$this->runScanner($user);
		}
	}
}
