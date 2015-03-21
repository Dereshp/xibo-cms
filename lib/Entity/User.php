<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2006-2015 Daniel Garner
 *
 * This file (User.php) is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version. 
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Entity;

use Xibo\Helper\ApplicationState;
use Xibo\Helper\Theme;

class User
{
    public $userId;
    public $userTypeId;
    public $userName;
    public $homePage;

    /**
     * Cached Permissions
     * @var array[Permission]
     */
    private $permissionCache = array();

    /**
     * Login a user
     * @return bool
     * @param string $username
     * @param string $password
     */
    function login($username, $password)
    {
        $results = \Xibo\Storage\PDOConnect::select('SELECT UserID, UserName, UserPassword, UserTypeID, CSPRNG FROM `user` WHERE UserName = :userName', array('userName' => $username));

        if (count($results) <= 0) {
            // User not found
            throw new \Xibo\Exception\AccessDeniedException();
        }

        $userInfo = $results[0];

        // User Data Object to check the password
        $userData = new Userdata();

        // Is SALT empty
        if ($userInfo['CSPRNG'] == 0) {

            // Check the password using a MD5
            if ($userInfo['UserPassword'] != md5($password)) {
                throw new \Xibo\Exception\AccessDeniedException();
            }

            // Now that we are validated, generate a new SALT and set the users password.
            $userData->ChangePassword(Kit::ValidateParam($userInfo['UserID'], _INT), null, $password, $password, true /* Force Change */);
        } else {

            // Check the users password using the random SALTED password
            if ($userData->validate_password($password, $userInfo['UserPassword']) === false) {
                throw new \Xibo\Exception\AccessDeniedException();
            }
        }

        // there is a result so we store the userID in the session variable
        $_SESSION['userid'] = \Kit::ValidateParam($userInfo['UserID'], _INT);
        $this->setIdentity($_SESSION['userid']);

        // Switch Session ID's
        global $session;
        $session->setIsExpired(0);
        $session->RegenerateSessionID(session_id());

        return true;
    }

    /**
     * Logs in a specific user
     * @param int $userId
     * @throws \Xibo\Exception\NotFoundException if the userId cannot be found
     */
    function setIdentity($userId)
    {
        $dbh = \Xibo\Storage\PDOConnect::init();
        $sth = $dbh->prepare('SELECT UserName, usertypeid, homepage FROM `user` WHERE userID = :userId AND Retired = 0');
        $sth->execute(array('userId' => $userId));

        if (!$results = $sth->fetch())
            throw new \Xibo\Exception\NotFoundException();

        $this->userId = $userId;
        $this->userName = \Kit::ValidateParam($results['UserName'], _USERNAME);
        $this->userTypeId = \Kit::ValidateParam($results['usertypeid'], _INT);
        $this->homePage = \Kit::ValidateParam($results['homepage'], _WORD);

        //write out to the db that the logged in user has accessed the page still
        \Xibo\Storage\PDOConnect::update('UPDATE `user` SET lastaccessed = :time, loggedIn = 1 WHERE userId = :userId', array('time' => date("Y-m-d H:i:s"), 'userId' => $userId));
    }

    /**
     * Logout the user associated with this user object
     * @return bool
     */
    function logout()
    {
        global $session;
        $db =& $this->db;

        $userId = \Kit::GetParam('userid', _SESSION, _INT);

        //write out to the db that the logged in user has accessed the page still
        $SQL = sprintf("UPDATE user SET loggedin = 0 WHERE userid = %d", $userId);
        if (!$results = $db->query($SQL)) trigger_error("Can not write last accessed info.", E_USER_ERROR);

        //to log out a user we need only to clear out some session vars
        unset($_SESSION['userid']);
        unset($_SESSION['username']);
        unset($_SESSION['password']);

        $session->setIsExpired(1);

        return true;
    }

    /**
     * Check to see if a user id is in the session information
     * @return bool
     */
    public function hasIdentity()
    {
        global $session;

        $userId = \Kit::GetParam('userid', _SESSION, _INT, 0);

        // Checks for a user ID in the session variable
        if ($userId == 0) {
            return false;
        } else {
            if (!is_numeric($_SESSION['userid'])) {
                unset($_SESSION['userid']);
                return false;
            } else if ($session->isExpired == 1) {
                unset($_SESSION['userid']);
                return false;
            } else {
                $result = \Xibo\Storage\PDOConnect::select('SELECT UserID FROM `user` WHERE loggedin = 1 AND userid = :userId', array('userId' => $userId));

                if (count($result) <= 0) {
                    unset($_SESSION['userid']);
                    return false;
                }

                $this->setIdentity($userId);

                return true;
            }
        }
    }

    function getNameFromID($id)
    {
        $db =& $this->db;

        $SQL = sprintf("SELECT username FROM user WHERE userid = %d", $id);

        if (!$results = $db->query($SQL)) trigger_error("Unknown user id in the system", E_USER_NOTICE);

        // if no user is returned
        if ($db->num_rows($results) == 0) {
            // assume that is the xibo_admin
            return "None";
        }

        $row = $db->get_row($results);

        return $row[0];
    }

    /**
     * Get an array of user groups for the given user id
     * @param <type> $id User ID
     * @param <type> $returnID Whether to return ID's or Names
     * @return <array>
     */
    public function GetUserGroups($id, $returnID = false)
    {
        $db =& $this->db;

        $groupIDs = array();
        $groups = array();

        $SQL = "";
        $SQL .= "SELECT group.group, ";
        $SQL .= "       group.groupID ";
        $SQL .= "FROM   `user` ";
        $SQL .= "       INNER JOIN lkusergroup ";
        $SQL .= "       ON     lkusergroup.UserID = user.UserID ";
        $SQL .= "       INNER JOIN `group` ";
        $SQL .= "       ON     group.groupID       = lkusergroup.GroupID ";
        $SQL .= sprintf("WHERE  `user`.userid                     = %d ", $id);

        if (!$results = $db->query($SQL)) {
            trigger_error($db->error());
            trigger_error("Error looking up user information (group)", E_USER_ERROR);
        }

        if ($db->num_rows($results) == 0) {
            // Every user should have a group?
            // Add one in!
            \Kit::ClassLoader('usergroup');

            $userGroupObject = new UserGroup($db);
            if (!$groupID = $userGroupObject->Add($this->getNameFromID($id), 1)) {
                // Error
                trigger_error(__('User does not have a group and Xibo is unable to add one.'), E_USER_ERROR);
            }

            // Link the two
            $userGroupObject->Link($groupID, $id);

            if ($returnID)
                return array($groupID);

            return array('Unknown');
        }

        // Build an array of the groups to return
        while ($row = $db->get_assoc_row($results)) {
            $groupIDs[] = \Kit::ValidateParam($row['groupID'], _INT);
            $groups[] = \Kit::ValidateParam($row['group'], _STRING);
        }

        if ($returnID)
            return $groupIDs;


        return $groups;
    }

    function getGroupFromID($id, $returnID = false)
    {
        $db =& $this->db;

        $SQL = "";
        $SQL .= "SELECT group.group, ";
        $SQL .= "       group.groupID ";
        $SQL .= "FROM   `user` ";
        $SQL .= "       INNER JOIN lkusergroup ";
        $SQL .= "       ON     lkusergroup.UserID = user.UserID ";
        $SQL .= "       INNER JOIN `group` ";
        $SQL .= "       ON     group.groupID       = lkusergroup.GroupID ";
        $SQL .= sprintf("WHERE  `user`.userid                     = %d ", $id);
        $SQL .= "AND    `group`.IsUserSpecific = 1";

        if (!$results = $db->query($SQL)) {
            trigger_error($db->error());
            trigger_error("Error looking up user information (group)", E_USER_ERROR);
        }

        if ($db->num_rows($results) == 0) {
            // Every user should have a group?
            // Add one in!
            \Kit::ClassLoader('usergroup');

            $userGroupObject = new UserGroup($db);
            if (!$groupID = $userGroupObject->Add($this->getNameFromID($id), 1)) {
                // Error
                trigger_error(__('User does not have a group and we are unable to add one.'), E_USER_ERROR);
            }

            // Link the two
            $userGroupObject->Link($groupID, $id);

            if ($returnID)
                return $groupID;

            return 'Unknown';
        }

        $row = $db->get_row($results);

        if ($returnID) {
            return $row[1];
        }
        return $row[0];
    }

    function getUserTypeFromID($id, $returnID = false)
    {
        $db =& $this->db;

        $SQL = sprintf("SELECT usertype.usertype, usertype.usertypeid FROM user INNER JOIN usertype ON usertype.usertypeid = user.usertypeid WHERE userid = %d", $id);

        if (!$results = $db->query($SQL)) {
            trigger_error("Error looking up user information (usertype)");
            trigger_error($db->error());
        }

        if ($db->num_rows($results) == 0) {
            if ($returnID) {
                return "3";
            }
            return "User";
        }

        $row = $db->get_row($results);

        if ($returnID) {
            return $row[1];
        }
        return $row[0];
    }

    function getEmailFromID($id)
    {
        $db =& $this->db;

        $SQL = sprintf("SELECT email FROM user WHERE userid = %d", $id);

        if (!$results = $db->query($SQL)) trigger_error("Unknown user id in the system", E_USER_NOTICE);

        if ($db->num_rows($results) == 0) {
            $SQL = "SELECT email FROM user WHERE userid = 1";

            if (!$results = $db->query($SQL)) {
                trigger_error("Unknown user id in the system [$id]");
            }
        }

        $row = $db->get_row($results);
        return $row[1];
    }

    /**
     * Gets the homepage for the given userid
     * @param <type> $userId
     * @return <type>
     */
    function GetHomePage($userId)
    {
        $db =& $this->db;

        $SQL = sprintf("SELECT homepage FROM `user` WHERE userid = %d", $userId);

        if (!$homepage = $db->GetSingleValue($SQL, 'homepage', _WORD))
            trigger_error(__('Unknown User'));

        return $homepage;
    }

    /**
     * Authenticates the page given against the user credentials held.
     * TODO: Would like to improve performance here by making these credentials cached
     * @return
     * @param $page string
     */
    public function PageAuth($page)
    {
        $db =& $this->db;
        $userid =& $this->userId;
        $usertype =& $this->userTypeId;

        // Check the page exists
        $dbh = \Xibo\Storage\PDOConnect::init();

        $sth = $dbh->prepare('SELECT pageID FROM `pages` WHERE name = :name');
        $sth->execute(array('name' => $page));

        $pageId = $sth->fetchColumn();

        if ($pageId == '') {
            Debug::LogEntry('audit', 'Blocked assess to unrecognised page: ' . $page . '.', 'index', 'PageAuth');
            throw new Exception(__('Requested page does not exist'));
        }

        // Check the security
        if ($usertype == 1)
            return true;

        // We have access to only the pages assigned to this group
        try {
            $dbh = \Xibo\Storage\PDOConnect::init();

            $SQL = "SELECT pageid ";
            $SQL .= " FROM `lkpagegroup` ";
            $SQL .= "    INNER JOIN `lkusergroup` ";
            $SQL .= "    ON lkpagegroup.groupID = lkusergroup.GroupID ";
            $SQL .= " WHERE lkusergroup.UserID = :userid AND pageid = :pageid";

            $sth = $dbh->prepare($SQL);
            $sth->execute(array(
                'userid' => $userid,
                'pageid' => $pageId
            ));

            $results = $sth->fetchAll();

            return (count($results) > 0);
        } catch (Exception $e) {

            Debug::LogEntry('error', $e->getMessage());

            return false;
        }
    }

    /**
     * Return a Menu for this user
     * TODO: Would like to cache this menu array for future requests
     * @return
     * @param $menu Object
     */
    public function MenuAuth($menu)
    {
        $db =& $this->db;
        $userid =& $this->userId;
        $usertypeid =& $this->userTypeId;

        //Debug::LogEntry('audit', sprintf('Authing the menu for usertypeid [%d]', $usertypeid));

        // Get some information about this menu
        // I.e. get the Menu Items this user has access to
        $SQL = "";
        $SQL .= "SELECT DISTINCT pages.name     , ";
        $SQL .= "         menuitem.Args , ";
        $SQL .= "         menuitem.Text , ";
        $SQL .= "         menuitem.Class, ";
        $SQL .= "         menuitem.Img, ";
        $SQL .= "         menuitem.External ";
        $SQL .= "FROM     menuitem ";
        $SQL .= "         INNER JOIN menu ";
        $SQL .= "         ON       menuitem.MenuID = menu.MenuID ";
        $SQL .= "         INNER JOIN pages ";
        $SQL .= "         ON       pages.pageID = menuitem.PageID ";
        if ($usertypeid != 1) {
            $SQL .= "       INNER JOIN lkmenuitemgroup ";
            $SQL .= "       ON       lkmenuitemgroup.MenuItemID = menuitem.MenuItemID ";
            $SQL .= "       INNER JOIN `group` ";
            $SQL .= "       ON       lkmenuitemgroup.GroupID = group.GroupID ";
            $SQL .= "       INNER JOIN lkusergroup ";
            $SQL .= "       ON     group.groupID       = lkusergroup.GroupID ";
        }
        $SQL .= sprintf("WHERE    menu.Menu              = '%s' ", $db->escape_string($menu));
        if ($usertypeid != 1) {
            $SQL .= sprintf(" AND lkusergroup.UserID = %d", $userid);
        }
        $SQL .= " ORDER BY menuitem.Sequence";


        if (!$result = $db->query($SQL)) {
            trigger_error($db->error());

            return false;
        }

        // No permissions to see any of it
        if ($db->num_rows($result) == 0) {
            return false;
        }

        $theMenu = array();

        // Load the results into a menu array
        while ($row = $db->get_assoc_row($result)) {
            $theMenu[] = $row;
        }

        return $theMenu;
    }

    /**
     * Load permissions for a particular entity
     * @param string $entity
     * @return array[Permission]
     */
    private function loadPermissions($entity)
    {
        // Check our cache to see if we have permissions for this entity cached already
        if (!isset($this->permissionCache[$entity])) {

            // Store the results in the cache (default to empty result)
            $this->permissionCache[$entity] = array();

            // Turn it into a ID keyed array
            foreach (\Xibo\Factory\PermissionFactory::getByUserId($entity, $this->userId) as $permission) {
                /* @var \Xibo\Entity\Permission $permission */
                $this->permissionCache[$entity][$permission->objectId] = $permission;
            }
        }

        return $this->permissionCache[$entity];
    }

    /**
     * Check that this object can be used with the permissions sytem
     * @param object $object
     */
    private function checkObjectCompatibility($object)
    {
        if (!method_exists($object, 'getId') || !method_exists($object, 'getOwnerId'))
            throw new InvalidArgumentException(__('Provided Object not under permission management'));
    }

    /**
     * Get a permission object
     * @param object $object
     * @return \Xibo\Entity\Permission
     */
    public function getPermission($object)
    {
        // Check that this object has the necessary methods
        $this->checkObjectCompatibility($object);

        // Admin users
        if ($this->userTypeId == 1 || $this->userId == $object->getOwnerId()) {
            return \Xibo\Factory\PermissionFactory::getFullPermissions();
        }

        // Get the permissions for that entity
        $permissions = $this->loadPermissions(get_class($object));

        // Check to see if our object is in the list
        if (array_key_exists($object->getId(), $permissions))
            return $permissions[$object->getId()];
        else
            return new \Xibo\Entity\Permission();
    }

    /**
     * Check the given object is viewable
     * @param object $object
     * @return bool
     */
    public function checkViewable($object)
    {
        // Check that this object has the necessary methods
        $this->checkObjectCompatibility($object);

        // Admin users
        if ($this->userTypeId == 1 || $this->userId == $object->getOwnerId())
            return true;

        // Get the permissions for that entity
        $permissions = $this->loadPermissions(get_class($object));

        // Check to see if our object is in the list
        if (array_key_exists($object->getId(), $permissions))
            return ($permissions[$object->getId()]->view == 1);
        else
            return false;
    }

    /**
     * Check the given object is editable
     * @param object $object
     * @return bool
     */
    public function checkEditable($object)
    {
        // Check that this object has the necessary methods
        $this->checkObjectCompatibility($object);

        // Admin users
        if ($this->userTypeId == 1 || $this->userId == $object->getOwnerId())
            return true;

        // Get the permissions for that entity
        $permissions = $this->loadPermissions(get_class($object));

        // Check to see if our object is in the list
        if (array_key_exists($object->getId(), $permissions))
            return ($permissions[$object->getId()]->edit == 1);
        else
            return false;
    }

    /**
     * Check the given object is delete-able
     * @param object $object
     * @return bool
     */
    public function checkDeleteable($object)
    {
        // Check that this object has the necessary methods
        $this->checkObjectCompatibility($object);

        // Admin users
        if ($this->userTypeId == 1 || $this->userId == $object->getOwnerId())
            return true;

        // Get the permissions for that entity
        $permissions = $this->loadPermissions(get_class($object));

        // Check to see if our object is in the list
        if (array_key_exists($object->getId(), $permissions))
            return ($permissions[$object->getId()]->delete == 1);
        else
            return false;
    }

    /**
     * Check the given objects permissions are modify-able
     * @param object $object
     * @return bool
     */
    public function checkPermissionsModifyable($object)
    {
        // Check that this object has the necessary methods
        $this->checkObjectCompatibility($object);

        // Admin users
        if ($this->userTypeId == 1 || $this->userId == $object->getOwnerId())
            return true;
        else
            return false;
    }

    /**
     * Returns the usertypeid for this user object.
     * @return int
     */
    public function getUserTypeId()
    {
        return $this->userTypeId;
    }

    /**
     * Authenticates a user against a fileId
     * @param int $fileId
     * @return bool true on granted
     * @throws \Xibo\Exception\NotFoundException
     */
    public function FileAuth($fileId)
    {
        $results = \Xibo\Storage\PDOConnect::select('SELECT UserID FROM file WHERE FileID = :fileId', array('fileId' => $fileId));

        if (count($results) <= 0)
            throw new \Xibo\Exception\NotFoundException('File not found');

        $userId = \Kit::ValidateParam($results[0]['UserID'], _INT);

        return ($userId == $this->userId);
    }

    /**
     * Returns an array of Media the current user has access to
     * @param array $sort_order
     * @param array $filter_by
     * @return array[Media]
     */
    public function MediaList($sort_order = array('name'), $filter_by = array())
    {
        // Get the Layouts
        $media = \Xibo\Factory\MediaFactory::query($sort_order, $filter_by);

        if ($this->userTypeId == 1)
            return $media;

        foreach ($media as $key => $mediaItem) {
            /* @var \Xibo\Entity\Media $mediaItem */

            // Check to see if we are the owner
            if ($mediaItem->ownerId == $this->userId)
                continue;

            // Check we are viewable
            if (!$this->checkViewable($mediaItem))
                unset($media[$key]);
        }

        return $media;
    }

    /**
     * List of Layouts this user can see
     * @param array $sort_order
     * @param array $filter_by
     * @return array[Layout]
     * @throws \Xibo\Exception\NotFoundException
     */
    public function LayoutList($sort_order = array('layout'), $filter_by = array())
    {
        // Get the Layouts
        $layouts = \Xibo\Factory\LayoutFactory::query($sort_order, $filter_by);

        if ($this->userTypeId == 1)
            return $layouts;

        foreach ($layouts as $key => $layout) {
            /* @var \Xibo\Entity\Layout $layout */

            // Check to see if we are the owner
            if ($layout->ownerId == $this->userId)
                continue;

            // Check we are viewable
            if (!$this->checkViewable($layout))
                unset($layouts[$key]);
        }

        return $layouts;
    }

    /**
     * A List of Templates
     * @param array $sort_order
     * @param array $filter_by
     * @return array[Layout]
     */
    public function TemplateList($sort_order = array('layout'), $filter_by = array())
    {
        $filter_by['excludeTemplates'] = 0;
        $filter_by['tags'] = 'template';

        return $this->LayoutList($sort_order, $filter_by);
    }

    /**
     * A list of Resolutions
     * @param array $sort_order
     * @param array $filter_by
     * @return array[Resolution]
     */
    public function ResolutionList($sort_order = array('resolution'), $filter_by = array())
    {
        // Get the Layouts
        $resolutions = \Xibo\Factory\ResolutionFactory::query($sort_order, $filter_by);

        if ($this->userTypeId == 1)
            return $resolutions;

        foreach ($resolutions as $key => $resolution) {
            /* @var \Xibo\Entity\Resolution $resolution */

            // Check to see if we are the owner
            if ($resolution->getOwnerId() == $this->userId)
                continue;

            // Check we are viewable
            if (!$this->checkViewable($resolution))
                unset($resolutions[$key]);
        }

        return $resolutions;
    }

    /**
     * Authorises a user against a dataSetId
     * @param <type> $dataSetId
     * @return <type>
     */
    public function DataSetAuth($dataSetId, $fullObject = false)
    {
        $auth = new PermissionManager($this);

        $SQL = '';
        $SQL .= 'SELECT UserID ';
        $SQL .= '  FROM dataset ';
        $SQL .= ' WHERE dataset.DataSetID = %d ';

        if (!$ownerId = $this->db->GetSingleValue(sprintf($SQL, $dataSetId), 'UserID', _INT))
            return $auth;

        // If we are the owner, or a super admin then give full permissions
        if ($this->userTypeId == 1 || $ownerId == $this->userId) {
            $auth->FullAccess();
            return $auth;
        }

        // Permissions for groups the user is assigned to, and Everyone
        $SQL = '';
        $SQL .= 'SELECT UserID, MAX(IFNULL(View, 0)) AS View, MAX(IFNULL(Edit, 0)) AS Edit, MAX(IFNULL(Del, 0)) AS Del ';
        $SQL .= '  FROM dataset ';
        $SQL .= '   INNER JOIN lkdatasetgroup ';
        $SQL .= '   ON lkdatasetgroup.DataSetID = dataset.DataSetID ';
        $SQL .= '   INNER JOIN `group` ';
        $SQL .= '   ON `group`.GroupID = lkdatasetgroup.GroupID ';
        $SQL .= ' WHERE dataset.DataSetID = %d ';
        $SQL .= '   AND (`group`.IsEveryone = 1 OR `group`.GroupID IN (%s)) ';
        $SQL .= 'GROUP BY dataset.UserID ';

        $SQL = sprintf($SQL, $dataSetId, implode(',', $this->GetUserGroups($this->userId, true)));
        //Debug::LogEntry('audit', $SQL);

        if (!$row = $this->db->GetSingleRow($SQL))
            return $auth;

        // There are permissions to evaluate
        $auth->Evaluate($row['UserID'], $row['View'], $row['Edit'], $row['Del']);

        if ($fullObject)
            return $auth;

        return $auth->edit;
    }

    /**
     * Returns an array of layouts that this user has access to
     */
    public function DataSetList()
    {
        $SQL = "";
        $SQL .= "SELECT DataSetID, ";
        $SQL .= "       DataSet, ";
        $SQL .= "       Description, ";
        $SQL .= "       UserID ";
        $SQL .= "  FROM dataset ";
        $SQL .= " ORDER BY DataSet ";

        //Debug::LogEntry('audit', sprintf('Retreiving list of layouts for %s with SQL: %s', $this->userName, $SQL));

        if (!$result = $this->db->query($SQL)) {
            trigger_error($this->db->error());
            return false;
        }

        $dataSets = array();

        while ($row = $this->db->get_assoc_row($result)) {
            $dataSetItem = array();

            // Validate each param and add it to the array.
            $dataSetItem['datasetid'] = \Kit::ValidateParam($row['DataSetID'], _INT);
            $dataSetItem['dataset'] = \Kit::ValidateParam($row['DataSet'], _STRING);
            $dataSetItem['description'] = \Kit::ValidateParam($row['Description'], _STRING);
            $dataSetItem['ownerid'] = \Kit::ValidateParam($row['UserID'], _INT);

            $auth = $this->DataSetAuth($dataSetItem['datasetid'], true);

            if ($auth->view) {
                $dataSetItem['view'] = (int)$auth->view;
                $dataSetItem['edit'] = (int)$auth->edit;
                $dataSetItem['del'] = (int)$auth->del;
                $dataSetItem['modifyPermissions'] = (int)$auth->modifyPermissions;

                $dataSets[] = $dataSetItem;
            }
        }

        return $dataSets;
    }

    /**
     * Authorises a user against a DisplayGroupId
     * @param <int> $displayGroupId
     * @return <type>
     */
    public function DisplayGroupAuth($displayGroupId, $fullObject = false)
    {
        $auth = new PermissionManager($this);
        $noOwnerId = 0;

        // If we are the owner, or a super admin then give full permissions
        if ($this->userTypeId == 1) {
            $auth->FullAccess();

            if ($fullObject)
                return $auth;

            return true;
        }

        // Permissions for groups the user is assigned to, and Everyone
        $SQL = '';
        $SQL .= 'SELECT MAX(IFNULL(View, 0)) AS View, MAX(IFNULL(Edit, 0)) AS Edit, MAX(IFNULL(Del, 0)) AS Del ';
        $SQL .= '  FROM displaygroup ';
        $SQL .= '   INNER JOIN lkdisplaygroupgroup ';
        $SQL .= '   ON lkdisplaygroupgroup.DisplayGroupID = displaygroup.DisplayGroupID ';
        $SQL .= '   INNER JOIN `group` ';
        $SQL .= '   ON `group`.GroupID = lkdisplaygroupgroup.GroupID ';
        $SQL .= ' WHERE displaygroup.DisplayGroupID = %d ';
        $SQL .= '   AND (`group`.IsEveryone = 1 OR `group`.GroupID IN (%s)) ';

        $SQL = sprintf($SQL, $displayGroupId, implode(',', $this->GetUserGroups($this->userId, true)));
        //Debug::LogEntry('audit', $SQL);

        if (!$row = $this->db->GetSingleRow($SQL))
            return $auth;

        // There are permissions to evaluate
        $auth->Evaluate($noOwnerId, $row['View'], $row['Edit'], $row['Del']);

        if ($fullObject)
            return $auth;

        return $auth->edit;
    }

    /**
     * Authenticates the current user and returns an array of display groups this user is authenticated on
     * @return
     */
    public function DisplayGroupList($isDisplaySpecific = 0, $name = '')
    {
        $db =& $this->db;
        $userid =& $this->userId;

        $SQL = "SELECT displaygroup.DisplayGroupID, displaygroup.DisplayGroup, displaygroup.IsDisplaySpecific, displaygroup.Description ";
        if ($isDisplaySpecific == 1)
            $SQL .= " , lkdisplaydg.DisplayID ";

        $SQL .= "  FROM displaygroup ";

        // If we are only interested in displays, then return the display
        if ($isDisplaySpecific == 1) {
            $SQL .= "   INNER JOIN lkdisplaydg ";
            $SQL .= "   ON lkdisplaydg.DisplayGroupID = displaygroup.DisplayGroupID ";
        }

        $SQL .= " WHERE 1 = 1 ";

        if ($name != '') {
            // convert into a space delimited array
            $names = explode(' ', $name);

            foreach ($names as $searchName) {
                // Not like, or like?
                if (substr($searchName, 0, 1) == '-')
                    $SQL .= " AND  (displaygroup.DisplayGroup NOT LIKE '%" . sprintf('%s', ltrim($db->escape_string($searchName), '-')) . "%') ";
                else
                    $SQL .= " AND  (displaygroup.DisplayGroup LIKE '%" . sprintf('%s', $db->escape_string($searchName)) . "%') ";
            }
        }

        if ($isDisplaySpecific != -1)
            $SQL .= sprintf(" AND displaygroup.IsDisplaySpecific = %d ", $isDisplaySpecific);

        $SQL .= " ORDER BY displaygroup.DisplayGroup ";

        Debug::LogEntry('audit', sprintf('Retreiving list of displaygroups for %s with SQL: %s', $this->userName, $SQL));

        if (!$result = $this->db->query($SQL)) {
            trigger_error($this->db->error());
            return false;
        }

        $displayGroups = array();

        while ($row = $this->db->get_assoc_row($result)) {
            $displayGroupItem = array();

            // Validate each param and add it to the array.
            $displayGroupItem['displaygroupid'] = \Kit::ValidateParam($row['DisplayGroupID'], _INT);
            $displayGroupItem['displaygroup'] = \Kit::ValidateParam($row['DisplayGroup'], _STRING);
            $displayGroupItem['description'] = \Kit::ValidateParam($row['Description'], _STRING);
            $displayGroupItem['isdisplayspecific'] = \Kit::ValidateParam($row['IsDisplaySpecific'], _STRING);
            $displayGroupItem['displayid'] = (($isDisplaySpecific == 1) ? \Kit::ValidateParam($row['DisplayID'], _INT) : 0);

            $auth = $this->DisplayGroupAuth($displayGroupItem['displaygroupid'], true);

            if ($auth->view) {
                $displayGroupItem['view'] = (int)$auth->view;
                $displayGroupItem['edit'] = (int)$auth->edit;
                $displayGroupItem['del'] = (int)$auth->del;
                $displayGroupItem['modifypermissions'] = (int)$auth->modifyPermissions;

                $displayGroups[] = $displayGroupItem;
            }
        }

        return $displayGroups;
    }

    /**
     * List of Displays this user has access to view
     */
    public function DisplayList($sort_order = array('displayid'), $filter_by = array(), $auth_level = 'view')
    {

        $SQL = 'SELECT display.displayid, ';
        $SQL .= '    display.display, ';
        $SQL .= '    displaygroup.description, ';
        $SQL .= '    layout.layout, ';
        $SQL .= '    display.loggedin, ';
        $SQL .= '    IFNULL(display.lastaccessed, 0) AS lastaccessed, ';
        $SQL .= '    display.inc_schedule, ';
        $SQL .= '    display.licensed, ';
        $SQL .= '    display.email_alert, ';
        $SQL .= '    displaygroup.DisplayGroupID, ';
        $SQL .= '    display.ClientAddress, ';
        $SQL .= '    display.MediaInventoryStatus, ';
        $SQL .= '    display.MacAddress, ';
        $SQL .= '    display.client_type, ';
        $SQL .= '    display.client_version, ';
        $SQL .= '    display.client_code, ';
        $SQL .= '    display.screenShotRequested, ';
        $SQL .= '    display.storageAvailableSpace, ';
        $SQL .= '    display.storageTotalSpace, ';
        $SQL .= '    currentLayout.layout AS currentLayout, ';
        $SQL .= '    currentLayout.layoutId AS currentLayoutId ';
        $SQL .= '  FROM display ';
        $SQL .= '    INNER JOIN lkdisplaydg ON lkdisplaydg.DisplayID = display.DisplayID ';
        $SQL .= '    INNER JOIN displaygroup ON displaygroup.DisplayGroupID = lkdisplaydg.DisplayGroupID ';
        $SQL .= '    LEFT OUTER JOIN layout ON layout.layoutid = display.defaultlayoutid ';
        $SQL .= '    LEFT OUTER JOIN layout currentLayout ON currentLayout.layoutId = display.currentLayoutId';

        if (\Kit::GetParam('displaygroupid', $filter_by, _INT) != 0) {
            // Restrict to a specific display group
            $SQL .= sprintf(' WHERE displaygroup.displaygroupid = %d ', \Kit::GetParam('displaygroupid', $filter_by, _INT));
        } else {
            // Restrict to display specific groups
            $SQL .= ' WHERE displaygroup.IsDisplaySpecific = 1 ';
        }

        // Filter by Display ID?
        if (\Kit::GetParam('displayid', $filter_by, _INT) != 0) {
            $SQL .= sprintf(' AND display.displayid = %d ', \Kit::GetParam('displayid', $filter_by, _INT));
        }

        // Filter by Display Name?
        if (\Kit::GetParam('display', $filter_by, _STRING) != '') {
            // convert into a space delimited array
            $names = explode(' ', \Kit::GetParam('display', $filter_by, _STRING));

            foreach ($names as $searchName) {
                // Not like, or like?
                if (substr($searchName, 0, 1) == '-')
                    $SQL .= " AND  (display.display NOT LIKE '%" . sprintf('%s', ltrim($this->db->escape_string($searchName), '-')) . "%') ";
                else
                    $SQL .= " AND  (display.display LIKE '%" . sprintf('%s', $this->db->escape_string($searchName)) . "%') ";
            }
        }

        if (\Kit::GetParam('macAddress', $filter_by, _STRING) != '') {
            $SQL .= sprintf(' AND display.macaddress LIKE \'%s\' ', '%' . $this->db->escape_string(Kit::GetParam('macAddress', $filter_by, _STRING)) . '%');
        }

        // Exclude a group?
        if (\Kit::GetParam('exclude_displaygroupid', $filter_by, _INT) != 0) {
            $SQL .= " AND display.DisplayID NOT IN ";
            $SQL .= "       (SELECT display.DisplayID ";
            $SQL .= "       FROM    display ";
            $SQL .= "               INNER JOIN lkdisplaydg ";
            $SQL .= "               ON      lkdisplaydg.DisplayID = display.DisplayID ";
            $SQL .= sprintf("   WHERE  lkdisplaydg.DisplayGroupID   = %d ", \Kit::GetParam('exclude_displaygroupid', $filter_by, _INT));
            $SQL .= "       )";
        }

        // Sorting?
        if (is_array($sort_order))
            $SQL .= 'ORDER BY ' . implode(',', $sort_order);

        if (!$result = $this->db->query($SQL)) {
            trigger_error($this->db->error());
            return false;
        }

        $displays = array();

        while ($row = $this->db->get_assoc_row($result)) {
            $displayItem = array();

            // Validate each param and add it to the array.
            $displayItem['displayid'] = \Kit::ValidateParam($row['displayid'], _INT);
            $displayItem['display'] = \Kit::ValidateParam($row['display'], _STRING);
            $displayItem['description'] = \Kit::ValidateParam($row['description'], _STRING);
            $displayItem['layout'] = \Kit::ValidateParam($row['layout'], _STRING);
            $displayItem['loggedin'] = \Kit::ValidateParam($row['loggedin'], _INT);
            $displayItem['lastaccessed'] = \Kit::ValidateParam($row['lastaccessed'], _STRING);
            $displayItem['inc_schedule'] = \Kit::ValidateParam($row['inc_schedule'], _INT);
            $displayItem['licensed'] = \Kit::ValidateParam($row['licensed'], _INT);
            $displayItem['email_alert'] = \Kit::ValidateParam($row['email_alert'], _INT);
            $displayItem['displaygroupid'] = \Kit::ValidateParam($row['DisplayGroupID'], _INT);
            $displayItem['clientaddress'] = \Kit::ValidateParam($row['ClientAddress'], _STRING);
            $displayItem['mediainventorystatus'] = \Kit::ValidateParam($row['MediaInventoryStatus'], _INT);
            $displayItem['macaddress'] = \Kit::ValidateParam($row['MacAddress'], _STRING);
            $displayItem['client_type'] = \Kit::ValidateParam($row['client_type'], _STRING);
            $displayItem['client_version'] = \Kit::ValidateParam($row['client_version'], _STRING);
            $displayItem['client_code'] = \Kit::ValidateParam($row['client_code'], _STRING);
            $displayItem['screenShotRequested'] = \Kit::ValidateParam($row['screenShotRequested'], _INT);
            $displayItem['storageAvailableSpace'] = \Kit::ValidateParam($row['storageAvailableSpace'], _INT);
            $displayItem['storageTotalSpace'] = \Kit::ValidateParam($row['storageTotalSpace'], _INT);
            $displayItem['currentLayoutId'] = \Kit::ValidateParam($row['currentLayoutId'], _INT);
            $displayItem['currentLayout'] = \Kit::ValidateParam($row['currentLayout'], _STRING);

            $auth = $this->DisplayGroupAuth($displayItem['displaygroupid'], true);

            if ($auth->view) {
                // If auth level = edit and we don't have edit, then leave them off
                if ($auth_level == 'edit' && !$auth->edit)
                    continue;

                $displayItem['view'] = (int)$auth->view;
                $displayItem['edit'] = (int)$auth->edit;
                $displayItem['del'] = (int)$auth->del;
                $displayItem['modifypermissions'] = (int)$auth->modifyPermissions;

                $displays[] = $displayItem;
            }
        }

        return $displays;

    }

    /**
     * Campaigns viewable by the user
     * @param array $sort_order
     * @param array $filter_by
     * @return array[Campaign]
     */
    public function CampaignList($sort_order = null, $filter_by = null)
    {
        // Get the Layouts
        $campaigns = \Xibo\Factory\CampaignFactory::query($sort_order, $filter_by);

        if ($this->userTypeId == 1)
            return $campaigns;

        foreach ($campaigns as $key => $campaign) {
            /* @var \Xibo\Entity\Campaign $campaign */

            // Check to see if we are the owner
            if ($campaign->ownerId == $this->userId)
                continue;

            // Check we are viewable
            if (!$this->checkViewable($campaign))
                unset($campaigns[$key]);
        }

        return $campaigns;
    }

    /**
     * Get a list of transitions
     * @param string $type in/out
     * @param string $code transition code
     * @return boolean
     */
    public function TransitionAuth($type = '', $code = '')
    {
        // Return a list of in/out transitions (or both)
        $SQL = 'SELECT TransitionID, ';
        $SQL .= '   Transition, ';
        $SQL .= '   Code, ';
        $SQL .= '   HasDuration, ';
        $SQL .= '   HasDirection, ';
        $SQL .= '   AvailableAsIn, ';
        $SQL .= '   AvailableAsOut ';
        $SQL .= '  FROM `transition` ';
        $SQL .= ' WHERE 1 = 1 ';

        if ($type != '') {
            // Filter on type
            if ($type == 'in')
                $SQL .= '  AND AvailableAsIn = 1 ';

            if ($type == 'out')
                $SQL .= '  AND AvailableAsOut = 1 ';
        }

        if ($code != '') {
            // Filter on code
            $SQL .= sprintf("AND Code = '%s' ", $this->db->escape_string($code));
        }

        $SQL .= ' ORDER BY Transition ';

        $rows = $this->db->GetArray($SQL);

        if (!is_array($rows)) {
            trigger_error($this->db->error());
            return false;
        }

        $transitions = array();

        foreach ($rows as $transition) {
            $transitionItem = array();

            $transitionItem['transitionid'] = \Kit::ValidateParam($transition['TransitionID'], _INT);
            $transitionItem['transition'] = \Kit::ValidateParam($transition['Transition'], _STRING);
            $transitionItem['code'] = \Kit::ValidateParam($transition['Code'], _WORD);
            $transitionItem['hasduration'] = \Kit::ValidateParam($transition['HasDuration'], _INT);
            $transitionItem['hasdirection'] = \Kit::ValidateParam($transition['HasDirection'], _INT);
            $transitionItem['enabledforin'] = \Kit::ValidateParam($transition['AvailableAsIn'], _INT);
            $transitionItem['enabledforout'] = \Kit::ValidateParam($transition['AvailableAsOut'], _INT);
            $transitionItem['class'] = (($transitionItem['hasduration'] == 1) ? 'hasDuration' : '') . ' ' . (($transitionItem['hasdirection'] == 1) ? 'hasDirection' : '');

            $transitions[] = $transitionItem;
        }

        return $transitions;
    }

    /**
     * List of Displays this user has access to view
     */
    public function DisplayProfileList($sort_order = array('name'), $filter_by = array())
    {

        try {
            $dbh = \Xibo\Storage\PDOConnect::init();

            $params = array();
            $SQL = 'SELECT displayprofileid, name, type, config, isdefault, userid FROM displayprofile ';

            $type = \Kit::GetParam('type', $filter_by, _WORD);
            if (!empty($type)) {
                $SQL .= ' WHERE type = :type ';
                $params['type'] = $type;
            }

            // Sorting?
            if (is_array($sort_order))
                $SQL .= 'ORDER BY ' . implode(',', $sort_order);

            $sth = $dbh->prepare($SQL);
            $sth->execute($params);

            $profiles = array();

            while ($row = $sth->fetch()) {
                $displayItem = array();

                // Validate each param and add it to the array.
                $displayItem['displayprofileid'] = \Kit::ValidateParam($row['displayprofileid'], _INT);
                $displayItem['name'] = \Kit::ValidateParam($row['name'], _STRING);
                $displayItem['type'] = \Kit::ValidateParam($row['type'], _STRING);
                $displayItem['config'] = \Kit::ValidateParam($row['config'], _STRING);
                $displayItem['isdefault'] = \Kit::ValidateParam($row['isdefault'], _INT);
                $displayItem['userid'] = \Kit::ValidateParam($row['userid'], _INT);

                $auth = new PermissionManager($this);

                // If we are the owner, or a super admin then give full permissions
                if ($this->userTypeId != 1 && $this->userId != $displayItem['userid'])
                    continue;

                $displayItem['view'] = 1;
                $displayItem['edit'] = 1;
                $displayItem['del'] = 1;
                $displayItem['modifypermissions'] = 1;

                $profiles[] = $displayItem;
            }

            return $profiles;
        } catch (Exception $e) {

            Debug::LogEntry('error', $e->getMessage(), get_class(), __FUNCTION__);

            return false;
        }
    }

    public function userList($sortOrder = array('username'), $filterBy = array())
    {
        // Normal users can only see themselves
        if ($this->userTypeId == 3) {
            $filterBy['userId'] = $this->userId;
        } // Group admins can only see users from their groups.
        else if ($this->userTypeId == 2) {
            $groups = $this->GetUserGroups($this->userId, true);
            $filterBy['groupIds'] = (isset($filterBy['groupIds'])) ? array_merge($filterBy['groupIds'], $groups) : $groups;
        }

        try {
            $user = Userdata::entries($sortOrder, $filterBy);
            $parsedUser = array();

            foreach ($user as $row) {
                $userItem = array();

                // Validate each param and add it to the array.
                $userItem['userid'] = $row->userId;
                $userItem['username'] = $row->userName;
                $userItem['usertypeid'] = $row->userTypeId;
                $userItem['homepage'] = $row->homePage;
                $userItem['email'] = $row->email;
                $userItem['newuserwizard'] = $row->newUserWizard;
                $userItem['lastaccessed'] = $row->lastAccessed;
                $userItem['loggedin'] = $row->loggedIn;
                $userItem['retired'] = $row->retired;
                $userItem['object'] = $row;

                // Add to the collection
                $parsedUser[] = $userItem;
            }

            return $parsedUser;
        } catch (Exception $e) {
            Debug::LogEntry('error', $e->getMessage(), get_class(), __FUNCTION__);
            return false;
        }
    }

    public function GetPref($key, $default = NULL)
    {
        $storedValue = Session::Get($key);

        return ($storedValue == NULL) ? $default : $storedValue;
    }

    public function SetPref($key, $value)
    {
        Session::Set($key, $value);
    }
}