<?php
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
/**
 * Two Factor Action Plugin
 *
 * @author Mike Wilmes mwilmes@avc.edu
 * Big thanks to Daniel Popp and his Google 2FA code (authgoogle2fa) as a
 * starting reference.
 *
 * Overview:
 * The plugin provides for two opportunities to perform two factor
 * authentication. The first is on the main login page, via a code provided by
 * an external authenticator. The second is at a separate prompt after the
 * initial login. By default, all modules will process from the second login,
 * but a module can subscribe to accepting a password from the main login when
 * it makes sense, because the user has access to the code in advance.
 *
 * If a user only has configured modules that provide for login at the main
 * screen, the code will only be accepted at the main login screen for
 * security purposes.
 *
 * Modules will be called to render their configuration forms on the profile
 * page and to verify a user's submitted code. If any module accepts the
 * submitted code, then the user is granted access.
 *
 * Each module may be used to transmit a message to the user that their
 * account has been logged into. One module may be used as the default
 * transmit option. These options are handled by the parent module.
 */
class action_plugin_autogroup extends DokuWiki_Action_Plugin {

    protected $_disabled = '';      // if disabled set to explanatory string
    protected $_group_cfg = null;   // The group management rules
    protected $_add = null;         // A flag to add when any rule matches
    protected $_remove = null;      // A flag to remove when all rules do not match
    protected $_in_proc = false;    // A locking flag to keep a group change from triggering this module recursively.

    public function __construct(){
        $this->setupLocale();
        $this->_add = (bool)$this->getConf('enable_add');
        $this->_remove = (bool)$this->getConf('enable_remove');
        $raw_cfg = array_map(function($x){return explode(',',$x,3);}, explode("\n", $this->getConf('regex')));            
        $this->_group_cfg = array();
        foreach($raw_cfg as $set) {
            $key = array_shift($set);
            $this->_group_cfg[$key][]=$set;
        }
        $this->_disabled = ( !$this->_add && !$this->_remove ) || count($this->_group_cfg)==0;
    }
    
    /**
     * Registers the event handlers.
     */
    public function register(Doku_Event_Handler $controller)
    {
        // In case this was called during setup, call the init again to have a chance to get the authentication module.
        if (!$this->_disabled) {
            // Update a single user after their info changes.
            $controller->register_hook('AUTH_USER_CHANGE', 'AFTER', $this, 'update_group_event');
            
            // USE THIS IF WE GET THAT CONFIG CHANGE EVENT!!!
            // Snag this event to update all users if a change was made on a config page.
            //$controller->register_hook('CONFIG_CHANGE_MADE', 'AFTER', $this, 'check_update_all_event');
            
            // Snag this event to update all users if a change was made on a config page.
            $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'check_update_all_event');
        } 
    }
    
    /**
     * Does the group list processing.
     */
	public function update_group_event(&$event, $param) {
        global $INPUT;  
        // If this module processing triggered this event, then abort.
        if ($this->_in_proc) { return; }
        // If disabled, then silently return.
        if ($this->_disabled) {
            return;
        }
        // If we can't read the user accounts or change the groups using the auth module, then abort.
        global $auth;
        if (!$auth || !$auth->canDo('getUsers') || !$auth->canDo('modGroups')) {
            $msg = $this->getLang('nosupport');
            msg($msg, -1);
            dbglog($msg);
            return;
        }
        
        // If we have a logged in user, then process.
        $user = $INPUT->server->str('REMOTE_USER', null);
        if ($user) { $this->_update_user_groups($user); }
        return;
	}

    /**
     * Does the group list processing.
     */
	private function _update_user_groups($user) {
        global $auth;
        
        // Set the event lock.
        $this->_in_proc = true;
        
        // Get this user's current group data.
        $oldinfo = $auth->getUserData($user);
        $oldgrps = $oldinfo['grps'];
        $newgrps = array_values($oldgrps);
        
        // Start validating regex rules.
        $in = array();
        $out = array();
        foreach ($this->_group_cfg as $group=>$lines){
            $match = false;
            foreach ($lines as $line) {
                list($attr, $regex) = $line;
                $match |= preg_match($regex, $oldinfo[$attr]);
            }
            if ($match) {
                if ($this->_add){
                    $in[] = $group;
                    if (!in_array($group,$newgrps)){
                        $newgrps[] = $group;
                    }
                }
            }
            else {
                if ($this->_remove){
                    $out[] = $group;                    
                    if (in_array($group,$newgrps)){
                        array_splice($newgrps, array_keys($newgrps, $group)[0] , 1);
                    }
                }
            }
        }
        
        if ( $newgrps != $oldgrps ) {
            $changes['grps'] = $newgrps;
        }

        if ($auth->triggerUserMod('modify', array($user, $changes))) {
            $msg = sprintf($this->getLang('update_ok'), $user, implode(',',$in), implode(',',$out));
            dbglog($msg);            
        }

        // Clear the event lock.
        $this->_in_proc = false;
    }

    /**
     * Used to update all groups for all users on the wiki.
     * Might take a while on large wikis.
     */
	public function update_all_groups() {
        // If disabled, then silently return.
        if ($this->_disabled) {
            return;
        }
        // If we can't read the user accounts or change the groups using the auth module, then abort.
        global $auth;
        if (!$auth || !$auth->canDo('getUsers') || !$auth->canDo('modGroups')) {
            $msg = $this->getLang('nosupport');
            msg($msg, -1);
            dbglog($msg);
            return;
        }
        // Get the user count and the list of users.
        $userCount = $auth->getUserCount();
        $userData = $auth->retrieveUsers(0, $userCount, array());
        // Update groups for each user.
        foreach ($userData as $user => $userinfo) {
            $this->_update_user_groups($user);
        }            
	}
    
    public function check_update_all_event(&$event, $param){
        if ($_SESSION['PLUGIN_CONFIG']['state'] == 'updated') {
            dbglog('going in');
            $this->update_all_groups();
        }
    }
}