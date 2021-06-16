<?php

/*
 * this script will provide door pictrures from multiple door phones to operator phones
 * 
 * it maintains a list of door phones with URL (to retrieve .jpg picture) and PBX (short)  Name (to monitor calls)
 * it requires SOAP-capable credentials on the PBX
 * 
 * the operaor phone would configure .../DoorContro.php?id=<my-own->
 * 
 */

require_once 'classes/innopbx.class.php';

class DoorPhones {

    var $doors = null;
    var $state = null;
    var $doorfile = null;
    var $statefile = null;
    var $id = null;
    var $knownDoors = null;

    /**
     * 
     * @param string $doors name of file with door definitions
     * @param string $state name of file with state memory
     * @param string $id if not null, h323id (i.e. "Name") of PBX uiser requesting the door cam picture
     */
    function __construct($doors, $state, $id) {
        $this->doorfile = $doors;
        $this->statefile = $state;
        $this->id = $id;

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
     * determine if i am called by a known door
     * @return string name of door that calls me (or null if not)
     */
    function calledByADoor() {
        // my own h323 (Name) given?
        if ($this->id == null)
            return null;
        // contact PBX
        $pbx = new innoPBX((string) $this->doors['pbx'], (string) $this->doors['pbxhttp'], (string) $this->doors['pbxpw'], (string) $this->doors['pbxuser']);
        if ($pbx->session() == 0) {
            unset($this->doors->door);
            die("doors.xml: cannot create PBX session with credentials found in " . htmlspecialchars($this->doors->asXML()));
        }
        // see if I am called by one of the known doors
        $calls = $pbx->Calls($pbx->session(), $this->id);
        foreach ($calls as $c) {
            // in all calls that I have
            foreach ($c->No as $no) {
                // look up the per entry
                if ($no->type == "peer") {
                    if (isset($this->knownDoors[$no->h323])) {
                        // and if it matches one of the doors, return the doors URL
                        if (isset($this->doors['switch']) && ($this->doors['switch']) == "true")
                                $this->switch2VideoApp($pbx, $c);
                        return $this->knownDoors[$no->h323];
                    } else if (isset($_GET['debug'])) {
                        print "I ($this->id) have a call, peer is '$no->h323'<br>\n";
                    }
                }
            }
        }
        if (isset($_GET['debug'])) {
            var_dump($this->id);
            var_dump($calls);
            exit;
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
        $doorcallconf = $this->getConf($doorcall->info);
        $search = 10;
        $myid = 0;
        $t0 = time();
        $usersession = $pbx->createUserSession($this->id);
        if (!$usersession->connect()) {
            die("cannot create user session for $this->id");
        }
        $myid = $usersession->session();
        while ($search) {
            $t1 = time();
            $age = $t1 - $t0;
            if ($age > 2) {
                // timeout may occur on a race condition where the call was terminated since it was detected with Calls()
                // this will effectivekly stop switching the video picture for one minute :-(
                break;
            }
            try {
                $pr = $pbx->Poll($pbx->session());
            } catch (Exception $e) {
                $pr = new stdClass();
                $pr->call = array();
                $search = false;
            }
            foreach ($pr->call as $call) {
                if ($call->user == $myid && $this->getConf($call->info) == $doorcallconf) {
                    $pbx->UserRc($call->call, 36);
                    header("X-inno-userrc: switched call #$call->call for $this->id to video app", false);
                    $search = false;
                    break;
                }
            }
        }
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

        // see if requestor is talking to a door currenlty - if so, show this door always
        if (($mydoor = $this->calledByADoor()) !== null) {
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
            var_dump($_SERVER);
            var_dump($this->doors);
            var_dump($this->state);
            var_dump($next);
            exit;
        }
        // show picture
        if (isset($next['proxy']) && $next['proxy'] == "true") {
            $protocol = "http://";
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
                // client is using https
                $protocol = "https://";
            }
            $me = $protocol .
                    ((empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_ADDR'] : $_SERVER['SERVER_NAME'])) .
                    ((empty($_SERVER['SERVER_PORT']) ? "" : ":{$_SERVER['SERVER_PORT']}"));
            $to = $me . $_SERVER['SCRIPT_NAME'] . "?proxy={$next['name']}";
            $this->warp($to);
        } else {
            $this->warp((string) $next['url']);
        }
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
        $hdr = array();
        $copts = array(
            CURLOPT_URL => $url
        );

        // special content type?
        if (!isset($door['content-type']))
            $hdr[] = "Content-Type: image/jpeg";
        else
            $hdr[] = "Content-Type: {$door['content-type']}";

        // auth?
        if (isset($door['user']) && isset($door['pw'])) {
            $copts[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $copts[CURLOPT_USERPWD] = $door['user'] . ":" . $door['pw'];
            $copts[CURLOPT_RETURNTRANSFER] = true;
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

        foreach ($hdr as $h)
            header($h);
        print $image;
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
if (!is_file("$dir/doors.xml") || !is_readable("$dir/doors.xml")) {
    die("door definition file (doors.xml) does not exist in $dir");
}

/*
 * create door control
 */
$doors = new DoorPhones("$dir/doors.xml", "$dir/state.xml", isset($_GET["id"]) ? $_GET["id"] : null);
if (empty($_GET['proxy'])) {
    $doors->get();
} else {
    $doors->proxy($_GET['proxy']);
}
