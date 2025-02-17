<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2016 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Kmielke\CalendarExtendedBundle;

use Contao\BackendTemplate;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\Database;
use Contao\Input;
use Contao\Pagination;
use Contao\StringUtil;
use Contao\System;

/**
 * Class ModuleEventListExt
 *
 * @copyright  Kester Mielke 2010-2013
 * @author     Kester Mielke
 * @package    Devtools
 */
class ModuleEventlist extends EventsExt
{

    /**
     * Current date object
     * @var \Contao\Date
     */
    protected $Date;
    protected $calConf = array();

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'mod_eventlist';


    /**
     * Display a wildcard in the back end
     *
     * @return string
     */
    public function generate()
    {
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request))
        {
            $objTemplate = new BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### ' . $GLOBALS['TL_LANG']['FMD']['eventlist'][0] . ' ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = StringUtil::specialcharsUrl(System::getContainer()->get('router')->generate('contao_backend', array('do'=>'themes', 'table'=>'tl_module', 'act'=>'edit', 'id'=>$this->id)));

            return $objTemplate->parse();
        }

        $this->cal_calendar = $this->sortOutProtected(StringUtil::deserialize($this->cal_calendar, true));
        $this->cal_holiday = $this->sortOutProtected(StringUtil::deserialize($this->cal_holiday, true));

        // Return if there are no calendars
        if (!is_array($this->cal_calendar) || empty($this->cal_calendar)) {
            return '';
        }

        // Calendar filter
        if (\Contao\Input::get('cal')) {
            // Create array of cal_id's to filter
            $cals1 = explode(',', \Contao\Input::get('cal'));
            // Check if the cal_id's are valid for this module
            $cals2 = array_intersect($cals1, $this->cal_calendar);
            if ($cals2) {
                $this->cal_calendar = array_intersect($cals2, $this->cal_calendar);
            }
        }

        // Get the background and foreground colors of the calendars
        foreach (array_merge($this->cal_calendar, $this->cal_holiday) as $cal) {
            $objBG = Database::getInstance()->prepare("select title, bg_color, fg_color from tl_calendar where id = ?")
                ->limit(1)->execute($cal);

            $this->calConf[$cal]['calendar'] = $objBG->title;

            if ($objBG->bg_color) {
                [$cssColor, $cssOpacity] = StringUtil::deserialize($objBG->bg_color);

                if (!empty($cssColor)) {
                    $this->calConf[$cal]['background'] .= 'background-color:#' . $cssColor . ';';
                }
                if (!empty($cssOpacity)) {
                    $this->calConf[$cal]['background'] .= 'opacity:' . ($cssOpacity / 100) . ';';
                }
            }

            if ($objBG->fg_color) {
                [$cssColor, $cssOpacity] = StringUtil::deserialize($objBG->fg_color);

                if (!empty($cssColor)) {
                    $this->calConf[$cal]['foreground'] .= 'color:#' . $cssColor . ';';
                }
                if (!empty($cssOpacity)) {
                    $this->calConf[$cal]['foreground'] .= 'opacity:' . ($cssOpacity / 100) . ';';
                }
            }
        }

        // Show the event reader if an item has been selected
        if ($this->cal_readerModule > 0 && Input::get('auto_item') !== null)
        {
            return $this->getFrontendModule($this->cal_readerModule, $this->strColumn);
        }

        // Tag the calendars (see #2137)
        if (System::getContainer()->has('fos_http_cache.http.symfony_response_tagger'))
        {
            $responseTagger = System::getContainer()->get('fos_http_cache.http.symfony_response_tagger');
            $responseTagger->addTags(array_map(static function ($id) { return 'contao.db.tl_calendar.' . $id; }, $this->cal_calendar));
        }

        return parent::generate();
    }


    /**
     * Generate the module
     */
    protected function compile()
    {
        /** @var \Contao\PageModel $objPage */
        global $objPage;
        $blnClearInput = false;

        $intYear = \Contao\Input::get('year');
        $intMonth = \Contao\Input::get('month');
        $intDay = \Contao\Input::get('day');

        // Jump to the current period
        if (!isset($_GET['year']) && !isset($_GET['month']) && !isset($_GET['day'])) {
            switch ($this->cal_format) {
                case 'cal_year':
                    $intYear = date('Y');
                    break;

                case 'cal_month':
                    $intMonth = date('Ym');
                    break;

                case 'cal_day':
                    $intDay = date('Ymd');
                    break;
            }

            $blnClearInput = true;
        }

        $blnDynamicFormat = (!$this->cal_ignoreDynamic && in_array($this->cal_format, array('cal_day', 'cal_month', 'cal_year')));

        // Create the date object
        try {
            if ($blnDynamicFormat && $intYear) {
                $this->Date = new \Contao\Date($intYear, 'Y');
                $this->cal_format = 'cal_year';
                $this->headline .= ' ' . date('Y', $this->Date->tstamp);
            } elseif ($blnDynamicFormat && $intMonth) {
                $this->Date = new \Contao\Date($intMonth, 'Ym');
                $this->cal_format = 'cal_month';
                $this->headline .= ' ' . \Contao\Date::parse('F Y', $this->Date->tstamp);
            } elseif ($blnDynamicFormat && $intDay) {
                $this->Date = new \Contao\Date($intDay, 'Ymd');
                $this->cal_format = 'cal_day';
                $this->headline .= ' ' . \Contao\Date::parse($objPage->dateFormat, $this->Date->tstamp);
            } else {
                $this->Date = new \Contao\Date();
            }
        } catch (\OutOfBoundsException) {
            throw new PageNotFoundException();
        }

        [$strBegin, $strEnd, $strEmpty] = $this->getDatesFromFormat($this->Date, $this->cal_format);

        // we will overwrite $strBegin, $strEnd if cal_format_ext is set
        if ($this->cal_format_ext != '') {
            $times = explode('|', $this->cal_format_ext);

            if (count($times) == 1) {
                $strBegin = time();
                $strEnd = strtotime($times[0], $strBegin);
            } elseif (count($times) == 2) {
                $strBegin = strtotime($times[0]) ? strtotime($times[0]) : time();
                $strEnd = strtotime($times[1], $strBegin);
            }
        }

        // we will overwrite $strBegin, $strEnd if range_date is set
        $arrRange = StringUtil::deserialize($this->range_date);
        if (is_array($arrRange) && $arrRange[0]['date_from']) {
            $startRange = strtotime($arrRange[0]['date_from']);
            $endRange = strtotime($arrRange[0]['date_to']);

            if ($startRange && $endRange) {
                if (checkdate(date('m', $startRange), date('d', $startRange), date('Y', $startRange)) &&
                    checkdate(date('m', $endRange), date('d', $endRange), date('Y', $endRange))
                ) {
                    $strBegin = strtotime($arrRange[0]['date_from']);
                    $strEnd = strtotime($arrRange[0]['date_to']);
                }
            }
        }

        // we have to check if we have to show recurrences and pass it to the getAllEventsExt function...
        $showRecurrences = ((int)$this->showRecurrences === 1) ? false : true;

        // Get all events
        $arrAllEvents = $this->getAllEventsExt($this->cal_calendar, $strBegin, $strEnd, array($this->cal_holiday, $showRecurrences));
        $sort = ($this->cal_order == 'descending') ? 'krsort' : 'ksort';

        // Sort the days
        $sort($arrAllEvents);

        // Sort the events
        foreach (array_keys($arrAllEvents) as $key) {
            $sort($arrAllEvents[$key]);
        }

        $arrEvents = array();
        $dateBegin = date('Ymd', $strBegin);
        $dateEnd = date('Ymd', $strEnd);

        // Step 1: get the current time
        $currTime = \Contao\Date::floorToMinute();
        // Remove events outside the scope
        foreach ($arrAllEvents as $key => $days) {
            // Do not show recurrences
            if ($showRecurrences) {
                if (($key < $dateBegin) && ($key > $dateEnd)) {
                    continue;
                }
            }

            foreach ($days as $day => $events) {
                foreach ($events as $event) {
                    // Use repeatEnd if > 0 (see #8447)
                    if (($event['repeatEnd'] ?: $event['endTime']) < $strBegin || $event['startTime'] > $strEnd) {
                        continue;
                    }

                    // Skip occurrences in the past but show running events (see #8497)
                    if ($event['repeatEnd'] && $event['end'] < $strBegin) {
                        continue;
                    }

                    // We have to get start and end from DB again, because start is overwritten in addEvent()
                    $objEV = Database::getInstance()->prepare("select start, stop from tl_calendar_events where id = ?")
                        ->limit(1)->execute($event['id']);
                    $eventStart = ($objEV->start) ? $objEV->start : false;
                    $eventStop = ($objEV->stop) ? $objEV->stop : false;
                    unset($objEV);

                    if ($event['show']) {
                        // Remove events outside time scope
                        if ($this->pubTimeRecurrences && ($eventStart && $eventStop)) {
                            // Step 2: get show from/until times
                            $startTimeShow = strtotime(date('dmY') . ' ' . date('Hi', $eventStart));
                            $endTimeShow = strtotime(date('dmY') . ' ' . date('Hi', $eventStop));

                            // Compare the times...
                            if ($currTime < $startTimeShow || $currTime > $endTimeShow) {
                                continue;
                            }
                        }
                    }

                    // We take the "show from" time or the "event start" time to check the display duration limit
                    $displayStart = ($event['start']) ? $event['start'] : $event['startTime'];
                    if (strlen($this->displayDuration) > 0) {
                        $displayStop = strtotime($this->displayDuration, $displayStart);
                        if ($displayStop < $currTime) {
                            continue;
                        }
                    }

                    // Hide Events that are already started
                    if ($this->hide_started && $event['startTime'] < $currTime) {
                        continue;
                    }

                    $event['firstDay'] = $GLOBALS['TL_LANG']['DAYS'][date('w', $day)];
                    $event['firstDate'] = \Contao\Date::parse($objPage->dateFormat, $day);
//                    $event['datetime'] = date('Y-m-d', $day);

                    $event['calendar_title'] = $this->calConf[$event['pid']]['calendar'];

                    if ($this->calConf[$event['pid']]['background']) {
                        $event['bgstyle'] = $this->calConf[$event['pid']]['background'];
                    }
                    if ($this->calConf[$event['pid']]['foreground']) {
                        $event['fgstyle'] = $this->calConf[$event['pid']]['foreground'];
                    }

                    // Set endtime to starttime always...
                    if ((int)$event['addTime'] === 1 && (int)$event['ignoreEndTime'] === 1) {
                        $event['time'] = \Contao\Date::parse($objPage->timeFormat, $event['startTime']);
//                        $event['date'] = \Contao\Date::parse($objPage->datimFormat, $event['startTime']) . ' - ' .   \Contao\Date::parse($objPage->dateFormat, $event['endTime']);
//                        $event['endTime'] = '';
//                        $event['time'] = '';
//                        if ((int)$event['addTime'] === 1) {
//                            $event['time'] = \Contao\Date::parse($objPage->timeFormat, $event['startTime']);
//                        }
                    }

                    // check the repeat values
                    $unit = '';
                    if ($event['recurring']) {
                        $arrRepeat = StringUtil::deserialize($event['repeatEach']) ? StringUtil::deserialize($event['repeatEach']) : null;
                        $unit = $arrRepeat['unit'];
                    }
                    if ($event['recurringExt']) {
                        $arrRepeat = StringUtil::deserialize($event['repeatEachExt']) ? StringUtil::deserialize($event['repeatEachExt']) : null;
                        $unit = $arrRepeat['unit'];
                    }

                    // get the configured weekdays if any
                    $useWeekdays = ($weekdays = StringUtil::deserialize($event['repeatWeekday'])) ? true : false;

                    // Set the next date
                    $nextDate = null;
                    if ($event['repeatDates']) {
                        $arrNext = StringUtil::deserialize($event['repeatDates']);
                        foreach ($arrNext as $k => $nextDate) {
                            if (strtotime($nextDate) > time()) {
                                // check if we have the correct weekday
                                if ($useWeekdays && $unit === 'days') {
                                    if (!in_array(date('w', $k), $weekdays)) {
                                        continue;
                                    }
                                }
                                $nextDate = \Contao\Date::parse($objPage->datimFormat, $k);
                                break;
                            }
                        }
                        $event['nextDate'] = $nextDate;
                    }

                    // Add the event to the array
                    $arrEvents[] = $event;
                }
            }
        }

        unset($arrAllEvents, $days);
        $total = count($arrEvents);
        $limit = $total;
        $offset = 0;

        // Overall limit
        if ($this->cal_limit > 0) {
            $total = min($this->cal_limit, $total);
            $limit = $total;
        }

        // Pagination
        if ($this->perPage > 0) {
            $id = 'page_e' . $this->id;
            $page = (\Contao\Input::get($id) !== null) ? \Contao\Input::get($id) : 1;

            // Do not index or cache the page if the page number is outside the range
            if ($page < 1 || $page > max(ceil($total / $this->perPage), 1)) {
                throw new PageNotFoundException();
            }

            $offset = ($page - 1) * $this->perPage;
            $limit = min($this->perPage + $offset, $total);

            $objPagination = new Pagination($total, $this->perPage, \Contao\Config::get('maxPaginationLinks'), $id);
            $this->Template->pagination = $objPagination->generate("\n  ");
        }

        $strMonth = '';
        $strDate = '';
        $strEvents = '';
        $dayCount = 0;
        $eventCount = 0;
        $headerCount = 0;
        $imgSize = false;

        // Override the default image size
        if ($this->imgSize != '') {
            $size = StringUtil::deserialize($this->imgSize);

            if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]) || ($size[2][0] ?? null) === '_') {
                $imgSize = $this->imgSize;
            }
        }

        // Parse events
        for ($i = $offset; $i < $limit; $i++) {
            $event = $arrEvents[$i];
            $blnIsLastEvent = false;

            // Last event on the current day
            if (($i + 1) == $limit || !isset($arrEvents[($i + 1)]['firstDate']) || $event['firstDate'] != $arrEvents[($i + 1)]['firstDate']) {
                $blnIsLastEvent = true;
            }

            /** @var \Contao\FrontendTemplate|object $objTemplate */
            $objTemplate = new \Contao\FrontendTemplate($this->cal_template ?: 'event_list');
            $objTemplate->setData($event);

            // Month header
            if ($strMonth != $event['month']) {
                $objTemplate->newMonth = true;
                $strMonth = $event['month'];
            }

            // Day header
            if ($strDate != $event['firstDate']) {
                $headerCount = 0;
                $objTemplate->header = true;
                $objTemplate->classHeader = ((($dayCount % 2) == 0) ? ' even' : ' odd') . (($dayCount == 0) ? ' first' : '') . (($event['firstDate'] == $arrEvents[($limit - 1)]['firstDate']) ? ' last' : '');
                $strDate = $event['firstDate'];

                ++$dayCount;
            }

            // Show the teaser text of redirect events (see #6315)
            if (is_bool($event['details'])) {
                $objTemplate->hasDetails = false;
            }

            // Add the template variables
            $objTemplate->classList = $event['class'] . ((($headerCount % 2) == 0) ? ' even' : ' odd') . (($headerCount == 0) ? ' first' : '') . ($blnIsLastEvent ? ' last' : '') . ' cal_' . $event['parent'];
            $objTemplate->classUpcoming = $event['class'] . ((($eventCount % 2) == 0) ? ' even' : ' odd') . (($eventCount == 0) ? ' first' : '') . ((($offset + $eventCount + 1) >= $limit) ? ' last' : '') . ' cal_' . $event['parent'];
            $objTemplate->readMore = StringUtil::specialchars(sprintf($GLOBALS['TL_LANG']['MSC']['readMore'], $event['title']));
            $objTemplate->more = $GLOBALS['TL_LANG']['MSC']['more'];
            $objTemplate->locationLabel = $GLOBALS['TL_LANG']['MSC']['location'];

            // Short view
            if ($this->cal_noSpan) {
                $objTemplate->day = $event['day'];
                $objTemplate->date = $event['date'];
            } else {
                $objTemplate->day = $event['firstDay'];
                $objTemplate->date = $event['firstDate'];
            }

            $objTemplate->addImage = false;
            $objTemplate->addBefore = false;

            // Add an image
            if ($event['addImage'])
            {
                $eventModel = CalendarEventsModel::findById($event['id']);
                $imgSize = $eventModel->size ?: null;

                // Override the default image size
                if ($this->imgSize)
                {
                    $size = StringUtil::deserialize($this->imgSize);

                    if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]) || ($size[2][0] ?? null) === '_')
                    {
                        $imgSize = $this->imgSize;
                    }
                }

                $figureBuilder = System::getContainer()->get('contao.image.studio')->createFigureBuilder();

                $figure = $figureBuilder
                    ->from($event['singleSRC'])
                    ->setSize($imgSize)
                    ->setOverwriteMetadata($eventModel->getOverwriteMetadata())
                    ->enableLightbox($eventModel->fullsize)
                    ->buildIfResourceExists();

                if (null !== $figure)
                {
                    // Rebuild with link to event if none is set
                    if (!$figure->getLinkHref())
                    {
                        $figure = $figureBuilder
                            ->setLinkHref($event['href'])
                            ->setLinkAttribute('title', $objTemplate->readMore)
                            ->build();
                    }

                    $figure->applyLegacyTemplateData($objTemplate, null, $eventModel->floating);
                }
            }

            $objTemplate->showRecurrences = $showRecurrences;
            $objTemplate->enclosure = array();

            // Add enclosure
            if ($event['addEnclosure']) {
                $this->addEnclosuresToTemplate($objTemplate, $event);
            }

            $strEvents .= $objTemplate->parse();

            ++$eventCount;
            ++$headerCount;
        }

        // No events found
        if ($strEvents == '') {
            $strEvents = "\n" . '<div class="empty">' . $strEmpty . '</div>' . "\n";
        }

        // See #3672
        $this->Template->headline = $this->headline;
        $this->Template->eventcount = $eventCount;
        $this->Template->events = $strEvents;

        // Clear the $_GET array (see #2445)
        if ($blnClearInput) {
            \Contao\Input::setGet('year', null);
            \Contao\Input::setGet('month', null);
            \Contao\Input::setGet('day', null);
        }
    }
}
