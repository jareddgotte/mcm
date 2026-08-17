<?php

/**
 * handles the user login/logout/session
 * @author Panique
 * @link http://www.php-login.net
 * @link https://github.com/panique/php-login/
 * @license http://opensource.org/licenses/MIT MIT License
 */
class Login
{
    /**
     * @var object $db_connection The database connection
     */
    private $db_connection = null;
    /**
     * @var array with translation of language strings
     */
    private $lang = array();
    /**
     * @var int $user_id The user's id
     */
    private $user_id = null;
    /**
     * @var string $user_name The user's name
     */
    private $user_name = "";
    /**
     * @var string $user_email The user's mail
     */
    private $user_email = "";
    /**
     * @var boolean $user_is_logged_in The user's login status
     */
    private $user_is_logged_in = false;
    /**
     * @var string $user_gravatar_image_url The user's gravatar profile pic url (or a default one)
     */
    public $user_gravatar_image_url = "";
    /**
     * @var string $user_gravatar_image_tag The user's gravatar profile pic url with <img ... /> around
     */
    public $user_gravatar_image_tag = "";
    /**
     * @var boolean $password_reset_link_is_valid Marker for view handling
     */
    private $password_reset_link_is_valid  = false;
    /**
     * @var boolean $password_reset_was_successful Marker for view handling
     */
    private $password_reset_was_successful = false;
    /**
     * @var array $errors Collection of error messages
     */
    public $errors = array();
    /**
     * @var array $messages Collection of success / neutral messages
     */
    public $messages = array();

    /**
     * the function "__construct()" automatically starts whenever an object of this class is created,
     * you know, when you do "$login = new Login();"
     */
    public function __construct()
    {
        // The session is already open: inc/bootstrap.php starts it, once, for
        // every entry point before any of this code runs.

        // TODO: organize this stuff better and make the constructor very small
        // TODO: unite Login and Registration classes ?

        // create internal reference to global array with translation of language strings
        // TODO: @devplanete: This should be $_GLOBALS !!?!?!?!?!?!
        $this->lang = & $GLOBALS['phplogin_lang'];

        // check the possible login actions:
        // 1. logout (happen when user clicks logout button)
        // 2. login via session data (happens each time user opens a page on your php project AFTER he has successfully logged in via the login form)
        // 3. login via cookie
        // 4. login via post data, which means simply logging in via the login form. after the user has submit his login/password successfully, his
        //    logged-in-status is written into his session data on the server. this is the typical behaviour of common login scripts.

        // if user tried to log out
        if (isset($_GET["logout"])) {
            $this->doLogout();

        // if user has an active session on the server
        } elseif (!empty($_SESSION['user_name']) && ($_SESSION['user_logged_in'] == 1)) {
            $this->loginWithSessionData();

            // checking for form submit from editing screen
            // user try to change his username
            if (isset($_POST["user_edit_submit_name"])) {
                // function below uses use $_SESSION['user_id'] et $_SESSION['user_email']
                $this->editUserName($_POST['user_name']);
            // user try to change his email
            } elseif (isset($_POST["user_edit_submit_email"])) {
                // function below uses use $_SESSION['user_id'] et $_SESSION['user_email']
                $this->editUserEmail($_POST['user_email']);
            // user try to change his password
            } elseif (isset($_POST["user_edit_submit_password"])) {
                // function below uses $_SESSION['user_name'] and $_SESSION['user_id']
                $this->editUserPassword($_POST['user_password_old'], $_POST['user_password_new'], $_POST['user_password_repeat']);
            }

        // login with cookie
        } elseif (isset($_COOKIE['rememberme'])) {
            $this->loginWithCookieData();

        // if user just submitted a login form
        } elseif (isset($_POST["login"])) {
            $this->loginWithPostData($_POST['user_name'], $_POST['user_password'], $_POST['user_rememberme']);
        }

        // checking if user requested a password reset mail
        if (isset($_POST["request_password_reset"]) && isset($_POST['user_name'])) {
            $this->setPasswordResetDatabaseTokenAndSendMail($_POST['user_name']);
        } elseif (isset($_GET["user_name"]) && isset($_GET["verification_code"])) {
            $this->checkIfEmailVerificationCodeIsValid($_GET["user_name"], $_GET["verification_code"]);
        } elseif (isset($_POST["submit_new_password"])) {
            $this->editNewPassword($_POST['user_name'], $_POST['user_password_reset_hash'], $_POST['user_password_new'], $_POST['user_password_repeat']);
        }

        // get gravatar profile picture if user is logged in
        if ($this->isUserLoggedIn() == true) {
            $this->getGravatarImageUrl($this->user_email);
        }
    }

    /**
     * Checks if database connection is opened.
     * If not, then tries to open it.
     */
    private function databaseConnection()
    {
        // connection already opened
        if ($this->db_connection != null) {
            return true;
        } else {
            try {
                $this->db_connection = new PDO('mysql:host='. DB_HOST .';dbname='. DB_NAME, DB_USER, DB_PASS);
                return true;
            } catch (PDOException $e) {
                $this->errors[] = $this->lang['Database error'] . $e->getMessage();
                return false;
            }
        }
    }

    /**
     * Search into database for the user data of user_name specified as parameter
     * @return user data as an object if existing user
     * @return false if user_name is not found in the database
     * TODO: @devplanete This returns two different types. Maybe this is valid, but it feels bad. We should rework this.
     * TODO: @devplanete After some resarch I'm VERY sure that this is not good coding style! Please fix this.
     */
    private function getUserData($user_name)
    {
        // if database connection opened
        if ($this->databaseConnection()) {
            // database query, getting all the info of the selected user
            $query_user = $this->db_connection->prepare('SELECT * FROM users WHERE user_name = :user_name');
            $query_user->bindValue(':user_name', $user_name, PDO::PARAM_STR);
            $query_user->execute();
            // get result row (as an object)
            return $query_user->fetchObject();
        } else {
            return false;
        }
    }

    /**
     * Logs in with S_SESSION data.
     * Technically we are already logged in at that point of time, as the $_SESSION values already exist.
     */
    private function loginWithSessionData()
    {
        $this->user_name = $_SESSION['user_name'];
        $this->user_email = $_SESSION['user_email'];

        // set logged in status to true, because we just checked for this:
        // !empty($_SESSION['user_name']) && ($_SESSION['user_logged_in'] == 1)
        // when we called this method (in the constructor)
        $this->user_is_logged_in = true;
    }

    /**
     * Logs in via the Cookie
     * @return bool success state of cookie login
     */
    private function loginWithCookieData()
    {
        if (isset($_COOKIE['rememberme'])) {
            // read the cookie and check its hash. The format is unchanged, so a
            // cookie issued by any earlier version of this file is still valid
            // here, and the check no longer says how much of a guessed hash was
            // right.
            $cookie_parts = mcm_remember_me_cookie_parts($_COOKIE['rememberme']);

            if ($cookie_parts !== false) {
                $user_id = $cookie_parts['user_id'];
                $token   = $cookie_parts['token'];

                // cookie looks good, try to select corresponding user
                if ($this->databaseConnection()) {
                    // get real token from database (and all other data)
                    $sth = $this->db_connection->prepare("SELECT user_id, user_name, user_email FROM users WHERE user_id = :user_id
                                                      AND user_rememberme_token = :user_rememberme_token AND user_rememberme_token IS NOT NULL");
                    $sth->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                    $sth->bindValue(':user_rememberme_token', $token, PDO::PARAM_STR);
                    $sth->execute();
                    // get result row (as an object)
                    $result_row = $sth->fetchObject();

                    if (isset($result_row->user_id)) {
                        // write user data into PHP SESSION [a file on your server]
                        $_SESSION['user_id'] = $result_row->user_id;
                        $_SESSION['user_name'] = $result_row->user_name;
                        $_SESSION['user_email'] = $result_row->user_email;
                        $_SESSION['user_logged_in'] = 1;

                        // declare user id, set the login status to true
                        $this->user_id = $result_row->user_id;
                        $this->user_name = $result_row->user_name;
                        $this->user_email = $result_row->user_email;
                        $this->user_is_logged_in = true;

                        // The visitor is authenticated now, so the identifier
                        // this session carries must not be one that was already
                        // in the browser beforehand.
                        mcm_session_regenerate_id();

                        // Cookie token usable only once
                        $this->newRememberMeCookie();
                        return true;
                    }
                }
            }
            // A cookie has been used but is not valid... we delete it
            $this->deleteRememberMeCookie();
            $this->errors[] = $this->lang['Invalid cookie'];
        }
        return false;
    }

    /**
     * Logs in with the data provided in $_POST, coming from the login form
     * @param $user_name
     * @param $user_password
     * @param $user_rememberme
     */
    private function loginWithPostData($user_name, $user_password, $user_rememberme)
    {
        if (empty($user_name)) {
            $this->errors[] = $this->lang['Empty username'];
        } else if (empty($user_password)) {
            $this->errors[] = $this->lang['Empty password'];

        // if POST data (from login form) contains non-empty user_name and non-empty user_password
        } else {
            // user can login with his username or his email address.
            // if user has not typed a valid email address, we try to identify him with his user_name
            if (!filter_var($user_name, FILTER_VALIDATE_EMAIL)) {
                // database query, getting all the info of the selected user
                $result_row = $this->getUserData(trim($user_name));

            // if user has typed a valid email address, we try to identify him with his user_email
            } else if ($this->databaseConnection()) {
                // database query, getting all the info of the selected user
                $query_user = $this->db_connection->prepare('SELECT * FROM users WHERE user_email = :user_email');
                $query_user->bindValue(':user_email', trim($user_name), PDO::PARAM_STR);
                $query_user->execute();
                // get result row (as an object)
                $result_row = $query_user->fetchObject();
            }

            // if this user not exists
            if (! isset($result_row->user_id)) {
                $this->errors[] = $this->lang['User not exist'];
            // check the provided password against the stored hash. Hashes written
            // by any earlier version of this file still verify here.
            } else if (! mcm_password_verify($user_password, $result_row->user_password_hash)) {
                $this->errors[] = $this->lang['Wrong password'];
            // does the user activates his account with the verification email
            } else if ($result_row->user_active != 1) {
                $this->errors[] = $this->lang['Account not activated'];

            } else {
                // write user data into PHP SESSION [a file on your server]
                $_SESSION['user_id'] = $result_row->user_id;
                $_SESSION['user_name'] = $result_row->user_name;
                $_SESSION['user_email'] = $result_row->user_email;
                $_SESSION['user_logged_in'] = 1;

                // declare user id, set the login status to true
                $this->user_id = $result_row->user_id;
                $this->user_name = $result_row->user_name;
                $this->user_email = $result_row->user_email;
                $this->user_is_logged_in = true;

                // The visitor is authenticated now, so the identifier this
                // session carries must not be one that was already in the
                // browser beforehand.
                mcm_session_regenerate_id();

                // if user has check the "remember me" checkbox, then generate token and write cookie
                if (isset($user_rememberme)) {
                    $this->newRememberMeCookie();
                } else {
                    // Reset rememberme token
                    $this->deleteRememberMeCookie();
                }

                // Opportunistic rehashing: this is the only moment the plain
                // password is available, so a hash that was calculated with
                // weaker settings than the ones in use now is quietly replaced.
                // Nobody is asked to do anything, and an account whose owner
                // never signs in keeps its old hash and keeps working.
                if (mcm_password_needs_rehash($result_row->user_password_hash)) {
                    $user_password_hash = mcm_password_hash($user_password);

                    // TODO: this should be put into another method !?
                    $query_update = $this->db_connection->prepare('UPDATE users SET user_password_hash = :user_password_hash WHERE user_id = :user_id');
                    $query_update->bindValue(':user_password_hash', $user_password_hash, PDO::PARAM_STR);
                    $query_update->bindValue(':user_id', $result_row->user_id, PDO::PARAM_INT);
                    $query_update->execute();

                    if ($query_update->rowCount() != 1) {
                        // The login itself has already succeeded, so this is a
                        // note for the log and nothing the visitor is told.
                        mcm_log('Password rehash', 'the recalculated hash could not be stored for user id ' . (int) $result_row->user_id);
                    }
                }
            }
        }
    }

    /**
     * Create all data needed for remember me cookie connection on client and server side
     */
    private function newRememberMeCookie()
    {
        // if database connection opened
        if ($this->databaseConnection()) {
            // generate a 64 char random string and store it in current user data.
            // 64 characters is the width of users.user_rememberme_token, so the
            // cookie and the column keep the shape they have always had.
            $random_token_string = mcm_random_token(64);
            $sth = $this->db_connection->prepare("UPDATE users SET user_rememberme_token = :user_rememberme_token WHERE user_id = :user_id");
            $sth->execute(array(':user_rememberme_token' => $random_token_string, ':user_id' => $_SESSION['user_id']));

            // generate cookie string that consists of userid, randomstring and combined hash of both
            $cookie_string = mcm_remember_me_cookie_value($_SESSION['user_id'], $random_token_string);

            // set cookie
            setcookie('rememberme', $cookie_string, time() + COOKIE_RUNTIME, "/", COOKIE_DOMAIN);
        }
    }

    /**
     * Delete all data needed for remember me cookie connection on client and server side
     */
    private function deleteRememberMeCookie()
    {
        // if database connection opened
        if ($this->databaseConnection()) {
            // Reset rememberme token
            $sth = $this->db_connection->prepare("UPDATE users SET user_rememberme_token = NULL WHERE user_id = :user_id");
            $sth->execute(array(':user_id' => $_SESSION['user_id']));
        }

        // set the rememberme-cookie to ten years ago (3600sec * 365 days * 10).
        // that's obivously the best practice to kill a cookie via php
        // @see http://stackoverflow.com/a/686166/1114320
        setcookie('rememberme', false, time() - (3600 * 3650), '/', COOKIE_DOMAIN);
    }

    /**
     * Perform the logout, resetting the session
     */
    public function doLogout()
    {
        $this->deleteRememberMeCookie();

        $_SESSION = array();
        session_destroy();

        $this->user_is_logged_in = false;
        $this->messages[] = $this->lang['Logged out'];
    }

    /**
     * Simply return the current state of the user's login
     * @return bool user's login status
     */
    public function isUserLoggedIn()
    {
        return $this->user_is_logged_in;
    }

    /**
     * Edit the user's name, provided in the editing form
     */
    public function editUserName($user_name)
    {
        // prevent database flooding
        $user_name = substr(trim($user_name), 0, 64);

        if (!empty($user_name) && $user_name == $_SESSION['user_name']) {
            $this->errors[] = $this->lang['Same username'];

        // username cannot be empty and must be azAZ09 and 2-64 characters
        // TODO: maybe this pattern should also be implemented in Registration.php (or other way round)
        } elseif (empty($user_name) || !preg_match("/^(?=.{2,64}$)[a-zA-Z][a-zA-Z0-9]*(?: [a-zA-Z0-9]+)*$/", $user_name)) {
            $this->errors[] = $this->lang['Invalid username'];

        } else {
            // check if new username already exists
            $result_row = $this->getUserData($user_name);

            if (isset($result_row->user_id)) {
                $this->errors[] = $this->lang['Username exist'];
            } else {
                // write user's new data into database
                $query_edit_user_name = $this->db_connection->prepare('UPDATE users SET user_name = :user_name WHERE user_id = :user_id');
                $query_edit_user_name->bindValue(':user_name', $user_name, PDO::PARAM_STR);
                $query_edit_user_name->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                $query_edit_user_name->execute();

                if ($query_edit_user_name->rowCount()) {
                    $_SESSION['user_name'] = $user_name;
                    $this->messages[] = $this->lang['Username changed'] . $user_name;
                } else {
                    $this->errors[] = $this->lang['Username change failed'];
                }
            }
        }
    }

    /**
     * Edit the user's email, provided in the editing form
     */
    public function editUserEmail($user_email)
    {
        // prevent database flooding
        $user_email = substr(trim($user_email), 0, 64);

        if (!empty($user_email) && $user_email == $_SESSION["user_email"]) {
            $this->errors[] = $this->lang['Same email'];
        // user mail cannot be empty and must be in email format
        } elseif (empty($user_email) || !filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = $this->lang['Invalid email'];

        } else if ($this->databaseConnection()) {
            // check if new email already exists
            $query_user = $this->db_connection->prepare('SELECT * FROM users WHERE user_email = :user_email');
            $query_user->bindValue(':user_email', $user_email, PDO::PARAM_STR);
            $query_user->execute();
            // get result row (as an object)
            $result_row = $query_user->fetchObject();

            // if this email exists
            if (isset($result_row->user_id)) {
                $this->errors[] = $this->lang['Email exist'];
            } else {
                // write users new data into database
                $query_edit_user_email = $this->db_connection->prepare('UPDATE users SET user_email = :user_email WHERE user_id = :user_id');
                $query_edit_user_email->bindValue(':user_email', $user_email, PDO::PARAM_STR);
                $query_edit_user_email->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                $query_edit_user_email->execute();

                if ($query_edit_user_email->rowCount()) {
                    $_SESSION['user_email'] = $user_email;
                    $this->messages[] = $this->lang['Email changed'] . $user_email;
                } else {
                    $this->errors[] = $this->lang['Email change failed'];
                }
            }
        }
    }

    /**
     * Edit the user's password, provided in the editing form
     */
    public function editUserPassword($user_password_old, $user_password_new, $user_password_repeat)
    {
        if (empty($user_password_new) || empty($user_password_repeat) || empty($user_password_old)) {
            $this->errors[] = $this->lang['Empty password'];
        // is the repeat password identical to password
        } elseif ($user_password_new !== $user_password_repeat) {
            $this->errors[] = $this->lang['Bad confirm password'];
        // password need to have a minimum length of 6 characters
        } elseif (strlen($user_password_new) < 6) {
            $this->errors[] = $this->lang['Password too short'];

        // all the above tests are ok
        } else {
            // database query, getting hash of currently logged in user (to check with just provided password)
            $result_row = $this->getUserData($_SESSION['user_name']);

            // if this user exists
            if (isset($result_row->user_password_hash)) {

                // check the old password against the stored hash, which may have
                // been written by any earlier version of this file
                if (mcm_password_verify($user_password_old, $result_row->user_password_hash)) {

                    // hash the new password with the configured cost factor
                    $user_password_hash = mcm_password_hash($user_password_new);

                    // write users new hash into database
                    $query_update = $this->db_connection->prepare('UPDATE users SET user_password_hash = :user_password_hash WHERE user_id = :user_id');
                    $query_update->bindValue(':user_password_hash', $user_password_hash, PDO::PARAM_STR);
                    $query_update->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                    $query_update->execute();

                    // check if exactly one row was successfully changed:
                    if ($query_update->rowCount()) {
                        // The password just changed, so the session gets a new
                        // identifier: any copy of the old one stops working.
                        mcm_session_regenerate_id();

                        $this->messages[] = $this->lang['Password changed'];
                    } else {
                        $this->errors[] = $this->lang['Password changed failed'];
                    }
                } else {
                    $this->errors[] = $this->lang['Wrong old password'];
                }
            } else {
                $this->errors[] = $this->lang['User not exist'];
            }
        }
    }

    /**
     * Sets a random token into the database (that will verify the user when he/she comes back via the link
     * in the email) and sends the according email.
     */
    public function setPasswordResetDatabaseTokenAndSendMail($user_name)
    {
        $user_name = trim($user_name);

        if (empty($user_name)) {
            $this->errors[] = $this->lang['Empty username'];

        } else {
            // generate timestamp (to see when exactly the user (or an attacker) requested the password reset mail)
            // btw this is an integer ;)
            $temporary_timestamp = time();
            // generate a random token for email password reset verification.
            // 40 characters is the width of users.user_password_reset_hash, the
            // same length the previous sha1() value had.
            $user_password_reset_hash = mcm_random_token(40);
            // database query, getting all the info of the selected user
            $result_row = $this->getUserData($user_name);

            // if this user exists
            if (isset($result_row->user_id)) {

                // database query:
                $query_update = $this->db_connection->prepare('UPDATE users SET user_password_reset_hash = :user_password_reset_hash,
                                                               user_password_reset_timestamp = :user_password_reset_timestamp
                                                               WHERE user_name = :user_name');
                $query_update->bindValue(':user_password_reset_hash', $user_password_reset_hash, PDO::PARAM_STR);
                $query_update->bindValue(':user_password_reset_timestamp', $temporary_timestamp, PDO::PARAM_INT);
                $query_update->bindValue(':user_name', $user_name, PDO::PARAM_STR);
                $query_update->execute();

                // check if exactly one row was successfully changed:
                if ($query_update->rowCount() == 1) {
                    // send a mail to the user, containing a link with that token hash string
                    $this->sendPasswordResetMail($user_name, $result_row->user_email, $user_password_reset_hash);
                    return true;
                } else {
                    $this->errors[] = $this->lang['Database error'];
                }
            } else {
                $this->errors[] = $this->lang['User not exist'];
            }
        }
        // return false (this method only returns true when the database entry has been set successfully)
        return false;
    }

    /**
     * Sends the password-reset-email.
     */
    public function sendPasswordResetMail($user_name, $user_email, $user_password_reset_hash)
    {
        $mail = new PHPMailer;

        // please look into the config/config.php for much more info on how to use this!
        // use SMTP or use mail()
        if (EMAIL_USE_SMTP) {
            // Set mailer to use SMTP
            $mail->IsSMTP();
            //useful for debugging, shows full SMTP errors
            //$mail->SMTPDebug = 1; // debugging: 1 = errors and messages, 2 = messages only
            // Enable SMTP authentication
            $mail->SMTPAuth = EMAIL_SMTP_AUTH;
            // Enable encryption, usually SSL/TLS
            if (defined(EMAIL_SMTP_ENCRYPTION)) {
                $mail->SMTPSecure = EMAIL_SMTP_ENCRYPTION;
            }
            // Specify host server
            $mail->Host = EMAIL_SMTP_HOST;
            $mail->Username = EMAIL_SMTP_USERNAME;
            $mail->Password = EMAIL_SMTP_PASSWORD;
            $mail->Port = EMAIL_SMTP_PORT;
        } else {
            $mail->IsMail();
        }

        $mail->From = EMAIL_PASSWORDRESET_FROM;
        $mail->FromName = EMAIL_PASSWORDRESET_FROM_NAME;
        $mail->AddAddress($user_email);
        $mail->Subject = EMAIL_PASSWORDRESET_SUBJECT;

        $link    = EMAIL_PASSWORDRESET_URL.'?user_name='.urlencode($user_name).'&verification_code='.urlencode($user_password_reset_hash);
        $mail->Body = EMAIL_PASSWORDRESET_CONTENT . ' ' . $link;

        if(!$mail->Send()) {
            $this->errors[] = $this->lang['Password mail not sent'] . $mail->ErrorInfo;
            return false;
        } else {
            $this->messages[] = $this->lang['Password mail sent'];
            return true;
        }
    }

    /**
     * Checks if the verification string in the account verification mail is valid and matches to the user.
     */
    public function checkIfEmailVerificationCodeIsValid($user_name, $verification_code)
    {
        $user_name = trim($user_name);

        if (empty($user_name) || empty($verification_code)) {
            $this->errors[] = $this->lang['Empty link parameter'];
        } else {
            // database query, getting all the info of the selected user
            $result_row = $this->getUserData($user_name);

            // if this user exists and have the same hash in database. The
            // comparison is constant-time, so it says nothing about how much of
            // a guessed code was correct.
            if (isset($result_row->user_id)
                && mcm_hash_equals((string) $result_row->user_password_reset_hash, (string) $verification_code)) {

                $timestamp_one_hour_ago = time() - 3600; // 3600 seconds are 1 hour

                if ($result_row->user_password_reset_timestamp > $timestamp_one_hour_ago) {
                    // set the marker to true, making it possible to show the password reset edit form view
                    $this->password_reset_link_is_valid = true;
                } else {
                    $this->errors[] = $this->lang['Reset link has expired'];
                }
            } else {
                $this->errors[] = $this->lang['User not exist'];
            }
        }
    }

    /**
     * Checks and writes the new password.
     */
    public function editNewPassword($user_name, $user_password_reset_hash, $user_password_new, $user_password_repeat)
    {
        // TODO: timestamp!
        $user_name = trim($user_name);

        if (empty($user_name) || empty($user_password_reset_hash) || empty($user_password_new) || empty($user_password_repeat)) {
            $this->errors[] = $this->lang['Empty password'];
        // is the repeat password identical to password
        } else if ($user_password_new !== $user_password_repeat) {
            $this->errors[] = $this->lang['Bad confirm password'];
        // password need to have a minimum length of 6 characters
        } else if (strlen($user_password_new) < 6) {
            $this->errors[] = $this->lang['Password too short'];
        // if database connection opened
        } else if ($this->databaseConnection()) {
            // hash the new password with the configured cost factor
            $user_password_hash = mcm_password_hash($user_password_new);

            // write users new hash into database
            $query_update = $this->db_connection->prepare('UPDATE users SET user_password_hash = :user_password_hash,
                                                           user_password_reset_hash = NULL, user_password_reset_timestamp = NULL
                                                           WHERE user_name = :user_name AND user_password_reset_hash = :user_password_reset_hash');
            $query_update->bindValue(':user_password_hash', $user_password_hash, PDO::PARAM_STR);
            $query_update->bindValue(':user_password_reset_hash', $user_password_reset_hash, PDO::PARAM_STR);
            $query_update->bindValue(':user_name', $user_name, PDO::PARAM_STR);
            $query_update->execute();

            // check if exactly one row was successfully changed:
            if ($query_update->rowCount() == 1) {
                // The password just changed, so whatever identifier this
                // browser arrived with does not survive the change either.
                mcm_session_regenerate_id();

                $this->password_reset_was_successful = true;
                $this->messages[] = $this->lang['Password changed'];
            } else {
                $this->errors[] = $this->lang['Password changed failed'];
            }
        }
    }

    /**
     * Gets the success state of the password-reset-link-validation.
     * @return boolean
     */
    public function passwordResetLinkIsValid()
    {
        return $this->password_reset_link_is_valid;
    }

    /**
     * Gets the success state of the password-reset action.
     * @return boolean
     */
    public function passwordResetWasSuccessful()
    {
        return $this->password_reset_was_successful;
    }

    /**
     * Gets the username
     * @return string username
     */
    public function getUsername()
    {
        return $this->user_name;
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     * Gravatar is the #1 (free) provider for email address based global avatar hosting.
     * The URL (or image) returns always a .jpg file !
     * For deeper info on the different parameter possibilities:
     * @see http://de.gravatar.com/site/implement/images/
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 50px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @source http://gravatar.com/site/implement/images/php/
     */
    public function getGravatarImageUrl($email, $s = 50, $d = 'mm', $r = 'g', $atts = array() )
    {
        $url = 'http://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r&f=y";

        // the image url (on gravatarr servers), will return in something like
        // http://www.gravatar.com/avatar/205e460b479e2e5b48aec07710c08d50?s=80&d=mm&r=g
        // note: the url does NOT have something like .jpg
        $this->user_gravatar_image_url = $url;

        // build img tag around
        $url = '<img src="' . $url . '"';
        foreach ($atts as $key => $val)
            $url .= ' ' . $key . '="' . $val . '"';
        $url .= ' />';

        // the image url like above but with an additional <img src .. /> around
        $this->user_gravatar_image_tag = $url;
    }
}

?>