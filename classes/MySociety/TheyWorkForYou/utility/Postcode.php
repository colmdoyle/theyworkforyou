<?php

namespace MySociety\TheyWorkForYou\Utility;

include_once INCLUDESPATH . 'constituencies.inc';

/**
 * Postcode Utilities
 *
 * Utility functions related to postcodes
 */

class Postcode
{

    /**
     * Postcode To Constituency (Internal)
     */

    public static function postcodeToConstituency($postcode, $api = false) {
        global $last_postcode, $last_postcode_value;
        $postcode = self::_canonicalisePostcode($postcode);
        if ($last_postcode == $postcode) {
            twfy_debug ("TIME", "Postcode $postcode looked up last time, is " . $last_postcode_value['WMC']);
            return $last_postcode_value['WMC'];
        }

        $start = getmicrotime();
        twfy_debug_timestamp();
        $ret = self::_postcodeToConstituency($postcode, true, $api);
        $duration = getmicrotime() - $start;
        twfy_debug ("TIME", "Postcode $postcode lookup took $duration seconds, returned " . is_string($ret) ? $ret : $ret['WMC']);
        twfy_debug_timestamp();

        if (is_string($ret)) return $ret;

        $last_postcode = $postcode;
        $last_postcode_value = $ret;
        return $ret['WMC'];
    }

    /**
     * Postcode To Constituencies
     */

    public static function postcodeToConstituencies($postcode, $api = false) {
        global $last_postcode, $last_postcode_value;
        $postcode = self::_canonicalisePostcode($postcode);
        if ($last_postcode == $postcode) {
            twfy_debug ("TIME", "Postcode $postcode looked up last time, is $last_postcode_value .");
            return $last_postcode_value;
        }

        $ret = self::_postcodeToConstituency($postcode, false, $api);
        if (is_string($ret)) return $ret;

        $last_postcode = $postcode;
        $last_postcode_value = $ret;
        return $ret;
    }

    /**
     * Postcode To Constituency (Internal)
     */

    private static function _postcodeToConstituency($postcode, $mp_only=true, $api) {
        # Try and match with regexp to exclude non postcodes quickly
        if (!validate_postcode($postcode))
            return '';

        $db = new \ParlDB;

        $q = $db->query('select name from postcode_lookup where postcode = :postcode', array(
            ':postcode' => $postcode
            ));

        if ($q->rows > 0) {
            $name = $q->field(0, 'name');
            if (self::postcodeIsScottish($postcode)) {
                $name = explode('|', $name);
                if (count($name)==3)
                    return array('WMC' => $name[0], 'SPC' => $name[1], 'SPE' => $name[2]);
            } elseif (self::postcodeIsNi($postcode)) {
                $name = explode('|', $name);
                if (count($name)==2)
                    return array('WMC' => $name[0], 'NIE' => $name[1]);
            } else {
                return array('WMC' => $name);
            }
        }

        # So as not to disturb Scot/NI lookup
        #if ($mp_only) {
        #    $xml = simplexml_load_string(@file_get_contents(POSTCODE_API_URL . urlencode($postcode)));
        #    if ($xml) {
        #        if ($xml->error) return '';
        #        return array('WMC' => iconv('utf-8', 'iso-8859-1//TRANSLIT', (string)$xml->future_constituency));
        #    }
        #}

        $filename = '/postcode/' . rawurlencode($postcode);
        $ch = curl_init('http://mapit.mysociety.org' . $filename);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $file = curl_exec($ch);
        if (curl_errno($ch)) {
            $errno = curl_errno($ch);
            trigger_error("Postcode database: " . $errno . ' ' . curl_error($ch), E_USER_WARNING);
            return 'CONNECTION_TIMED_OUT';
        }
        curl_close($ch);

        $r = json_decode($file, true);
        if (!$file) {
            trigger_error("Postcode database is not working. Content:\n".$file.", request: ". $filename, E_USER_WARNING);
            return '';
        }
        if (isset($r['error'])) {
            return '';
        }
        $areas = array();
        foreach ($r['areas'] as $row) {
            if (in_array($row['type'], array('WMC', 'SPC', 'SPE', 'NIE')))
                $areas[$row['type']] = utf8_decode($row['name']);
        }

        if (!isset($areas['WMC'])) {
            return '';
        }

        # Normalise name - assume SP and NI are already so...
        $normalised = normalise_constituency_name(strtolower($areas['WMC']));
        if ($normalised) {
            $areas['WMC'] = $normalised;
            if (self::postcodeIsScottish($postcode)) {
                $serialized = "$areas[WMC]|$areas[SPC]|$areas[SPE]";
            } elseif (self::postcodeIsNi($postcode)) {
                $serialized = "$areas[WMC]|$areas[NIE]";
            } else {
                $serialized = $normalised;
            }
            $db->query('replace into postcode_lookup values(:postcode, :serialized)',
                array(
                    ':postcode' => $postcode,
                    ':serialized' => $serialized
                ));
        } else {
            return '';
        }

        return $areas;
    }

    /**
     * Canonicalise Postcode
     *
     * Take a postcode and turn it into a tidied, uppercased canonical version.
     */

    private static function _canonicalisePostcode($pc) {
        $pc = str_replace(' ', '', $pc);
        $pc = trim($pc);
        $pc = strtoupper($pc);
        $pc = preg_replace('#(\d[A-Z]{2})#', ' $1', $pc);
        return $pc;
    }

    /**
     * Is Postcode Scottish
     */

    public static function postcodeIsScottish($pc) {
        if (!preg_match('#^([A-Z]{1,2})(\d+) (\d)([A-Z]{2})#', self::_canonicalisePostcode($pc), $m))
            return false;

        # Check for Scottish postal areas
        if (in_array($m[1], array('AB', 'DD', 'EH', 'FK', 'G', 'HS', 'IV', 'KA', 'KW', 'KY', 'ML', 'PA', 'PH', 'ZE')))
            return true;

        if ($m[1]=='DG') {
            if ($m[2]==16 && $m[3]==5 && in_array($m[4], array('HT','HU','HZ','JA','JB'))) return false; # A few postcodes in England
            return true;
        }

        # Damn postcodes crossing country boundaries
        if ($m[1]=='TD') {
            if ($m[2]!=15 && $m[2]!=12 && $m[2]!=9) return true; # TD1-8, 10-11, 13-14 all in Scotland
            if ($m[2]==9) {
                if ($m[3]!=0) return true; # TD9 1-9 all in Scotland
                if (!in_array($m[4], array('TJ','TP','TR','TS','TT','TU','TW'))) return true; # Nearly all of TD9 0 in Scotland
            }
            $m[5] = substr($m[4], 0, 1);
            if ($m[2]==12) { # $m[3] will be 4 currently.
                if ($m[4]=='XE') return true;
                if (in_array($m[5], array('A','B','D','E','H','J','L','N','W','Y'))) return true; # These bits of TD12 4 are in Scotland, others (Q, R, S, T, U, X) in England
            }
            # TD15 is mostly England
            if ($m[2]==15) {
                if ($m[3]!=1) return false; # TD15 2 and 9 are in England
                if (in_array($m[4], array('BT','SU','SZ','UF','UG','UH','UJ','UL','US','UZ','WY','WZ'))) return true;
                if ($m[5]=='T' && $m[4]!='TA' && $m[4]!='TB') return true; # Most of TD15 1T* in Scotland
                if ($m[5]=='X' && $m[4]!='XX') return true; # TD15 1XX in England, rest of TD15 1X* in Scotland
            }
        }

        # Not in Scotland
        return false;
    }

    /**
     * Is Postcode Northern Irish?
     */

    public static function postcodeIsNi($pc) {
        $prefix = substr(self::_canonicalisePostcode($pc), 0, 2);
        if ($prefix == 'BT')
            return true;
        return false;
    }

}
