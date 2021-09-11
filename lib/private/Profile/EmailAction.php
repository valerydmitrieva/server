<?php

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Profile\IProfileAction;

/**
 * @copyright Copyright (c) 2021 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
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

class EmailAction implements IProfileAction {

	/** @var IL10N */
	private $l10n;

	/** @var IUrlGenerator */
	private $urlGenerator;

	/** @var string */
	private $value;

	/**
	 * Action constructor
	 *
	 * @param IL10N $l10n
	 * @param IURLGenerator $urlGenerator
	 */
	public function __construct(
		IL10N $l10n,
		IURLGenerator $urlGenerator
	) {
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
	}

	public function getName(): string {
		return 'email';
	}

	public function getTitle(): string {
		return $this->l10n->t('Email %s', [$this->value]);
	}

	public function getPriority(): int {
		return 20;
	}

	public function getIcon(): string {
		return 'icon-mail';
	}

	public function getTarget(): string {
		return 'mailto:' . $this->value;
	}

	public function setValue(string $value): string {
		return $this->value = $value;
	}
}
