<?php

// innovaphone PBX SOAP API PHP wrapper class
//
// WSDL structure base classes
class innoUserInfoBase {
    
}

;

class innoCallInfoBase {
    
}

;

class innoAnyInfoBase {
    
}

;

class innoGroupBase {
    
}

;

class innoNoBase {
    
}

;

class innoInfoBase {
    
}

;

class innoDeviceBase {
    
}

;

class innoPresenceBase {
    
}

;

/**
 * class repesenting a UserInitialize Session taking care of the "right" PBX
 */
class innoPBXUserSession {

    /**
     *
     * @var innoPBX the master PBX link
     */
    private $_master = null;

    /**
     *
     * @var innoPBX the registration PBX
     */
    private $_pbx = null;

    /**
     *
     * @var innoUserInfoBase[] my user record
     */
    public $_me = null;

    /**
     *
     * @var innoDeviceBase[] my Devices
     */
    protected $_devices = null;

    public function Devices() {
        return $this->_devices;
    }

    /**
     *
     * @var string the device we limit to (or null for all)
     */
    public $_device = null;

    /**
     *
     * @var int user session @ registration PBX
     */
    private $_session;

    /**
     * constructor
     * @param innoPBX $master constructor
     * @param string $usercn users's long name
     */
    public function __construct(innoPBX $master, $usercn = null) {
        $this->_master = $master;
        if ($usercn != null)
            $this->find($usercn);
    }

    /**
     * destructos, calls UserEnd for the registration PBX
     */
    public function __destruct() {
        if ($this->_session != 0)
            $this->_master->UserEnd($this->_session);
    }

    /**
     * find user by one (and only one!) of its attributes and store it in $this->_me
     * @param string $cn
     * @param string $h323
     * @param string $e164
     * @return boolean
     */
    public function find($cn, $h323 = null, $e164 = null) {

        switch ($this->_master->wsdlVersion) {
            case 8 :
                $ui = $this->_master->FindUser("true", "true", "true", $cn, $h323, $e164, 1, false);
                break;
            case 10 :
                $ui = $this->_master->FindUser("true", "true", "true", "true", $cn, $h323, $e164, 1, false, true);
                break;
        }
        if (count($ui) != 1) {
            return innoPBX::showError("unknown/duplicate user (cn=$cn, h323=$h323, e164=$e164)");
        }
        $this->_me = $ui[0];

        // get users devices 
        $this->_devices = $this->_master->Devices($this->_master->session(), $this->_me->cn);
        if (count($this->_devices) == 0) {
            return innoPBX::showError("unable to get devices for {$this->_me->cn} from PBX {$this->_me->loc}");
        }
        return true;
    }

    /**
     * session access
     * @return int
     */
    public function session() {
        return $this->_session;
    }

    /**
     * get access to the registration PBX (for all SOAP functions)
     * @return innoPBX
     */
    public function pbx() {
        return $this->_pbx;
    }

    /**
     * get access to the master PBX (for all SOAP functions)
     * @return innoPBX
     */
    public function master() {
        return $this->_master;
    }

    /**
     * do a user initialize
     * @param string $device
     * @param boolean $xfer
     * @param boolean $disc
     * @return boolean
     */
    public function connect($device = null, $xfer = false, $disc = true) {
        // we must send the UserInitialize to the registration PBX
        // get the "right" (i.e. registration) PBX
        if ($this->_me->loc == "") {
            // no location, must be master
            $this->_pbx = $this->_master;
        } else {
            switch ($this->_master->wsdlVersion) {
                case 8 :
                    $regPBXURL = $this->_master->LocationUrl("true", "true", $this->_me->loc);
                case 10 :
                    $regPBXURL = $this->_master->LocationUrl("true", "true", "true", "true", $this->_me->loc, $this->_master->usetls);
                    break;
            }
            if ($regPBXURL == "") {
                return innoPBX::showError("unable to locate PBX '{$this->_me->loc}'");
            }
            $this->_pbx = new innoPBX(
                    $regPBXURL, $this->_master->httpUser(), $this->_master->httpPassword(), $this->_me->cn, $this->_master->___usedoptions, null, $this->_master->wsdlVersion, $this->_master->usetls
            );
        }

        // connect PBX
        if ($this->_pbx->key() == 0) {
            return innoPBX::showError("failed to login to PBX ({$this->_me->loc}) for ({$this->_me->cn})");
        }

        // determine device id from $device parameter
        $devname = null;
        $this->_device = null;
        if (is_null($device)) {
            $this->_device = null;
        } elseif (is_object($device)) {
            $devname = $device->hw;
        } else {
            // not a device record, so it will be a device-hw name directly
            $devname = $device;
        }
        // look it up in our devices table
        if (!is_null($devname)) {
            foreach ($this->_devices as &$dev) {
                if ($devname == $dev->hw) {
                    $this->_device = $dev;
                    break;
                }
            }
            if ($this->_device == null) {
                return innoPBX::showError("bad device '$devname' for user '{$this->_me->cn}'");
            }
        }

        // initialize user session
        $this->_session = $this->_pbx->UserInitialize($this->_pbx->session(), $this->_me->cn, false, false, $devname);
        if ($this->_session == 0) {
            return innoPBX::showError("Failed to create a session for {$this->_me->cn} on {$this->_me->loc}");
        }

        return true;
    }

}

/**
 * class repesenting a PBX with its Initialize session
 * you should use that to access the master PBX if you have a master/slave system
 */
class innoPBX extends SoapClient {

    /**
     * error message maintained by this library (not b< the PBX) 
     * @var string gaga
     */
    public static $innoPBXError = "";

    /**
     * @var string the session key returned by the PBX
     */
    protected $___key;   // the session key
    /**
     * the session ID returned by the PBX
     * @var string 
     */
    protected $___session;  // the session id
    /**
     * default options which may be overridden by the caller
     * @var string[]
     */
    protected $___options = array(
        // default SOAPClient::__construct options used by the class
        "connection_timeout" => 10,
        "exceptions" => true,
    );
    /* protected */

    /**
     * the options actually being used
     * @var string[] 
     */
    var $___usedoptions = array();

    /**
     * do we use HTTPS to access PBX?
     * @var boolean 
     */
    var $usetls = false;

    /**
     * wsdl file version, MUST match the wsdl file you provide (if you provide one)
     * @var int
     */
    var $wsdlVersion = null;

    /**
     * the wsdl file
     * note that this file needs to be changed in order to use a different WSDL version 
     * see http://wiki.innovaphone.com/index.php?title=Reference10:Concept_SOAP_API
     * please also note that PHP will retrieve this file upon each call to this classes constructor
     * see the PHP documentation for methods to provide a local wsdl file instead to speed this up
     */
    const ___wsdl8 = 'http://www.innovaphone.com/wsdl/pbx800.wsdl';
    const ___wsdl10 = 'http://www.innovaphone.com/wsdl/pbx10_00.wsdl';

    /**
     * class constructor
     * @param string $server FQDN or IP address or full SOAP URL
     * @param string $httpu HTTP user (e.g. "admin")
     * @param string $httpp HTTP password (e.g. "ip800")
     * @param string $user PBX user (users long name)
     * @param array $options see SOAPClient::__construct for details on possible SOAP options
     * @param string $wsdl URL of wsdl file to be used (defaults to ___wsdl)
     * @param boolean $usetls use https:// URL if true
     */
    public function __construct(
    $server, // the PBX IP
            $httpu, $httpp, $user, $options = null, $wsdl = null, $version = 8, $usetls = false
    ) {

        // default class name mappings
        $dfcls = array(
            "UserInfo" => "innoUserInfoBase",
            "CallInfo" => "innoCallInfoBase",
            "AnyInfo" => "innoAnyInfoBase",
            "Group" => "innoGroupBase",
            "No" => "innoNoBase",
            "Info" => "innoInfoBase",
            "Device" => "innoDeviceBase",
            "Group" => "innoGroupBase",
            "Presence" => "innoPresenceBase",
        );
        $clsmaps = array();
        foreach ($dfcls as $cls => $clsmap) {
            if (class_exists($clsmap)) {
                $clsmaps[$cls] = $clsmap;
            }
        }
        $this->___options['classmap'] = $clsmaps;

        // wsdl given or use std wsdl?
        if ($wsdl == null) {
            // derive wsdl from requested version
            switch ($version) {
                case 8 : $wsdl = self::___wsdl8;
                    break;
                case 10 : $wsdl = self::___wsdl10;
                    break;
                default : die("unsupported wsdl version $version");
            }
        }
        $this->wsdlVersion = $version;

        // these options are forced and cannot be overridden by user
        $this->___usedoptions = array(// forced options
            'login' => $httpu,
            'password' => $httpp,
            'location' => ((substr($server, 0, 7) == "http://") ||
            (substr($server, 0, 8) == "https://")
            ) ? $server : ( $usetls ? "https" : "http") . "://$server/PBX0/user.soap",
        );
        $this->usetls = substr($this->___usedoptions['location'], 0, 6) == "https:";

        // merge in user options
        if (is_array($options))
            $this->___usedoptions += $options;

        // finally merge in default options
        $this->___usedoptions += $this->___options; // merged in class global options
        // construct parent class
        parent::__construct($wsdl, $this->___usedoptions);

        // get the connection
        $appname = "PHP PBX SOAP Wrapper";
        switch ($version) {
            case 8 :
                $init = $this->Initialize($user, $appname, true, true, true, true);
                break;
            case 10 :
                $init = $this->Initialize($user, $appname, true, true, true, true, true);
                break;
        }
        $this->___key = $init['key'];
        $this->___session = $init['return'];
        if ($this->___key == 0)
            self::$innoPBXError = "Unable to Initialize to '{$server}' for '${user}'";
    }

    /**
     * call End() to the PBX
     */
    public function __destruct() {
        $this->End();
    }

    /**
     * access the session key
     * @return int
     */
    public function key() {
        return $this->___key;
    }

    /**
     * access the session ID
     * @return int
     */
    public function session() {
        return $this->___session;
    }

    /**
     * access the account name used for HTTP access
     * @return string
     */
    public function httpUser() {
        return $this->___usedoptions['login'];
    }

    /**
     * access the account password used for HTTP access
     * @return string
     */
    public function httpPassword() {
        return $this->___usedoptions['password'];
    }

    /**
     * show last error message
     * @param boolean $die
     */
    public static function showError($err = null, $return = false, $die = false) {
        if ($err !== null)
            innopbx::$innoPBXError = $err;
        var_dump(innopbx::$innoPBXError);
        if ($die)
            die();
        return $return;
    }

    public function createUserSession($usercn) {
        // get user session object
        return new innoPBXUserSession($this, $usercn);
    }

}

?>