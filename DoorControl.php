<?php
/*
 * this script will provide door pictures from multiple door phones to operator phones
 * 
 * it maintains a list of door phones with URL (to retrieve the .jpg picture) and PBX (short) Name (to monitor calls)
 * it requires SOAP-capable credentials on the PBX
 * 
 * the operator phone would configure .../DoorControl.php?id=<my-own->
 * 
 */

require_once 'classes/innopbx.class.php';

class DoorPhones {

    var $doors = null;
    var $state = null;
    var $doorfile = null;
    var $statefile = null;
    var $id = null;
    var $device = null;
    var $knownDoors = null;

    /**
     * 
     * @param string $doors name of file with door definitions
     * @param string $state name of file with state memory
     * @param string $id if not null, h323id (i.e. "Name") of PBX user requesting the door cam picture
     * @param string $device (optional) hardware device used for registration if not null
     */
    function __construct($doors, $state, $id = null, $device = null) {
        $this->doorfile = $doors;
        $this->statefile = $state;
        $this->id = $id;
        $this->device = $device;

        // load the door definitions
        if (($this->doors = simplexml_load_file($doors)) === false)
            die("$doors: bad door XML");
        // load or create the state file
        if (!is_file($state)) {
            $this->state = new SimpleXMLElement("<state/>");
        } else {
            if (($this->state = simplexml_load_file($state)) === false)
                die("$doors: bad state XML");
        }
        // do some sanity testing on door definition
        if (count($this->doors->door) < 1)
            die("$doors: no doors defined");
        $dt = array();
        foreach ($this->doors->door as $d) {
            if (!isset($d['name']))
                die("$doors: name missing in " . htmlspecialchars($d->asXML()));
            if (isset($dt[(string) $d['name']]))
                die("$doors: duplicate name {$d['name']} in " . htmlspecialchars($d->asXML()));
            if (!isset($d['url']))
                die("$doors: url missing in " . htmlspecialchars($d->asXML()));
            if (in_array((string) $d['url'], $dt))
                die("$doors: duplicate url {$d['url']} in " . htmlspecialchars($d->asXML()));
            $dt[(string) $d['name']] = (string) $d['url'];
        }
        $this->knownDoors = $dt;
        if (!isset($this->doors['interval'])) {
            unset($this->doors->door);
            die("$doors: interval missing in " . htmlspecialchars($this->doors->asXML()));
        }
    }

    function setId($id) {
        $this->id = $id;
    }

    var $statefh = null;

    /**
     * lock the state file so we can update it later on
     */
    function lock() {
        if (($this->statefh = fopen($this->statefile, "c+")) === false)
            die("$this->statefile: cannot LOCK (open r/w)");
        if (flock($this->statefh, LOCK_EX) === false)
            die("$this->statefile: cannot LOCK (flock())");
    }

    /**
     * update the state file
     */
    function update() {
        if (ftruncate($this->statefh, 0) === false)
            die("$this->statefile: cannot UNLOCK (ftruncate())");
        if (fwrite($this->statefh, $this->state->asXML()) === false)
            die("$this->statefile: cannot LOCK (fwrite)");
    }

    /**
     * unlock the state file
     */
    function unlock() {
        if (flock($this->statefh, LOCK_UN) === false)
            die("$this->statefile: cannot UNLOCK (flock())");
    }

    /**
     * connect the PBX using configured credentials
     * @return innoPBX
     */
    function connectPBX() {
        // contact PBX
        $pbx = new innoPBX((string) $this->doors['pbx'], (string) $this->doors['pbxhttp'], (string) $this->doors['pbxpw'], (string) $this->doors['pbxuser'], null, null, 10);
        if ($pbx->session() == 0) {
            unset($this->doors->door);
            die("doors.xml: cannot create PBX session with credentials found in " . htmlspecialchars($this->doors->asXML()));
        }
        return $pbx;
    }

    /**
     * determine if I am called by a known door
     * @return string name of door that calls me (or null if not)
     */
    function calledByADoor($pbx, $returndoor = true, $switchapp = true) {
        // my own h323 (Name) given?
        $doorcalls = 0;
        if ($this->id == null)
            return null;
        // see if I am called by one of the known doors
        $calls = $pbx->Calls($pbx->session(), $this->id);
        foreach ($calls as $c) {
            // in all calls that I have
            foreach ($c->No as $no) {
                // look up the per entry
                if ($no->type == "peer") {
                    if (isset($this->knownDoors[$no->h323])) {
                        // and if it matches one of the doors, return the doors URL
                        if ($returndoor) {
                            // check if proxy must be used
                            foreach ($this->doors as $door) {
                                if ($door['name'] == $no->h323) {
                                    if ($door['proxy'] == 'true') {
                                        return $this->buildProxyURL($no->h323);
                                    } else {
                                        return $this->knownDoors[$no->h323];
                                    }
                                }
                            }
                        }
                        if ($switchapp) {
                            $doorcalls += $this->switch2VideoApp($pbx, $c);
                        }
                    } else if (isset($_GET['debug'])) {
                        print "I ($this->id) have a call, peer is '$no->h323'<br>\n";
                    }
                }
            }
        }
        if (isset($_GET['debug'])) {
            echo '<pre>';
            var_dump($this->id);
            var_dump($calls);
            echo '</pre>';
            exit;
        }
        if ($switchapp && $doorcalls) {
            return $doorcalls;
        }
        return null;
    }

    function getConf(array $info) {
        foreach ($info as $val) {
            if ($val->type == "conf")
                return $val->vals;
        }
        return null;
    }

    function switch2VideoApp(innoPBX $pbx, $doorcall) {
        $calls = 0;
        $doorcallconf = $this->getConf($doorcall->info);
        $search = 10;
        $myid = 0;
        $t0 = time();
        $usersession = $pbx->createUserSession($this->id);
        if (!$usersession->connect($this->device)) {
            die("cannot create user session for $this->id($this->device)");
        }

        // get new pbx if connect() was redirected to slave
        $used_pbx = $usersession->pbx();

        $myid = $usersession->session();
        while ($search) {
            $t1 = time();
            $age = $t1 - $t0;
            if ($age > 2) {
                // timeout may occur on a race condition where the call was terminated since it was detected with Calls()
                // this will effectively stop switching the video picture for one minute :-(
                break;
            }
            try {
                $pr = $used_pbx->Poll($used_pbx->session());
            } catch (Exception $e) {
                $pr = new stdClass();
                $pr->call = array();
                $search = false;
            }
            foreach ($pr->call as $call) {
                if ($call->user == $myid && $this->getConf($call->info) == $doorcallconf) {
                    $used_pbx->UserRc($call->call, 36);
                    $calls++;
                    header("X-inno-userrc: switched call #$call->call for $this->id to video app", false);
                    $search = false;
                    // break;  allow more than one call to the operator (multireg)
                }
            }
        }
        return $calls;
    }

    function switchapps() {
        $pbx = $this->connectPBX();
        return $this->calledByADoor($pbx, false, true);
    }

    /**
     * redirect calling client to another URL (the picture) temporarily
     * @param string $to url to redirect to
     */
    function warp($to) {
        header("Location: " . $to, true, 307);
        die();
    }

    /**
     * get the requested picture
     */
    function get() {
        /*
         * we walk through our list of cameras every <interval> seconds
         */

        $pbx = $this->connectPBX();

        // see if requestor is talking to a door currenlty - if so, show this door always
        if (($mydoor = $this->calledByADoor($pbx)) !== null) {
            if (isset($_GET['debug'])) {
                die("I am called by a door - so show $mydoor");
            }
            $this->warp($mydoor);
            // we do not update any state here, as this is a special treatment
        }
        $this->lock();

        // determine the last picture we delivered (and the time of delivery) 
        if (isset($this->state['time']))
            $time = (int) $this->state['time'];
        else
            $time = 0;
        $last = null;
        // if there is one kown, look it up in our door table
        if (isset($this->state['last'])) {
            $last = (string) $this->state['last'];
            // search last
            $di = 0;
            foreach ($this->doors->door as $key => $d) {
                if ((string) $d['name'] == $last)
                    break;
                $di++;
            }
            // determine the next one
            if ($di >= count($this->doors->door)) {
                $last = $next = $this->doors->door[0];
            } else if ($di < (count($this->doors->door) - 1)) {
                $last = $this->doors->door[$di];
                $next = $this->doors->door[$di + 1];
            } else {
                $last = $this->doors->door[$di];
                $next = $this->doors->door[0];
            }
        }
        if (isset($_GET['debug']))
            print (time() - $time) . " seconds since last switch ... <br>";
        // if last switch was more than an interval ago ...
        if ($time && (($time + (int) $this->doors['interval']) <= time())) {
            if (isset($_GET['debug']))
                print "switching to next {$next['name']}<br>";
            $this->state['time'] = time();
        } else {
            if (isset($_GET['debug']))
                print "staying with last {$last['name']}<br>";
            if (!$time)
                $this->state['time'] = time();
            $next = $last;
        }
        $this->state['last'] = $next['name'];
        // write back state
        $this->update();
        $this->unlock();

        if (isset($_GET['debug'])) {
            echo '<pre>';
            var_dump($_SERVER);
            var_dump($this->doors);
            var_dump($this->state);
            var_dump($next);
            echo '</pre>';
            exit;
        }
        // show picture
        if (isset($next['proxy']) && $next['proxy'] == "true") {
            $to = $this->buildProxyURL($next['name']);
            $this->warp($to);
        } else {
            $this->warp((string) $next['url']);
        }
    }

    /**
     * create URL to get image via proxy mode
     * @param string $name name of door cam to get picture from
     * @return URL for header redirect
     */
    function buildProxyURL($name) {
        $protocol = "http://";
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            // client is using https
            $protocol = "https://";
        }
        $me = $protocol .
            ((empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_ADDR'] : $_SERVER['SERVER_NAME'])) .
            ((empty($_SERVER['SERVER_PORT']) ? "" : ":{$_SERVER['SERVER_PORT']}"));
        return $me . $_SERVER['SCRIPT_NAME'] . "?proxy=".urlencode($name);
    }

    /**
     * retrieve a picture from a given door
     * only used if proxy attribute is true in door
     * @param int  $id name of door cam to get picture from
     */
    function proxy($id) {
        $door = null;
        $doors = array();
        foreach ($this->doors->door as $key => $val) {
            if (!empty($val['name']) && ($val['name'] == $id)) {
                $door = $val;
                break;
            }
            $doors[] = $val['name'];
        }
        // search door
        if (!isset($door))
            die("$id: not a valid door name (must be one of " . implode("|", $doors) . ")");
        if (!isset($door['url']))
            die("missing url attribute for door '$id'");
        if (!isset($door['proxy']) || ($door['proxy'] != "true"))
            die("$id: this door is not configured for proxy viewing");

        $url = $door['url'];
        $copts = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            //CURLOPT_BINARYTRANSFER => true,
        );

        // ssl curl settings
        if (isset($door['proxy-skip-ssl-verify']) && $door['proxy-skip-ssl-verify'] == 'true') $copts[CURLOPT_SSL_VERIFYPEER] = 0;

        // auth?
        if (isset($door['user']) && isset($door['pw'])) {
            $copts[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $copts[CURLOPT_USERPWD] = $door['user'] . ":" . $door['pw'];
            $copts[CURLOPT_FOLLOWLOCATION] = true;
        }
        $ch = curl_init();
        curl_setopt_array($ch, $copts);

        try {
            $image = curl_exec($ch);
            // validate CURL status
            if (curl_errno($ch))
                throw new Exception(curl_error($ch), 500);

            // validate HTTP status code (user/password credential issues)
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status_code != 200)
                throw new Exception("Response with Status Code [" . $status_code . "].", 500);
        } catch (Exception $e) {
            die("failed to retrieve $url for door '$id': " . $e->getMessage());
        }

        // resize picture
        if (isset($door['proxy-resize']) && $door['proxy-resize'] == 'true') {
            $phone_models = array(
                'innovaphone-IP241' => array(320, 200),
                'innovaphone-IP111' => array(320, 200),
                'innovaphone-IP112' => array(320, 200),
                'innovaphone-IP222' => array(320, 200),
                'innovaphone-IP232' => array(480, 200),
            );

            $image = imagecreatefromstring($image);
            if (!$image) die("$id: image cannot be converted");

            $original_width = imagesx($image);
            $original_height = imagesy($image);
            if (isset($_GET['debug'])) echo ('orig: '.$original_width.' / '.$original_height.'<br />');

            // check target phone screen size
            $phone = explode('/', $_SERVER['HTTP_USER_AGENT']);
            if (isset($phone[0]) && isset($phone_models[$phone[0]])) {
                $target_width = $phone_models[$phone[0]][0];
                $target_height = $phone_models[$phone[0]][1];
            } else {
                // unknown client use default from IP222
                $target_width = 320;
                $target_height = 200;
            }
            if (isset($_GET['debug'])) echo ('target: '.$target_width.' / '.$target_height.'<br />');

            // calculate on base of width
            if ($original_width > $target_width) {
                $scale = $target_width/$original_width;
                $scalled_height = ceil($original_height*$scale);
                if (isset($_GET['debug'])) echo('calc width: ' . $target_width . ' / ' . $scalled_height . '<br />');

                // calculate height
                if ($scalled_height > $target_height) {
                    $scale = $target_height/$scalled_height;
                    $scalled_width = ceil($target_width*$scale);
                    if (isset($_GET['debug'])) echo('calc height: ' . $scalled_width . ' / ' . $target_height . '<br />');
                    $target_width = $scalled_width;
                } else {
                    $target_height = $scalled_height;
                }

            // calculate on base of height
            } elseif ($original_height > $target_height) {
                $scale = $target_height/$original_height;
                $scalled_width = ceil($original_height*$scale);
                if (isset($_GET['debug'])) echo('calc height: ' . $scalled_width . ' / ' . $target_height . '<br />');
                $target_width = $scalled_width;

                // recalulation for width would be skipped - no known szenario for ip cams
                // ...

            // dont resize picture - smaller then screen
            } else {
                $target_height = $original_height;
                $target_width = $original_width;
            }

            // sampled version
            $new_image = imagecreatetruecolor( $target_width, $target_height);
            imagecopyresampled($new_image, $image,0,0,0,0, $target_width, $target_height, $original_width, $original_height);
            if (isset($_GET['debug'])) echo ('new: '.imagesx($new_image).' / '.imagesy($new_image).'<br />');

            // ouput
            if (isset($_GET['debug'])) exit();
            header('Content-Type: image/jpeg');
            imagejpeg($new_image);


        // output original picture
        } else {
            // use custom header if given
            if (isset($door['content-type'])) {
                header("Content-Type: ".$door['content-type']);
            } else {
                header("Content-Type: Content-Type: image/jpeg");
            }
            print $image;
        }
    }

}

// get my own path
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $dir = dirname($_SERVER['SCRIPT_FILENAME']);
} else {
    die("your server does not support \$_SERVER['SCRIPT_FILENAME']");
}
// check dir
if (!is_dir($dir)) {
    die("your server delivers wrong directory ($dir) for \$_SERVER['SCRIPT_FILENAME']");
}
// check definition file
$xmlfile = null;
foreach (array("$dir/dvl-doors.xml", "$dir/doors.xml") as $try) {
    if (is_file($try) && is_readable($try)) {
        $xmlfile = $try;
        break;
    }
}
if ($xmlfile === null)
    die("door definition file (doors.xml) does not exist in $dir");

/*
 * switch2app request?
 */
if (!empty($_GET['switchapp'])) {
    $scriptresult = "";
    if (!isset($_GET['h323'])) {
        $scriptresult = "must have h323 arg for switchapp operation";
    } elseif (empty($_GET['h323'])) {
        $scriptresult = "works for diverted calls only";
    } else {
        $h323 = $_GET['h323'];
        $doors = new DoorPhones($xmlfile, "$dir/state.xml");
        $pbx = $doors->connectPBX();
        $cn = null;
        $e164 = null;
        $ui = $pbx->FindUser("true", "true", "true", "true", $cn, $h323, $e164, 1, false, true);
        if ((count($ui) != 1) || $ui[0]->h323 != $h323) {
            $scriptresult = "cannot find object with 'Name' = '$h323'";
        } else {
            $cn = $ui[0]->cn;
            $doors->setId($cn);
            $ncalls = $doors->switchapps();
            $scriptresult = "switched $ncalls call(s) for door '" . htmlentities($cn) . "'";
        }
    }
    print "
        <?xml version=\"1.0\" encoding=\"utf-8\"?>
        <voicemail xmlns=\"http://www.innovaphone.com/xsd/vm.xsd\">
            <function define=\"Main\">
                <dbg string=\"switchops.xml: " . htmlentities($scriptresult) . "\"/>
            </function>
        </voicemail>
        ";
    exit;
}

/*
 * door picture retrieval?
 */
$doors = new DoorPhones($xmlfile, "$dir/state.xml", isset($_GET["id"]) ? $_GET["id"] : null, isset($_GET["hw"]) ? $_GET["hw"] : null);
if (empty($_GET['proxy'])) {
    $doors->get();
} else {
    $doors->proxy($_GET['proxy']);
}
    
