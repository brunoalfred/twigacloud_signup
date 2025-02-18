<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Bruno Alfred <hello@brunoalfred.me>
 *
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Twigacloudsignup\Service;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberUtil;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\Twigacloudsignup\AppInfo\Application;
use OCA\Twigacloudsignup\Db\Registration;
use OCA\Twigacloudsignup\Db\RegistrationMapper;
use OCA\Settings\Mailer\NewUserMailHelper;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Session\Exceptions\SessionNotAvailableException;
use \OCP\IUserManager;
use \OCP\IUserSession;
use \OCP\IGroupManager;
use \OCP\IL10N;
use \OCP\IConfig;
use \OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class RegistrationService
{

	/** @var string */
	private $appName;
	/** @var PhoneService */
	private $phoneService;
	/** @var IL10N */
	private $l10n;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var RegistrationMapper */
	private $registrationMapper;
	/** @var IUserManager */
	private $userManager;
	/** @var IAccountManager */
	private $accountManager;
	/** @var IConfig */
	private $config;
	/** @var IGroupManager */
	private $groupManager;
	/** @var ISecureRandom */
	private $random;
	/** @var IUserSession  */
	private $userSession;
	/** @var IRequest */
	private $request;
	/** @var LoggerInterface */
	private $logger;
	/** @var ISession */
	private $session;
	/** @var IProvider */
	private $tokenProvider;
	/** @var ICrypto */
	private $crypto;
	/** @var SmsGatewayService */
	private $smsGatewayService;

	public function __construct(
		string $appName,
		PhoneService $phoneService,
		IL10N $l10n,
		IURLGenerator $urlGenerator,
		RegistrationMapper $registrationMapper,
		IUserManager $userManager,
		IAccountManager $accountManager,
		IConfig $config,
		IGroupManager $groupManager,
		ISecureRandom $random,
		IUserSession $userSession,
		IRequest $request,
		LoggerInterface $logger,
		ISession $session,
		IProvider $tokenProvider,
		ICrypto $crypto,
		SmsGatewayService $smsGatewayService
	) {
		$this->appName = $appName;
		$this->phoneService = $phoneService;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->registrationMapper = $registrationMapper;
		$this->userManager = $userManager;
		$this->accountManager = $accountManager;
		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->random = $random;
		$this->userSession = $userSession;
		$this->request = $request;
		$this->logger = $logger;
		$this->session = $session;
		$this->tokenProvider = $tokenProvider;
		$this->crypto = $crypto;
		$this->smsGatewayService = $smsGatewayService;
	}

	public function confirmEmail(Registration $registration): void
	{
		$registration->setEmailConfirmed(true);
		$this->registrationMapper->update($registration);
	}

	public function generateNewToken(Registration $registration): void
	{
		$this->registrationMapper->generateNewToken($registration);
		$this->registrationMapper->update($registration);
	}

	/**
	 * Create registration request, used by both the API and form
	 * @param string $email
	 * @param string $username
	 * @param string $password
	 * @param string $displayname
	 * @return Registration
	 */
	public function createRegistration(string $phone, string $username = '', string $password = '', string $displayname = ''): Registration
	{
		$registration = new Registration();
		$registration->setPhone($phone);
		$registration->setUsername($username);
		$registration->setDisplayname($displayname);
		if ($password !== '') {
			$password = $this->crypto->encrypt($password);
			$registration->setPassword($password);
		}
		$this->registrationMapper->generateNewToken($registration);
		$this->registrationMapper->generateClientSecret($registration);
		$this->registrationMapper->insert($registration);
		return $registration;
	}

	/**
	 * @param string $phone
	 * @throws RegistrationException
	 */
	public function validatePhone(string $phone): void
	{

		$this->phoneService->validatePhone($phone);

		// check for pending registrations
		try {
			$this->registrationMapper->find($phone); //if not found DB will throw a exception
			throw new RegistrationException(
				$this->l10n->t('A user has already taken this phone, maybe you already have an account?'),
				$this->l10n->t('You can <a href="%s">log in now</a>.', [$this->urlGenerator->getAbsoluteURL('/')])
			);
		} catch (DoesNotExistException $e) {
		}
	}

	/**
	 * @param string $displayname
	 * @throws RegistrationException
	 */
	public function validateDisplayname(string $displayname): void
	{
		if ($displayname === '') {
			throw new RegistrationException($this->l10n->t('Please provide a valid display name.'));
		}
	}

	/**
	 * @param string $username
	 * @throws RegistrationException
	 */
	public function validateUsername(string $username): void
	{
		if ($username === '') {
			throw new RegistrationException($this->l10n->t('Please provide a valid login name.'));
		}

		$regex = $this->config->getAppValue($this->appName, 'username_policy_regex', '');
		if ($regex && preg_match($regex, $username) === 0) {
			throw new RegistrationException($this->l10n->t('Please provide a valid login name.'));
		}

		if ($this->registrationMapper->usernameIsPending($username) || $this->userManager->get($username) !== null) {
			throw new RegistrationException($this->l10n->t('The login name you have chosen already exists.'));
		}
	}

	/**
	 * @param string $phone
	 * @throws RegistrationException
	 */
	public function validatePhoneNumber(string $phone): void
	{
		$defaultRegion = $this->config->getSystemValueString('default_phone_region', '');

		if ($defaultRegion === '') {
			// When no default region is set, only +49… numbers are valid
			if (strpos($phone, '+') !== 0) {
				throw new RegistrationException($this->l10n->t('The phone number needs to contain the country code.'));
			}

			$defaultRegion = 'EN';
		}

		$phoneUtil = PhoneNumberUtil::getInstance();
		try {
			$phoneNumber = $phoneUtil->parse($phone, $defaultRegion);
			if (!$phoneNumber instanceof PhoneNumber || !$phoneUtil->isValidNumber($phoneNumber)) {
				throw new RegistrationException($this->l10n->t('The phone number is invalid.'));
			}
		} catch (NumberParseException $e) {
			throw new RegistrationException($this->l10n->t('The phone number is invalid.'));
		}
	}




	/**
	 * @param Registration $registration
	 * @param string|null $loginName
	 * @param string|null $fullName
	 * @param string|null $phone
	 * @param string|null $password
	 * @return IUser
	 * @throws RegistrationException|InvalidArgumentException
	 */
	public function createAccount(Registration $registration, ?string $loginName = null, ?string $fullName = null, ?string $phone = null, ?string $password = null): IUser
	{
		if ($loginName === null) {
			$loginName = $registration->getUsername();
		}

		if ($registration->getPassword() !== null) {
			$password = $this->crypto->decrypt($registration->getPassword());
		}

		if (!$password) {
			throw new RegistrationException($this->l10n->t('Please provide a password.'));
		}

		$this->validateUsername($loginName);

		if (
			$this->config->getAppValue('twigacloudsignup', 'show_fullname', 'no') === 'yes'
			&& $this->config->getAppValue('twigacloudsignup', 'enforce_fullname', 'no') === 'yes'
		) {
			$this->validateDisplayname($fullName);
		}

		if (
			class_exists(PhoneNumberUtil::class)
			&& $this->config->getAppValue('twigacloudsignup', 'show_phone', 'no') === 'yes'
		) {
			if ($phone) {
				$this->validatePhoneNumber($phone);
			} elseif ($this->config->getAppValue('twigacloudsignup', 'enforce_phone', 'no') === 'yes') {
				throw new RegistrationException($this->l10n->t('Please provide a valid phone number.'));
			}
		}

		/* TODO
		 * createUser tests username validity once, but validateUsername already checked it,
		 * but createUser doesn't check if there is a pending registration with that name
		 *
		 * And validateUsername will throw RegistrationException while
		 * createUser throws \InvalidArgumentException
		 */
		$user = $this->userManager->createUser($loginName, $password);
		if ($user === false) {
			throw new RegistrationException($this->l10n->t('Unable to create user, there are problems with the user backend.'));
		}
		$userId = $user->getUID();


		// Set user email
		try {
			// $user->setSystemEMailAddress($registration->getEmail()); we can consider setting this another time
		} catch (\Exception $e) {
			throw new RegistrationException($this->l10n->t('Unable to set user email: ' . $e->getMessage()));
		}

		// Set display name
		if ($fullName && $this->config->getAppValue('twigacloudsignup', 'show_fullname', 'no') === 'yes') {
			$user->setDisplayName($fullName);
		}

		// Set phone number in account data
		if (
			method_exists($this->accountManager, 'updateAccount')
			&& $phone
			&& $this->config->getAppValue('twigacloudsignup', 'show_phone', 'no') === 'yes'
		) {
			$account = $this->accountManager->getAccount($user);
			$property = $account->getProperty(IAccountManager::PROPERTY_PHONE);
			$account->setProperty(
				IAccountManager::PROPERTY_PHONE,
				$phone,
				$property->getScope(),
				IAccountManager::NOT_VERIFIED
			);
			$this->accountManager->updateAccount($account);
		}

		// Add user to group
		$registeredUserGroup = $this->config->getAppValue($this->appName, 'registered_user_group', 'none');
		if ($registeredUserGroup !== 'none') {
			$group = $this->groupManager->get($registeredUserGroup);
			if ($group === null) {
				// This might happen if $registered_user_group is deleted after setting the value
				// Here I choose to log error instead of stopping the user to register
				$this->logger->error("You specified newly registered users be added to '$registeredUserGroup' group, but it does not exist.");
				$groupId = '';
			} else {
				$group->addUser($user);
				$groupId = $group->getGID();
			}
		} else {
			$groupId = '';
		}

		// disable user if this is requested by config
		$adminApprovalRequired = $this->config->getAppValue($this->appName, 'admin_approval_required', 'no');
		if ($adminApprovalRequired === 'yes') {
			$user->setEnabled(false);
			$this->config->setUserValue($userId, Application::APP_ID, 'send_welcome_mail_on_enable', 'yes');
		} else {
			$this->sendWelcomeSms($registration);
		}

		$this->phoneService->notifyAdmins($userId, $user->getEMailAddress(), $user->isEnabled(), $groupId);
		return $user;
	}

	public function sendWelcomeSms(Registration $registration): void
	{
		try {
			$this->smsGatewayService->sendSms($registration->getPhone() , $this->l10n->t('Welcome to Twiga Cloud! %s', [$registration->getUsername()]));
		} catch (\Exception $e) {
			// Catching this so at least admins are notified
			$this->logger->error(
				'Unable to send the invitation sms to {user}',
				[
					'user' => $registration->getId(),
					'exception' => $e,
				]
			);
		}
	}

	/**
	 * @param string $phone
	 * @return Registration
	 * @throws DoesNotExistException
	 */
	public function getRegistrationForPhone(string $phone): Registration
	{
		return $this->registrationMapper->find($phone);
	}

	/**
	 * @param string $secret
	 * @return Registration
	 * @throws DoesNotExistException
	 */
	public function getRegistrationForSecret(string $secret): Registration
	{
		return $this->registrationMapper->findBySecret($secret);
	}

	public function deleteRegistration(Registration $registration): void
	{
		$this->registrationMapper->delete($registration);
	}

	/**
	 * Return a 25 digit device password
	 *
	 * Example: AbCdE-fGhIj-KlMnO-pQrSt-12345
	 *
	 * @return string
	 */
	private function generateRandomDeviceToken(): string
	{
		$groups = [];
		for ($i = 0; $i < 5; $i++) {
			$groups[] = $this->random->generate(5, ISecureRandom::CHAR_HUMAN_READABLE);
		}
		return implode('-', $groups);
	}

	/**
	 * @param string $uid
	 * @return string
	 * @throws RegistrationException
	 */
	public function generateAppPassword(string $uid): string
	{
		$name = $this->l10n->t('Registration app auto setup');
		try {
			$sessionId = $this->session->getId();
		} catch (SessionNotAvailableException $ex) {
			throw new RegistrationException('Failed to generate an app token.');
		}

		try {
			$sessionToken = $this->tokenProvider->getToken($sessionId);
			$loginName = $sessionToken->getLoginName();
			try {
				$password = $this->tokenProvider->getPassword($sessionToken, $sessionId);
			} catch (PasswordlessTokenException $ex) {
				$password = null;
			}
		} catch (InvalidTokenException $ex) {
			throw new RegistrationException('Failed to generate an app token.');
		}

		$token = $this->generateRandomDeviceToken();
		$this->tokenProvider->generateToken($token, $uid, $loginName, $password, $name, IToken::PERMANENT_TOKEN);
		return $token;
	}

	/**
	 * @param string $userId
	 * @param string $username
	 * @param string $password
	 * @param bool $decrypt
	 */
	public function loginUser(string $userId, string $username, string $password, bool $decrypt = false): void
	{
		if ($decrypt) {
			$password = $this->crypto->decrypt($password);
		}

		$this->userSession->login($username, $password);
		$this->userSession->createSessionToken($this->request, $userId, $username, $password);
	}

	// getRegistrationByUserId 

	/**
	 * @param string $userId
	 */

	 public function getRegistrationByUserId(string $userId): Registration
	 {
		 return $this->registrationMapper->findByUserId($userId);
	 }


}
