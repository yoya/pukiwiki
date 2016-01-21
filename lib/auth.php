<?php
// PukiWiki - Yet another WikiWikiWeb clone
// $Id: auth.php,v 1.22 2011/01/25 15:01:01 henoheno Exp $
// Copyright (C) 2003-2005, 2007 PukiWiki Developers Team
// License: GPL v2 or (at your option) any later version
//
// Authentication related functions

define('PKWK_PASSPHRASE_LIMIT_LENGTH', 512);

/////////////////////////////////////////////////
// Auth type

define('AUTH_TYPE_NONE', 0);
define('AUTH_TYPE_BASIC', 1);
define('AUTH_TYPE_EXTERNAL', 2);
define('AUTH_TYPE_FORM', 3);

define('AUTH_TYPE_EXTERNAL_REMOTE_USER', 4);
define('AUTH_TYPE_EXTERNAL_X_FORWARDED_USER', 5);


// Passwd-auth related ----

function pkwk_login($pass = '')
{
	global $adminpass;

	if (! PKWK_READONLY && isset($adminpass) &&
		pkwk_hash_compute($pass, $adminpass) === $adminpass) {
		return TRUE;
	} else {
		sleep(2);       // Blocking brute force attack
		return FALSE;
	}
}

// Compute RFC2307 'userPassword' value, like slappasswd (OpenLDAP)
// $phrase : Pass-phrase
// $scheme : Specify '{scheme}' or '{scheme}salt'
// $prefix : Output with a scheme-prefix or not
// $canonical : Correct or Preserve $scheme prefix
function pkwk_hash_compute($phrase = '', $scheme = '{x-php-md5}', $prefix = TRUE, $canonical = FALSE)
{
	if (! is_string($phrase) || ! is_string($scheme)) return FALSE;

	if (strlen($phrase) > PKWK_PASSPHRASE_LIMIT_LENGTH)
		die('pkwk_hash_compute(): malicious message length');

	// With a {scheme}salt or not
	$matches = array();
	if (preg_match('/^(\{.+\})(.*)$/', $scheme, $matches)) {
		$scheme = & $matches[1];
		$salt   = & $matches[2];
	} else if ($scheme != '') {
		$scheme  = ''; // Cleartext
		$salt    = '';
	}

	// Compute and add a scheme-prefix
	switch (strtolower($scheme)) {

	// PHP crypt()
	case '{x-php-crypt}' :
		$hash = ($prefix ? ($canonical ? '{x-php-crypt}' : $scheme) : '') .
			($salt != '' ? crypt($phrase, $salt) : crypt($phrase));
		break;

	// PHP md5()
	case '{x-php-md5}'   :
		$hash = ($prefix ? ($canonical ? '{x-php-md5}' : $scheme) : '') .
			md5($phrase);
		break;

	// PHP sha1()
	case '{x-php-sha1}'  :
		$hash = ($prefix ? ($canonical ? '{x-php-sha1}' : $scheme) : '') .
			sha1($phrase);
		break;

	// LDAP CRYPT
	case '{crypt}'       :
		$hash = ($prefix ? ($canonical ? '{CRYPT}' : $scheme) : '') .
			($salt != '' ? crypt($phrase, $salt) : crypt($phrase));
		break;

	// LDAP MD5
	case '{md5}'         :
		$hash = ($prefix ? ($canonical ? '{MD5}' : $scheme) : '') .
			base64_encode(pkwk_hex2bin(md5($phrase)));
		break;

	// LDAP SMD5
	case '{smd5}'        :
		// MD5 Key length = 128bits = 16bytes
		$salt = ($salt != '' ? substr(base64_decode($salt), 16) : substr(crypt(''), -8));
		$hash = ($prefix ? ($canonical ? '{SMD5}' : $scheme) : '') .
			base64_encode(pkwk_hex2bin(md5($phrase . $salt)) . $salt);
		break;

	// LDAP SHA
	case '{sha}'         :
		$hash = ($prefix ? ($canonical ? '{SHA}' : $scheme) : '') .
			base64_encode(pkwk_hex2bin(sha1($phrase)));
		break;

	// LDAP SSHA
	case '{ssha}'        :
		// SHA-1 Key length = 160bits = 20bytes
		$salt = ($salt != '' ? substr(base64_decode($salt), 20) : substr(crypt(''), -8));
		$hash = ($prefix ? ($canonical ? '{SSHA}' : $scheme) : '') .
			base64_encode(pkwk_hex2bin(sha1($phrase . $salt)) . $salt);
		break;

	// LDAP CLEARTEXT and just cleartext
	case '{cleartext}'   : /* FALLTHROUGH */
	case ''              :
		$hash = ($prefix ? ($canonical ? '{CLEARTEXT}' : $scheme) : '') .
			$phrase;
		break;

	// Invalid scheme
	default:
		$hash = FALSE;
		break;
	}

	return $hash;
}


// Basic-auth related ----

// Check edit-permission
function check_editable($page, $auth_flag = TRUE, $exit_flag = TRUE)
{
	global $script, $_title_cannotedit, $_msg_unfreeze;

	if (edit_auth($page, $auth_flag, $exit_flag) && is_editable($page)) {
		// Editable
		return TRUE;
	} else {
		// Not editable
		if ($exit_flag === FALSE) {
			return FALSE; // Without exit
		} else {
			// With exit
			$body = $title = str_replace('$1',
				htmlsc(strip_bracket($page)), $_title_cannotedit);
			if (is_freeze($page))
				$body .= '(<a href="' . $script . '?cmd=unfreeze&amp;page=' .
					rawurlencode($page) . '">' . $_msg_unfreeze . '</a>)';
			$page = str_replace('$1', make_search($page), $_title_cannotedit);
			catbody($title, $page, $body);
			exit;
		}
	}
}

// Check read-permission
function check_readable($page, $auth_flag = TRUE, $exit_flag = TRUE)
{
	return read_auth($page, $auth_flag, $exit_flag);
}

function edit_auth($page, $auth_flag = TRUE, $exit_flag = TRUE)
{
	global $edit_auth, $edit_auth_pages, $_title_cannotedit;
	return $edit_auth ?  basic_auth($page, $auth_flag, $exit_flag,
		$edit_auth_pages, $_title_cannotedit) : TRUE;
}

function read_auth($page, $auth_flag = TRUE, $exit_flag = TRUE)
{
	global $read_auth, $read_auth_pages, $_title_cannotread;
	return $read_auth ?  basic_auth($page, $auth_flag, $exit_flag,
		$read_auth_pages, $_title_cannotread) : TRUE;
}

// Basic authentication
function basic_auth($page, $auth_flag, $exit_flag, $auth_pages, $title_cannot)
{
	global $auth_method_type, $auth_users, $_msg_auth, $auth_user, $auth_groups;
	global $auth_user_groups, $auth_type, $g_query_string;
	global $auth_external_login_url;
	// Checked by:
	$target_str = '';
	if ($auth_method_type == 'pagename') {
		$target_str = $page; // Page name
	} else if ($auth_method_type == 'contents') {
		$target_str = join('', get_source($page)); // Its contents
	}

	$user_list = array();
	foreach($auth_pages as $key=>$val)
		if (preg_match($key, $target_str))
			$user_list = array_merge($user_list, explode(',', $val));

	if (empty($user_list)) return TRUE; // No limit

	$matches = array();
	if (PKWK_READONLY ||
		! $auth_user ||
		count(array_intersect($auth_user_groups, $user_list)) === 0)
	{
		// Auth failed
		pkwk_common_headers();
		if ($auth_flag && !$auth_user) {
			if (AUTH_TYPE_BASIC === $auth_type) {
				header('WWW-Authenticate: Basic realm="' . $_msg_auth . '"');
				header('HTTP/1.0 401 Unauthorized');
			} elseif (AUTH_TYPE_FORM === $auth_type) {
				$url_after_login = get_script_uri() . '?' . $g_query_string;
				$loginurl = get_script_uri() . '?plugin=loginform'
					. '&page=' . pagename_urlencode($page)
					. '&url_after_login=' . rawurlencode($url_after_login);
				header('HTTP/1.0 302 Found');
				header('Location: ' . $loginurl);
			} elseif (AUTH_TYPE_EXTERNAL === $auth_type) {
				$url_after_login = get_script_uri() . '?' . $g_query_string;
				$loginurl = $auth_external_login_url . '?'
					. '&page=' . pagename_urlencode($page)
					. '&url_after_login=' . rawurlencode($url_after_login);
				header('HTTP/1.0 302 Found');
				header('Location: ' . $loginurl);
			}
		}
		if ($exit_flag) {
			$body = $title = str_replace('$1',
				htmlsc(strip_bracket($page)), $title_cannot);
			$page = str_replace('$1', make_search($page), $title_cannot);
			catbody($title, $page, $body);
			exit;
		}
		return FALSE;
	} else {
		return TRUE;
	}
}

/**
 * Send 401 if client send a invalid credentials
 *
 * @return true if valid, false if invalid credentials
 */
function ensure_valid_auth_user()
{
	global $auth_type, $auth_users, $_msg_auth, $auth_user, $auth_groups;
	global $auth_user_groups;
	switch ($auth_type) {
		case AUTH_TYPE_BASIC:
		{
			if (isset($_SERVER['PHP_AUTH_USER'])) {
				$user = $_SERVER['PHP_AUTH_USER'];
				if (in_array($user, array_keys($auth_users))) {
					if (pkwk_hash_compute(
						$_SERVER['PHP_AUTH_PW'],
						$auth_users[$user]) === $auth_users[$user]) {
						$auth_user = $user;
						$auth_user_groups = get_groups_from_username($user);
						return true;
					}
				}
				header('WWW-Authenticate: Basic realm="' . $_msg_auth . '"');
				header('HTTP/1.0 401 Unauthorized');
			}
			$auth_user = '';
			$auth_user_groups = get_groups_from_username($user);
			return true; // no auth input
		}
		case AUTH_TYPE_FORM:
		case AUTH_TYPE_EXTERNAL:
		{
			session_start();
			// session_regenerate_id(true);
			$user = '';
			if (isset($_SESSION['authenticated_user'])) {
				$user = $_SESSION['authenticated_user'];
			}
			$auth_user = $user;
			break;
		}
		case AUTH_TYPE_EXTERNAL_REMOTE_USER:
			$auth_user = $_SERVER['REMOTE_USER'];
			break;
		case AUTH_TYPE_EXTERNAL_X_FORWARDED_USER:
			$auth_user =  $_SERVER['HTTP_X_FORWARDED_USER'];
			break;
		default: // AUTH_TYPE_NONE
			$auth_user = '';
			break;
	}
	$auth_user_groups = get_groups_from_username($auth_user);
	return true; // is not basic auth
}

/**
 * Return group name array whose group contains the user
 *
 * Result array contains reserved 'valid-user' group for all authenticated user
 * @global array $auth_groups
 * @param string $user
 * @return array
 */
function get_groups_from_username($user)
{
	global $auth_groups;
	if ($user !== '') {
		$groups = array();
		foreach ($auth_groups as $group=>$users) {
			$sp = explode(',', $users);
			if (in_array($user, $sp)) {
				$groups[] = $group;
			}
		}
		// Implecit group that has same name as user itself
		$groups[] = $user;
		// 'valid-user' group for
		$valid_user = 'valid-user';
		if (!in_array($valid_user, $groups)) {
			$groups[] = $valid_user;
		}
		return $groups;
	}
	return array();
}

/**
 * Get authenticated user name.
 *
 * @global type $auth_user
 * @return type
 */
function get_auth_user()
{
	global $auth_user;
	return $auth_user;
}

/**
 * Sign in with username and password
 *
 * @param String username
 * @param String password
 * @return true is sign in is OK
 */
function form_auth($username, $password)
{
	global $ldap_user_account, $auth_users;
	$user = $username;
	if ($ldap_user_account) {
		// LDAP account
		return ldap_auth($username, $password);
	} else {
		// Defined users in pukiwiki.ini.php
		if (in_array($user, array_keys($auth_users))) {
			if (pkwk_hash_compute(
				$password,
				$auth_users[$user]) === $auth_users[$user]) {
				$_SESSION['authenticated_user'] = $user;
				session_regenerate_id(true); // require: PHP5.1+
				return true;
			}
		}
	}
	return false;
}

function ldap_auth($username, $password)
{
	global $ldap_url, $ldap_bind_dn, $ldap_bind_password;
	if (preg_match('#^(ldap\:\/\/[^/]+/)(.*)$#', $ldap_url, $m)) {
		$ldap_server = $m[1];
		$ldap_base_dn = $m[2];
		$ldapconn = ldap_connect($ldap_server);
		if ($ldapconn) {
			ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
			if (preg_match('#\$login\b#', $ldap_bind_dn)) {
				// Bind by user credential
				$bind_dn_user = preg_replace('#\$login#', $username, $ldap_bind_dn);
				$ldap_bind_user = ldap_bind($ldapconn, $bind_dn_user, $password);
				if ($ldap_bind_user) {
					$user_info = get_ldap_user_info($ldapconn, $username, $ldap_base_dn);
					if ($user_info) {
						$_SESSION['authenticated_user'] = $user_info['uid'];
						session_regenerate_id(true); // require: PHP5.1+
						return true;
					}
				}
			} else {
				// Bind by bind dn
				$ldap_bind = ldap_bind($ldapconn, $ldap_bind_dn, $ldap_bind_password);
				if ($ldap_bind) {
					$user_info = get_ldap_user_info($ldapconn, $username, $ldap_base_dn);
					if ($user_info) {
						$ldap_bind_user2 = ldap_bind($ldapconn, $user_info['dn'], $password);
						if ($ldap_bind_user2) {
							$_SESSION['authenticated_user'] = $user_info['uid'];
							session_regenerate_id(true); // require: PHP5.1+
							return true;
						}
					}
				}
			}
		}
	}
}

/**
 * Search user and get 'dn', 'uid', 'fullname' and 'mail'
 * @param type $ldapconn
 * @param type $username
 * @param type $base_dn
 * @return boolean
 */
function get_ldap_user_info($ldapconn, $username, $base_dn) {
	$filter = "(|(uid=$username)(sAMAccountName=$username))";
	$result1 = ldap_search($ldapconn, $base_dn, $filter, array('dn', 'uid', 'cn', 'samaccountname', 'displayname', 'mail'));
	$entries = ldap_get_entries($ldapconn, $result1);
	$info = $entries[0];
	if (isset($info['dn'])) {
		$user_dn = $info['dn'];
		$cano_username = $username;
		if (isset($info['uid'][0])) {
			$cano_username = $info['uid'][0];
		} elseif (isset($info['samaccountname'][0])) {
			$cano_username = $info['samaccountname'][0];
		}
		$cano_fullname = $username;
		if (isset($info['displayname'][0])) {
			$cano_fullname = $info['displayname'][0];
		} elseif (isset($info['cn'][0])) {
			$cano_fullname = $info['cn'][0];
		}
		return array(
			'dn' => $user_dn,
			'uid' => $cano_username,
			'fullname' => $cano_fullname,
			'mail' => $info['mail'][0]
		);
	}
	return false;
}

/**
 * Redirect after login. Need to assing location or page
 *
 * @param type $location
 * @param type $page
 */
function form_auth_redirect($location, $page)
{
	header('HTTP/1.0 302 Found');
	if ($location) {
		header('Location: ' . $location);
	} else {
		$url = get_script_uri() . '?' . $page;
		header('Location: ' . $url);
	}
}
