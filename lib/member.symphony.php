<?php

	Class SymphonyMember extends Members {

		public function __construct($driver) {
			parent::__construct($driver);
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		/**
		 * This function determines what field instance to use based on the current
		 * $_POST data.
		 *
		 * @param array $credentials
		 * @param boolean $simplified
		 *  If true, this function assumes that the $credentials array contains a
		 *  username and email key, otherwise, it will attempt to map a value using
		 *  the field handles to a normalised username/email.
		 * @return Field
		 */
		public static function setIdentityField(Array $credentials, $simplified = true) {
			if($simplified) {
				extract($credentials);
			}
			else {
				// Map POST data to simple terms
				if(isset($credentials[extension_Members::$handles['identity']])) {
					$username = $credentials[extension_Members::$handles['identity']];
				}

				if(isset($credentials[extension_Members::$handles['email']])) {
					$email = $credentials[extension_Members::$handles['email']];
				}
			}

			// Check to see if neither can be found, just return null
			if(is_null($username) && is_null($email)) {
				return null;
			}

			// If email is supplied, use the Email field
			if(!is_null($email) && (isset($email) && !empty($email))) {
				$identity_field = extension_Members::$fields['email'];
			}
			// If username is supplied, use the Username field
			else if (!is_null($username) && (isset($username) && !empty($username))) {
				$identity_field = extension_Members::$fields['identity'];
			}

			return $identity_field;
		}

	/*-------------------------------------------------------------------------
		Finding:
	-------------------------------------------------------------------------*/

		/**
		 * Returns an Entry object given an array of credentials
		 *
		 * @param array $credentials
		 * @return integer
		 */
		public function findMemberIDFromCredentials(Array $credentials) {
			extract($credentials);

			// It's expected that $password is sha1'd and salted.
			if((is_null($username) && is_null($email)) || is_null($password)) return null;

			$identity = SymphonyMember::setIdentityField($credentials);

			if(!$identity instanceof Field) return null;

			// Member from Identity
			$member_id = $identity->fetchMemberIDBy($credentials);

			// Validate against Password
			$auth = extension_Members::$fields['authentication'];
			if(!is_null($auth)) {
				$member_id = $auth->fetchMemberIDBy($credentials, $member_id);
			}

			// No Member found, can't even begin to check Activation
			// Return null
			if(is_null($member_id)) return null;

			// Check that if there's activiation, that this Member is activated.
			if(!is_null(extension_Members::getConfigVar('activation'))) {
				$entry = self::$driver->em->fetch($member_id);

				// If we are denying login for non activated members, lets do so now
				if(extension_Members::$fields['activation']->get('deny_login') == 'yes') {
					extension_Members::$_errors[extension_Members::$fields['activation']->get('element_name')] = array(
						'message' => __('Member is not activated.'),
						'type' => 'invalid',
						'label' => extension_Members::$fields['activation']->get('label')
					);

					return null;
				}

				// If the member isn't activated and a Role field doesn't exist
				// just return false.
				if($entry[0]->getData(extension_Members::getConfigVar('activation'), true)->activated != "yes") {
					if(is_null(extension_Members::getConfigVar('role'))) {
						extension_Members::$_errors[extension_Members::$fields['activation']->get('element_name')] = array(
							'message' => __('Not activated.'),
							'type' => 'invalid',
							'label' => extension_Members::$fields['activation']->get('label')
						);
						return false;
					}
				}
			}

			return $member_id;
		}


		public function fetchMemberFromID($member_id = null) {
			$member = parent::fetchMemberFromID($member_id);

			if(is_null($member)) return null;

			// If the member isn't activated and a Role field exists, we need to override
			// the current Role with the Activation Role. This may allow Members to view certain
			// things until they active their account.
			if(!is_null(extension_Members::getConfigVar('activation'))) {
				if($member->getData(extension_Members::getConfigVar('activation'), true)->activated != "yes") {
					if(!is_null(extension_Members::getConfigVar('role'))) {
						$member->setData(
							extension_Members::getConfigVar('role'),
							extension_Members::$fields['activation']->get('activation_role_id')
						);
					}
				}
			}

			return $member;
		}

	/*-------------------------------------------------------------------------
		Authentication:
	-------------------------------------------------------------------------*/

		/**
		 * Login function takes an associative array of fields that contain
		 * an Identity field (Email/Username) and a Password field. They keys
		 * should be the Field's `element_name`.
		 * An optional parameter, `$isHashed` refers to if the password provided
		 * is hashed already, or requires hashing prior to logging in.
		 *
		 * @param array $credentials
		 * @param boolean $isHashed
		 *  Defaults to false, which will encode the password value before attempting
		 *  to log the user in
		 * @return boolean
		 */
		public function login(Array $credentials, $isHashed = false) {
			$username = $email = $password = null;
			$data = array();

			// Map POST data to simple terms
			if(isset($credentials[extension_Members::$handles['identity']])) {
				$username = $credentials[extension_Members::$handles['identity']];
			}

			if(isset($credentials[extension_Members::$handles['email']])) {
				$email = $credentials[extension_Members::$handles['email']];
			}

			// Allow login via username OR email. This normalises the $data array from the custom
			// field names to simple names for ease of use.
			if(isset($username)) {
				$data['username'] = Symphony::Database()->cleanValue($username);
			}
			else if(isset($email) && !is_null(extension_Members::getConfigVar('email'))) {
				$data['email'] = Symphony::Database()->cleanValue($email);
			}

			// Map POST data for password to `$password`
			if(isset($credentials[extension_Members::$handles['authentication']])) {
				$password = $credentials[extension_Members::$handles['authentication']];

				// Use normalised handles for the fields
				if(!empty($password)) {
					$data['password'] = $isHashed ? $password : extension_Members::$fields['authentication']->encodePassword($password);
				}
				else {
					$data['password'] = '';
				}
			}

			if($id = $this->findMemberIDFromCredentials($data)) {
				try{
					self::$member_id = $id;
					$this->initialiseCookie();
					$this->initialiseMemberObject();

					$this->cookie->set('id', $id);

					if(isset($username)) {
						$this->cookie->set('username', $data['username']);
					}
					else {
						$this->cookie->set('email', $data['email']);
					}

					$this->cookie->set('password', $data['password']);

					self::$isLoggedIn = true;

				} catch(Exception $ex){
					// Or do something else?
					throw new Exception($ex);
				}

				return true;
			}

			$this->logout();

			return false;
		}

		public function isLoggedIn() {
			if(self::$isLoggedIn) return true;

			$this->initialiseCookie();

			$data = array(
				'password' => $this->cookie->get('password')
			);

			if(!is_null($this->cookie->get('username'))) {
				$data['username'] = $this->cookie->get('username');
			}
			else {
				$data['email'] = $this->cookie->get('email');
			}

			if($id = $this->findMemberIDFromCredentials($data)) {
				self::$member_id = $id;
				self::$isLoggedIn = true;
				return true;
			}

			$this->logout();

			return false;
		}

	/*-------------------------------------------------------------------------
		Filters:
	-------------------------------------------------------------------------*/

		public function filter_LockRole(Array &$context) {
			// If there is a Role field, this will force it to be the Default Role.
			if(!is_null(extension_Members::getConfigVar('role'))) {
				// Can't use `$context` as `$fields` only contains $_POST['fields']
				if(isset($_POST['id'])) {
					$member = parent::fetchMemberFromID(
						Symphony::Database()->cleanValue($_POST['id'])
					);

					if(!$member instanceof Entry) return;

					// If there is a Role set to this Member, lock the `$fields` role to the same value
					$role_id = $member->getData(extension_Members::getConfigVar('role'), true)->role_id;
					$context['fields'][extension_Members::$handles['role']] = $role_id;
				}
				// New Member, so use the default Role
				else {
					$context['fields'][extension_Members::$handles['role']] = extension_Members::$fields['role']->get('default_role');
				}
			}
		}

		public function filter_LockActivation(Array &$context) {
			// If there is an Activation field, this will force it to be no.
			if(!is_null(extension_Members::getConfigVar('activation'))) {
				// Can't use `$context` as `$fields` only contains $_POST['fields']
				if(isset($_POST['id'])) {
					$member = parent::fetchMemberFromID(
						Symphony::Database()->cleanValue($_POST['id'])
					);

					if(!$member instanceof Entry) return;

					// Lock the `$fields` activation to the same value as what is set to the Member
					$activated = $member->getData(extension_Members::getConfigVar('activation'), true)->activated;
					$context['fields'][extension_Members::$handles['activation']] = $activated;
				}
				// New Member, so use the default Role
				else {
					$context['fields'][extension_Members::$handles['activation']] = 'no';
				}
			}
		}

		/**
		 * Part 1 - Update Password
		 * If there is an Authentication field, we need to inject the 'optional'
		 * key so that it won't flag a user's password as invalid if they fail to
		 * enter it. The use of the 'optional' key will only trigger validation should
		 * they enter a value in the password field, in which it assumes the user is
		 * trying to update their password.
		 */
		public function filter_UpdatePassword(Array &$context) {
			if(!is_null(extension_Members::getConfigVar('authentication'))) {
				$context['fields'][extension_Members::$handles['authentication']]['optional'] = 'yes';
			}
		}

		/**
		 * Part 2 - Update Password, logs the user in
		 * If the user changed their password, we need to login them back into the
		 * system with their new password.
		 */
		public function filter_UpdatePasswordLogin(Array $context) {
			// If the user didn't update their password.
			if(empty($context['fields'][extension_Members::$handles['authentication']]['password'])) return;

			$this->login(array(
				extension_Members::$handles['authentication'] => $context['fields'][extension_Members::$handles['authentication']]['password'],
				extension_Members::$handles['identity'] => $context['entry']->getData(extension_Members::getConfigVar('identity'), true)->value
			), true);

			if(isset($_REQUEST['redirect'])) {
				redirect($_REQUEST['redirect']);
			}
		}

	}
