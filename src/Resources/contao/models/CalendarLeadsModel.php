<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2016 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Kmielke\CalendarExtendedBundle;


/**
 * Reads leads
 *
 * @author    Kester Mielke
 */
class CalendarLeadsModel extends \Model
{

    /**
     * Table name
     * @var string
     */
    protected static $strTableMaster = 'tl_lead';
    protected static $strTableDetail = 'tl_lead_data';


    /**
     * @param $pid
     *
     * @return \Database\Result|object
     */
    public static function findByPid($pid)
    {
        // SQL bauen
        $sql = 'select pid, name, value from ' . static::$strTableDetail . ' where pid = ? order by id';
        // und ausführen
        return \Database::getInstance()->prepare($sql)->execute($pid);
    }


    /**
     * @param $lid int leadid
     * @param $eid int eventid
     * @param $mail string email
     * @param $published int published
     *
     * @return bool
     */
    public static function updateByLeadEventMail($lid, $eid, $mail, $published)
    {
        $objResult = self::findPidByLeadEventMail($lid, $eid, $mail);
        if (!$objResult || $objResult->numRows === 0) {
            return false;
        }

        $result = self::updateByPidField($objResult->pid, 'published', $published);
        if (!$result) {
            return false;
        }

        return true;
    }


    /**
     * @param $pid int pid
     * @param $field string fieldname
     * @param $value mixed value
     *
     * @return bool
     */
    public static function updateByPid($pid, $value)
    {
        // SQL bauen
        $sql = 'update ' . static::$strTableDetail . ' set value = ?, label = ? where pid = ? and name = "published"';
        // und ausführen
        $objResult = \Database::getInstance()->prepare($sql)->execute((int)$value, (int)$value, (int)$pid);

        return (bool)$objResult;
    }
}
