<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2013 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

/**
 *
 * This class handles all of the session related stuff in Ampache
 * it takes over for the vauth libs, and takes some stuff out of other
 * classes where it didn't belong.
 */
class Auth {

    /**
     * Constructor
     *
     * This should never be called
     */
    private function __construct() {
        // Rien a faire
    }

    /**
     * logout
     *
     * This is called when you want to log out and nuke your session.
     * This is the function used for the Ajax logouts, if no id is passed
     * it tries to find one from the session,
     */
    public static function logout($key='', $relogin = true) {

        // If no key is passed try to find the session id
        $key = $key ? $key : session_id();

        // Nuke the cookie before all else
        Session::destroy($key);
        if ((!$relogin) && Config::get('logout_redirect')) {
            $target = Config::get('logout_redirect');
        }
        else {
            $target = Config::get('web_path') . '/login.php';
        }

        // Do a quick check to see if this is an AJAXed logout request
        // if so use the iframe to redirect
        if (defined('AJAX_INCLUDE')) {
            ob_end_clean();
            ob_start();

            /* Set the correct headers */
            header("Content-type: text/xml; charset=" . Config::get('site_charset'));
            header("Content-Disposition: attachment; filename=ajax.xml");
            header("Expires: Tuesday, 27 Mar 1984 05:00:00 GMT");
            header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
            header("Cache-Control: no-store, no-cache, must-revalidate");
            header("Pragma: no-cache");

            $results['rfc3514'] = '<script type="text/javascript">reloadRedirect("' . $target . '")</script>';
            echo xml_from_array($results);
        }
        else {
            /* Redirect them to the login page */
            header('Location: ' . $target);
        }

        exit;
    }

    /**
     * login
     *
     * This takes a username and password and then returns the results
     * based on what happens when we try to do the auth.
     */
    public static function login($username, $password) {
        foreach (Config::get('auth_methods') as $method) {
            $function_name = $method . '_auth';

            if (!method_exists('Auth', $function_name)) { 
                continue;
            }

            $results = self::$function_name($username, $password);
            if ($results['success']) { break; }
        }

        return $results;
    }

    /**
     * mysql_auth
     *
     * This is the core function of our built-in authentication.
     */
    private static function mysql_auth($username, $password) {

        if (strlen($password) && strlen($username)) {
            $sql = 'SELECT `password` FROM `user` WHERE `username` = ?';
            $db_results = Dba::read($sql, array($username));

            if ($row = Dba::fetch_assoc($db_results)) {
                // Use SHA2 now... cooking with fire.
                // For backwards compatibility we hash a couple of different
                // variations of the password. Increases collision chances, but
                // doesn't break things.
                // FIXME: Break things in the future.
                $hashed_password[] = hash('sha256', $password);
                $hashed_password[] = hash('sha256', 
                    Dba::escape(stripslashes(htmlspecialchars(strip_tags($password)))));

                // Automagically update the password if it's old and busted.
                if ($row['password'] == $hashed_password[1] &&
                    $hashed_password[0] != $hashed_password[1]) {
                    $user = User::get_from_username($username);
                    $user->update_password($password);
                }

                if (in_array($row['password'], $hashed_password)) {
                    return array(
                        'success' => true,
                        'type' => 'mysql',
                        'username' => $username
                    );
                }
            }
        }

        return array(
            'success' => false,
            'error' => 'MySQL login attempt failed'
        );
    }

    /**
     * pam_auth
     *
     * Check to make sure the pam_auth function is implemented (module is 
     * installed), then check the credentials.
     */
    private static function pam_auth($username, $password) {
        if (!function_exists('pam_auth')) {
            $results['success']    = false;
            $results['error']    = 'The PAM PHP module is not installed';
            return $results;
        }

        $password = scrub_in($password);

        if (pam_auth($username, $password)) {
            $results['success']    = true;
            $results['type']    = 'pam';
            $results['username']    = $username;
        }
        else {
            $results['success']    = false;
            $results['error']    = 'PAM login attempt failed';
        }

        return $results;
    }

    /**
     * external_auth
     *
     * Calls an external program compatible with mod_authnz_external
     * such as pwauth.
     */
    private static function external_auth($username, $password) {
        $authenticator = Config::get('external_authenticator');
        if (!$authenticator) {
            return array(
                'success' => false,
                'error' => 'No external authenticator configured'
            );
        }

        //FIXME: should we do input sanitization?
        $proc = proc_open($authenticator,
            array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w')
            ), $pipes);

        if (is_resource($proc)) {
            fwrite($pipes[0], $username."\n".$password."\n");
            fclose($pipes[0]);
            fclose($pipes[1]);
            if ($stderr = fread($pipes[2], 8192)) {
                debug_event('external_auth', $stderr, 5);
            }
            fclose($pipes[2]);
        }
        else {
            return array(
                'success' => false,
                'error' => 'Failed to run external authenticator'
            );
        }

        if (proc_close($proc) == 0) {
            return array(
                'success' => true,
                'type' => 'external',
                'username' => $username
            );
        }

        return array(
            'success' => false,
            'error' => 'The external authenticator did not accept the login'
        );
    }

    /**
     * ldap_auth
     * Step one, connect to the LDAP server and perform a search for the
     * username provided.
     * Step two, attempt to bind using that username and the password
     * provided.
     * Step three, figure out if they are authorized to use ampache:
     * TODO: in config but unimplemented:
     *      * require-dn "Grant access if the DN in the directive matches 
     *        the DN fetched from the LDAP directory"
     *      * require-attribute "an attribute fetched from the LDAP 
     *        directory matches the given value"
     */
    private static function ldap_auth($username, $password) {

        $ldap_username    = Config::get('ldap_username');
        $ldap_password    = Config::get('ldap_password');

        $require_group    = Config::get('ldap_require_group');

        // This is the DN for the users (required)
        $ldap_dn    = Config::get('ldap_search_dn');

        // This is the server url (required)
        $ldap_url    = Config::get('ldap_url');

        // This is the ldap filter string (required)
        $ldap_filter    = Config::get('ldap_filter');

        //This is the ldap objectclass (required)
        $ldap_class    = Config::get('ldap_objectclass');

        if (!($ldap_dn && $ldap_url && $ldap_filter && $ldap_class)) {
            debug_event('ldap_auth', 'Required config value missing', 1);
            $results['success'] = false;
            $results['error'] = 'Incomplete LDAP config';
            return $results;
        }

        $ldap_name_field    = Config::get('ldap_name_field');
        $ldap_email_field    = Config::get('ldap_email_field');

        if ($ldap_link = ldap_connect($ldap_url) ) {

            /* Set to Protocol 3 */
            ldap_set_option($ldap_link, LDAP_OPT_PROTOCOL_VERSION, 3);

            // bind using our auth if we need to for initial search
            if (!ldap_bind($ldap_link, $ldap_username, $ldap_password)) {
                $results['success'] = false;
                $results['error'] = 'Could not bind to LDAP server.';
                return $results;
            } // If bind fails

            $sr = ldap_search($ldap_link, $ldap_dn, "(&(objectclass=$ldap_class)($ldap_filter=$username))");
            $info = ldap_get_entries($ldap_link, $sr);

            if ($info["count"] == 1) {
                $user_entry = ldap_first_entry($ldap_link, $sr);
                $user_dn    = ldap_get_dn($ldap_link, $user_entry);
                $password   = scrub_in($password);
                // bind using the user..
                $retval = ldap_bind($ldap_link, $user_dn, $password);

                if ($retval) {
                    // When the current user needs to be in
                    // a specific group to access Ampache,
                    // check whether the 'member' list of 
                    // the group contains the DN
                    if ($require_group) {
                        $group_result = ldap_read($ldap_link, $require_group, 'objectclass=*', array('member'));
                        if (!$group_result) {
                            debug_event('ldap_auth', "Failure reading $require_group", 1);
                            $results['success'] = false;
                            $results['error'] = 'The LDAP group could not be read';
                            return $results;
                        }

                        $group_info = ldap_get_entries($ldap_link, $group_result);

                        if ($group_info['count'] < 1) {
                            debug_event('ldap_auth', "No members found in $require_group", 1);
                            $results['success'] = false;
                            $results['error'] = 'Empty LDAP group';
                            return $results;
                        }

                        $group_match = preg_grep("/^$user_dn\$/i", $group_info[0]['member']);
                        if (!$group_match) {
                            debug_event('ldap_auth', "$user_dn is not a member of $require_group",1);
                            $results['success'] = false;
                            $results['error'] = 'LDAP login attempt failed';
                            return $results;
                        }
                    }
                    ldap_close($ldap_link);
                    $results['success']  = true;
                    $results['type']     = "ldap";
                    $results['username'] = $username;
                    $results['name']     = $info[0][$ldap_name_field][0];
                    $results['email']    = $info[0][$ldap_email_field][0];

                    return $results;

                } // if we get something good back

            } // if something was sent back

        } // if failed connect

        /* Default to bad news */
        $results['success'] = false;
        $results['error']   = 'LDAP login attempt failed';

        return $results;

    } // ldap_auth

    /**
     * http_auth
     * This auth method relies on HTTP auth from the webserver
     */
    private static function http_auth($username, $password) {
        if (($_SERVER['REMOTE_USER'] == $username) ||
            ($_SERVER['HTTP_REMOTE_USER'] == $username)) {
            $results['success']    = true;
            $results['type']    = 'http';
            $results['username']    = $username;
            $results['name']    = $username;
            $results['email']    = '';
        }
        else {
            $results['success'] = false;
            $results['error']   = 'HTTP auth login attempt failed';
        }
        return $results;
    } // http_auth

}
?>
