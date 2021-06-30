<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FilesUserCleanup\Command;

use OC\Core\Command\Base;
use OC\Files\Cache\Cache;
use OC\User\User;
use OCP\Files\Config\IMountProviderCollection;
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\UserInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Cleanup extends Base {
	private $userManager;
	private $mountProviderCollection;

	public function __construct(IUserManager $userManager, IMountProviderCollection $mountProviderCollection) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->mountProviderCollection = $mountProviderCollection;
	}

	protected function configure() {
		parent::configure();

		$this
			->setName('files_user_cleanup:cleanup')
			->setDescription('Cleanup files of deleted user')
			->addArgument('user_id', InputArgument::REQUIRED, 'id of the user to cleanup the files for')
			->addOption('user-backend', 'b', InputOption::VALUE_REQUIRED, 'User backend the user belonged to');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = $input->getArgument('user_id');
		if ($this->userManager->userExists($uid)) {
			$helper = $this->getHelper('question');

			$question = new ConfirmationQuestion("User $uid still exists as a nextcloud user, are you sure you want to delete all of it's files? [y/N] ", false);
			if (!$helper->ask($input, $output, $question)) {
				return 0;
			}
		}

		$backends = $this->userManager->getBackends();
		if (count($backends) > 1) {
			$selectedBackend = $input->getOption('user-backend');
			if ($selectedBackend) {
				$selectedBackend = strtolower($selectedBackend);
				$matching = array_filter($backends, function($backend) use ($selectedBackend) {
					return strtolower($this->getBackendName($backend)) === $selectedBackend;
				});
				if (count($matching) === 1) {
					$backend = current($matching);
				} else if (count($matching) === 1) {
					$output->writeln("Multiple user backend matching '$selectedBackend' found, this is currently not supported.");
					return 1;
				} else {
					$output->writeln("User backend '$selectedBackend' not found.");
					return 1;
				}
			} else {
				$output->writeln("More than one user backend is configured, please select one of the following backends with the `--user-backend` option.");
				foreach ($backends as $backend) {
					$output->writeln("    " . $this->getBackendName($backend));
				}
				return 1;
			}
		} else {
			$backend = current($backends);
		}
		$user = new User($uid, $backend, new EventDispatcher());

		$homeMount = $this->mountProviderCollection->getHomeMountForUser($user);
		$storage = $homeMount->getStorage();
		if (!$storage->is_dir('files')) {
			$output->writeln("user doesn't seem to have any files to delete");
			return 0;
		}

		$output->writeln("Deleting all files from the users home directory...");
		$storage->rmdir('');

		$output->writeln("Cleaning up filecache...");

		$cache = $storage->getCache();
		if ($cache instanceof Cache) {
			$cache->clear();
		} else {
			throw new \Exception("Home storage has invalid cache");
		}

		$output->writeln("Done");

		return 0;
	}

	private function getBackendName(UserInterface $backend): string {
		if ($backend instanceof IUserBackend) {
			return $backend->getBackendName();
		} else {
			return get_class($backend);
		}
	}
}
