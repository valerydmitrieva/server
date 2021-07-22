<?php
/**
 * @copyright Copyright (c) 2016 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author sualko <klaus@jsxc.org>
 * @author Carl Schwan <carl@carlschwan.eu>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Settings;

use Closure;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Settings\IIconSection;
use OCP\Settings\IManager;
use OCP\Settings\ISettings;
use OCP\Settings\ISubAdminSettings;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerExceptionInterface;

class Manager implements IManager {

	/** @var LoggerInterface */
	private $log;

	/** @var IL10N */
	private $l;

	/** @var IFactory */
	private $l10nFactory;

	/** @var IURLGenerator */
	private $url;

	/** @var IServerContainer */
	private $container;

	/** @var AuthorizedGroupMapper $mapper */
	private $mapper;

	/** @var IGroupManager $groupManager */
	private $groupManager;

	/** @var ISubAdmin $subAdmin */
	private $subAdmin;

	public function __construct(
		LoggerInterface $log,
		IFactory $l10nFactory,
		IURLGenerator $url,
		IServerContainer $container,
		AuthorizedGroupMapper $mapper,
		IGroupManager $groupManager,
		ISubAdmin $subAdmin
	) {
		$this->log = $log;
		$this->l10nFactory = $l10nFactory;
		$this->url = $url;
		$this->container = $container;
		$this->mapper = $mapper;
		$this->groupManager = $groupManager;
		$this->subAdmin = $subAdmin;
	}

	/** @var array */
	protected $sectionClasses = [];

	/** @var array */
	protected $sections = [];

	/**
	 * @param string $type 'admin' or 'personal'
	 * @param string $section Class must implement OCP\Settings\IIconSection
	 *
	 * @return void
	 */
	public function registerSection(string $type, string $section) {
		if (!isset($this->sectionClasses[$type])) {
			$this->sectionClasses[$type] = [];
		}

		$this->sectionClasses[$type][] = $section;
	}

	/**
	 * @param string $type 'admin' or 'personal'
	 *
	 * @return IIconSection[]
	 */
	protected function getSections(string $type): array {
		if (!isset($this->sections[$type])) {
			$this->sections[$type] = [];
		}

		if (!isset($this->sectionClasses[$type])) {
			return $this->sections[$type];
		}

		foreach (array_unique($this->sectionClasses[$type]) as $index => $class) {
			/** @var IIconSection $section */
			$section = \OC::$server->get($class);

			$sectionID = $section->getID();

			if ($sectionID !== 'connected-accounts' && isset($this->sections[$type][$sectionID])) {
				$this->log->info('', ['exception' => new \InvalidArgumentException('Section with the same ID already registered: ' . $sectionID . ', class: ' . $class)]);
				continue;
			}

			$this->sections[$type][$sectionID] = $section;

			unset($this->sectionClasses[$type][$index]);
		}

		return $this->sections[$type];
	}

	/** @var array */
	protected $settingClasses = [];

	/** @var array */
	protected $settings = [];

	/** @var array $delegationAllowedClasses */
	private $delegationAllowedClasses = [];

	/**
	 * @psam-param 'admin'|'personal' $type The type of the setting.
	 * @param string $setting Class must implement OCP\Settings\ISettings
	 * @param bool $allowedDelegation
	 *
	 * @return void
	 */
	public function registerSetting(string $type, string $setting, bool $allowedDelegation = false) {
		$this->settingClasses[$setting] = $type;
		if ($allowedDelegation && $type === 'admin') {
			$this->delegationAllowedClasses[] = $setting;
		}
	}

	/**
	 * @param string $type 'admin' or 'personal'
	 * @param string $section
	 * @param Closure $filter optional filter to apply on all loaded ISettings
	 *
	 * @return ISettings[]
	 */
	protected function getSettings(string $type, string $section, Closure $filter = null): array {
		if (!isset($this->settings[$type])) {
			$this->settings[$type] = [];
		}
		if (!isset($this->settings[$type][$section])) {
			$this->settings[$type][$section] = [];
		}

		foreach ($this->settingClasses as $class => $settingsType) {
			if ($type !== $settingsType) {
				continue;
			}

			try {
				/** @var ISettings $setting */
				$setting = $this->container->get($class);
			} catch (ContainerExceptionInterface $e) {
				$this->log->info($e->getMessage(), ['exception' => $e]);
				continue;
			}

			if (!$setting instanceof ISettings) {
				$this->log->info('', ['exception' => new \InvalidArgumentException('Invalid settings setting registered (' . $class . ')')]);
				continue;
			}

			if ($filter !== null && !$filter($setting)) {
				continue;
			}
			if ($setting->getSection() === null) {
				continue;
			}

			if (!isset($this->settings[$settingsType][$setting->getSection()])) {
				$this->settings[$settingsType][$setting->getSection()] = [];
			}
			$this->settings[$settingsType][$setting->getSection()][] = $setting;

			unset($this->settingClasses[$class]);
		}

		return $this->settings[$type][$section];
	}

	/**
	 * @inheritdoc
	 */
	public function getAdminSections(): array {
		// built-in sections
		$sections = [];

		$appSections = $this->getSections('admin');

		foreach ($appSections as $section) {
			/** @var IIconSection $section */
			if (!isset($sections[$section->getPriority()])) {
				$sections[$section->getPriority()] = [];
			}

			$sections[$section->getPriority()][] = $section;
		}

		ksort($sections);

		return $sections;
	}

	/**
	 * @inheritdoc
	 */
	public function getAdminSettings($section, bool $subAdminOnly = false): array {
		if ($subAdminOnly) {
			$subAdminSettingsFilter = function (ISettings $settings) {
				return $settings instanceof ISubAdminSettings;
			};
			$appSettings = $this->getSettings('admin', $section, $subAdminSettingsFilter);
		} else {
			$appSettings = $this->getSettings('admin', $section);
		}

		$settings = [];
		foreach ($appSettings as $setting) {
			if (!isset($settings[$setting->getPriority()])) {
				$settings[$setting->getPriority()] = [];
			}
			$settings[$setting->getPriority()][] = $setting;
		}

		ksort($settings);
		return $settings;
	}

	/**
	 * @inheritdoc
	 */
	public function getPersonalSections(): array {
		if ($this->l === null) {
			$this->l = $this->l10nFactory->get('lib');
		}

		$sections = [];

		$legacyForms = \OC_App::getForms('personal');
		if ((!empty($legacyForms) && $this->hasLegacyPersonalSettingsToRender($legacyForms))
			|| count($this->getPersonalSettings('additional')) > 1) {
			$sections[98] = [new Section('additional', $this->l->t('Additional settings'), 0, $this->url->imagePath('core', 'actions/settings-dark.svg'))];
		}

		$appSections = $this->getSections('personal');

		foreach ($appSections as $section) {
			/** @var IIconSection $section */
			if (!isset($sections[$section->getPriority()])) {
				$sections[$section->getPriority()] = [];
			}

			$sections[$section->getPriority()][] = $section;
		}

		ksort($sections);

		return $sections;
	}

	/**
	 * @param string[] $forms
	 *
	 * @return bool
	 */
	private function hasLegacyPersonalSettingsToRender(array $forms): bool {
		foreach ($forms as $form) {
			if (trim($form) !== '') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @inheritdoc
	 */
	public function getPersonalSettings($section): array {
		$settings = [];
		$appSettings = $this->getSettings('personal', $section);

		foreach ($appSettings as $setting) {
			if (!isset($settings[$setting->getPriority()])) {
				$settings[$setting->getPriority()] = [];
			}
			$settings[$setting->getPriority()][] = $setting;
		}

		ksort($settings);
		return $settings;
	}

	public function getAdminDelegationAllowedSettings(): array {
		return $this->delegationAllowedClasses;
	}

	public function getAllowedAdminSettings(string $section, IUser $user): array {
		$isAdmin = $this->groupManager->isAdmin($user->getUID());
		$isSubAdmin = $this->subAdmin->isSubAdmin($user);
		$subAdminOnly = !$isAdmin && $isSubAdmin;

		if ($subAdminOnly) {
			// not an admin => look if the user is still authorized to access some
			// settings
			$subAdminSettingsFilter = function (ISettings $settings) {
				return $settings instanceof ISubAdminSettings;
			};
			$appSettings = $this->getSettings('admin', $section, $subAdminSettingsFilter);
		} elseif ($isAdmin) {
			$appSettings = $this->getSettings('admin', $section);
		} else {
			$authorizedSettingsClasses = $this->mapper->findAllClassesForUser($user);
			$authorizedGroupFilter = function (ISettings $settings) use ($authorizedSettingsClasses) {
				return in_array(get_class($settings), $authorizedSettingsClasses);
			};
			$appSettings = $this->getSettings('admin', $section, $authorizedGroupFilter);
		}

		$settings = [];
		foreach ($appSettings as $setting) {
			if (!isset($settings[$setting->getPriority()])) {
				$settings[$setting->getPriority()] = [];
			}
			$settings[$setting->getPriority()][] = $setting;
		}

		ksort($settings);
		return $settings;
	}

	public function getAllAllowedAdminSettings(IUser $user): array {
		$this->getSettings('admin', ''); // Make sure all the settings are loaded
		$settings = [];
		$authorizedSettingsClasses = $this->mapper->findAllClassesForUser($user);
		foreach ($this->settings['admin'] as $section) {
			foreach ($section as $setting) {
				if (in_array(get_class($setting), $authorizedSettingsClasses)) {
					$settings[] = $setting;
				}
			}
		}
		return $settings;
	}
}
