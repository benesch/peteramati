<?php
// conference.php -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Conference {

    public $dblink = null;

    var $settings;
    var $settingTexts;
    var $sversion;
    var $deadlineCache;

    private $save_messages = true;
    var $headerPrinted = false;
    private $_save_logs = false;

    private $scriptStuff = "";
    private $usertimeId = 1;

    function __construct($dsn) {
        global $Opt;
        // unpack dsn, connect to database, load current settings
        if (($this->dsn = $dsn))
            list($this->dblink, $Opt["dbName"]) = Dbl::connect_dsn($this->dsn);
        if (!@$Opt["confid"])
            $Opt["confid"] = @$Opt["dbName"];
        if ($this->dblink) {
            Dbl::set_default_dblink($this->dblink);
            Dbl::set_error_handler(array($this, "query_error_handler"));
            $this->load_settings();
        } else
            $this->crosscheck_options();
    }


    //
    // Initialization functions
    //

    function load_settings() {
        global $Opt, $OptOverride, $Now, $OK;

        // load settings from database
        $this->settings = array();
        $this->settingTexts = array();
        $this->_pc_seeall_cache = null;
        $this->deadlineCache = null;

        $result = $this->q("select name, value, data from Settings");
        while ($result && ($row = $result->fetch_row())) {
            $this->settings[$row[0]] = (int) $row[1];
            if ($row[2] !== null)
                $this->settingTexts[$row[0]] = $row[2];
            if (substr($row[0], 0, 4) == "opt.") {
                $okey = substr($row[0], 4);
                if (!array_key_exists($okey, $OptOverride))
                    $OptOverride[$okey] = @$Opt[$okey];
                $Opt[$okey] = ($row[2] === null ? $row[1] : $row[2]);
            }
        }
        Dbl::free($result);

        // update schema
        if ($this->settings["allowPaperOption"] < 91) {
            require_once("updateschema.php");
            $oldOK = $OK;
            updateSchema($this);
            $OK = $oldOK;
        }
        $this->sversion = $this->settings["allowPaperOption"];

        // invalidate caches after loading from backup
        if (isset($this->settings["frombackup"])
            && $this->invalidateCaches()) {
            $this->qe("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
            unset($this->settings["frombackup"]);
        }

        // update options
        if (isset($Opt["ldapLogin"]) && !$Opt["ldapLogin"])
            unset($Opt["ldapLogin"]);
        if (isset($Opt["httpAuthLogin"]) && !$Opt["httpAuthLogin"])
            unset($Opt["httpAuthLogin"]);

        // set conferenceKey
        if (!isset($Opt["conferenceKey"])) {
            if (!isset($this->settingTexts["conf_key"])
                && ($key = hotcrp_random_bytes(32)) !== false)
                $this->save_setting("conf_key", 1, $key);
            $Opt["conferenceKey"] = defval($this->settingTexts, "conf_key", "");
        }

        // set capability key
        if (!@$this->settings["cap_key"]
            && !@$Opt["disableCapabilities"]
            && !(($key = hotcrp_random_bytes(16)) !== false
                 && ($key = base64_encode($key))
                 && $this->save_setting("cap_key", 1, $key)))
            $Opt["disableCapabilities"] = true;

        // GC old capabilities
        if ($this->sversion >= 58
            && defval($this->settings, "__capability_gc", 0) < $Now - 86400) {
            foreach (array($this->dblink, Contact::contactdb()) as $db)
                if ($db) {
                    Dbl::ql($db, "delete from Capability where timeExpires>0 and timeExpires<$Now");
                    Dbl::ql($db, "delete from CapabilityMap where timeExpires>0 and timeExpires<$Now");
                }
            $this->q("insert into Settings (name, value) values ('__capability_gc', $Now) on duplicate key update value=values(value)");
            $this->settings["__capability_gc"] = $Now;
        }

        $this->crosscheck_settings();
        $this->crosscheck_options();
    }

    private function crosscheck_settings() {
    }

    private function crosscheck_options() {
        global $Opt, $ConfSiteBase;

        // set longName, downloadPrefix, etc.
        $confid = $Opt["confid"];
        if ((!isset($Opt["longName"]) || $Opt["longName"] == "")
            && (!isset($Opt["shortName"]) || $Opt["shortName"] == "")) {
            $Opt["shortNameDefaulted"] = true;
            $Opt["longName"] = $Opt["shortName"] = $confid;
        } else if (!isset($Opt["longName"]) || $Opt["longName"] == "")
            $Opt["longName"] = $Opt["shortName"];
        else if (!isset($Opt["shortName"]) || $Opt["shortName"] == "")
            $Opt["shortName"] = $Opt["longName"];
        if (!isset($Opt["downloadPrefix"]) || $Opt["downloadPrefix"] == "")
            $Opt["downloadPrefix"] = $confid . "-";

        // expand ${confid}, ${confshortname}
        foreach (array("sessionName", "downloadPrefix", "conferenceSite",
                       "paperSite", "defaultPaperSite", "contactName",
                       "contactEmail", "emailFrom", "emailSender",
                       "emailCc", "emailReplyTo") as $k)
            if (isset($Opt[$k]) && is_string($Opt[$k])
                && strpos($Opt[$k], "$") !== false) {
                $Opt[$k] = preg_replace(',\$\{confid\}|\$confid\b,', $confid, $Opt[$k]);
                $Opt[$k] = preg_replace(',\$\{confshortname\}|\$confshortname\b,', $Opt["shortName"], $Opt[$k]);
            }

        // remove final slash from $Opt["paperSite"]
        if (!isset($Opt["paperSite"]) || $Opt["paperSite"] == "")
            $Opt["paperSite"] = Navigation::site_absolute();
        if ($Opt["paperSite"] == "" && isset($Opt["defaultPaperSite"]))
            $Opt["paperSite"] = $Opt["defaultPaperSite"];
        $Opt["paperSite"] = preg_replace('|/+\z|', "", $Opt["paperSite"]);

        // set assetsUrl and scriptAssetsUrl
        if (!isset($Opt["scriptAssetsUrl"]) && isset($_SERVER["HTTP_USER_AGENT"])
            && strpos($_SERVER["HTTP_USER_AGENT"], "MSIE") !== false)
            $Opt["scriptAssetsUrl"] = $ConfSiteBase;
        if (!isset($Opt["assetsUrl"]))
            $Opt["assetsUrl"] = $ConfSiteBase;
        if ($Opt["assetsUrl"] !== "" && !str_ends_with($Opt["assetsUrl"], "/"))
            $Opt["assetsUrl"] .= "/";
        if (!isset($Opt["scriptAssetsUrl"]))
            $Opt["scriptAssetsUrl"] = $Opt["assetsUrl"];
        Ht::$img_base = $Opt["assetsUrl"] . "images/";

        // set docstore from filestore
        if (@$Opt["docstore"] === true)
            $Opt["docstore"] = "docs";
        else if (!@$Opt["docstore"] && @$Opt["filestore"]) {
            if (($Opt["docstore"] = $Opt["filestore"]) === true)
                $Opt["docstore"] = "filestore";
            $Opt["docstoreSubdir"] = @$Opt["filestoreSubdir"];
        }

        // handle timezone
        if (function_exists("date_default_timezone_set")) {
            if (isset($Opt["timezone"])) {
                if (!date_default_timezone_set($Opt["timezone"])) {
                    $this->errorMsg("Timezone option “" . htmlspecialchars($Opt["timezone"]) . "” is invalid; falling back to “America/New_York”.");
                    date_default_timezone_set("America/New_York");
                }
            } else if (!ini_get("date.timezone") && !getenv("TZ"))
                date_default_timezone_set("America/New_York");
        }

        // set safePasswords
        if (!@$Opt["safePasswords"] || (is_int($Opt["safePasswords"]) && $Opt["safePasswords"] < 1))
            $Opt["safePasswords"] = 0;
        else if ($Opt["safePasswords"] === true)
            $Opt["safePasswords"] = 1;
    }

    function setting($name, $defval = false) {
        return defval($this->settings, $name, $defval);
    }

    function setting_data($name) {
        $x = @$this->settingTexts[$name];
        if ($x && is_object($x))
            $x = $this->settingTexts[$name] = json_encode($x);
        return $x;
    }

    function setting_json($name) {
        $x = @$this->settingTexts[$name];
        if ($x && is_string($x)) {
            $x = json_decode($x);
            if (is_object($x))
                $this->settingTexts[$name] = $x;
        }
        return $x;
    }

    function session($name, $defval = null) {
        if (isset($_SESSION[$this->dsn][$name]))
            return $_SESSION[$this->dsn][$name];
        else
            return $defval;
    }

    function save_session($name, $value) {
        if ($value !== null)
            $_SESSION[$this->dsn][$name] = $value;
        else
            unset($_SESSION[$this->dsn][$name]);
    }

    function save_session_array($name, $index, $value) {
        if (!is_array(@$_SESSION[$this->dsn][$name]))
            $_SESSION[$this->dsn][$name] = array();
        if ($index !== true)
            $_SESSION[$this->dsn][$name][$index] = $value;
        else
            $_SESSION[$this->dsn][$name][] = $value;
    }

    function capability_text($prow, $capType) {
        // A capability has the following representation (. is concatenation):
        //    capFormat . paperId . capType . hashPrefix
        // capFormat -- Character denoting format (currently 0).
        // paperId -- Decimal representation of paper number.
        // capType -- Capability type (e.g. "a" for author view).
        // To create hashPrefix, calculate a SHA-1 hash of:
        //    capFormat . paperId . capType . paperCapVersion . capKey
        // where paperCapVersion is a decimal representation of the paper's
        // capability version (usually 0, but could allow conference admins
        // to disable old capabilities paper-by-paper), and capKey
        // is a random string specific to the conference, stored in Settings
        // under cap_key (created in load_settings).  Then hashPrefix
        // is the base-64 encoding of the first 8 bytes of this hash, except
        // that "+" is re-encoded as "-", "/" is re-encoded as "_", and
        // trailing "="s are removed.
        //
        // Any user who knows the conference's cap_key can construct any
        // capability for any paper.  Longer term, one might set each paper's
        // capVersion to a random value; but the only way to get cap_key is
        // database access, which would give you all the capVersions anyway.

        if (!isset($this->settingTexts["cap_key"]))
            return false;
        $start = "0" . $prow->paperId . $capType;
        $hash = sha1($start . $prow->capVersion . $this->settingTexts["cap_key"], true);
        $suffix = str_replace(array("+", "/", "="), array("-", "_", ""),
                              base64_encode(substr($hash, 0, 8)));
        return $start . $suffix;
    }

    // update the 'papersub' setting: are there any submitted papers?
    function updatePapersubSetting($forsubmit) {
        $papersub = defval($this->settings, "papersub");
        if ($papersub === null && $forsubmit)
            $this->q("insert into Settings (name, value) values ('papersub',1) on duplicate key update name=name");
        else if ($papersub <= 0 || !$forsubmit)
            // see also settings.php
            $this->q("update Settings set value=(select ifnull(min(paperId),0) from Paper where " . (defval($this->settings, "pc_seeall") <= 0 ? "timeSubmitted>0" : "timeWithdrawn<=0") . ") where name='papersub'");
    }

    function updatePaperaccSetting($foraccept) {
        if (!isset($this->settings["paperacc"]) && $foraccept)
            $this->q("insert into Settings (name, value) values ('paperacc', " . time() . ") on duplicate key update name=name");
        else if (defval($this->settings, "paperacc") <= 0 || !$foraccept)
            $this->q("update Settings set value=(select max(outcome) from Paper where timeSubmitted>0 group by paperId>0) where name='paperacc'");
    }

    function updateRevTokensSetting($always) {
        if ($always || defval($this->settings, "rev_tokens", 0) < 0)
            $this->qe("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)");
    }

    function save_setting($name, $value, $data = null) {
        $qname = $this->dblink->escape_string($name);
        $change = false;
        if ($value === null && $data === null) {
            if ($this->qe("delete from Settings where name='$qname'")) {
                unset($this->settings[$name]);
                unset($this->settingTexts[$name]);
                $change = true;
            }
        } else {
            if ($data === null)
                $dval = "null";
            else if (is_string($data))
                $dval = "'" . $this->dblink->escape_string($data) . "'";
            else
                $dval = "'" . $this->dblink->escape_string(json_encode($data)) . "'";
            if ($this->qe("insert into Settings (name, value, data) values ('$qname', $value, $dval) on duplicate key update value=values(value), data=values(data)")) {
                $this->settings[$name] = $value;
                $this->settingTexts[$name] = $data;
                $change = true;
            }
        }
        if ($change) {
            $this->_pc_seeall_cache = null;
            $this->crosscheck_settings();
            if (str_starts_with($name, "opt."))
                $this->crosscheck_options();
        }
        return $change;
    }

    function invalidateCaches($caches = null) {
        global $OK;
        $inserts = array();
        $removes = array();
        $time = time();
        if ($caches ? isset($caches["pc"]) : $this->setting("pc") > 0) {
            if (!$caches || $caches["pc"]) {
                $inserts[] = "('pc',$time)";
                $this->settings["pc"] = $time;
            } else {
                $removes[] = "'pc'";
                unset($this->settings["pc"]);
            }
        }
        $ok = true;
        if (count($inserts))
            $ok = $ok && ($this->qe("insert into Settings (name, value) values " . join(",", $inserts) . " on duplicate key update value=values(value)") !== false);
        if (count($removes))
            $ok = $ok && ($this->qe("delete from Settings where name in (" . join(",", $removes) . ")") !== false);
        return $ok;
    }

    function qx($query) {
        return $this->dblink->query($query);
    }

    function ql($query) {
        $result = $this->dblink->query($query);
        if (!$result)
            error_log(caller_landmark() . ": " . $this->dblink->error);
        return $result;
    }

    function q($query) {
        global $OK;
        $result = $this->dblink->query($query);
        if ($result === false)
            $OK = false;
        return $result;
    }

    function db_error_html($getdb = true, $while = "") {
        global $Opt;
        $text = "<p>Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . htmlspecialchars($this->dblink->error);
        return $text . "</p>";
    }

    function db_error_text($getdb = true, $while = "") {
        $text = "Database error";
        if ($while)
            $text .= " $while";
        if ($getdb)
            $text .= ": " . $this->dblink->error;
        return $text;
    }

    function query_error_handler($dblink, $query) {
        global $OK;
        if (PHP_SAPI == "cli")
            fwrite(STDERR, caller_landmark(1, "/^(?:Dbl::|Conference::q|call_user_func)/") . ": database error: $dblink->error in $query\n");
        else
            $this->errorMsg($this->db_error_html(true, Ht::pre_text_wrap($query)));
        $OK = false;
    }

    function qe($query, $while = "", $suggestRetry = false) {
        global $OK;
        if ($while || $suggestRetry)
            error_log(caller_landmark() . ": bad call to Conference::qe");
        $result = $this->dblink->query($query);
        if ($result === false) {
            if (PHP_SAPI == "cli")
                fwrite(STDERR, caller_landmark() . ": " . $this->db_error_text(true, "[$query]") . "\n");
            else
                $this->errorMsg($this->db_error_html(true, Ht::pre_text_wrap($query)));
            $OK = false;
        }
        return $result;
    }


    // times

    function printableInterval($amt) {
        if ($amt > 259200 /* 3 days */) {
            $amt = ceil($amt / 86400);
            $what = "day";
        } else if ($amt > 28800 /* 8 hours */) {
            $amt = ceil($amt / 3600);
            $what = "hour";
        } else if ($amt > 3600 /* 1 hour */) {
            $amt = ceil($amt / 1800) / 2;
            $what = "hour";
        } else if ($amt > 180) {
            $amt = ceil($amt / 60);
            $what = "minute";
        } else if ($amt > 0) {
            $amt = ceil($amt);
            $what = "second";
        } else
            return "past";
        return plural($amt, $what);
    }

    static function _dateFormat($long) {
        global $Opt;
        if (!isset($Opt["_dateFormatInitialized"])) {
            if (!isset($Opt["time24hour"]) && isset($Opt["time24Hour"]))
                $Opt["time24hour"] = $Opt["time24Hour"];
            if (!isset($Opt["dateFormatLong"]) && isset($Opt["dateFormat"]))
                $Opt["dateFormatLong"] = $Opt["dateFormat"];
            if (!isset($Opt["dateFormat"])) {
                if (isset($Opt["time24hour"]) && $Opt["time24hour"])
                    $Opt["dateFormat"] = "j M Y H:i:s";
                else
                    $Opt["dateFormat"] = "j M Y g:i:sa";
            }
            if (!isset($Opt["dateFormatLong"]))
                $Opt["dateFormatLong"] = "l " . $Opt["dateFormat"];
            if (!isset($Opt["timestampFormat"]))
                $Opt["timestampFormat"] = $Opt["dateFormat"];
            if (!isset($Opt["dateFormatSimplifier"])) {
                if (isset($Opt["time24hour"]) && $Opt["time24hour"])
                    $Opt["dateFormatSimplifier"] = "/:00(?!:)/";
                else
                    $Opt["dateFormatSimplifier"] = "/:00(?::00|)(?= ?[ap]m)/";
            }
            if (!isset($Opt["dateFormatTimezone"]))
                $Opt["dateFormatTimezone"] = null;
            $Opt["_dateFormatInitialized"] = true;
        }
        if ($long == "timestamp")
            return $Opt["timestampFormat"];
        else if ($long)
            return $Opt["dateFormatLong"];
        else
            return $Opt["dateFormat"];
    }

    function parseableTime($value, $include_zone) {
        global $Opt;
        $f = self::_dateFormat(false);
        $d = date($f, $value);
        if ($Opt["dateFormatSimplifier"])
            $d = preg_replace($Opt["dateFormatSimplifier"], "", $d);
        if ($include_zone) {
            if ($Opt["dateFormatTimezone"] === null)
                $d .= " " . date("T", $value);
            else if ($Opt["dateFormatTimezone"])
                $d .= " " . $Opt["dateFormatTimezone"];
        }
        return $d;
    }
    function parse_time($d, $reference = null) {
        global $Now, $Opt;
        if ($reference === null)
            $reference = $Now;
        if (!isset($Opt["dateFormatTimezoneRemover"])
            && function_exists("timezone_abbreviations_list")) {
            $mytz = date_default_timezone_get();
            $x = array();
            foreach (timezone_abbreviations_list() as $tzname => $tzinfo) {
                foreach ($tzinfo as $tz)
                    if ($tz["timezone_id"] == $mytz)
                        $x[] = preg_quote($tzname);
            }
            if (count($x) == 0)
                $x[] = preg_quote(date("T", $reference));
            $Opt["dateFormatTimezoneRemover"] =
                "/(?:\\s|\\A)(?:" . join("|", $x) . ")(?:\\s|\\z)/i";
        }
        if (@$Opt["dateFormatTimezoneRemover"])
            $d = preg_replace($Opt["dateFormatTimezoneRemover"], " ", $d);
        $d = preg_replace('/\butc([-+])/i', 'GMT$1', $d);
        return strtotime($d, $reference);
    }

    function _printableTime($value, $long, $useradjust, $preadjust = null) {
        global $Opt;
        if ($value <= 0)
            return "N/A";
        $t = date(self::_dateFormat($long), $value);
        if ($Opt["dateFormatSimplifier"])
            $t = preg_replace($Opt["dateFormatSimplifier"], "", $t);
        if ($Opt["dateFormatTimezone"] === null)
            $t .= " " . date("T", $value);
        else if ($Opt["dateFormatTimezone"])
            $t .= " " . $Opt["dateFormatTimezone"];
        if ($preadjust)
            $t .= $preadjust;
        if ($useradjust) {
            $sp = strpos($useradjust, " ");
            $t .= "<$useradjust class=\"usertime\" id=\"usertime$this->usertimeId\" style=\"display:none\"></" . ($sp ? substr($useradjust, 0, $sp) : $useradjust) . ">";
            Ht::stash_script("setLocalTime('usertime$this->usertimeId',$value)");
            ++$this->usertimeId;
        }
        return $t;
    }
    function printableTime($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, true, $useradjust, $preadjust);
    }
    function printableTimestamp($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, "timestamp", $useradjust, $preadjust);
    }
    function printableTimeShort($value, $useradjust = false, $preadjust = null) {
        return $this->_printableTime($value, false, $useradjust, $preadjust);
    }
    function unparse_time_log($value) {
        return date("d/M/Y:H:i:s O", $value);
    }

    function printableTimeSetting($what, $useradjust = false, $preadjust = null) {
        return $this->printableTime(defval($this->settings, $what, 0), $useradjust, $preadjust);
    }
    function printableDeadlineSetting($what, $useradjust = false, $preadjust = null) {
        if (!isset($this->settings[$what]) || $this->settings[$what] <= 0)
            return "No deadline";
        else
            return "Deadline: " . $this->printableTime($this->settings[$what], $useradjust, $preadjust);
    }

    function settingsAfter($name) {
        global $Now;
        $t = @$this->settings[$name];
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesAfter($name, $grace = null) {
        global $Now;
        $t = @$this->settings[$name];
        if ($t !== null && $t > 0 && $grace && ($g = @$this->settings[$grace]))
            $t += $grace;
        return $t !== null && $t > 0 && $t <= $Now;
    }
    function deadlinesBetween($name1, $name2, $grace = null) {
        global $Now;
        $t = @$this->settings[$name1];
        if (($t === null || $t <= 0 || $t > $Now) && $name1)
            return false;
        $t = @$this->settings[$name2];
        if ($t !== null && $t > 0 && $grace && ($g = @$this->settings[$grace]))
            $t += $grace;
        return $t === null || $t <= 0 || $t >= $Now;
    }


    function cacheableImage($name, $alt, $title = null, $class = null, $style = null) {
        global $ConfSiteBase, $ConfSitePATH;
        $t = "<img src='${ConfSiteBase}images/$name' alt=\"$alt\"";
        if ($title)
            $t .= " title=\"$title\"";
        if ($class)
            $t .= " class=\"$class\"";
        if ($style)
            $t .= " style=\"$style\"";
        return $t . " />";
    }

    function echoScript($script) {
        if ($this->scriptStuff)
            echo $this->scriptStuff;
        $this->scriptStuff = "";
        if ($script)
            echo "<script>", $script, "</script>";
    }

    function footerScript($script, $uniqueid = null) {
        Ht::stash_script($script, $uniqueid);
    }

    function footerHtml($html, $uniqueid = null) {
        Ht::stash_html($html, $uniqueid);
    }


    //
    // Paper storage
    //

    function storeDocument($uploadId, $paperId, $documentType) {
        return DocumentHelper::upload(new HotCRPDocument($documentType),
                                      $uploadId,
                                      (object) array("paperId" => $paperId));
    }

    function storePaper($uploadId, $prow, $final) {
        global $Opt;
        $paperId = (is_numeric($prow) ? $prow : $prow->paperId);

        $doc = $this->storeDocument($uploadId, $paperId, $final ? DTYPE_FINAL : DTYPE_SUBMISSION);
        if (isset($doc->error_html)) {
            $this->errorMsg($doc->error_html);
            return false;
        }

        if (!$this->qe("update Paper set "
                . ($final ? "finalPaperStorageId" : "paperStorageId") . "=" . $doc->paperStorageId
                . ", size=" . $doc->size
                . ", mimetype='" . sqlq($doc->mimetype)
                . "', timestamp=" . $doc->timestamp
                . ", sha1='" . sqlq($doc->sha1)
                . "' where paperId=$paperId and timeWithdrawn<=0"))
            return false;

        return $doc->size;
    }

    function downloadPaperName($paperId, $mimetype, $documentType) {
        global $Opt;
        $title = $Opt["downloadPrefix"];
        $dtn = HotCRPDocument::unparse_dtype($documentType);
        $title .= ($dtn ? $dtn : "xxx");
        if (ctype_digit($title[strlen($title) - 1]))
            $title .= "-";
        return $title . $paperId . Mimetype::extension($mimetype);
    }

    function document_result($prow, $documentType, $docid = null) {
        global $Opt;
        if (is_array($prow) && count($prow) <= 1)
            $prow = (count($prow) ? $prow[0] : -1);
        if (is_numeric($prow))
            $paperMatch = "=" . $prow;
        else if (is_array($prow))
            $paperMatch = " in (" . join(",", $prow) . ")";
        else
            $paperMatch = "=" . $prow->paperId;
        $q = "select p.paperId, s.mimetype, s.sha1, s.timestamp, ";
        if (!@$Opt["docstore"] && !is_array($prow))
            $q .= "s.paper as content, ";
        $q .= "s.filename, s.infoJson, $documentType documentType, s.paperStorageId from Paper p";
        if ($docid)
            $sjoin = $docid;
        else if ($documentType == DTYPE_SUBMISSION)
            $sjoin = "p.paperStorageId";
        else if ($documentType == DTYPE_FINAL)
            $sjoin = "p.finalPaperStorageId";
        else {
            $q .= " left join PaperOption o on (o.paperId=p.paperId and o.optionId=$documentType)";
            $sjoin = "o.value";
        }
        return $this->q($q . " left join PaperStorage s on (s.paperStorageId=$sjoin) where p.paperId$paperMatch");
    }

    function document_row($result, $dtype = DTYPE_SUBMISSION) {
        if (!($doc = edb_orow($result)))
            return $doc;
        // type doesn't matter
        if ($dtype === null && isset($doc->documentType))
            $dtype = $doc->documentType = (int) $doc->documentType;
        $doc->docclass = new HotCRPDocument($dtype);
        // in modern versions sha1 is set at storage time; before it wasn't
        if ($doc->paperStorageId && $doc->sha1 == "") {
            if (!$doc->docclass->load_content($doc))
                return false;
            $doc->sha1 = sha1($doc->content, true);
            $this->q("update PaperStorage set sha1='" . sqlq($doc->sha1) . "' where paperStorageId=" . $doc->paperStorageId);
        }
        return $doc;
    }

    private function __downloadPaper($paperId, $attachment, $documentType, $docid) {
        global $Opt, $Me, $zlib_output_compression;

        $result = $this->document_result($paperId, $documentType, $docid);
        if (!$result) {
            $this->log("Download error: " . $this->dblink->error, $Me, $paperId);
            return set_error_html("Database error while downloading paper.");
        } else if (edb_nrows($result) == 0)
            return set_error_html("No such document.");

        // Check data
        $docs = array();
        while (($doc = $this->document_row($result, $documentType))) {
            if (!$doc->mimetype)
                $doc->mimetype = MIMETYPEID_PDF;
            $doc->filename = HotCRPDocument::filename($doc);
            $docs[] = $doc;
        }
        if (count($docs) == 1 && $docs[0]->paperStorageId <= 1)
            return set_error_html("Paper #" . $docs[0]->paperId . " hasn’t been uploaded yet.");
        $downloadname = false;
        if (count($docs) > 1)
            $downloadname = $Opt["downloadPrefix"] . pluralx(2, HotCRPDocument::unparse_dtype($documentType)) . ".zip";
        return DocumentHelper::download($docs, $downloadname, $attachment);
    }

    function downloadPaper($paperId, $attachment, $documentType = DTYPE_SUBMISSION, $docid = null) {
        global $Me;
        $result = $this->__downloadPaper($paperId, $attachment, $documentType, $docid);
        if ($result->error) {
            $this->errorMsg($result->error_html);
            return false;
        } else
            return true;
    }


    //
    // Paper search
    //

    static private function _cvt_numeric_set($optarr) {
        $ids = array();
        foreach (mkarray($optarr) as $x)
            if (($x = cvtint($x)) > 0)
                $ids[] = $x;
        return $ids;
    }

    function query_all_reviewer_preference() {
        if ($this->sversion >= 69)
            return "group_concat(concat(contactId,' ',preference,' ',coalesce(expertise,'.')) separator ',')";
        else
            return "group_concat(concat(contactId,' ',preference,' .') separator ',')";
    }

    function query_topic_interest($table = "") {
        if ($this->sversion >= 73)
            return $table . "interest";
        else
            return "if(" . $table . "interest=2,4,(" . $table . "interest-1)*2)";
    }

    function query_topic_interest_score() {
        if ($this->sversion >= 73)
            return "interest";
        else
            return "(if(interest=2,2,interest-1)*2)";
    }

    function paperQuery($contact, $options = array()) {
        // Options:
        //   "paperId" => $pid  Only paperId $pid (if array, any of those)
        //   "reviewId" => $rid Only paper reviewed by $rid
        //   "commentId" => $c  Only paper where comment is $c
        //   "finalized"        Only submitted papers
        //   "unsub"            Only unsubmitted papers
        //   "accepted"         Only accepted papers
        //   "active"           Only nonwithdrawn papers
        //   "author"           Only papers authored by $contactId
        //   "myReviewRequests" Only reviews requested by $contactId
        //   "myReviews"        All reviews authored by $contactId
        //   "myOutstandingReviews" All unsubmitted reviews auth by $contactId
        //   "myReviewsOpt"     myReviews, + include papers not yet reviewed
        //   "allReviews"       All reviews (multiple rows per paper)
        //   "allReviewScores"  All review scores (multiple rows per paper)
        //   "allComments"      All comments (multiple rows per paper)
        //   "reviewerName"     Include reviewer names
        //   "commenterName"    Include commenter names
        //   "reviewer" => $cid Include reviewerConflictType/reviewerReviewType
        //   "tags"             Include paperTags
        //   "tagIndex" => $tag Include tagIndex of named tag
        //   "tagIndex" => tag array -- include tagIndex, tagIndex1, ...
        //   "topics"
        //   "options"
        //   "scores" => array(fields to score)
        //   "order" => $sql    $sql is SQL 'order by' clause (or empty)

        $reviewerQuery = isset($options["myReviews"]) || isset($options["allReviews"]) || isset($options["myReviewRequests"]) || isset($options["myReviewsOpt"]) || isset($options["myOutstandingReviews"]);
        $allReviewerQuery = isset($options["allReviews"]) || isset($options["allReviewScores"]);
        $scoresQuery = !$reviewerQuery && isset($options["allReviewScores"]);
        if (is_object($contact))
            $contactId = $contact->contactId;
        else {
            $contactId = (int) $contact;
            $contact = null;
        }
        if (isset($options["reviewer"]) && is_object($options["reviewer"]))
            $reviewerContactId = $options["reviewer"]->contactId;
        else if (isset($options["reviewer"]))
            $reviewerContactId = $options["reviewer"];
        else
            $reviewerContactId = $contactId;
        if (@$options["author"])
            $myPaperReview = null;
        else if ($allReviewerQuery)
            $myPaperReview = "MyPaperReview";
        else
            $myPaperReview = "PaperReview";

        // paper selection
        $paperset = array();
        if (isset($options["paperId"]))
            $paperset[] = self::_cvt_numeric_set($options["paperId"]);
        if (isset($options["reviewId"])) {
            if (is_numeric($options["reviewId"])) {
                $result = Dbl::qe("select paperId from PaperReview where reviewId=" . $options["reviewId"]);
                $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
            } else if (preg_match('/^(\d+)([A-Z][A-Z]?)$/i', $options["reviewId"], $m)) {
                $result = Dbl::qe("select paperId from PaperReview where paperId=$m[1] and reviewOrdinal=" . parseReviewOrdinal($m[2]));
                $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
            } else
                $paperset[] = array();
        }
        if (isset($options["commentId"])) {
            $result = Dbl::qe("select paperId from PaperComment where commentId" . sql_in_numeric_set(self::_cvt_numeric_set($options["commentId"])));
            $paperset[] = self::_cvt_numeric_set(edb_first_columns($result));
        }
        if (count($paperset) > 1)
            $paperset = array(call_user_func_array("array_intersect", $paperset));
        $papersel = "";
        if (count($paperset))
            $papersel = "paperId" . sql_in_numeric_set($paperset[0]) . " and ";

        // prepare query: basic tables
        $where = array();

        $joins = array("Paper");

        $cols = array("Paper.*, PaperConflict.conflictType");

        $aujoinwhere = null;
        if (@$options["author"] && $contact
            && ($aujoinwhere = $contact->actAuthorSql("PaperConflict", true)))
            $where[] = $aujoinwhere;
        if (@$options["author"] && !$aujoinwhere)
            $joins[] = "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")";
        else
            $joins[] = "left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId)";

        // my review
        $qr = "";
        if ($contact && ($tokens = $contact->review_tokens()))
            $qr = " or PaperReview.reviewToken in (" . join(", ", $tokens) . ")";
        if (@$options["myReviewRequests"])
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=$contactId and PaperReview.reviewType=" . REVIEW_EXTERNAL . ")";
        else if (@$options["myReviews"])
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))";
        else if (@$options["myOutstandingReviews"])
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr) and PaperReview.reviewNeedsSubmit!=0)";
        else if (@$options["myReviewsOpt"])
            $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))";
        else if (@$options["allReviews"] || @$options["allReviewScores"]) {
            $x = (@$options["reviewLimitSql"] ? " and (" . $options["reviewLimitSql"] . ")" : "");
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId$x)";
        } else if (!@$options["author"])
            $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))";

        // all reviews
        $joins[] = "left join (select paperId, count(*) count from PaperReview where {$papersel}(reviewSubmitted or reviewNeedsSubmit>0) group by paperId) R_started on (R_started.paperId=Paper.paperId)";
        $cols[] = "coalesce(R_started.count,0) startedReviewCount";

        $j = "select paperId, count(*) count";
        $cols[] = "coalesce(R_submitted.count,0) reviewCount";
        if (@$options["scores"])
            foreach ($options["scores"] as $fid) {
                $cols[] = "R_submitted.{$fid}Scores";
                if ($myPaperReview)
                    $cols[] = "$myPaperReview.$fid";
                $j .= ", group_concat($fid order by reviewId) {$fid}Scores";
            }
        if (@$options["reviewTypes"]) {
            $cols[] = "R_submitted.reviewTypes";
            $j .= ", group_concat(reviewType order by reviewId) reviewTypes";
        }
        if (@$options["reviewTypes"] || @$options["scores"]) {
            $cols[] = "R_submitted.reviewContactIds";
            $j .= ", group_concat(contactId order by reviewId) reviewContactIds";
        }
        $joins[] = "left join ($j from PaperReview where {$papersel}reviewSubmitted>0 group by paperId) R_submitted on (R_submitted.paperId=Paper.paperId)";

        // fields
        if (@$options["author"])
            $cols[] = "null reviewType, null reviewId, null myReviewType";
        else {
            // see also papercolumn.php
            array_push($cols, "PaperReview.reviewType, PaperReview.reviewId",
                       "PaperReview.reviewModified, PaperReview.reviewSubmitted",
                       "PaperReview.reviewNeedsSubmit, PaperReview.reviewOrdinal",
                       "PaperReview.reviewBlind, PaperReview.reviewToken",
                       "PaperReview.contactId as reviewContactId, PaperReview.requestedBy",
                       "max($myPaperReview.reviewType) as myReviewType",
                       "max($myPaperReview.reviewSubmitted) as myReviewSubmitted",
                       "min($myPaperReview.reviewNeedsSubmit) as myReviewNeedsSubmit",
                       "$myPaperReview.contactId as myReviewContactId",
                       "PaperReview.reviewRound");
        }

        if ($reviewerQuery || $scoresQuery) {
            $cols[] = "PaperReview.reviewEditVersion as reviewEditVersion";
            foreach (ReviewForm::field_list_all_rounds() as $f)
                if ($reviewerQuery || $f->has_options)
                    $cols[] = "PaperReview.$f->id as $f->id";
        }

        if ($myPaperReview == "MyPaperReview")
            $joins[] = "left join PaperReview as MyPaperReview on (MyPaperReview.paperId=Paper.paperId and MyPaperReview.contactId=$contactId)";

        if (@$options["topics"] || @$options["topicInterestScore"]) {
            $j = "left join (select paperId";
            if (@$options["topics"]) {
                $j .= ", group_concat(PaperTopic.topicId) as topicIds, group_concat(ifnull(" . $this->query_topic_interest("TopicInterest.") . ",0)) as topicInterest";
                $cols[] = "PaperTopics.topicIds, PaperTopics.topicInterest";
            }
            if (@$options["topicInterestScore"]) {
                $j .= ", sum(" . $this->query_topic_interest_score() . ") as topicInterestScore";
                $cols[] = "coalesce(PaperTopics.topicInterestScore,0) as topicInterestScore";
            }
            $j .= " from PaperTopic left join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=$reviewerContactId) where {$papersel}true group by paperId) as PaperTopics on (PaperTopics.paperId=Paper.paperId)";
            $joins[] = $j;
        }

        if (@$options["options"] && @$this->settingTexts["options"]) {
            $joins[] = "left join (select paperId, group_concat(PaperOption.optionId, '#', value) as optionIds from PaperOption where {$papersel}true group by paperId) as PaperOptions on (PaperOptions.paperId=Paper.paperId)";
            $cols[] = "PaperOptions.optionIds";
        } else if (@$options["options"])
            $cols[] = "'' as optionIds";

        if (@$options["tags"]) {
            $joins[] = "left join (select paperId, group_concat(' ', tag, '#', tagIndex order by tag separator '') as paperTags from PaperTag where {$papersel}true group by paperId) as PaperTags on (PaperTags.paperId=Paper.paperId)";
            $cols[] = "PaperTags.paperTags";
        }
        if (@$options["tagIndex"] && !is_array($options["tagIndex"]))
            $options["tagIndex"] = array($options["tagIndex"]);
        if (@$options["tagIndex"])
            for ($i = 0; $i < count($options["tagIndex"]); ++$i) {
                $joins[] = "left join PaperTag as TagIndex$i on (TagIndex$i.paperId=Paper.paperId and TagIndex$i.tag='" . sqlq($options["tagIndex"][$i]) . "')";
                $cols[] = "TagIndex$i.tagIndex as tagIndex" . ($i ? : "");
            }

        if (@$options["reviewerPreference"]) {
            $joins[] = "left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$reviewerContactId)";
            $cols[] = "coalesce(PaperReviewPreference.preference, 0) as reviewerPreference";
            if ($this->sversion >= 69)
                $cols[] = "PaperReviewPreference.expertise as reviewerExpertise";
            else
                $cols[] = "NULL as reviewerExpertise";
        }

        if (@$options["allReviewerPreference"] || @$options["desirability"]) {
            $subq = "select paperId";
            if (@$options["allReviewerPreference"]) {
                $subq .= ", " . $this->query_all_reviewer_preference() . " as allReviewerPreference";
                $cols[] = "APRP.allReviewerPreference";
            }
            if (@$options["desirability"]) {
                $subq .= ", sum(if(preference<=-100,0,greatest(least(preference,1),-1))) as desirability";
                $cols[] = "coalesce(APRP.desirability,0) as desirability";
            }
            $subq .= " from PaperReviewPreference where {$papersel}true group by paperId";
            $joins[] = "left join ($subq) as APRP on (APRP.paperId=Paper.paperId)";
        }

        if (@$options["allConflictType"]) {
            $joins[] = "left join (select paperId, group_concat(concat(contactId,' ',conflictType) separator ',') as allConflictType from PaperConflict where {$papersel}conflictType>0 group by paperId) as AllConflict on (AllConflict.paperId=Paper.paperId)";
            $cols[] = "AllConflict.allConflictType";
        }

        if (@$options["reviewer"]) {
            $joins[] = "left join PaperConflict RPC on (RPC.paperId=Paper.paperId and RPC.contactId=$reviewerContactId)";
            $joins[] = "left join PaperReview RPR on (RPR.paperId=Paper.paperId and RPR.contactId=$reviewerContactId)";
            $cols[] = "RPC.conflictType reviewerConflictType, RPR.reviewType reviewerReviewType";
        }

        if (@$options["allComments"]) {
            $joins[] = "join PaperComment on (PaperComment.paperId=Paper.paperId)";
            $joins[] = "left join PaperConflict as CommentConflict on (CommentConflict.paperId=PaperComment.paperId and CommentConflict.contactId=PaperComment.contactId)";
            array_push($cols, "PaperComment.commentId, PaperComment.contactId as commentContactId",
                       "CommentConflict.conflictType as commentConflictType",
                       "PaperComment.timeModified, PaperComment.comment",
                       "PaperComment.replyTo, PaperComment.commentType");
        }

        if (@$options["reviewerName"]) {
            if (@$options["reviewerName"] === "lead" || @$options["reviewerName"] === "shepherd")
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=Paper.{$options['reviewerName']}ContactId)";
            else if (@$options["allComments"])
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperComment.contactId)";
            else if (@$options["reviewerName"])
                $joins[] = "left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperReview.contactId)";
            array_push($cols, "ReviewerContactInfo.firstName as reviewFirstName",
                       "ReviewerContactInfo.lastName as reviewLastName",
                       "ReviewerContactInfo.email as reviewEmail",
                       "ReviewerContactInfo.lastLogin as reviewLastLogin");
        }

        if (@$options["foldall"])
            $cols[] = "1 as folded";

        // conditions
        if (count($paperset))
            $where[] = "Paper.paperId" . sql_in_numeric_set($paperset[0]);
        if (@$options["finalized"])
            $where[] = "timeSubmitted>0";
        else if (@$options["unsub"])
            $where[] = "timeSubmitted<=0";
        if (@$options["accepted"])
            $where[] = "outcome>0";
        if (@$options["undecided"])
            $where[] = "outcome=0";
        if (@$options["active"] || @$options["myReviews"]
            || @$options["myReviewRequests"])
            $where[] = "timeWithdrawn<=0";
        if (@$options["myLead"])
            $where[] = "leadContactId=$contactId";
        if (@$options["unmanaged"])
            $where[] = "managerContactId=0";

        $pq = "select " . join(",\n    ", $cols)
            . "\nfrom " . join("\n    ", $joins);
        if (count($where))
            $pq .= "\nwhere " . join("\n    and ", $where);

        // grouping and ordering
        if (@$options["allComments"])
            $pq .= "\ngroup by Paper.paperId, PaperComment.commentId";
        else if ($reviewerQuery || $scoresQuery)
            $pq .= "\ngroup by Paper.paperId, PaperReview.reviewId";
        else
            $pq .= "\ngroup by Paper.paperId";
        if (@$options["order"] && $options["order"] != "order by Paper.paperId")
            $pq .= "\n" . $options["order"];
        else {
            $pq .= "\norder by Paper.paperId";
            if ($reviewerQuery || $scoresQuery)
                $pq .= ", PaperReview.reviewOrdinal";
            if (isset($options["allComments"]))
                $pq .= ", PaperComment.commentId";
        }

        //$this->infoMsg(Ht::pre_text_wrap($pq));
        return $pq . "\n";
    }

    function paperRow($sel, $contact, &$whyNot = null) {
        $whyNot = array();
        if (!is_array($sel))
            $sel = array("paperId" => $sel);
        if (isset($sel["paperId"]))
            $whyNot["paperId"] = $sel["paperId"];
        if (isset($sel["reviewId"]))
            $whyNot["reviewId"] = $sel["reviewId"];

        if (isset($sel['paperId']) && cvtint($sel['paperId']) < 0)
            $whyNot['invalidId'] = 'paper';
        else if (isset($sel['reviewId']) && cvtint($sel['reviewId']) < 0
                 && !preg_match('/^\d+[A-Z][A-Z]?$/i', $sel['reviewId']))
            $whyNot['invalidId'] = 'review';
        else {
            $q = $this->paperQuery($contact, $sel);
            $result = $this->q($q);

            if (!$result)
                $whyNot['dbError'] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            else if (edb_nrows($result) == 0)
                $whyNot['noPaper'] = 1;
            else
                return PaperInfo::fetch($result, $contact);
        }

        return null;
    }

    function review_rows($q, $contact) {
        $result = $this->qe($q);
        $rrows = array();
        while (($row = PaperInfo::fetch($result, $contact)))
            $rrows[$row->reviewId] = $row;
        return $rrows;
    }

    function comment_query($where) {
        return "select PaperComment.*, firstName reviewFirstName, lastName reviewLastName, email reviewEmail
                from PaperComment join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
                where $where order by commentId";
    }

    function comment_rows($q, $contact) {
        $result = $this->qe($q);
        $crows = array();
        while (($row = PaperInfo::fetch($result, $contact))) {
            $crows[$row->commentId] = $row;
            if (isset($row->commentContactId))
                $cid = $row->commentContactId;
            else
                $cid = $row->contactId;
            $row->threadContacts = array($cid => 1);
            for ($r = $row; defval($r, "replyTo", 0) && isset($crows[$r->replyTo]); $r = $crows[$r->replyTo])
                /* do nothing */;
            $row->threadHead = $r->commentId;
            $r->threadContacts[$cid] = 1;
        }
        foreach ($crows as $row)
            if ($row->threadHead != $row->commentId)
                $row->threadContacts = $crows[$row->threadHead]->threadContacts;
        return $crows;
    }


    function reviewRow($selector, &$whyNot = null) {
        $whyNot = array();

        if (!is_array($selector))
            $selector = array('reviewId' => $selector);
        if (isset($selector['reviewId'])) {
            $whyNot['reviewId'] = $selector['reviewId'];
            if (($reviewId = cvtint($selector['reviewId'])) <= 0) {
                $whyNot['invalidId'] = 'review';
                return null;
            }
        }
        if (isset($selector['paperId'])) {
            $whyNot['paperId'] = $selector['paperId'];
            if (($paperId = cvtint($selector['paperId'])) <= 0) {
                $whyNot['invalidId'] = 'paper';
                return null;
            }
        }

        $q = "select PaperReview.*,
                ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email, ContactInfo.roles as contactRoles,
                ContactInfo.contactTags,
                ReqCI.firstName as reqFirstName, ReqCI.lastName as reqLastName, ReqCI.email as reqEmail";
        if (isset($selector["ratings"]))
            $q .= ",
                group_concat(ReviewRating.rating order by ReviewRating.rating desc) as allRatings,
                count(ReviewRating.rating) as numRatings";
        if (isset($selector["myRating"]))
            $q .= ",
                MyRating.rating as myRating";
        $q .= "\n               from PaperReview
                join ContactInfo using (contactId)
                left join ContactInfo as ReqCI on (ReqCI.contactId=PaperReview.requestedBy)\n";
        if (isset($selector["ratings"]))
            $q .= "             left join ReviewRating on (ReviewRating.reviewId=PaperReview.reviewId)\n";
        if (isset($selector["myRating"]))
            $q .= "             left join ReviewRating as MyRating on (MyRating.reviewId=PaperReview.reviewId and MyRating.contactId=" . $selector["myRating"] . ")\n";

        $where = array();
        $order = array("paperId");
        if (isset($reviewId))
            $where[] = "PaperReview.reviewId=$reviewId";
        if (isset($paperId))
            $where[] = "PaperReview.paperId=$paperId";
        $cwhere = array();
        if (isset($selector["contactId"]))
            $cwhere[] = "PaperReview.contactId=" . cvtint($selector["contactId"]);
        if (@$selector["rev_tokens"])
            $cwhere[] = "PaperReview.reviewToken in (" . join(",", $selector["rev_tokens"]) . ")";
        if (count($cwhere))
            $where[] = "(" . join(" or ", $cwhere) . ")";
        if (count($cwhere) > 1)
            $order[] = "(PaperReview.contactId=" . cvtint($selector["contactId"]) . ") desc";
        if (isset($selector['reviewOrdinal']))
            $where[] = "PaperReview.reviewSubmitted>0 and reviewOrdinal=" . cvtint($selector['reviewOrdinal']);
        else if (isset($selector['submitted']))
            $where[] = "PaperReview.reviewSubmitted>0";
        if (!count($where)) {
            $whyNot['internal'] = 1;
            return null;
        }

        $q = $q . " where " . join(" and ", $where) . " group by PaperReview.reviewId
                order by " . join(", ", $order) . ", reviewOrdinal, reviewType desc, reviewId";

        $result = $this->q($q);
        if (!$result) {
            $whyNot['dbError'] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
            return null;
        }

        $x = array();
        while (($row = edb_orow($result)))
            $x[] = $row;

        if (isset($selector["array"]))
            return $x;
        else if (count($x) == 1 || defval($selector, "first"))
            return @$x[0];
        if (count($x) == 0)
            $whyNot['noReview'] = 1;
        else
            $whyNot['multipleReviews'] = 1;
        return null;
    }


    // Activity

    private static function _flowQueryWheres(&$where, $table, $t0) {
        $time = $table . ($table == "PaperReview" ? ".reviewSubmitted" : ".timeModified");
        if (is_array($t0))
            $where[] = "($time<$t0[0] or ($time=$t0[0] and $table.contactId>$t0[1]) or ($time=$t0[0] and $table.contactId=$t0[1] and $table.paperId>$t0[2]))";
        else if ($t0)
            $where[] = "$time<$t0";
    }

    private function _flowQueryRest() {
        return "          Paper.title,
                substring(Paper.title from 1 for 80) as shortTitle,
                Paper.timeSubmitted,
                Paper.timeWithdrawn,
                Paper.blind as paperBlind,
                Paper.outcome,
                Paper.managerContactId,
                ContactInfo.firstName as reviewFirstName,
                ContactInfo.lastName as reviewLastName,
                ContactInfo.email as reviewEmail,
                PaperConflict.conflictType,
                MyPaperReview.reviewType as myReviewType,
                MyPaperReview.reviewSubmitted as myReviewSubmitted,
                MyPaperReview.reviewNeedsSubmit as myReviewNeedsSubmit,
                MyPaperReview.contactId as myReviewContactId\n";
    }

    private function _commentFlowQuery($contact, $t0, $limit) {
        // XXX review tokens
        $q = "select PaperComment.*,
                substring(PaperComment.comment from 1 for 300) as shortComment,\n"
            . $this->_flowQueryRest()
            . "\t\tfrom PaperComment
                join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
                join Paper on (Paper.paperId=PaperComment.paperId)
                left join PaperConflict on (PaperConflict.paperId=PaperComment.paperId and PaperConflict.contactId=$contact->contactId)
                left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperComment.paperId and MyPaperReview.contactId=$contact->contactId)\n";
        $where = $contact->canViewCommentReviewWheres();
        self::_flowQueryWheres($where, "PaperComment", $t0);
        if (count($where))
            $q .= " where " . join(" and ", $where);
        $q .= " order by PaperComment.timeModified desc, PaperComment.contactId asc, PaperComment.paperId asc";
        if ($limit)
            $q .= " limit $limit";
        return $q;
    }

    private function _reviewFlowQuery($contact, $t0, $limit) {
        // XXX review tokens
        $q = "select PaperReview.*,\n"
            . $this->_flowQueryRest()
            . "\t\tfrom PaperReview
                join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
                join Paper on (Paper.paperId=PaperReview.paperId)
                left join PaperConflict on (PaperConflict.paperId=PaperReview.paperId and PaperConflict.contactId=$contact->contactId)
                left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperReview.paperId and MyPaperReview.contactId=$contact->contactId)\n";
        $where = $contact->canViewCommentReviewWheres();
        self::_flowQueryWheres($where, "PaperReview", $t0);
        $where[] = "PaperReview.reviewSubmitted>0";
        $q .= " where " . join(" and ", $where);
        $q .= " order by PaperReview.reviewSubmitted desc, PaperReview.contactId asc, PaperReview.paperId asc";
        if ($limit)
            $q .= " limit $limit";
        return $q;
    }

    function _activity_compar($a, $b) {
        if (!$a || !$b)
            return !$a && !$b ? 0 : ($a ? -1 : 1);
        $at = isset($a->timeModified) ? $a->timeModified : $a->reviewSubmitted;
        $bt = isset($b->timeModified) ? $b->timeModified : $b->reviewSubmitted;
        if ($at != $bt)
            return $at > $bt ? -1 : 1;
        else if ($a->contactId != $b->contactId)
            return $a->contactId < $b->contactId ? -1 : 1;
        else if ($a->paperId != $b->paperId)
            return $a->paperId < $b->paperId ? -1 : 1;
        else
            return 0;
    }

    function reviewerActivity($contact, $t0, $limit) {
        // Return the $limit most recent pieces of activity on or before $t0.
        // Requires some care, since comments and reviews are loaded from
        // different queries, and we want to return the results sorted.  So we
        // load $limit comments and $limit reviews -- but if the comments run
        // out before the $limit is reached (because some comments cannot be
        // seen by the current user), we load additional comments & try again,
        // and the same for reviews.

        if ($t0 && preg_match('/\A(\d+)\.(\d+)\.(\d+)\z/', $t0, $m))
            $ct0 = $rt0 = array($m[1], $m[2], $m[3]);
        else
            $ct0 = $rt0 = $t0;
        $activity = array();

        $crows = $rrows = array(); // comment/review rows being worked through
        $curcr = $currr = null;    // current comment/review row
        // We read new comment/review rows when the current set is empty.

        while (count($activity) < $limit) {
            // load $curcr with most recent viewable comment
            if ($curcr)
                /* do nothing */;
            else if (($curcr = array_pop($crows))) {
                if (!$contact->can_view_comment($curcr, $curcr, false)) {
                    $curcr = null;
                    continue;
                }
            } else if ($ct0) {
                $crows = array_reverse($this->comment_rows(self::_commentFlowQuery($contact, $ct0, $limit), $contact));
                if (count($crows) == $limit)
                    $ct0 = array($crows[0]->timeModified, $crows[0]->contactId, $crows[0]->paperId);
                else
                    $ct0 = null;
                continue;
            }

            // load $currr with most recent viewable review
            if ($currr)
                /* do nothing */;
            else if (($currr = array_pop($rrows))) {
                if (!$contact->can_view_review($currr, $currr, false)) {
                    $currr = null;
                    continue;
                }
            } else if ($rt0) {
                $rrows = array_reverse($this->review_rows(self::_reviewFlowQuery($contact, $rt0, $limit), $contact));
                if (count($rrows) == $limit)
                    $rt0 = array($rrows[0]->reviewSubmitted, $rrows[0]->contactId, $rrows[0]->paperId);
                else
                    $rt0 = null;
                continue;
            }

            // if neither, ran out of activity
            if (!$curcr && !$currr)
                break;

            // otherwise, choose the later one first
            if (self::_activity_compar($curcr, $currr) < 0) {
                $curcr->isComment = true;
                $activity[] = $curcr;
                $curcr = null;
            } else {
                $currr->isComment = false;
                $activity[] = $currr;
                $currr = null;
            }
        }

        return $activity;
    }


    //
    // Message routines
    //

    function msg($text, $type) {
        if (PHP_SAPI == "cli") {
            if ($type === "xmerror" || $type === "merror")
                fwrite(STDERR, "$text\n");
            else if ($type === "xwarning" || $type === "mxwarning"
                     || !defined("HOTCRP_TESTHARNESS"))
                fwrite(STDOUT, "$text\n");
        } else {
            $text = "<div class=\"$type\">$text</div>\n";
            if ($this->save_messages) {
                ensure_session();
                $this->save_session_array("msgs", true, $text);
            } else
                echo $text;
        }
    }

    function infoMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xinfo" : "info");
    }

    function warnMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xwarning" : "warning");
    }

    function confirmMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xconfirm" : "confirm");
    }

    function errorMsg($text, $minimal = false) {
        $this->msg($text, $minimal ? "xmerror" : "merror");
        return false;
    }

    function errorMsgExit($text) {
        if ($text)
            $this->msg($text, "merror");
        $this->footer();
        exit;
    }


    //
    // Conference header, footer
    //

    function make_css_link($url) {
        global $ConfSitePATH, $Opt;
        $t = '<link rel="stylesheet" type="text/css" href="';
        if (str_starts_with($url, "stylesheets/")
            || !preg_match(',\A(?:https?:|/),i', $url))
            $t .= $Opt["assetsUrl"];
        $t .= $url;
        if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
            $t .= "?mtime=$mtime";
        return $t . '" />';
    }

    function make_script_file($url, $no_strict = false) {
        global $ConfSiteBase, $ConfSitePATH, $Opt;
        if (str_starts_with($url, "scripts/")) {
            $post = "";
            if (($mtime = @filemtime("$ConfSitePATH/$url")) !== false)
                $post = "mtime=$mtime";
            if (@$Opt["strictJavascript"] && !$no_strict)
                $url = $Opt["scriptAssetsUrl"] . "cacheable.php?file=" . urlencode($url)
                    . "&strictjs=1" . ($post ? "&$post" : "");
            else
                $url = $Opt["scriptAssetsUrl"] . $url . ($post ? "?$post" : "");
            if ($Opt["scriptAssetsUrl"] === $ConfSiteBase)
                return Ht::script_file($url);
        }
        return Ht::script_file($url, array("crossorigin" => "anonymous"));
    }

    private function header_head($title) {
        global $Me, $ConfSiteBase, $ConfSiteSuffix, $ConfSitePATH,
            $Opt, $CurrentList;
        echo "<!DOCTYPE html>
<html>
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<meta http-equiv=\"Content-Language\" content=\"en\" />
<meta name=\"google\" content=\"notranslate\" />\n";
        if (strstr($title, "<") !== false)
            $title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);

        if (isset($Opt["fontScript"]))
            echo $Opt["fontScript"];

        echo $this->make_css_link("stylesheets/style.css"), "\n";
        if (isset($Opt["stylesheets"]))
            foreach ($Opt["stylesheets"] as $css)
                echo $this->make_css_link($css), "\n";

        // favicon
        if (($favicon = defval($Opt, "favicon"))) {
            if (strpos($favicon, "://") === false && $favicon[0] != "/") {
                if (@$Opt["assetsUrl"] && substr($favicon, 0, 7) === "images/")
                    $favicon = $Opt["assetsUrl"] . $favicon;
                else
                    $favicon = $ConfSiteBase . $favicon;
            }
            if (substr($favicon, -4) == ".png")
                echo "<link rel=\"icon\" type=\"image/png\" href=\"$favicon\" />\n";
            else if (substr($favicon, -4) == ".ico")
                echo "<link rel=\"shortcut icon\" href=\"$favicon\" />\n";
            else if (substr($favicon, -4) == ".gif")
                echo "<link rel=\"icon\" type=\"image/gif\" href=\"$favicon\" />\n";
            else
                echo "<link rel=\"icon\" href=\"$favicon\" />\n";
        }

        // jQuery
        if (isset($Opt["jqueryUrl"]))
            $jquery = $Opt["jqueryUrl"];
        else if (@$Opt["jqueryCdn"])
            $jquery = "//code.jquery.com/jquery-1.11.3.min.js";
        else
            $jquery = "scripts/jquery-1.11.3.min.js";
        $this->scriptStuff = $this->make_script_file($jquery, true) . "\n";

        $this->scriptStuff .= $this->make_script_file("scripts/jquery.color-2.1.2.min.js", true) . "\n";
        $this->scriptStuff .= $this->make_script_file("scripts/jquery.flot.min.js", true) . "\n";
        //$this->scriptStuff .= $this->make_script_file("scripts/ZeroClipboard.min.js", true) . "\n";

        // Javascript settings to set before script.js
        $this->scriptStuff .= "<script>siteurl=\"$ConfSiteBase\";siteurl_suffix=\"$ConfSiteSuffix\"";
        if (session_id() !== "")
            $this->scriptStuff .= ";siteurl_postvalue=\"" . post_value() . "\"";
        if (@$CurrentList
            && ($list = SessionList::lookup($CurrentList)))
            $this->scriptStuff .= ";hotcrp_list={num:$CurrentList,id:\"" . addcslashes($list->listid, "\n\r\\\"/") . "\"}";
        if (($urldefaults = hoturl_defaults()))
            $this->scriptStuff .= ";siteurl_defaults=" . json_encode($urldefaults);
        $huser = (object) array();
        if ($Me && $Me->email)
            $huser->email = $Me->email;
        if ($Me && $Me->is_pclike())
            $huser->is_pclike = true;
        $this->scriptStuff .= ";hotcrp_user=" . json_encode($huser);

        $pid = @$_REQUEST["paperId"];
        $pid = $pid && ctype_digit($pid) ? (int) $pid : 0;
        if ($pid)
            $this->scriptStuff .= ";hotcrp_paperid=$pid";
        if ($pid && $Me && $Me->privChair
            && ($forceShow = @$_REQUEST["forceShow"]) && $forceShow != "0")
            $this->scriptStuff .= ";hotcrp_want_override_conflict=true";
        //$this->scriptStuff .= ";ZeroClipboard.setDefaults({moviePath:\"${ConfSiteBase}cacheable$ConfSiteSuffix?file=scripts/ZeroClipboard.swf&amp;mtime=" . filemtime("$ConfSitePATH/scripts/ZeroClipboard.swf") . "\"})";
        $this->scriptStuff .= "</script>\n";

        // script.js
        $this->scriptStuff .= $this->make_script_file("scripts/script.js") . "\n";

        echo $this->scriptStuff;
        $this->scriptStuff = "";

        echo "<title>", $title, " - ", htmlspecialchars($Opt["shortName"]),
            "</title>\n</head>\n";
    }

    function header($title, $id = "", $actionBar = null, $showTitle = true) {
        global $ConfSiteBase, $ConfSiteSuffix, $ConfSitePATH, $Me, $Now, $Opt;
        if ($this->headerPrinted)
            return;

        // <head>
        $this->header_head($title);

        // <body>
        echo "<body", ($id ? " id='$id'" : ""), " onload='hotcrp_load()'>\n";

        // on load of script.js
        $this->scriptStuff .= "<script>";

        // initial load (JS's timezone offsets are negative of PHP's)
        $this->scriptStuff .= "hotcrp_load.time(" . (-date("Z", $Now) / 60) . "," . (@$Opt["time24hour"] ? 1 : 0) . ")";

        $this->scriptStuff .= "</script>";

        echo "<div id='prebody'>\n";

        echo "<div id='header'>\n<div id='header_left_conf'><h1>";
        if ($title && $showTitle && ($title == "Home" || $title == "Sign in"))
            echo "<a name='' class='qq' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($Opt["shortName"]), "</a>";
        else
            echo "<a name='' class='uu' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($Opt["shortName"]), "</a></h1></div><div id='header_left_page'><h1>", $title;
        echo "</h1></div><div id='header_right'>";
        if ($Me && !$Me->is_empty()) {
            // profile link
            $xsep = ' <span class="barsep">&nbsp;|&nbsp;</span> ';
            if ($Me->has_email() && false) {
                echo '<a class="q" href="', hoturl("profile"), '"><strong>',
                    htmlspecialchars($Me->email),
                    '</strong></a> &nbsp; <a href="', hoturl("profile"), '">Profile</a>',
                    $xsep;
            } else if ($Me->has_email())
                echo '<strong>', htmlspecialchars($Me->email), '</strong>', $xsep;

            // "act as" link
            if (($actas = @$_SESSION["last_actas"]) && @$_SESSION["trueuser"]) {
                // Become true user if not currently chair.
                if (!$Me->privChair || strcasecmp($Me->email, $actas) == 0)
                    $actas = $_SESSION["trueuser"]->email;
                if (strcasecmp($Me->email, $actas) != 0)
                    echo "<a href=\"", self_href(array("actas" => $actas)), "\">", ($Me->privChair ? htmlspecialchars($actas) : "Admin"), "&nbsp;", Ht::img("viewas.png", "Act as " . htmlspecialchars($actas)), "</a>", $xsep;
            }

            // help, sign out
            $x = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
            if (!$Me->has_email() && !isset($Opt["httpAuthLogin"]))
                echo '<a href="', hoturl("index", "signin=1"), '">Sign&nbsp;in</a>';
            if (!$Me->is_empty() || isset($Opt["httpAuthLogin"]))
                echo '<a href="', hoturl_post("index", "signout=1"), '">Sign&nbsp;out</a>';
        }
        echo '<div id="maindeadline" style="display:none"></div></div>', "\n";

        echo "  <hr class=\"c\" />\n";

        echo $actionBar;

        echo "</div>\n<div id=\"initialmsgs\">\n";
        if (@$Opt["maintenance"])
            echo "<div class=\"merror\"><strong>The site is down for maintenance.</strong> ", (is_string($Opt["maintenance"]) ? $Opt["maintenance"] : "Please check back later."), "</div>";
        if (($msgs = $this->session("msgs")) && count($msgs)) {
            foreach ($msgs as $m)
                echo $m;
            $this->save_session("msgs", null);
            echo "<div id=\"initialmsgspacer\"></div>";
        }
        $this->save_messages = false;
        echo "</div>\n";

        $this->headerPrinted = true;
        echo "</div>\n<div class='body'>\n";
    }

    function footer() {
        global $Opt, $Me, $ConfSitePATH;
        echo "</div>\n", // class='body'
            "<div id='footer'>\n";
        $footy = defval($Opt, "extraFooter", "");
        if (false)
            $footy .= "<a href='http://read.seas.harvard.edu/~kohler/hotcrp/'>HotCRP</a> Conference Management Software";
        if (!defval($Opt, "noFooterVersion", 0)) {
            if ($Me && $Me->privChair) {
                if (is_dir("$ConfSitePATH/.git")) {
                    $args = array();
                    exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null", $args);
                    if (count($args) == 2 && $args[0] != $args[1])
                        $footy .= " [" . substr($args[0], 0, 7) . "...]";
                }
            }
        }
        if ($footy)
            echo "<div id='footer_crp'>$footy</div>";
        echo "<div class='clear'></div></div>\n";
        echo $this->scriptStuff, Ht::take_stash(), "</body>\n</html>\n";
        $this->scriptStuff = "";
    }

    function output_ajax($values = null, $div = false) {
        if ($values === false || $values === true)
            $values = array("ok" => $values);
        else if ($values === null)
            $values = array();
        else if (is_object($values))
            $values = get_object_vars($values);
        $t = "";
        $msgs = $this->session("msgs", array());
        $this->save_session("msgs", null);
        foreach ($msgs as $msg)
            if (preg_match('|\A<div class="(.*?)">([\s\S]*)</div>\s*\z|', $msg, $m)) {
                if ($m[1] == "merror" && !isset($values["error"]))
                    $values["error"] = $m[2];
                if ($div)
                    $t .= "<div class=\"x$m[1]\">$m[2]</div>\n";
                else
                    $t .= "<span class=\"$m[1]\">$m[2]</span>\n";
            }
        if (!isset($values["response"]) && $t !== "")
            $values["response"] = $t;
        if (array_key_exists("ok", $values) && $values["ok"] === null)
            $values["ok"] = !@$values["error"];
        if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        if (check_post())
            header("Access-Control-Allow-Origin: *");
        echo json_encode($values);
    }

    function ajaxExit($values = null, $div = false) {
        $this->output_ajax($values, $div);
        exit;
    }


    //
    // Action recording
    //

    function save_logs($on) {
        if ($on && $this->_save_logs === false)
            $this->_save_logs = array();
        else if (!$on && $this->_save_logs !== false) {
            $x = $this->_save_logs;
            $this->_save_logs = false;
            foreach ($x as $cid_text => $pids) {
                $pos = strpos($cid_text, "|");
                $this->log(substr($cid_text, $pos + 1),
                           substr($cid_text, 0, $pos), $pids);
            }
        }
    }

    function log($text, $who, $pids = null) {
        if (!$who)
            $who = 0;
        else if (!is_numeric($who))
            $who = $who->contactId;

        if (is_object($pids))
            $pids = array($pids->paperId);
        else if (!is_array($pids))
            $pids = $pids > 0 ? array($pids) : array();
        $ps = array();
        foreach ($pids as $p)
            $ps[] = is_object($p) ? $p->paperId : $p;

        if ($this->_save_logs !== false) {
            foreach ($ps as $p)
                $this->_save_logs["$who|$text"][] = $p;
            return;
        }

        if (count($ps) == 0)
            $ps = "null";
        else if (count($ps) == 1)
            $ps = $ps[0];
        else {
            $text .= " (papers " . join(", ", $ps) . ")";
            $ps = "null";
        }
        $this->q("insert into ActionLog (ipaddr, contactId, paperId, action) values ('" . sqlq(@$_SERVER["REMOTE_ADDR"]) . "', " . (int) $who . ", $ps, '" . sqlq(substr($text, 0, 4096)) . "')");
    }


    //
    // Miscellaneous
    //

    public function capability_manager($for) {
        global $Opt;
        if (@$Opt["contactdb_dsn"]
            && ($cdb = Contact::contactdb())
            && ((is_string($for) && substr($for, 0, 1) === "U")
                || ($for instanceof Contact && $for->contactDbId)))
            return new CapabilityManager($cdb, "U");
        else
            return new CapabilityManager($this->dblink, "");
    }


    function makeDownloadPath($doc) {
        global $ConfSiteBase, $ConfSiteSuffix;
        if (!property_exists($doc, "mimetype") || !isset($doc->documentType)) {
            $trace = debug_backtrace();
            error_log($trace[0]["file"] . ":" . $trace[0]["line"] . ": makeDownloadPath called with incomplete document");
        }
        if ($doc->mimetype)
            return $ConfSiteBase . "doc$ConfSiteSuffix/" . HotCRPDocument::filename($doc);
        else {
            $x = $ConfSiteBase . "doc$ConfSiteSuffix?p=" . $doc->paperId;
            if ($doc->documentType == DTYPE_FINAL)
                return $x . "&amp;final=1";
            else if ($doc->documentType > 0)
                return $x . "&amp;dt=$doc->documentType";
            else
                return $x;
        }
    }

    function allowEmailTo($email) {
        global $Opt;
        return $Opt["sendEmail"]
            && ($at = strpos($email, "@")) !== false
            && substr($email, $at) != "@_.com";
    }


    public function encode_capability($capid, $salt, $timeExpires, $save) {
        global $Opt;
        list($keyid, $key) = Contact::password_hmac_key(null, true);
        if (($hash_method = defval($Opt, "capabilityHashMethod")))
            /* OK */;
        else if (($hash_method = $this->setting_data("capabilityHashMethod")))
            /* OK */;
        else {
            $hash_method = (PHP_INT_SIZE == 8 ? "sha512" : "sha256");
            $this->save_setting("capabilityHashMethod", 1, $hash_method);
        }
        $text = substr(hash_hmac($hash_method, $capid . " " . $timeExpires . " " . $salt, $key, true), 0, 16);
        if ($save)
            $this->q("insert ignore into CapabilityMap (capabilityValue, capabilityId, timeExpires) values ('" . sqlq($text) . "', $capid, $timeExpires)");
        return "1" . str_replace(array("+", "/", "="), array("-", "_", ""),
                                 base64_encode($text));
    }

    public function create_capability($capabilityType, $options = array()) {
        $contactId = defval($options, "contactId", 0);
        $paperId = defval($options, "paperId", 0);
        $timeExpires = defval($options, "timeExpires", time() + 259200);
        $salt = hotcrp_random_bytes(24);
        $data = defval($options, "data");
        $this->q("insert into Capability (capabilityType, contactId, paperId, timeExpires, salt, data) values ($capabilityType, $contactId, $paperId, $timeExpires, '" . sqlq($salt) . "', " . ($data === null ? "null" : "'" . sqlq($data) . "'") . ")");
        $capid = $this->dblink->insert_id;
        if (!$capid || !function_exists("hash_hmac"))
            return false;
        return $this->encode_capability($capid, $salt, $timeExpires, true);
    }

    public function check_capability($capabilityText) {
        if ($capabilityText[0] != "1")
            return false;
        $value = base64_decode(str_replace(array("-", "_"), array("+", "/"),
                                           substr($capabilityText, 1)));
        if (strlen($value) >= 16
            && ($result = $this->q("select * from CapabilityMap where capabilityValue='" . sqlq($value) . "'"))
            && ($row = edb_orow($result))
            && ($row->timeExpires == 0 || $row->timeExpires >= time())) {
            $result = $this->q("select * from Capability where capabilityId=" . $row->capabilityId);
            if (($row = edb_orow($result))) {
                $row->capabilityValue = $value;
                return $row;
            }
        }
        return false;
    }

}
