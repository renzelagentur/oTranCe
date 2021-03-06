<?php
/**
 * This file is part of oTranCe released under the GNU GPL 2 license
 * http://www.oTranCe.de
 *
 * @package         oTranCe
 * @subpackage      Models
 * @version         SVN: $
 * @author          $Author$
 */

/**
 * User model
 *
 * @package         oTranCe
 * @subpackage      Models
 */
class Application_Model_User extends Msd_Application_Model
{
    /**
     * Name of current user
     *
     * @var
     */
    private $_username;

    /**
     * Id of current user
     *
     * @var int
     */
    private $_userId;

    /**
     * User table
     *
     * @var string
     */
    private $_tableUsers;

    /**
     * Usersettings table
     *
     * @var string
     */
    private $_tableUsersettings;

    /**
     * Userrights table
     *
     * @var string
     */
    private $_tableUserrights;

    /**
     * User language edit rights table
     *
     * @var string
     */
    private $_tableUserLanguages;

    /**
     * Language table
     *
     * @var string
     */
    private $_tableLanguages;

    /**
     * Rights of the current user
     *
     * @var array
     */
    private $_userrights;

    /**
     * Holds validation messages.
     *
     * @var array
     */
    private $_validateMessages = array();

    /**
     * Model initialization method.
     *
     * @return void
     */
    public function init()
    {
        $this->_tableUsersettings  = $this->_tablePrefix . 'usersettings';
        $this->_tableUserrights    = $this->_tablePrefix . 'userrights';
        $this->_tableLanguages     = $this->_tablePrefix . 'languages';
        $this->_tableUserLanguages = $this->_tablePrefix . 'user_languages';
        $this->_tableUsers         = $this->_tablePrefix . 'users';
        $auth                      = Zend_Auth::getInstance()->getIdentity();
        $this->_username           = isset($auth['name']) ? $auth['name'] : '';
        $this->_userId             = isset($auth['id']) ? (int)$auth['id'] : 0;
    }

    /**
     * Get id of user
     *
     * @return int
     */
    public function getUserId()
    {
        return $this->_userId;
    }

    /**
     * Check if we have a logged in user
     *
     * @return bool
     */
    public function isLoggedIn()
    {
        return $this->_userId > 0 ? true : false;
    }

    /**
     * Get list of translators with edit rights for all or given language
     *
     * Return ass. array[lang_id] = user_id
     *
     * @param int $languageId Id of language
     *
     * @return array
     */
    public function getTranslators($languageId = 0)
    {
        $ret = array();
        $this->_dbo->selectDb($this->_database);
        $sql = 'SELECT * FROM `' . $this->_tableUserLanguages . '`';
        if ($languageId > 0) {
            $sql .= ' WHERE `language_id` = ' . intval($languageId);
        }
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC);
        foreach ($res as $val) {
            $ret[$val['language_id']][] = $val['user_id'];
        }

        return $ret;
    }

    /**
     * Get an array containing all active translators as printable list grouped by languageIds.
     *
     * Return array[$languageId] = 'user1, user2, user3, ...';
     *
     * @param bool $implodeList Whether to implode the names per language or return an array
     * @param bool $getAll      Whether all translators with edit rights should be returned.
     *                          If set to false, only those with edit actions are returned.
     *
     * @return array
     */
    public function getTranslatorlist($implodeList = true, $getAll = false)
    {
        $translatorList = $this->getTranslatorData($getAll);
        $ret            = array();
        foreach ($translatorList as $languageId => $translatorData) {
            foreach ($translatorData as $translator) {
                if (empty($ret[$languageId])) {
                    $ret[$languageId] = array();
                }
                $ret[$languageId][$translator['userId']] = $translator['userName'];
                //append number of edit actions in
                if ($getAll === false) {
                    $ret[$languageId][$translator['userId']] .= ' (' . $translator['editActions'] . ')';
                }
            }
        }

        if ($implodeList === true) {
            foreach ($translatorList as $languageId => $translator) {
                $ret[$languageId] = implode(', ', $ret[$languageId]);
            }
        }
        $this->_statistics = $ret;

        return $ret;
    }

    /**
     * Get an array containing all active translators grouped by languageIds.
     *
     * Return array[$languageId] = array( 0 => array('userId'    => x,
     *                                               'username'  => y),
     *                                    1 => array('userId' => z,
     *                                    ....);
     *
     * @param bool $getAll If set to false, only translators with edit actions are returned.
     *
     * @return array
     */
    public function getTranslatorData($getAll = false)
    {
        if ($getAll === true) {
            $sql        = 'SELECT l.`language_id` as `lang_id`, u.`id` as `user_id`, u.`username`'
                . ' FROM `' . $this->_tableUserLanguages . '` l'
                . ' LEFT JOIN `' . $this->_tableUsers . '` u ON u.`id` = l.`user_id`'
                . ' WHERE u.`active` = 1 ORDER BY l.`language_id` ASC, u.`username` ASC';
            $statistics = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC);
        } else {
            $statisticsModel = new Application_Model_Statistics();
            $statistics      = $statisticsModel->getUserstatistics();
        }
        $ret = array();
        foreach ($statistics as $val) {
            if (empty($ret[$val['lang_id']])) {
                $ret[$val['lang_id']] = array();
            }
            $ret[$val['lang_id']][] = array(
                'userId'      => $val['user_id'],
                'userName'    => $val['username'],
                'editActions' => isset($val['editActions']) ? $val['editActions'] : 0
            );
        }

        return $ret;
    }

    /**
     * Get list of users
     *
     * @param string $filter         Filter for user name
     * @param int    $offset         Offset in db
     * @param int    $recordsPerPage Nr of records to fetch
     *
     * @return array
     */
    public function getUsers($filter = '', $offset = 0, $recordsPerPage = 0)
    {
        $ret = array();
        $sql = 'SELECT SQL_CALC_FOUND_ROWS * FROM `' . $this->_database . '`.`' . $this->_tableUsers . '`';
        if ($filter > '') {
            $sql .= ' WHERE `username` LIKE \'%' . $this->_dbo->escape($filter) . '%\'';
        }

        $sql .= ' ORDER BY `username` ASC';
        if ($recordsPerPage != 0) {
            $sql .= ' LIMIT ' . $offset . ',' . $recordsPerPage;
        }

        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        foreach ($res as $val) {
            $ret[$val['id']] = $val;
        }

        return $ret;
    }

    /**
     * Get list of user names (key is the user id)
     *
     * @return array
     */
    public function getUserNames()
    {
        $ret = array();
        $sql = 'SELECT `id`, `username` FROM `' . $this->_database . '`.`' . $this->_tableUsers . '`'
            . ' ORDER BY `username` ASC';
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        foreach ($res as $val) {
            $ret[$val['id']] = $val['username'];
        }

        return $ret;
    }

    /**
     * Get a user by id
     *
     * @param int $userId Id of user
     *
     * @return array
     */
    public function getUserById($userId)
    {
        $userId = (int)$userId;
        $sql    = 'SELECT * FROM `' . $this->_database . '`.`' . $this->_tableUsers . '`'
            . ' WHERE `id`= ' . $userId;
        $res    = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        if (isset($res[0]['id'])) {
            return $res[0];
        }
        $defaults = array(
            'id'          => '0',
            'username'    => '',
            'password'    => '',
            'active'      => '0',
            'realName'    => '',
            'email'       => '',
            'newLanguage' => ''
        );

        return $defaults;
    }

    /**
     * Get a user by email
     *
     * @param string $userEmail Email of user
     *
     * @return array
     */
    public function getUserByEmail($userEmail)
    {
        $sql = 'SELECT * FROM `' . $this->_database . '`.`' . $this->_tableUsers . '`'
            . ' WHERE `email`= \'' . $this->_dbo->escape($userEmail) . '\'';
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);

        return isset($res[0]) ? $res[0] : array();
    }

    /**
     * Get the locale of the interface language setting of a user
     *
     * @param int $userId Id of user
     *
     * @return string The locale of the interface language of the user
     */
    public function getUserLanguageLocale($userId)
    {
        return $this->loadSetting('interfaceLanguage', 'en', false, $userId);
    }

    /**
     * Get user settings for a param and return as numeric array
     *
     * @param string $name       Name of setting to read
     * @param mixed  $default    Default value if no value is found in db
     * @param bool   $forceArray Force returning as array
     * @param int    $userId     Id of user, if not set teh current userId will be used
     *
     * @return mixed
     */
    public function loadSetting($name, $default = '', $forceArray = false, $userId = null)
    {
        if ($forceArray === true) {
            $default = array();
        }
        if ($userId === null) {
            $userId = $this->_userId;
        }
        $sql = 'SELECT `value` FROM `' . $this->_database . '`.`' . $this->_tableUsersettings . '` '
            . 'WHERE `user_id`=\'' . $userId . '\' '
            . 'AND `setting`=\'' . $name . '\' ORDER BY `value` ASC';
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        if (isset($res[0])) {
            if ($forceArray === false && count($res) == 1) {
                return $res[0]['value'];
            }
            $ret = array();
            foreach ($res as $val) {
                $ret[] = $val['value'];
            }

            return $ret;
        } else {
            return $default;
        }
    }

    /**
     * Get user reference languages as array.
     * Returns active languages only.
     *
     * @return array
     */
    public function getRefLanguages()
    {
        $sql = "SELECT us.`value`
            FROM `{$this->_database}`.`{$this->_tableUsersettings}` us
            LEFT JOIN`{$this->_database}`.`{$this->_tableLanguages}` l ON l.`id` = us.`value`
            WHERE us.`user_id` = '{$this->_userId}' AND us.`setting` = 'referenceLanguage' AND l.`active` = 1
            ORDER BY l.`locale` ASC";
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        $ret = array();
        if (is_array($res)) {
            foreach ($res as $value) {
                $ret[] = $value['value'];
            }
        }

        return $ret;
    }

    /**
     * Switch a users reference language status.
     * Returns new status.
     *
     * @param int    $userId     Id of user
     * @param string $languageId Locale of language
     *
     * @return bool
     */
    public function switchReferenceLanguageStatus($userId, $languageId)
    {
        $userId     = (int)$userId;
        $languageId = (string)$languageId;
        $status     = $this->getReferenceLanguageStatus($userId, $languageId);
        if ($status == true) {
            $this->deleteReferenceLanguageSettings($languageId, $userId = 0);
        } else {
            $sql = 'INSERT INTO `' . $this->_database . '`.`' . $this->_tableUsersettings . '` '
                . ' (`user_id`, `setting`, `value`) VALUES ('
                . $userId . ', \'referenceLanguage\', ' . $languageId . ')';
            $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        }
        $status = $this->getReferenceLanguageStatus($userId, $languageId);

        return $status;
    }

    /**
     * Detect if a user has set a langugae as reference language.
     *
     * @param int $userId     Id of user
     * @param int $languageId Id of language
     *
     * @return bool
     */
    public function getReferenceLanguageStatus($userId, $languageId)
    {
        $status     = false;
        $userId     = (int)$userId;
        $languageId = (string)$languageId;
        $sql        = 'SELECT `id` FROM `' . $this->_database . '`.`' . $this->_tableUsersettings . '` '
            . ' WHERE `user_id` = ' . $userId . ' AND `setting` = \'referenceLanguage\''
            . ' AND `value` = \'' . $languageId . '\'';
        $res        = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        if (isset($res[0]['id'])) {
            $status = true;
        }

        return $status;
    }

    /**
     * Delete reference language settings of a language for all or the given user.
     *
     * @param int $languageId Id of language
     * @param int $userId     Id of a user
     *
     * @return bool
     */
    public function deleteReferenceLanguageSettings($languageId, $userId = 0)
    {
        $sql    = 'DELETE FROM `' . $this->_database . '`.`' . $this->_tableUsersettings . '`'
            . ' WHERE `setting`= \'referenceLanguage\''
            . ' AND `value`=' . intval($languageId);
        $userId = (int)$userId;
        if ($userId > 0) {
            $sql .= ' AND `user_id` = ' . $userId;
        }
        $res = $this->_dbo->query($sql, Msd_Db::SIMPLE);

        return (bool)$res;
    }

    /**
     * Delete a users language edit rights of the given language
     *
     * @param int $userId     The id of the user
     * @param int $languageId The id of the language
     *
     * @return boolean
     */
    public function deleteUsersEditLanguageRight($userId, $languageId)
    {
        $userId     = (int)$userId;
        $languageId = (int)$languageId;
        $sql        = 'DELETE FROM `' . $this->_tableUserLanguages . '`'
            . ' WHERE `user_id` = ' . $userId . ' AND `language_id` = ' . $languageId;

        return $this->_dbo->query($sql, Msd_Db::SIMPLE);
    }

    /**
     * Delete a users language edit rights of the given language
     *
     * @param int $userId     The id of the user
     * @param int $languageId The id of the language
     *
     * @return boolean
     */
    public function addUsersEditLanguageRight($userId, $languageId)
    {
        $userId     = (int)$userId;
        $languageId = (int)$languageId;
        $sql        = 'INSERT INTO `' . $this->_tableUserLanguages . '` (`user_id`, `language_id`)'
            . ' VALUES(' . $userId . ', ' . $languageId . ')';

        return $this->_dbo->query($sql, Msd_Db::SIMPLE);
    }

    /**
     * Deletes an user setting.
     *
     * @param string $name Name of setting to delete.
     *
     * @return bool
     */
    public function deleteSetting($name)
    {
        $sqlName = $this->_dbo->escape($name);
        $sql     = "DELETE FROM `{$this->_tableUsersettings}`
            WHERE `user_id` = {$this->_userId} AND `setting` = '$sqlName'";
        $res     = $this->_dbo->query($sql, Msd_Db::SIMPLE);

        return ($res !== null && $res !== false);
    }

    /**
     * Get user language edit rights.
     * Needed to get a sortet list by locale.
     *
     * @param int  $userId                Id of user, if not set use the current user
     * @param bool $skipInactiveLanguages Only return active languages
     *
     * @return array
     */
    public function getUserLanguageRights($userId = 0, $skipInactiveLanguages = true)
    {
        $userId = (int)$userId;
        if ($userId == 0) {
            $userId = $this->_userId;
        }
        $sql = 'SELECT r.`language_id` FROM `' . $this->_database . '`.`' . $this->_tableUserLanguages . '` r'
            . ' JOIN `' . $this->_database . '`.`' . $this->_tableLanguages . '` l ON '
            . ' l.`id` = r.`language_id`'
            . ' WHERE `user_id`=\'' . $userId . '\'';
        if ($skipInactiveLanguages === true) {
            $sql .= ' AND `l`.`active` = 1';
        }
        $sql .= ' ORDER BY `l`.`locale` ASC';
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        $ret = array();
        foreach ($res as $val) {
            $ret[] = $val['language_id'];
        }

        return $ret;
    }

    /**
     * Get global rights of given user (all except edit rights of languages)
     *
     * @param int $userId Id of user
     *
     * @return array
     */
    public function getUserGlobalRights($userId = null)
    {
        if ($userId === null) {
            $userId = $this->_userId;
        }
        $sql = 'SELECT * FROM `' . $this->_database . '`.`' . $this->_tableUserrights . '`'
            . ' WHERE `user_id`=\'' . $userId . '\'';
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        $ret = array();
        foreach ($res as $r) {
            $ret[$r['right']] = $r['value'];
        }
        $ret = array_merge($this->getDefaultRights(), $ret);

        return $ret;
    }

    /**
     * Get array with default rights.
     *
     * Default array shows all menu items (except "Admin") and disallows adding of new language keys.
     *
     * @return array
     */
    public function getDefaultRights()
    {
        $defaultRights = array(
            'editConfig'              => 1,
            'showEntries'             => 1,
            'showConnector'           => 1,
            'showDownloads'           => 1,
            'showBrowseFiles'         => 1,
            'showImport'              => 1,
            'showExport'              => 1,
            'showLog'                 => 1,
            'showStatistics'          => 1,
            'admin'                   => 0,
            /* other rights */
            'addVar'                  => 0,
            'editKey'                 => 0,
            'importEqualVar'          => 0,
            /* admin rights */
            'editProject'             => 0,
            'addUser'                 => 0,
            'editUsers'               => 0,
            'deleteUsers'             => 0,
            'editLanguage'            => 0,
            'addLanguage'             => 0,
            'deleteLanguage'          => 0,
            'editTemplate'            => 0,
            'editImporter'            => 0,
            'addTemplate'             => 0,
            'editVcs'                 => 0,
            'editTranslationServices' => 0,
        );

        return $defaultRights;
    }

    /**
     * Checks if the current user has a right set to value
     *
     * @param string $right The right to check
     * @param int    $value The value the rights must have
     *
     * @return bool
     */
    public function hasRight($right, $value = 1)
    {
        if ($this->_userrights === null) {
            $this->_userrights = $this->getUserRights();
        }
        if (isset($this->_userrights[$right])) {
            if ($this->_userrights[$right] == $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user rights
     *
     * @param int $userId Id of user, if not set use the current user
     *
     * @return array
     */
    public function getUserRights($userId = 0)
    {
        $userId = (int)$userId;
        if ($userId == 0) {
            $userId = $this->_userId;
        }
        $sql    = 'SELECT * FROM `' . $this->_database . '`.`' . $this->_tableUserrights . '`'
            . ' WHERE `user_id`=\'' . $userId . '\'';
        $res    = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);
        $rights = $this->getDefaultRights();
        foreach ($res as $val) {
            $rights[$val['right']] = $val['value'];
        }
        $this->_userrights = $rights;

        return $rights;
    }

    /**
     * Checks if the current user has the edit right for the given languageId
     *
     * @param int $userId     The Id of the user
     * @param int $languageId The Id of the language
     *
     * @return bool
     */
    public function hasLanguageEditRight($userId, $languageId)
    {
        $userId     = (int)$userId;
        $languageId = (int)$languageId;
        $sql        = 'SELECT `user_id` FROM `' . $this->_database . '`.`' . $this->_tableUserLanguages . '`'
            . ' WHERE `user_id`=\'' . $userId . '\' AND `language_id` = ' . $languageId
            . ' LIMIT 1';
        $res        = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);

        return isset($res[0]['user_id']) ? true : false;
    }

    /**
     * Save a user right to database
     *
     * @param int    $userId Id of user
     * @param string $right  Name of right
     * @param int    $value  Value to save
     *
     * @return bool
     */
    public function saveRight($userId, $right, $value = 1)
    {
        $this->deleteRight($userId, $right, $value);
        $value = (int)$value;
        $sql   = 'INSERT INTO `' . $this->_database . '`.`' . $this->_tableUserrights . '`'
            . ' (`user_id`, `right`, `value`) VALUES ('
            . intval($userId) . ', '
            . '\'' . $this->_dbo->escape($right) . '\', '
            . $value . ') ON DUPLICATE KEY UPDATE `value` = ' . (int)$value;
        $res   = $this->_dbo->query($sql, Msd_Db::SIMPLE, false);

        return (bool)$res;
    }

    /**
     * Delete a user right from database.
     *
     * Attention! Removing the right from the database doesn't mean that the user does not have that right.
     * It might be given back through the default right array which is merged with the result of the values
     * taken from the database. If you need to disallow a right, use saveRight(id, right, 0) instead.
     *
     * @param int    $userId Id of user
     * @param string $right  Name of right
     *
     * @return bool
     */
    public function deleteRight($userId, $right)
    {
        // check if user has an entry for this right
        $sql = 'DELETE FROM `' . $this->_database . '`.`' . $this->_tableUserrights . '`'
            . ' WHERE `user_id`=' . intval($userId)
            . ' AND `right`=\'' . $this->_dbo->escape($right) . '\'';
        $res = $this->_dbo->query($sql, Msd_Db::SIMPLE);

        return (bool)$res;
    }

    /**
     * Delete a user and all of his rights and settings from the database
     *
     * @param int $userId Id of user
     *
     * @return bool
     */
    public function deleteUserById($userId)
    {
        $res    = true;
        $userId = (int)$userId;
        //remove user rights
        $sql = 'DELETE FROM `' . $this->_database . '`.`' . $this->_tableUserrights . '`'
            . ' WHERE `user_id`=' . $userId;
        $res &= $this->_dbo->query($sql, Msd_Db::SIMPLE);

        //remove user settings
        $sql = 'DELETE FROM `' . $this->_database . '`.`' . $this->_tableUsersettings . '`'
            . ' WHERE `user_id`=' . $userId;
        $res &= $this->_dbo->query($sql, Msd_Db::SIMPLE);

        //remove user language edit rights
        $sql = 'DELETE FROM `' . $this->_database . '`.`' . $this->_tableUserLanguages . '`'
            . ' WHERE `user_id`=' . $userId;
        $res &= $this->_dbo->query($sql, Msd_Db::SIMPLE);

        //only remove user if other actions have been successfull
        //if something was unsuccessful and we drop the user, you can't retry this
        //because the name won't show up in the list and you you won't have the delete icon
        if ($res == true) {
            $sql = 'DELETE FROM `' . $this->_database . '`.`' . $this->_tableUsers . '`'
                . ' WHERE `id`=' . $userId;
            $res &= $this->_dbo->query($sql, Msd_Db::SIMPLE);
        }

        return (bool)$res;
    }

    /**
     * Delete language edit rights of a language for all users
     *
     * @param int $languageId Id of language
     *
     * @return bool
     */
    public function deleteLanguageRights($languageId)
    {
        $sql = 'DELETE FROM `' . $this->_database . '`.`' . $this->_tableUserLanguages . '`'
            . ' WHERE `language_id`= ' . intval($languageId);
        $res = $this->_dbo->query($sql, Msd_Db::SIMPLE);

        return (bool)$res;
    }

    /**
     * Validates the user account data.
     *
     * @param array        $userData           Data of the user account.
     * @param Msd_Language $translator         Translator for output messages
     * @param bool         $onlyCheckPasswords Check all fields. If set to no only the password is checked.
     *
     * @return bool
     */
    public function validateData($userData, Msd_Language $translator, $onlyCheckPasswords = false)
    {
        $this->clearValidateMessages();
        if (!$onlyCheckPasswords) {
            $notEmptyValidate = new Zend_Validate_NotEmpty();
            if ($userData['id'] == 0) {
                // new user
                if (!$notEmptyValidate->isValid($userData['pass1'])) {
                    $messages                         = $translator->translateZendMessageIds(
                        $notEmptyValidate->getMessages()
                    );
                    $this->_validateMessages['pass1'] = $messages;
                }

                // check if we already have a user with that name
                $existingUser = $this->getUserByName($userData['username']);
                if (!empty($existingUser)) {
                    $message                               = $translator->translate('L_REGISTER_USERNAME_EXISTS');
                    $this->_validateMessages['username'][] = sprintf($message, $userData['username']);
                }
            }

            // Check for invalid chars in alnum fields
            $alnumValidate = new Zend_Filter_Alnum(true);
            $alnumValues   = array('realName', 'newLanguage');
            foreach ($alnumValues as $key) {
                if (empty($userData[$key])) {
                    continue;
                }

                if ($alnumValidate->filter($userData[$key]) !== $userData[$key]) {
                    $this->_validateMessages[$key][] = $translator->translate('L_ERROR_INVALID_CHARS');
                }
            }

            // Check real name is not empty
            if (!$notEmptyValidate->isValid($userData['realName'])) {
                $messages                            = $translator->translateZendMessageIds(
                    $notEmptyValidate->getMessages()
                );
                $this->_validateMessages['realName'] = $messages;
            }

            // Check e-mail is not empty
            if (!$notEmptyValidate->isValid($userData['email'])) {
                $messages                         = $translator->translateZendMessageIds(
                    $notEmptyValidate->getMessages()
                );
                $this->_validateMessages['email'] = $messages;
            }

            // Check user name has 2-50 chars
            $strLenValidate = new Zend_Validate_StringLength(array('min' => 2, 'max' => 50));
            if (!$strLenValidate->isValid($userData['username'])) {
                $messages                            = $translator->translateZendMessageIds(
                    $strLenValidate->getMessages()
                );
                $this->_validateMessages['username'] = array_merge(
                    $this->_validateMessages['username'],
                    $messages
                );
            }

            // Check user name has 2-50 chars
            if (!$strLenValidate->isValid($userData['realName'])) {
                $messages                            = $translator->translateZendMessageIds(
                    $strLenValidate->getMessages()
                );
                $this->_validateMessages['realName'] = array_merge(
                    $this->_validateMessages['realName'],
                    $messages
                );
            }

            // Check provided e-mail is valid
            $emailValidate = new Zend_Validate_EmailAddress();
            if (!$emailValidate->isValid($userData['email'])) {
                $messages                         = $translator->translateZendMessageIds($emailValidate->getMessages());
                $this->_validateMessages['email'] = array_merge(
                    $this->_validateMessages['email'],
                    $messages
                );
            }
        }

        // Check password has 2-50 chars
        $strLenValidate = new Zend_Validate_StringLength(array('min' => 2, 'max' => 50));
        if (isset($userData['pass1']) && !$strLenValidate->isValid($userData['pass1'])) {
            $messages                         = $translator->translateZendMessageIds($strLenValidate->getMessages());
            $this->_validateMessages['pass1'] = array_merge(
                $this->_validateMessages['pass1'],
                $messages
            );
        }

        // Check provided passwords are equal
        if (isset($userData['pass1']) && ($userData['pass1'] > '' || $userData['pass2'] > '')) {
            $identicalValidate = new Zend_Validate_Identical($userData['pass1']);
            if (!$identicalValidate->isValid($userData['pass2'])) {
                $messages                         = $translator->translateZendMessageIds(
                    $identicalValidate->getMessages()
                );
                $this->_validateMessages['pass1'] = array_merge(
                    $this->_validateMessages['pass1'],
                    $messages
                );
            }
        }

        $isValid = true;
        // if any error message is set the validation failed
        if (!empty($this->_validateMessages['username']) || !empty($this->_validateMessages['pass1'])
            || !empty($this->_validateMessages['realName'])
            || !empty($this->_validateMessages['email'])
        ) {
            $isValid = false;
        }

        return $isValid;
    }

    /**
     * Clear the validation messages and make sure indexes exist.
     *
     * @return void
     */
    public function clearValidateMessages()
    {
        $this->_validateMessages = array(
            'username' => array(),
            'pass1'    => array(),
            'realName' => array(),
            'email'    => array(),
        );
    }

    /**
     * Retrieves the validation messages.
     *
     * @return array
     */
    public function getValidateMessages()
    {
        return $this->_validateMessages;
    }

    /**
     * Register the user and save all needed params to database
     *
     * @param array $userData          Ass. array holding user data
     * @param array $languagesMetaData Array holding lang meta data
     *
     * @return int|bool New id of user or false on error
     */
    public function registerUser($userData, $languagesMetaData)
    {
        $newUserId = $this->saveAccount($userData);
        if ($newUserId) {
            if (!empty($userData['lang'])) {
                $this->saveLanguageRights($newUserId, array_keys($userData['lang']));
            }
            $userData['id'] = $newUserId;
            // save interface language setting to user profile
            $interfaceLanguage = $this->_dynamicConfig->getParam('interfaceLanguage', false);
            $this->saveSetting('interfaceLanguage', $interfaceLanguage, $userData['id']);
            $this->addFallBackLanguageAsReferenceLanguage($userData['id']);

            // log register action
            $historyModel = new Application_Model_History();
            $historyModel->logUserRegistered($userData['id']);
        }

        return $newUserId;
    }

    /**
     * Create or update a user account
     *
     * @param array $userData Parameters of account
     *
     * @return bool|int False if there was an error, otherwise return user id
     */
    public function saveAccount($userData)
    {
        if ($userData['id'] != 0) {
            $sql = 'UPDATE `' . $this->_database . '`.`' . $this->_tableUsers . '`'
                . ' SET `username` = \'' . $this->_dbo->escape($userData['username']) . '\'';
            if (isset($userData['realName'])) {
                $sql .= ', `realName` = \'' . $this->_dbo->escape($userData['realName']) . '\'';
            }
            if (isset($userData['email'])) {
                $sql .= ', `email` = \'' . $this->_dbo->escape($userData['email']) . '\'';
            }
            if (isset($userData['active'])) {
                $sql .= ', `active`=' . intval($userData['active']);
            }
            if (isset($userData['pass1']) && $userData['pass1'] > '') {
                $sql .= ', `password`=MD5(\'' . $this->_dbo->escape($userData['pass1']) . '\')';
            }
            $sql .= ' WHERE `id`=' . intval($userData['id']);
        } else {
            $sql = 'INSERT INTO `' . $this->_database . '`.`' . $this->_tableUsers . '`'
                . ' (`username`, `realName`, `email`, `password`, `active`, `newLanguage`) VALUES ('
                . '\'' . $this->_dbo->escape($userData['username']) . '\', '
                . '\'' . $this->_dbo->escape($userData['realName']) . '\', '
                . '\'' . $this->_dbo->escape($userData['email']) . '\', '
                . 'MD5(\'' . $this->_dbo->escape($userData['pass1']) . '\'), '
                . intval($userData['active']) . ',';
            if (isset($userData['newLanguage'])) {
                $sql .= '\'' . $this->_dbo->escape($userData['newLanguage']) . '\'';
            } else {
                $sql .= '\'\'';
            }
            $sql .= ')';
        }

        $res = (bool)$this->_dbo->query($sql, Msd_Db::SIMPLE);
        if ($res !== false) {
            $user = $this->getUserByName($userData['username']);
            $res  = isset($user['id']) ? $user['id'] : false;
        }

        return $res;
    }

    /**
     * Get a user by name
     *
     * @param string $userName Name of user
     *
     * @return array
     */
    public function getUserByName($userName)
    {
        $sql = 'SELECT * FROM `' . $this->_database . '`.`' . $this->_tableUsers . '`'
            . ' WHERE `username`= \'' . $this->_dbo->escape($userName) . '\'';
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC, true);

        return isset($res[0]) ? $res[0] : array();
    }

    /**
     * Save language edit rights of a user to database
     *
     * @param int   $userId      The id of the user
     * @param array $languageIds Array of language ids
     *
     * @return boolean
     */
    public function saveLanguageRights($userId, $languageIds)
    {
        $userId = (int)$userId;
        if ($userId < 1) {
            return false;
        }

        // first remove rights from all other languages
        $sql = 'DELETE FROM `' . $this->_tableUserLanguages . '`'
            . ' WHERE `user_id` = ' . $userId;
        if (!empty($languageIds)) {
            $sql .= ' AND NOT `language_id` IN (' . implode(',', $languageIds) . ')';
        }
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC);

        // save language edit rights
        if (is_array($languageIds) && !empty($languageIds)) {
            $sql = 'REPLACE INTO `' . $this->_tableUserLanguages . '`' . ' (`user_id`,`language_id`) VALUES ';
            foreach ($languageIds as $languageId) {
                $sql .= sprintf('(%s, %s), ', (int)$userId, (int)$languageId);
            }
            $sql = substr($sql, 0, -2);
            $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC);
        }

        return (bool)$res;
    }

    /**
     * Save user settings to db
     *
     * @param string       $name   The setting to save to db
     * @param string|array $values The value to save to db
     * @param int          $userId Id of user, if not set teh current user id is used
     *
     * @return boolean
     */
    public function saveSetting($name, $values, $userId = null)
    {
        if ($userId === null) {
            $userId = $this->_userId;
        }
        // delete old entries
        $sql = 'DELETE FROM `' . $this->_tableUsersettings . '` WHERE '
            . '`user_id`=' . $userId . ' AND `setting`=\'' . $name . '\'';
        $this->_dbo->query($sql, Msd_Db::SIMPLE);

        if (!is_array($values)) {
            $values = array($values);
        }
        if (!isset($values[0]) || $values[0] == '') {
            // nothing to save -> return
            return true;
        }
        $paramPattern = "(%s, '%s', '%s')";
        $insertValues = array();
        foreach ($values as $value) {
            $insertValues[] = sprintf($paramPattern, $userId, $name, $value);
        }
        $params = implode(', ', $insertValues);

        $sql = 'INSERT INTO `' . $this->_tableUsersettings . '`'
            . ' (`user_id`,`setting`,`value`) VALUES ' . $params;
        $res = $this->_dbo->query($sql, Msd_Db::ARRAY_ASSOC);

        return (bool)$res;
    }

    /**
     * Add fallback language as reference language to user configuration
     *
     * @param int $userId Id of user
     *
     * @return void
     */
    public function addFallBackLanguageAsReferenceLanguage($userId)
    {
        $languagesModel     = new Application_Model_Languages();
        $fallbackLanguageId = $languagesModel->getFallbackLanguageId();
        if ($fallbackLanguageId !== false) {
            $this->saveSetting('referenceLanguage', $fallbackLanguageId, $userId);
        }
    }

    /**
     * Sets new md5 password for user
     *
     * @param int    $userId   Id of user
     * @param string $password The password to set
     *
     * @return bool
     */
    public function setPassword($userId, $password)
    {
        $sql = 'UPDATE `' . $this->_database . '`.`' . $this->_tableUsers . '`'
            . ' SET `password` =MD5(\'' . $this->_dbo->escape($password) . '\')';

        $sql .= ' WHERE `id`=' . intval($userId);

        $res = (bool)$this->_dbo->query($sql, Msd_Db::SIMPLE);

        return $res;
    }


    /**
     * Checks if username does exist
     *
     * @param string $userName Is the username
     *
     * @return bool
     */
    public function userNameExists($userName)
    {
        $res = $this->getUserByName($userName);
        if (!empty($res)) {
            return true;
        }

        return false;

    }
}
