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
use Contao\CoreBundle\Exception\InternalServerErrorException;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Util\UrlUtil;
use Contao\Environment;

use Contao\Input;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Front end module "event reader".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleEventReader extends EventsExt
{

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'mod_event';


    /**
     * Display a wildcard in the back end
     *
     * @return string
     */
    public function generate()
    {
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request)) {
            $objTemplate = new BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### '.$GLOBALS['TL_LANG']['FMD']['eventreader'][0].' ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = StringUtil::specialcharsUrl(System::getContainer()->get('router')->generate('contao_backend', ['do' => 'themes', 'table' => 'tl_module', 'act' => 'edit', 'id' => $this->id]));

            return $objTemplate->parse();
        }

        // Return an empty string if "auto_item" is not set to combine list and reader on same page
        if (Input::get('auto_item') === null) {
            return '';
        }

        $cals = ($this->cal_holiday)
            ? array_merge(StringUtil::deserialize($this->cal_calendar), StringUtil::deserialize($this->cal_holiday))
            : StringUtil::deserialize($this->cal_calendar);
        $this->cal_calendar = $this->sortOutProtected($cals);

        if (empty($this->cal_calendar) || !\is_array($this->cal_calendar)) {
            throw new InternalServerErrorException('The event reader ID '.$this->id.' has no calendars specified.');
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

        $this->Template->event = '';

        $urlGenerator = System::getContainer()->get('contao.routing.content_url_generator');

        if ($this->overviewPage && ($overviewPage = PageModel::findById($this->overviewPage))) {
            $this->Template->referer = $urlGenerator->generate($overviewPage);
            $this->Template->back = $this->customLabel ?: $GLOBALS['TL_LANG']['MSC']['eventOverview'];
        }

        // Get the current event
        $objEvent = CalendarEventsModelExt::findPublishedByParentAndIdOrAlias(\Contao\Input::get('events'), $this->cal_calendar);

        // The event does not exist (see #33)
        if ($objEvent === null) {
            throw new PageNotFoundException('Page not found: '.Environment::get('uri'));
        }

        // Redirect if the event has a target URL (see #1498)
        switch ($objEvent->source) {
            case 'internal':
            case 'article':
            case 'external':
                throw new RedirectResponseException($urlGenerator->generate($objEvent, [], UrlGeneratorInterface::ABSOLUTE_URL), 301);
        }

        // Add author info
        $objEvent->author_name = ($objEvent->getRelated("author")->name) ? $objEvent->getRelated("author")->name : null;
        $objEvent->author_mail = ($objEvent->getRelated("author")->email) ? $objEvent->getRelated("author")->email : null;

        // Overwrite the page metadata (see #2853, #4955 and #87)
        $responseContext = System::getContainer()->get('contao.routing.response_context_accessor')->getResponseContext();

        if ($responseContext?->has(HtmlHeadBag::class)) {
            $htmlHeadBag = $responseContext->get(HtmlHeadBag::class);
            $htmlDecoder = System::getContainer()->get('contao.string.html_decoder');

            if ($objEvent->pageTitle) {
                $htmlHeadBag->setTitle($objEvent->pageTitle); // Already stored decoded
            } elseif ($objEvent->title) {
                $htmlHeadBag->setTitle($htmlDecoder->inputEncodedToPlainText($objEvent->title));
            }

            if ($objEvent->description) {
                $htmlHeadBag->setMetaDescription($htmlDecoder->inputEncodedToPlainText($objEvent->description));
            } elseif ($objEvent->teaser) {
                $htmlHeadBag->setMetaDescription($htmlDecoder->htmlToPlainText($objEvent->teaser));
            }

            if ($objEvent->robots) {
                $htmlHeadBag->setMetaRobots($objEvent->robots);
            }

            if ($objEvent->canonicalLink) {
                $url = System::getContainer()->get('contao.insert_tag.parser')->replaceInline($objEvent->canonicalLink);

                // Ensure absolute links
                if (!preg_match('#^https?://#', $url)) {
                    if (!$request = System::getContainer()->get('request_stack')->getCurrentRequest()) {
                        throw new \RuntimeException('The request stack did not contain a request');
                    }

                    $url = UrlUtil::makeAbsolute($url, $request->getUri());
                }

                $htmlHeadBag->setCanonicalUri($url);
            } elseif (!$this->cal_keepCanonical) {
                $htmlHeadBag->setCanonicalUri($urlGenerator->generate($objEvent, [], UrlGeneratorInterface::ABSOLUTE_URL));
            }
        }

        $intStartTime = $objEvent->startTime;
        $intEndTime = $objEvent->endTime;
        $span = \Contao\Calendar::calculateSpan($intStartTime, $intEndTime);

        // Save original times...
        $orgStartTime = $objEvent->startTime;
        $orgEndTime = $objEvent->endTime;

        // Do not show dates in the past if the event is recurring (see #923)
        if ($objEvent->recurring) {
            $arrRange = StringUtil::deserialize($objEvent->repeatEach);

            while ($intStartTime < time() && $intEndTime < $objEvent->repeatEnd) {
                $intStartTime = strtotime('+'.$arrRange['value'].' '.$arrRange['unit'], $intStartTime);
                $intEndTime = strtotime('+'.$arrRange['value'].' '.$arrRange['unit'], $intEndTime);
            }
        }

        // Do not show dates in the past if the event is recurringExt
        if ($objEvent->recurringExt) {
            $arrRange = StringUtil::deserialize($objEvent->repeatEachExt);

            // list of months we need
            $arrMonth = [
                1 => 'january',
                2 => 'february',
                3 => 'march',
                4 => 'april',
                5 => 'may',
                6 => 'june',
                7 => 'july',
                8 => 'august',
                9 => 'september',
                10 => 'october',
                11 => 'november',
                12 => 'december',
            ];

            // month and year of the start date
            $month = date('n', $intStartTime);
            $year = date('Y', $intEndTime);
            while ($intStartTime < time() && $intEndTime < $objEvent->repeatEnd) {
                // find the next date
                $nextValueStr = $arrRange['value'].' '.$arrRange['unit'].' of '.$arrMonth[$month].' '.$year;
                $nextValueDate = strtotime($nextValueStr, $intStartTime);
                // add time to the new date
                $intStartTime = strtotime(date("Y-m-d", $nextValueDate).' '.date("H:i:s", $intStartTime));
                $intEndTime = strtotime(date("Y-m-d", $nextValueDate).' '.date("H:i:s", $intEndTime));

                $month++;
                if (($month % 13) == 0) {
                    $month = 1;
                    $year += 1;
                }
            }
        }

        // Do not show dates in the past if the event is recurring irregular
        if (!is_null($objEvent->repeatFixedDates)) {
            $arrFixedDates = StringUtil::deserialize($objEvent->repeatFixedDates);

            // Check if there are valid data in the array...
            if (is_array($arrFixedDates) && strlen($arrFixedDates[0]['new_repeat'])) {
                foreach ($arrFixedDates as $fixedDate) {
                    $nextValueDate = ($fixedDate['new_repeat']) ? strtotime($fixedDate['new_repeat']) : $intStartTime;
                    if (strlen($fixedDate['new_start'])) {
                        $nextStartTime = strtotime(date("Y-m-d", $nextValueDate).' '.date("H:i:s", strtotime($fixedDate['new_start'])));
                        $nextValueDate = $nextStartTime;
                    } else {
                        $nextStartTime = strtotime(date("Y-m-d", $nextValueDate).' '.date("H:i:s", $intStartTime));
                    }
                    if (strlen($fixedDate['new_end'])) {
                        $nextEndTime = strtotime(date("Y-m-d", $nextValueDate).' '.date("H:i:s", strtotime($fixedDate['new_end'])));
                    } else {
                        $nextEndTime = strtotime(date("Y-m-d", $nextValueDate).' '.date("H:i:s", $intEndTime));
                    }

                    if ($nextValueDate > time() && $nextEndTime <= $objEvent->repeatEnd) {
                        $intStartTime = $nextStartTime;
                        $intEndTime = $nextEndTime;
                        break;
                    }
                }
            }
        }

        // Replace the date an time with the correct ones from the recurring event
        if (\Contao\Input::get('times')) {
            [$intStartTime, $intEndTime] = explode(",", \Contao\Input::get('times'));
        }

        $strDate = \Contao\Date::parse($objPage->dateFormat, $intStartTime);

        if ($span > 0) {
            $strDate = \Contao\Date::parse($objPage->dateFormat, $intStartTime).$GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'].\Contao\Date::parse($objPage->dateFormat, $intEndTime);
        }

        $strTime = '';

        if ($objEvent->addTime) {
            if ($span > 0) {
                $strDate = \Contao\Date::parse($objPage->datimFormat, $intStartTime).$GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'].\Contao\Date::parse($objPage->datimFormat, $intEndTime);
            } elseif ($intStartTime == $intEndTime) {
                $strTime = \Contao\Date::parse($objPage->timeFormat, $intStartTime);
            } else {
                $strTime = \Contao\Date::parse($objPage->timeFormat, $intStartTime).$GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'].\Contao\Date::parse($objPage->timeFormat, $intEndTime);
            }
        }

        // Fix date if we have to ignore the time
        if ((int)$objEvent->ignoreEndTime === 1) {
            // $strDate = \Contao\Date::parse($objPage->datimFormat, $objEvent->startTime) . $GLOBALS['TL_LANG']['MSC']['cal_timeSeparator'] . \Contao\Date::parse($objPage->dateFormat, $objEvent->endTime);
            // $strTime = null;
            $strDate = \Contao\Date::parse($objPage->dateFormat, $intStartTime);
            $objEvent->endTime = '';
            $objEvent->time = '';
        }

        $until = '';
        $recurring = '';

        // Recurring event
        if ($objEvent->recurring) {
            $arrRange = StringUtil::deserialize($objEvent->repeatEach);

            if (is_array($arrRange) && isset($arrRange['unit']) && isset($arrRange['value'])) {
                $strKey = 'cal_'.$arrRange['unit'];
                $recurring = sprintf($GLOBALS['TL_LANG']['MSC'][$strKey], $arrRange['value']);

                if ($objEvent->recurrences > 0) {
                    $until = sprintf($GLOBALS['TL_LANG']['MSC']['cal_until'], \Contao\Date::parse($objPage->dateFormat, $objEvent->repeatEnd));
                }
            }
        }

        // Recurring eventExt
        if ($objEvent->recurringExt) {
            $arrRange = StringUtil::deserialize($objEvent->repeatEachExt);
            $strKey = 'cal_'.$arrRange['value'];
            $strVal = $GLOBALS['TL_LANG']['DAYS'][$GLOBALS['TL_LANG']['DAYS'][$arrRange['unit']]];
            $recurring = sprintf($GLOBALS['TL_LANG']['MSC'][$strKey], $strVal);

            if ($objEvent->recurrences > 0) {
                $until = sprintf($GLOBALS['TL_LANG']['MSC']['cal_until'], \Contao\Date::parse($objPage->dateFormat, $objEvent->repeatEnd));
            }
        }

        // moveReason fix...
        $moveReason = null;

        // get moveReason from exceptions
        if ($objEvent->useExceptions) {
            $exceptions = StringUtil::deserialize($objEvent->exceptionList);
            if ($exceptions) {
                foreach ($exceptions as $fixedDate) {
                    // look for the reason only if we have a move action
                    if ($fixedDate['action'] === "move") {
                        // value to add to the old date
                        $addToDate = $fixedDate['new_exception'];
                        $newDate = strtotime($addToDate, $fixedDate['exception']);
                        if (date("Ymd", $newDate) == date("Ymd", $intStartTime)) {
                            $moveReason = ($fixedDate['reason']) ? $fixedDate['reason'] : null;
                        }
                    }
                }
            }
        }

        // get moveReason from fixed dates if exists...
        if (!is_null($objEvent->repeatFixedDates)) {
            $arrFixedDates = StringUtil::deserialize($objEvent->repeatFixedDates);
            if (is_array($arrFixedDates)) {
                foreach ($arrFixedDates as $fixedDate) {
                    if (date("Ymd", strtotime($fixedDate['new_repeat'])) == date("Ymd", $intStartTime)) {
                        $moveReason = ($fixedDate['reason']) ? $fixedDate['reason'] : null;
                    }
                }
            }
        }

        // check the repeat values
        $unit = '';
        if ($objEvent->recurring) {
            $arrRepeat = StringUtil::deserialize($objEvent->repeatEach) ? StringUtil::deserialize($objEvent->repeatEach) : null;
            $unit = $arrRepeat['unit'];
        }
        if ($objEvent->recurringExt) {
            $arrRepeat = StringUtil::deserialize($objEvent->repeatEachExt) ? StringUtil::deserialize($objEvent->repeatEachExt) : null;
            $unit = $arrRepeat['unit'];
        }

        // get the configured weekdays if any
        $useWeekdays = ($weekdays = StringUtil::deserialize($objEvent->repeatWeekday)) ? true : false;

        // Set the next date
        $nextDate = null;
        if ($objEvent->repeatDates) {
            $arrNext = StringUtil::deserialize($objEvent->repeatDates);
            if (is_array($arrNext)) {
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
            }
            $event['nextDate'] = $nextDate;
        }

        if ($objEvent->allRecurrences) {
            $objEvent->allRecurrences = StringUtil::deserialize($objEvent->allRecurrences);
        }

        /** @var \Contao\Contao\FrontendTemplate|object $objTemplate */
        $objTemplate = new \Contao\Contao\FrontendTemplate($this->cal_template ?: 'event_full');
        $objTemplate->setData($objEvent->row());

        $objTemplate->date = $strDate;
        $objTemplate->time = $strTime;
        $objTemplate->datetime = $objEvent->addTime ? date('Y-m-d\TH:i:sP', $intStartTime) : date('Y-m-d', $intStartTime);
        $objTemplate->begin = $intStartTime;
        $objTemplate->end = $intEndTime;
        $objTemplate->class = ($objEvent->cssClass != '') ? ' '.$objEvent->cssClass : '';
        $objTemplate->recurring = $recurring;
        $objTemplate->until = $until;
        $objTemplate->locationLabel = $GLOBALS['TL_LANG']['MSC']['location'];
        $objTemplate->details = '';
        $objTemplate->hasDetails = false;
        $objTemplate->hasTeaser = false;

        $objTemplate->nextDate = $nextDate;
        $objTemplate->moveReason = ($moveReason) ? $moveReason : null;

        // Restore event times...
        $objEvent->startTime = $orgStartTime;
        $objEvent->endTime = $orgEndTime;

        // Clean the RTE output
        if ($objEvent->teaser != '') {
            $objTemplate->hasTeaser = true;
            $objTemplate->teaser = $objEvent->teaser;
            $objTemplate->teaser = StringUtil::encodeEmail($objTemplate->teaser);
        }

        // Display the "read more" button for external/article links
        if ($objEvent->source != 'default') {
            $objTemplate->details = true;
            $objTemplate->hasDetails = true;
        } // Compile the event text
        else {
            $id = $objEvent->id;

            $objTemplate->details = function () use ($id) {
                $strDetails = '';
                $objElement = \Contao\ContentModel::findPublishedByPidAndTable($id, 'tl_calendar_events');

                if ($objElement !== null) {
                    while ($objElement->next()) {
                        $strDetails .= $this->getContentElement($objElement->current());
                    }
                }

                return $strDetails;
            };

            $objTemplate->hasDetails = function () use ($id) {
                return \Contao\ContentModel::countPublishedByPidAndTable($id, 'tl_calendar_events') > 0;
            };
        }

        $objTemplate->addImage = false;
        $objTemplate->addBefore = false;

        // Add an image
        if ($objEvent->addImage) {
            $imgSize = $objEvent->size ?: null;

            // Override the default image size
            if ($this->imgSize) {
                $size = StringUtil::deserialize($this->imgSize);

                if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]) || ($size[2][0] ?? null) === '_') {
                    $imgSize = $this->imgSize;
                }
            }

            $figure = System::getContainer()
                ->get('contao.image.studio')
                ->createFigureBuilder()
                ->from($objEvent->singleSRC)
                ->setSize($imgSize)
                ->setOverwriteMetadata($objEvent->getOverwriteMetadata())
                ->enableLightbox($objEvent->fullsize)
                ->buildIfResourceExists();

            $figure?->applyLegacyTemplateData($objTemplate, null, $objEvent->floating);
        }

        $objTemplate->enclosure = [];

        // Add enclosures
        if ($objEvent->addEnclosure) {
            $this->addEnclosuresToTemplate($objTemplate, $objEvent->row());
        }

        $this->Template->event = $objTemplate->parse();

        // HOOK: comments extension required
        if ($objEvent->noComments || !isset(System::getContainer()->getParameter('kernel.bundles')['ContaoCommentsBundle'])) {
            $this->Template->allowComments = false;

            return;
        }

        /** @var \Contao\CalendarModel $objCalendar */
        $objCalendar = $objEvent->getRelated('pid');
        $this->Template->allowComments = $objCalendar->allowComments;

        // Comments are not allowed
        if (!$objCalendar->allowComments) {
            return;
        }

        // Adjust the comments headline level
        $intHl = min(intval(str_replace('h', '', $this->hl)), 5);
        $this->Template->hlc = 'h'.($intHl + 1);

        $this->import('Comments');
        $arrNotifies = [];

        // Notify the system administrator
        if ($objCalendar->notify != 'notify_author') {
            $arrNotifies[] = $GLOBALS['TL_ADMIN_EMAIL'];
        }

        // Notify the author
        if ($objCalendar->notify != 'notify_admin') {
            /** @var \Contao\UserModel $objAuthor */
            if (($objAuthor = $objEvent->getRelated('author')) !== null && $objAuthor->email != '') {
                $arrNotifies[] = $objAuthor->email;
            }
        }

        $objConfig = new \stdClass();

        $objConfig->perPage = $objCalendar->perPage;
        $objConfig->order = $objCalendar->sortOrder;
        $objConfig->template = $this->com_template;
        $objConfig->requireLogin = $objCalendar->requireLogin;
        $objConfig->disableCaptcha = $objCalendar->disableCaptcha;
        $objConfig->bbcode = $objCalendar->bbcode;
        $objConfig->moderate = $objCalendar->moderate;

        $this->Comments->addCommentsToTemplate($this->Template, $objConfig, 'tl_calendar_events', $objEvent->id, $arrNotifies);
    }
}
