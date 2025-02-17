<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (C) 2005-2012 Leo Feyer
 *
 * @package   Contao
 * @author    Kester Mielke
 * @license   LGPL
 * @copyright Kester Mielke 2010-2013
 */

$GLOBALS['TL_CONFIG']['tl_calendar_events']['maxRepeatExceptions'] = 365;

/**
 * the range of days for the move date option
 * 14 means from -14 days to 14 days
 */
$GLOBALS['TL_CONFIG']['tl_calendar_events']['moveDays'] = 7;

/**
 * the start, end and interval of times for the move time option
 * 00:00|23:59|30 means start at 00:00 and add 30 min. to the time
 *
 * this will be used for start and end time
 *
 * examples
 * interval 15: 00:15, 00:30, 00:45, 01:00...
 * interval 30: 00:00, 00:30, 01:00, 01:30...
 */
$GLOBALS['TL_CONFIG']['tl_calendar_events']['moveTimes'] = '10:00|22:00|30';

$GLOBALS['TL_LANG']['DAYS']['sunday']    = 0;
$GLOBALS['TL_LANG']['DAYS']['monday']    = 1;
$GLOBALS['TL_LANG']['DAYS']['tuesday']   = 2;
$GLOBALS['TL_LANG']['DAYS']['wednesday'] = 3;
$GLOBALS['TL_LANG']['DAYS']['thursday']  = 4;
$GLOBALS['TL_LANG']['DAYS']['friday']    = 5;
$GLOBALS['TL_LANG']['DAYS']['saturday']  = 6;

$GLOBALS['TL_CONFIG']['tl_calendar_events']['weekdays'][0] = 'sunday';
$GLOBALS['TL_CONFIG']['tl_calendar_events']['weekdays'][1] = 'monday';
$GLOBALS['TL_CONFIG']['tl_calendar_events']['weekdays'][2] = 'tuesday';
$GLOBALS['TL_CONFIG']['tl_calendar_events']['weekdays'][3] = 'wednesday';
$GLOBALS['TL_CONFIG']['tl_calendar_events']['weekdays'][4] = 'thursday';
$GLOBALS['TL_CONFIG']['tl_calendar_events']['weekdays'][5] = 'friday';
$GLOBALS['TL_CONFIG']['tl_calendar_events']['weekdays'][6] = 'saturday';

// Event Filter
$GLOBALS['TL_CONFIG']['tl_calendar_events']['filter']['title'] = [];
$GLOBALS['TL_CONFIG']['tl_calendar_events']['filter']['location_name'] = [];
$GLOBALS['TL_CONFIG']['tl_calendar_events']['filter']['location_str'] = [];
$GLOBALS['TL_CONFIG']['tl_calendar_events']['filter']['location_plz'] = [];

/**
 * Front end modules
 */
array_insert($GLOBALS['FE_MOD'], 99, array
(
    'events' => array
    (
        'timetable'	        => 'Kmielke\CalendarExtendedBundle\ModuleTimeTable',
        'yearview'	        => 'Kmielke\CalendarExtendedBundle\ModuleYearView',
        'fullcalendar'      => 'Kmielke\CalendarExtendedBundle\ModuleFullcalendar'
    )
));

// Replace Contao Module
$GLOBALS['FE_MOD']['events']['calendar']    = 'Kmielke\CalendarExtendedBundle\ModuleCalendar';
$GLOBALS['FE_MOD']['events']['eventlist']   = 'Kmielke\CalendarExtendedBundle\ModuleEventlist';
$GLOBALS['FE_MOD']['events']['eventmenu']   = 'Kmielke\CalendarExtendedBundle\ModuleEventMenu';
$GLOBALS['FE_MOD']['events']['eventreader'] = 'Kmielke\CalendarExtendedBundle\ModuleEventReader';

/**
 * BACK END FORM FIELDS
 */

array_insert($GLOBALS['BE_FFL'], 99, array
(
    'timePeriodExt'     => 'Kmielke\CalendarExtendedBundle\TimePeriodExt',
));

/**
 * Event Hook
 */
$GLOBALS['TL_HOOKS']['getAllEvents'][] = array('Kmielke\CalendarExtendedBundle\EventUrls', 'modifyEventUrl');
