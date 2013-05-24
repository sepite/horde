<?php
/**
 * Horde_ActiveSync_Connector_Importer::
 *
 * Portions of this class were ported from the Z-Push project:
 *   File      :   wbxml.php
 *   Project   :   Z-Push
 *   Descr     :   WBXML mapping file
 *
 *   Created   :   01.10.2007
 *
 *   � Zarafa Deutschland GmbH, www.zarafaserver.de
 *   This file is distributed under GPL-2.0.
 *   Consult COPYING file for details
 *
 * @license   http://www.horde.org/licenses/gpl GPLv2
 *            NOTE: According to sec. 8 of the GENERAL PUBLIC LICENSE (GPL),
 *            Version 2, the distribution of the Horde_ActiveSync module in or
 *            to the United States of America is excluded from the scope of this
 *            license.
 * @copyright 2011-2013 Horde LLC (http://www.horde.org)
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @package   ActiveSync
 */
/**
 * Horde_ActiveSync_Connector_Imports:: Receives Wbxml from device.
 *
 * @license   http://www.horde.org/licenses/gpl GPLv2
 *            NOTE: According to sec. 8 of the GENERAL PUBLIC LICENSE (GPL),
 *            Version 2, the distribution of the Horde_ActiveSync module in or
 *            to the United States of America is excluded from the scope of this
 *            license.
 * @copyright 2011-2013 Horde LLC (http://www.horde.org)
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @package   ActiveSync
 */
class Horde_ActiveSync_Connector_Importer
{
    /**
     * State machine
     *
     * @var Horde_ActiveSync_State_Base
     */
    protected $_state;

    /**
     * The server object.
     *
     * @var Horde_ActiveSync
     */
    protected $_as;

    /**
     * Conflict resolution flags
     *
     * @var integer
     */
    protected $_flags;

    /**
     * The backend specific folder id
     *
     * @var string
     */
    protected $_folderId;

    /**
     * Logger
     *
     * @var Horde_Log_Logger
     */
    protected $_logger;

    /**
     * Const'r
     *
     * @param Horde_ActiveSync $as  The server object.
     */
    public function __construct(Horde_ActiveSync $as)
    {
        $this->_as = $as;
    }

    /**
     * Initialize the exporter for this collection
     *
     * @param Horde_ActiveSync_State_Base $state  The state machine.
     * @param string $folderId                    The collection's backend
     *                                            serverid (not the EAS id).
     *                                            @todo H6
     * @param integer $flags                      Conflict resolution flags.
     */
    public function init(Horde_ActiveSync_State_Base $state, $folderId = null, $flags = 0)
    {
        $this->_state = $state;
        $this->_flags = $flags;
        $this->_folderId = $folderId;
    }

    /**
     * Setter for a logger instance
     *
     * @param Horde_Log_Logger $logger  The logger
     */
    public function setLogger($logger)
    {
        $this->_logger = $logger;
    }

    /**
     * Import a message change from the wbxml stream
     *
     * @param string|boolean $id                       A server message id or
     *                                                 false if a new message.
     * @param Horde_ActiveSync_Message_Base $message   A message object
     * @param Horde_ActiveSync_Device $device          A device descriptor
     * @param integer $clientid                        Client id sent from PIM
     *                                                 on message addition.
     *
     * @return string|boolean The server message id or false
     */
    public function importMessageChange(
        $id, Horde_ActiveSync_Message_Base $message,
        Horde_ActiveSync_Device $device, $clientid)
    {
        if ($this->_folderId == Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return false;
        }

        // Changing an existing object
        if ($id) {
            $conflict = $this->_isConflict(
                Horde_ActiveSync::CHANGE_TYPE_CHANGE,
                $this->_folderId,
                $id);
            if ($conflict && $this->_flags == Horde_ActiveSync::CONFLICT_OVERWRITE_PIM) {
                $this->_logger->notice(sprintf(
                    '[%s] Conflict when updating %s.',
                    getmypid(), $id)
                );
                return $id;
            }
        } else {
            if ($uid = $this->_state->isDuplicatePIMAddition($clientid)) {
                // Already saw this addition, but PIM never received UID
                $this->_logger->notice(sprintf(
                    '[%s] Duplicate addition for %s',
                    getmypid(), $uid)
                );
                return $uid;
            }
        }

        // Tell the backend about the change
        if (!$stat = $this->_as->driver->changeMessage($this->_folderId, $id, $message, $device)) {
            $this->_logger->err(sprintf(
                '[%s] Change message failed when updating %s',
                getmypid(), $id)
            );
            return false;
        }

        $stat['parent'] = $this->_folderId;

        // Record the state of the message
        $this->_state->updateState(
            ($message instanceof Horde_ActiveSync_Message_Mail
                ? Horde_ActiveSync::CHANGE_TYPE_FLAGS
                : Horde_ActiveSync::CHANGE_TYPE_CHANGE),
            $stat,
            Horde_ActiveSync::CHANGE_ORIGIN_PIM,
            $this->_as->driver->getUser(),
            $clientid);

        return $stat['id'];
    }

    /**
     * Import message deletions. This may conflict if the local object has been
     * modified.
     *
     * @param array $ids          Server message uids to delete
     * @param string $collection  The server collection type.
     *
     * @return array An array of successfully deleted uids.
     */
    public function importMessageDeletion(array $ids, $collection)
    {
        if ($this->_folderId == Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return;
        }

        // Check all deletions for conflicts and update device state.
        // We do this first, so (1) any conflicts will be properly resolved and
        // (2) the device state knows about the deletion before the server tries
        // to send the change back to the device.
        foreach ($ids as $id) {
            // Email deletions do not conflict
            if ($collection != Horde_ActiveSync::CLASS_EMAIL) {
                $conflict = $this->_isConflict(
                    Horde_ActiveSync::CHANGE_TYPE_DELETE, $this->_folderId, $ids);
            } else {
                $conflict = false;
            }

            // Update client state
            $change = array();
            $change['id'] = $id;
            $change['mod'] = time();
            $change['parent'] = $this->_folderId;
            $this->_state->updateState(
                Horde_ActiveSync::CHANGE_TYPE_DELETE,
                $change,
                Horde_ActiveSync::CHANGE_ORIGIN_PIM,
                $this->_as->driver->getUser());

            // If server wins the conflict, don't import change - it will be
            // detected on next sync and sent back to PIM (since we updated the
            // device state).
            if ($conflict && $this->_flags == Horde_ActiveSync::CONFLICT_OVERWRITE_PIM) {
                return;
            }
        }

        // Tell backend about the deletion
        return $this->_as->driver->deleteMessage($this->_folderId, $ids);
    }

    /**
     * Import a change in 'read' flags. This can never conflict.
     *
     * @param integer $id   Server message id (The IMAP UID).
     * @param string $flag  The state of the /seen flag
     */
    public function importMessageReadFlag($id, $flag)
    {
        if ($this->_folderId == Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return;
        }

        $change = array();
        $change['id'] = $id;
        $change['flags'] = array('read' => $flag);
        $change['parent'] = $this->_folderId;
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_FLAGS,
            $change,
            Horde_ActiveSync::CHANGE_ORIGIN_PIM,
            $this->_as->driver->getUser());

        $this->_as->driver->setReadFlag($this->_folderId, $id, $flag);
    }

    /**
     * Perform a message move initiated on the PIM
     *
     * @param array $uids  The source message ids.
     * @param string $dst  The destination folder id.
     *
     * @return array  An array containing the following keys:
     *   - results: An array with old uids as keys and new uids as values.
     *   - missing: An array containing source uids that were not found on the
     *              IMAP server.
     */
    public function importMessageMove(array $uids, $dst)
    {
        $results = $this->_as->driver->moveMessage($this->_folderId, $uids, $dst);

        // Check for any missing (not found) source messages.
        if (count($results) != count($uids)) {
            $missing = array_diff($uids, array_keys($results));
        } else {
            $missing = array();
        }

        // Update client state. For MOVES, we treat it as a delete from the
        // DST folder.
        foreach ($uids as $uid) {
            $change = array();
            $change['id'] = $uid;
            $change['mod'] = time();
            $change['parent'] = $this->_folderId;
            $this->_state->updateState(
                Horde_ActiveSync::CHANGE_TYPE_DELETE,
                $change,
                Horde_ActiveSync::CHANGE_ORIGIN_PIM,
                $this->_as->driver->getUser());
        }

        return array('results' => $results, 'missing' => $missing);
    }

    /**
     * Import a folder change from the wbxml stream
     *
     * @param string $uid          The folder uid
     * @param string $displayname  The folder display name
     * @param string $parent       The parent folder id.
     *
     * @return string|boolean  The new serverid if successful, otherwise false.
     *
     * @todo Horde 6 - This should take and return a Horde_ActiveSync_Message_Folder object.
     */
    public function importFolderChange($uid, $displayname, $parent = Horde_ActiveSync::FOLDER_ROOT)
    {
        // do nothing if it is a dummy folder
        if ($parent === Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return false;
        }

        // TODO: BC HACK. For now, we need to convert the uid -> folderid.
        $collections = $this->_as->getCollectionsObject();
        if (!empty($parent)) {
            $parent_sid = $collections->getBackendIdForFolderUid($parent);
        } else {
            $parent_sid = $parent;
        }
        if (!empty($uid)) {
            $folderid = $collections->getBackendIdForFolderUid($uid);
        } else {
            $folderid = false;
        }

        try {
            $new_uid = $this->_as->driver->changeFolder($folderid, $displayname, $parent_sid, $uid);
        } catch (Horde_ActiveSync_Exception $e) {
            return false;
        }

        $change = array();
        $change['id'] = $new_uid;
        $change['folderid'] = $folderid;
        $change['mod'] = $displayname;
        $change['parent'] = $parent;
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_CHANGE,
            $change,
            Horde_ActiveSync::CHANGE_ORIGIN_PIM);

        return $new_uid;
    }

    /**
     * Imports a folder deletion from the PIM
     *
     * @param string $uid     The folder uid
     * @param string $parent  The folder id of the parent folder.
     */
    public function importFolderDeletion($uid, $parent = Horde_ActiveSync::FOLDER_ROOT)
    {
        /* Do nothing if it is a dummy folder */
        if ($parent === Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return;
        }
        $collections = $this->_as->getCollectionsObject();
        if (!empty($parent)) {
            $parent_sid = $collections->getBackendIdForFolderUid($parent);
        } else {
            $parent_sid = $parent;
        }
        $folderid = $collections->getBackendIdForFolderUid($uid);
        $change = array();
        $change['id'] = $uid;
        $this->_as->driver->deleteFolder($folderid, $parent_sid);
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_DELETE,
            $change,
            Horde_ActiveSync::CHANGE_ORIGIN_NA);
    }

    /**
     *  Check if this change conflicts with server changes
     *  This is only true in the following situations:
     *
     *    Changed here and changed there
     *    Changed here and deleted there
     *    Deleted here and changed there
     *
     * Any other combination of operations can be done
     * (e.g. change flags & move or move & delete)
     *
     * @param string $type      The type of change('change', 'delete' etc...)
     * @param string $folderid  The id of the folder this change is from.
     * @param string $id        The uid for the changed message.
     *
     * @return boolean
     */
    protected function _isConflict($type, $folderid, $id)
    {
        $stat = $this->_as->driver->statMessage($folderid, $id);
        if (!$stat) {
            /* Message is gone, if type is change, this is a conflict */
            if ($type == Horde_ActiveSync::CHANGE_TYPE_CHANGE) {
                return true;
            } else {
                return false;
            }
        }

        return $this->_state->isConflict($stat, $type);
    }

}
